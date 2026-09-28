<?php

declare(strict_types=1);

/**
 * Daily bill-upload compliance.
 *
 * WHAT IS BEING CHECKED
 * Every active branch must submit its bills each day. A branch is compliant for
 * a date D when, for each payment type the owner has marked as required, at
 * least one active bill exists for that branch with payment_type = that type.
 * A branch with nothing required is trivially compliant and is never alerted on.
 *
 * COMPLIANCE IS MEASURED ON uploaded_at, NOT business_date
 * business_date is the date printed on the document and a branch may legitimately
 * back-date it. If compliance keyed off business_date, a branch could satisfy
 * Monday by uploading Sunday's bill on Monday, and a branch that uploads
 * everything in one late batch at 23:00 would look permanently complete. The
 * question the owner is actually asking is "did each branch send today's
 * uploads today", so the submission timestamp is the right basis. This also
 * matches public/branch/dashboard.php, which already counts on DATE(uploaded_at).
 *
 * THE DAY BOUNDARIES ARE COMPUTED IN PHP, NOT BY MYSQL
 * The query compares uploaded_at against a half-open range built from
 * [D 00:00:00, D+1 00:00:00) rather than using DATE(uploaded_at) = D. Two
 * reasons. First, MySQL evaluates DATE() in the *server's* zone while the app
 * runs in APP_TIMEZONE (Asia/Kolkata by default, config.php:154), and no
 * connection in this project issues SET time_zone, so a server zone that differs
 * would silently shift the window. Second, a range comparison is sargable and
 * uses idx_uploaded_at, which DATE() cannot.
 *
 * DEADLINE SEMANTICS
 * isEnforcedOn() answers "is the owner asking to be told about this day yet".
 * The default deadline is 23:30 local, so the cron can safely run hourly all
 * day: the check is a no-op until the deadline passes, and then it evaluates
 * the day it is in. Alerting on the day it is missed - rather than on the
 * following morning - means the branch can still fix it that night and the
 * alert self-resolves, which is why Notification stores resolved_at.
 *
 * IDEMPOTENCY
 * evaluateAndNotify() writes a daily_upload_checks row keyed UNIQUE on
 * check_date and creates notifications keyed UNIQUE on dedupe_key. The cron
 * running hourly and the owner dashboard running the same sweep on page load
 * therefore cannot produce a duplicate alert.
 */
class DailyCompliance
{
    public const SETTING_ENABLED       = 'daily_upload_alert_enabled';
    public const SETTING_DEADLINE_TIME = 'daily_upload_deadline_time';
    public const SETTING_DEADLINE_MODE = 'daily_upload_deadline_mode';
    public const SETTING_WEEKDAYS      = 'daily_upload_deadline_weekdays';
    public const SETTING_START_DATE    = 'daily_upload_alert_start_date';
    public const SETTING_END_DATE      = 'daily_upload_alert_end_date';

    private const DEFAULT_DEADLINE = '23:30';

    /** Payment types, in the order they are reported and stored in dedupe keys. */
    public const TYPES = ['cash', 'card'];

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public static function isEnabled(): bool
    {
        return Setting::get(self::SETTING_ENABLED, '1') === '1';
    }

    /** Which payment types must be present. Mirrors the branch dashboard. */
    public static function requiredTypes(): array
    {
        $required = [];
        if (Setting::get('daily_cash_required', '1') === '1') {
            $required[] = 'cash';
        }
        if (Setting::get('daily_card_required', '1') === '1') {
            $required[] = 'card';
        }
        return $required;
    }

    /**
     * Is the day actually judged at all?
     *
     * With nothing required, every branch is trivially "compliant" - there is no
     * gap to have. That is correct for the maths and disastrous for the words: a
     * table that says "Uploaded" beside a branch with zero bills is a lie the
     * owner has no way to detect, and it is easy to reach by unticking both
     * requirement boxes.
     *
     * The pages ask this before describing a branch as compliant, and show
     * "Not required" instead. A status has to be able to say "this was never
     * checked", otherwise a disabled check looks exactly like a passing one.
     */
    public static function hasRequirement(): bool
    {
        return self::requiredTypes() !== [];
    }

    public static function deadlineTime(): string
    {
        return self::normaliseTime((string) Setting::get(self::SETTING_DEADLINE_TIME, self::DEFAULT_DEADLINE))
            ?? self::DEFAULT_DEADLINE;
    }

    public static function deadlineMode(): string
    {
        return Setting::get(self::SETTING_DEADLINE_MODE, 'time') === 'per_weekday'
            ? 'per_weekday'
            : 'time';
    }

    /**
     * Per-weekday overrides, keyed by PHP date('w'): 0 = Sunday .. 6 = Saturday.
     * A weekday with no override falls back to the single global deadline, so a
     * partially filled grid is valid rather than a configuration error.
     *
     * @return array<int,string>
     */
    public static function weekdayDeadlines(): array
    {
        $raw = (string) Setting::get(self::SETTING_WEEKDAYS, '');
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $day => $time) {
            $day = (int) $day;
            if ($day < 0 || $day > 6) {
                continue;
            }
            $clean = self::normaliseTime((string) $time);
            if ($clean !== null) {
                $out[$day] = $clean;
            }
        }
        return $out;
    }

    /**
     * The deadline that applies to a given date, honouring the per-weekday mode.
     */
    public static function deadlineFor(string $date): string
    {
        if (self::deadlineMode() === 'per_weekday') {
            $day = (int) date('w', strtotime($date));
            $overrides = self::weekdayDeadlines();
            if (isset($overrides[$day])) {
                return $overrides[$day];
            }
        }
        return self::deadlineTime();
    }

    /**
     * The instant at which enforcement begins for a date, as a Unix timestamp.
     *
     * Built in PHP so it is in APP_TIMEZONE. date_default_timezone_set() in
     * config.php:154 has already applied that zone, so date() here is local
     * hotel time, which is what "23:30" means to the owner setting it.
     */
    public static function deadlineTimestamp(string $date): int
    {
        $time = self::deadlineFor($date);
        [$h, $m] = array_pad(array_map('intval', explode(':', $time)), 2, 0);
        $ts = strtotime($date . ' 00:00:00');
        if ($ts === false) {
            return 0;
        }
        return $ts + ($h * 3600) + ($m * 60);
    }

    /**
     * Should the owner be told about this date yet?
     *
     * False when alerts are switched off, when the date falls outside the
     * configured enforcement window, or when the deadline has not arrived. The
     * last case is what lets the cron run hourly: nothing happens until it
     * matters, and no alert is ever raised for a day that is still in progress.
     */
    public static function isEnforcedOn(string $date, ?int $now = null): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $ts = strtotime($date . ' 00:00:00');
        if ($ts === false) {
            return false;
        }

        $start = self::enforcementDate(self::SETTING_START_DATE);
        if ($start !== null && $ts < $start) {
            return false;
        }

        $end = self::enforcementDate(self::SETTING_END_DATE);
        if ($end !== null && $ts > $end) {
            return false;
        }

        $now = $now ?? time();
        return $now >= self::deadlineTimestamp($date);
    }

    /**
     * The first date that is still being enforced, i.e. what a caller sweeping
     * "everything outstanding" should start from.
     */
    public static function enforcementWindowStart(?int $now = null): string
    {
        $now = $now ?? time();
        $start = self::enforcementDate(self::SETTING_START_DATE);
        if ($start !== null && $start <= $now) {
            return date('Y-m-d', $start);
        }
        return date('Y-m-d', $now);
    }

    // ------------------------------------------------------------------
    // Evaluation
    // ------------------------------------------------------------------

    /**
     * Live compliance for a date, without writing anything.
     *
     * The dashboard banner, the bell and the report page all read this, so a
     * stale stored alert can never contradict what the table on screen shows.
     *
     * Only active branches are evaluated. public/branch/upload.php:17 refuses
     * uploads for an inactive branch, so alerting on one would produce an alert
     * that can never be satisfied and trains the owner to ignore the badge.
     *
     * @return array{
     *   date:string, evaluated:bool, deadline:string, next_evaluation_at:string,
     *   required:array<int,string>, branches_total:int, branches_missing:int,
     *   missing_cash:int, missing_card:int, branches:array<int,array>
     * }
     */
    public static function statusForDate(string $date, ?int $now = null): array
    {
        $now      = $now ?? time();
        $required = self::requiredTypes();
        $ts       = strtotime($date . ' 00:00:00');
        $from     = $ts === false ? $date . ' 00:00:00' : date('Y-m-d H:i:s', $ts);
        $to       = $ts === false ? $date . ' 23:59:59' : date('Y-m-d H:i:s', $ts + 86400);

        $result = [
            'date'               => $date,
            'evaluated'          => self::isEnforcedOn($date, $now),
            'deadline'           => self::deadlineFor($date),
            // Machine-readable form of the same instant. The owner pages hand
            // this to the browser so a page left open across the deadline can
            // refresh itself, instead of the owner staring at a stale page
            // wondering why the alert has not appeared.
            'deadline_ts'        => self::deadlineTimestamp($date),
            'next_evaluation_at' => date('Y-m-d H:i', self::deadlineTimestamp($date)),
            'required'           => $required,
            'branches_total'     => 0,
            'branches_missing'   => 0,
            'missing_cash'       => 0,
            'missing_card'       => 0,
            'branches'           => [],
        ];

        $rows = Database::fetchAll(
            "SELECT b.id, b.branch_code, b.branch_name, b.status,
                    (SELECT COUNT(*) FROM bills bl
                      WHERE bl.branch_id = b.id
                        AND bl.status = 'active'
                        AND bl.payment_type = 'cash'
                        AND bl.uploaded_at >= ? AND bl.uploaded_at < ?) AS cash_count,
                    (SELECT COUNT(*) FROM bills bl
                      WHERE bl.branch_id = b.id
                        AND bl.status = 'active'
                        AND bl.payment_type = 'card'
                        AND bl.uploaded_at >= ? AND bl.uploaded_at < ?) AS card_count,
                    (SELECT MAX(bl.uploaded_at) FROM bills bl
                      WHERE bl.branch_id = b.id
                        AND bl.status = 'active'
                        AND bl.uploaded_at >= ? AND bl.uploaded_at < ?) AS last_upload
             FROM branches b
             WHERE b.deleted_at IS NULL AND b.status = 'active'
             ORDER BY b.branch_name ASC",
            [$from, $to, $from, $to, $from, $to]
        );

        $result['branches_total'] = count($rows);

        foreach ($rows as $row) {
            $counts = [
                'cash' => (int) $row['cash_count'],
                'card' => (int) $row['card_count'],
            ];

            $missing = [];
            foreach ($required as $type) {
                if ($counts[$type] === 0) {
                    $missing[] = $type;
                }
            }

            $result['branches'][] = [
                'id'           => (int) $row['id'],
                'branch_code'  => (string) $row['branch_code'],
                'branch_name'  => (string) $row['branch_name'],
                'cash_count'   => $counts['cash'],
                'card_count'   => $counts['card'],
                'last_upload'  => $row['last_upload'],
                'missing'      => $missing,
                'compliant'    => $missing === [],
            ];

            if ($missing === []) {
                continue;
            }

            $result['branches_missing']++;
            if (in_array('cash', $missing, true)) {
                $result['missing_cash']++;
            }
            if (in_array('card', $missing, true)) {
                $result['missing_card']++;
            }
        }

        return $result;
    }

    /**
     * Persist one day's check and raise an alert for every non-compliant branch.
     *
     * Safe to call repeatedly: the check row is upserted and each alert is
     * guarded by its dedupe key, so a second call for an unchanged day creates
     * nothing and returns 0.
     *
     * @return array{date:string, skipped:bool, created:int, branches_missing:int, branches_total:int}
     */
    public static function evaluateAndNotify(string $date, bool $force = false, ?int $now = null): array
    {
        $status = self::statusForDate($date, $now);

        if (!$status['evaluated'] && !$force) {
            return [
                'date'             => $date,
                'skipped'          => true,
                'created'          => 0,
                'branches_missing' => $status['branches_missing'],
                'branches_total'   => $status['branches_total'],
            ];
        }

        $created = 0;

        foreach ($status['branches'] as $branch) {
            if ($branch['missing'] === []) {
                continue;
            }
            $created += self::notifyForBranch($branch, $date);
        }

        Database::execute(
            'INSERT INTO daily_upload_checks
                (check_date, evaluated_at, branches_total, branches_missing, notifications_created)
             VALUES (?, NOW(), ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                evaluated_at = NOW(),
                branches_total = VALUES(branches_total),
                branches_missing = VALUES(branches_missing),
                notifications_created = VALUES(notifications_created)',
            [$date, $status['branches_total'], $status['branches_missing'], $created]
        );

        return [
            'date'             => $date,
            'skipped'          => false,
            'created'          => $created,
            'branches_missing' => $status['branches_missing'],
            'branches_total'   => $status['branches_total'],
        ];
    }

    /**
     * Raise (or re-use) the alert for one non-compliant branch.
     *
     * The dedupe key carries which types are missing, not just the branch and
     * day, so a branch that fixes cash and then misses the card deadline gets a
     * second, correctly-scoped alert rather than having the first one silently
     * reused.
     *
     * @param array{id:int,branch_code:string,branch_name:string,cash_count:int,card_count:int,missing:array<int,string>} $branch
     */
    /**
     * Raise (or re-use) the alert for one non-compliant branch.
     *
     * Goes to two audiences, because they can act differently:
     *  - the owner, who has to chase the branch and owns the compliance record;
     *  - the admins of that branch, who are the only people who can actually
     *    upload the missing bill.
     *
     * Branch admins are only told about TODAY. A backfill sweep over past days
     * would otherwise greet a newly created admin with a wall of alerts for days
     * before they worked there, and they cannot act on those: compliance is
     * judged on the upload timestamp, so yesterday can never be satisfied after
     * the fact. The owner keeps the full history; the branch keeps what it can
     * still act on.
     *
     * @return int Rows created for the OWNER only. daily_upload_checks records
     *         the owner's notification count, and letting branch fan-out inflate
     *         it would make the operational history depend on how many admins a
     *         branch happens to have.
     */
    private static function notifyForBranch(array $branch, string $date): int
    {
        $missing = self::sortedMissing($branch);
        if ($missing === []) {
            return 0;
        }

        $severity = count($missing) === 2 ? 'danger' : 'warning';

        // The label the owner reads lists the actual gaps, so a branch that only
        // needs a card bill is never told it is missing cash.
        $missingLabelKey = count($missing) === 2
            ? 'compliance.missing_both'
            : 'compliance.missing_' . $missing[0];

        $params = [
            'branch' => (string) $branch['branch_name'],
            'code'   => (string) $branch['branch_code'],
            'date'   => (string) $date,
        ];
        $dedupe = self::dedupeBaseFor($branch['id'], $date, $missing);

        $created = Notification::createForOwners(
            Notification::TYPE_DAILY_UPLOAD_MISSING,
            $severity,
            'compliance.alert_title',
            $missingLabelKey,
            $params,
            $branch['id'],
            $date,
            $dedupe
        );

        if ($date === date('Y-m-d')) {
            Notification::createForBranch(
                (int) $branch['id'],
                Notification::TYPE_DAILY_UPLOAD_MISSING,
                $severity,
                'compliance.alert_title',
                $missingLabelKey,
                $params,
                $date,
                $dedupe
            );
        }

        return $created;
    }

    /**
     * The logical event key for one branch, day and set of gaps.
     *
     * Which types are missing is part of the key on purpose: a branch that fixes
     * cash and then misses the card deadline is a different event from the one
     * the sweep raised, and reusing the old key would either hide the new gap or
     * resurrect a resolved alert.
     *
     * @param array<int,string> $missing
     */
    private static function dedupeBaseFor(int $branchId, string $date, array $missing): string
    {
        return sprintf('daily_upload_missing:%d:%s:%s', $branchId, $date, implode('+', $missing));
    }

    /**
     * Just the outstanding types, in a stable order.
     *
     * Order matters because it is part of the dedupe key: cash+card and card+cash
     * describe the same gap and must not produce two different alerts.
     *
     * @return array<int,string>
     */
    private static function sortedMissing(array $branch): array
    {
        $missing = array_values(array_intersect($branch['missing'] ?? [], self::TYPES));
        sort($missing);
        return $missing;
    }

    /**
     * Settle the "bills not uploaded" alert for the day a bill landed on.
     *
     * The interesting case is a partial fix. A branch that needed cash *and* card
     * and has uploaded only its cash bill is still non-compliant, so closing the
     * old alert and stopping there would leave the owner with no badge at all for
     * a day that is still incomplete - exactly the silence that makes an alert
     * badge something people learn to ignore. So the day's state is re-checked
     * and, if something is still missing, the stale alert is closed and a new one
     * is raised naming exactly what is left.
     *
     * Compliance is measured on the submission timestamp, not business_date, so
     * the day judged here is the day the bill landed. Uploading a back-dated bill
     * therefore settles yesterday's alert and leaves today's alone.
     *
     * @return array{closed:int, compliant:bool, missing:array<int,string>}
     *         closed    - alerts this call closed, for the audit log
     *         compliant - whether the day is now complete
     *         missing   - the types still outstanding, in a stable order
     */
    public static function settleAfterUpload(int $branchId, string $date): array
    {
        $branch = null;
        foreach (self::statusForDate($date)['branches'] as $candidate) {
            if ($candidate['id'] === $branchId) {
                $branch = $candidate;
                break;
            }
        }

        if ($branch === null) {
            // The branch is no longer active, or no longer exists, so there is
            // nothing left to chase and the alert is closed on that basis.
            return [
                'closed'    => Notification::resolveForBranchDate($branchId, $date),
                'compliant' => true,
                'missing'   => [],
            ];
        }

        $missing = self::sortedMissing($branch);
        if ($missing === []) {
            return [
                'closed'    => Notification::resolveForBranchDate($branchId, $date),
                'compliant' => true,
                'missing'   => [],
            ];
        }

        // Still incomplete. When the outstanding gaps are exactly what the open
        // alert already says, that alert is still true and is left untouched -
        // otherwise a bill that changes nothing would churn the badge off and on.
        if (Notification::hasOpenForOwners(self::dedupeBaseFor($branchId, $date, $missing))) {
            return ['closed' => 0, 'compliant' => false, 'missing' => $missing];
        }

        $closed = Notification::resolveForBranchDate($branchId, $date);
        self::notifyForBranch($branch, $date);

        return ['closed' => $closed, 'compliant' => false, 'missing' => $missing];
    }
    /**
     * Run the day's check if - and only if - it can do something.
     *
     * The single place the "is it worth sweeping right now" decision is made, so
     * the pages that fall back to a sweep cannot drift apart. The condition is
     * "is anything actually missing", never "has this day not been checked yet":
     * gating on the second disables alerts for the rest of the day as soon as any
     * check row exists, which is how raising the requirements, or a branch that
     * starts missing a bill, ended up alerting nobody.
     *
     * Cheap in the common case: with nothing missing there is no dedupe lookup
     * at all, because nothing is raised. $status may be passed in by a caller
     * that already computed it for rendering, to avoid running the branch query
     * twice on a page load.
     *
     * @param array<string,mixed>|null $status Pre-computed statusForDate() result.
     */
    public static function sweepIfNeeded(string $date, ?array $status = null, ?int $now = null): array
    {
        $status = $status ?? self::statusForDate($date, $now);

        if (!$status['evaluated'] || $status['branches_missing'] === 0) {
            return [
                'date'             => $date,
                'skipped'          => true,
                'created'          => 0,
                'branches_missing' => $status['branches_missing'],
                'branches_total'   => $status['branches_total'],
            ];
        }

        // Writes to daily_upload_checks, so a host that has not run migration
        // 003 would throw here - and this runs on every dashboard load, so that
        // would be a 500 on the dashboard rather than a missing feature.
        if (!self::isAvailable()) {
            return [
                'date'             => $date,
                'skipped'          => true,
                'created'          => 0,
                'branches_missing' => $status['branches_missing'],
                'branches_total'   => $status['branches_total'],
            ];
        }

        return self::evaluateAndNotify($date, false, $now);
    }

    /**
     * Are this feature's tables present?
     *
     * The compliance tables arrive with migration 003. Branch and bill
     * management do not depend on them and must keep working while they are
     * absent, so the pages ask this instead of letting a write fail.
     */
    public static function isAvailable(): bool
    {
        return Database::tableExists('notifications') && Database::tableExists('daily_upload_checks');
    }

    /** Recorded sweeps, newest first, for the report page. */
    public static function checkHistory(int $limit = 30): array
    {
        $limit = max(1, min(365, $limit));

        return Database::fetchAll(
            "SELECT * FROM daily_upload_checks ORDER BY check_date DESC LIMIT {$limit}"
        );
    }

    /**
     * When the check for a day becomes due, or null if it never will.
     *
     * Null covers "the feature is off", "nothing is required", a day outside the
     * start/end window, and a day whose deadline has already passed - in all of
     * those cases there is nothing to wait for, so the pages say nothing rather
     * than showing a countdown to a moment that will never come.
     */
    public static function pendingDeadlineFor(string $date, ?int $now = null): ?int
    {
        $now = $now ?? time();

        if (Setting::get('daily_upload_alert_enabled', '1') !== '1') {
            return null;
        }
        if (self::requiredTypes() === []) {
            return null;
        }
        if (self::isEnforcedOn($date, $now)) {
            return null;
        }

        $ts = self::deadlineTimestamp($date);

        return $ts > $now ? $ts : null;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Accept only a real HH:MM, so a typo in the settings form cannot produce a
     * deadline of "99:99" that would silently never fire (or always fire).
     *
     * Minutes and hours may be written with one or two digits: the settings form
     * posts 6:05 as "06:05", but a hand-edited settings row can hold "6:5" and
     * falling back to the default for that would quietly enforce the wrong hour.
     */
    private static function normaliseTime(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $value, $m) !== 1) {
            return null;
        }
        $h = (int) $m[1];
        $m = (int) $m[2];
        if ($h > 23 || $m > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * An optional YYYY-MM-DD setting, as a timestamp at local midnight.
     * An empty or malformed value means "no bound", not "epoch".
     */
    private static function enforcementDate(string $key): ?int
    {
        $raw = trim((string) Setting::get($key, ''));
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw . ' 00:00:00');
        return $ts === false ? null : $ts;
    }
}
