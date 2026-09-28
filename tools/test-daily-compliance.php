<?php

declare(strict_types=1);

/**
 * Behavioural test for the daily upload compliance feature.
 *
 *   php tools/test-daily-compliance.php
 *
 * Needs a database with database/migrations/003_daily_compliance.sql applied
 * (php tools/migrate.php). Override the connection with the usual DB_HOST,
 * DB_PORT, DB_NAME, DB_USER, DB_PASS environment variables.
 *
 * WHY IT RUNS INSIDE A TRANSACTION
 * Every scenario writes real notifications, settings and bills. The whole run
 * is wrapped in one transaction that is always rolled back, so a test run cannot
 * leave phantom alerts in a database someone is using, and a failure halfway
 * through does not poison the next run.
 *
 * WHY THE SCENARIOS PICK THEIR OWN "NOW"
 * Compliance is time-of-day sensitive, and a test that waits for 23:30 to prove
 * the deadline is enforced is a test nobody runs. isEnforcedOn(), statusForDate()
 * and evaluateAndNotify() all accept an explicit $now, so each case declares the
 * instant it means to be testing and the deadline logic is exercised for real
 * rather than stubbed out.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$passed = 0;
$failed = [];
$currentScenario = '(setup)';

function scenario(string $name): void
{
    global $currentScenario;
    $currentScenario = $name;
    echo "\n  " . $name . "\n";
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $currentScenario;

    if ($ok) {
        $passed++;
        echo "    [ok]   {$label}\n";
        return;
    }
    $failed[] = $currentScenario . ': ' . $label . ($detail === '' ? '' : ' (' . $detail . ')');
    echo "    [FAIL] {$label}" . ($detail === '' ? '' : " ({$detail})") . "\n";
}

// ------------------------------------------------------------------
// Fixtures
// ------------------------------------------------------------------

/** A fixed owner with no alerts, so assertions are about this run only. */
function testOwner(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    Database::execute(
        "INSERT INTO users (username, name, email, password_hash, role, status)
         VALUES (?, 'Compliance Test Owner', ?, 'x', 'owner', 'active')",
        [
            'compliance_owner_' . bin2hex(random_bytes(4)),
            'compliance-owner-' . bin2hex(random_bytes(4)) . '@test.invalid',
        ]
    );
    return $id = Database::lastId();
}

/** Ids of every branch this run created, so scenarios can isolate themselves. */
$GLOBALS['compliance_test_branches'] = [];

/**
 * A branch admin, so the branch-side fan-out can be exercised.
 *
 * Cached per branch: two calls for the same branch return the same user, which
 * is what makes "the admin of a compliant branch gets nothing" a meaningful
 * assertion rather than an accident of fixture count.
 */
function testAdmin(int $branchId): int
{
    static $byBranch = [];
    if (isset($byBranch[$branchId])) {
        return $byBranch[$branchId];
    }

    $tag = bin2hex(random_bytes(4));
    Database::execute(
        "INSERT INTO users (branch_id, username, name, email, password_hash, role, status)
         VALUES (?, ?, 'Compliance Test Admin', ?, 'x', 'branch_admin', 'active')",
        [$branchId, 'compliance_admin_' . $tag, 'compliance-admin-' . $tag . '@test.invalid']
    );
    $GLOBALS['compliance_test_users'][] = Database::lastId();

    return $byBranch[$branchId] = Database::lastId();
}
function testBranch(string $suffix, string $status = 'active'): int
{
    static $n = 0;
    Database::execute(
        'INSERT INTO branches (branch_code, branch_name, address, phone, email, status)
         VALUES (?, ?, NULL, NULL, NULL, ?)',
        [
            'TST' . str_pad((string) (++$n), 4, '0', STR_PAD_LEFT),
            'Compliance Test Branch ' . $suffix,
            $status,
        ]
    );

    $id = Database::lastId();
    $GLOBALS['compliance_test_branches'][] = $id;
    return $id;
}

/**
 * Leave exactly one test branch active.
 *
 * A branch with no uploads is non-compliant on every other day too, so without
 * this a scenario about branch C also sees the silent branches A and B from
 * earlier scenarios and the "how many are missing" counts stop meaning
 * anything. The sweep always evaluates all active branches, so isolating the
 * subject of each scenario is what makes the numbers readable.
 */
function onlyBranchActive(int $branchId): void
{
    foreach ($GLOBALS['compliance_test_branches'] as $id) {
        if ($id !== $branchId) {
            Database::execute("UPDATE branches SET status = 'inactive' WHERE id = ?", [$id]);
        }
    }
}

/** A bill whose submission timestamp we control, which is the whole point. */
function testBill(int $branchId, string $paymentType, string $businessDate, string $uploadedAt, string $status = 'active'): int
{
    Database::execute(
        "INSERT INTO bills (branch_id, uploaded_by, payment_type, business_date,
                            original_filename, stored_filename, file_path, mime_type,
                            file_size, storage_status, status, uploaded_at)
         VALUES (?, ?, ?, ?, 'test.pdf', 'test.pdf', '/tmp/test.pdf', 'application/pdf',
                 1024, 'db_only', ?, ?)",
        [$branchId, testOwner(), $paymentType, $businessDate, $status, $uploadedAt]
    );
    return Database::lastId();
}

function setSetting(string $key, string $value): void
{
    Database::execute(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$key, $value]
    );
    Setting::clearCache();
}

function notificationsFor(int $ownerId, string $billDate): array
{
    return Database::fetchAll(
        'SELECT * FROM notifications WHERE user_id = ? AND bill_date = ? ORDER BY id',
        [$ownerId, $billDate]
    );
}

/** Alerts for one branch on one day - the assertions care about the fixture,
 *  not about whatever else the database already contained. */
function alertsForBranch(int $ownerId, int $branchId, string $billDate): array
{
    return Database::fetchAll(
        'SELECT * FROM notifications
         WHERE user_id = ? AND branch_id = ? AND bill_date = ? ORDER BY id',
        [$ownerId, $branchId, $billDate]
    );
}

/** Just the alerts the owner is still being nagged about. */
function openAlertsForBranch(int $ownerId, int $branchId, string $billDate): array
{
    return Database::fetchAll(
        'SELECT * FROM notifications
         WHERE user_id = ? AND branch_id = ? AND bill_date = ? AND resolved_at IS NULL
         ORDER BY id',
        [$ownerId, $branchId, $billDate]
    );
}

/** The evaluated row for one branch, found by id rather than by position. */
function branchStatus(array $status, int $branchId): ?array
{
    foreach ($status['branches'] as $b) {
        if ((int) $b['id'] === $branchId) {
            return $b;
        }
    }
    return null;
}

function clearNotifications(int $ownerId): void
{
    Database::execute('DELETE FROM notifications WHERE user_id = ?', [$ownerId]);
    Database::execute('DELETE FROM daily_upload_checks WHERE check_date >= ?', ['2000-01-01']);
}

// ------------------------------------------------------------------
// Setup: baseline configuration
// ------------------------------------------------------------------

echo "Daily upload compliance test\n";
echo str_repeat('=', 66) . "\n";

$missingTables = [];
foreach (['notifications', 'daily_upload_checks'] as $table) {
    $found = Database::fetch(
        "SELECT COUNT(*) AS c FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?",
        [$table]
    );
    if ((int) ($found['c'] ?? 0) === 0) {
        $missingTables[] = $table;
    }
}
if ($missingTables) {
    echo "\nFAILED: missing table(s) " . implode(', ', $missingTables)
        . "\nRun: php tools/migrate.php\n";
    exit(1);
}

// Everything below is fixture data, so the transaction has to be open before the
// first write. Opening it later would leave the test owner, its branches and its
// bills in the database on a failed run.
Database::connection()->beginTransaction();

/**
 * Park every branch and owner that existed before this run.
 *
 * The sweep evaluates all active branches, and createForOwners() fans out to
 * every active owner, so on a database that already has real data every
 * assertion about "how many branches are missing" or "how many alerts were
 * created" would be measuring the seed data instead of the fixture. The updates
 * happen inside the transaction and are rolled back at the end, so the database
 * is left exactly as it was found.
 *
 * This has to happen *before* the test fixtures are created, or the blanket
 * UPDATE would switch the fixture owner off along with the seeded ones.
 */
$preexistingBranches = Database::fetchAll('SELECT id FROM branches');
Database::execute("UPDATE branches SET status = 'inactive' WHERE status = 'active'");
check(
    'the seeded branches were parked for the duration of the test',
    count($preexistingBranches) > 0,
    count($preexistingBranches) . ' branch(es) parked'
);

$preexistingOwners = Database::fetchAll("SELECT id FROM users WHERE role = 'owner'");
Database::execute("UPDATE users SET status = 'inactive' WHERE role = 'owner'");
check(
    'the seeded owners were parked for the duration of the test',
    count($preexistingOwners) > 0,
    count($preexistingOwners) . ' owner(s) parked'
);

$ownerId = testOwner();

// Cash and card both required is the strictest configuration, so a branch is
// only compliant when it has submitted both. Every scenario below is written
// against that.
setSetting('daily_cash_required', '1');
setSetting('daily_card_required', '1');
setSetting(DailyCompliance::SETTING_ENABLED, '1');
setSetting(DailyCompliance::SETTING_DEADLINE_TIME, '23:30');
setSetting(DailyCompliance::SETTING_DEADLINE_MODE, 'time');
setSetting(DailyCompliance::SETTING_WEEKDAYS, '');
setSetting(DailyCompliance::SETTING_START_DATE, '');
setSetting(DailyCompliance::SETTING_END_DATE, '');

// Two fixed days so the assertions read as calendar dates.
$day      = '2026-03-10';
$prevDay  = '2026-03-09';
$midnight = strtotime($day . ' 00:00:00');
$beforeDeadline = $midnight + (12 * 3600);            // 12:00
$afterDeadline  = $midnight + (23 * 3600) + (45 * 60); // 23:45

try {
    // ------------------------------------------------------------------
    scenario('A day in progress is never alerted on');
    // ------------------------------------------------------------------
    clearNotifications($ownerId);
    $branch = testBranch('A');
    onlyBranchActive($branch);

    $status = DailyCompliance::statusForDate($day, $beforeDeadline);
    check('evaluated is false before the deadline', $status['evaluated'] === false, 'evaluated=' . var_export($status['evaluated'], true));
    check('the branch is still counted as non-compliant', $status['branches_missing'] === 1, 'missing=' . $status['branches_missing']);

    $result = DailyCompliance::evaluateAndNotify($day, false, $beforeDeadline);
    check('evaluateAndNotify skips the day', $result['skipped'] === true);
    check('no alert is written', $result['created'] === 0, 'created=' . $result['created']);
    check('nothing is stored for the owner', notificationsFor($ownerId, $day) === []);

    // ------------------------------------------------------------------
    scenario('After the deadline a silent branch is alerted');
    // ------------------------------------------------------------------
    $result = DailyCompliance::evaluateAndNotify($day, false, $afterDeadline);
    check('the sweep ran', $result['skipped'] === false);
    check('one alert was created', $result['created'] === 1, 'created=' . $result['created']);
    check('one branch is missing', $result['branches_missing'] === 1);

    $rows = notificationsFor($ownerId, $day);
    check('the owner has exactly one alert', count($rows) === 1, 'rows=' . count($rows));
    check('the alert is the danger severity for no uploads at all', ($rows[0]['severity'] ?? '') === 'danger', $rows[0]['severity'] ?? '');
    check('the body names both missing types', ($rows[0]['body_key'] ?? '') === 'compliance.missing_both', $rows[0]['body_key'] ?? '');
    check('the alert starts unread and unresolved', $rows[0]['read_at'] === null && $rows[0]['resolved_at'] === null);
    check('unreadCount sees it', Notification::unreadCount($ownerId) === 1, 'unread=' . Notification::unreadCount($ownerId));

    // ------------------------------------------------------------------
    scenario('Re-running the sweep does not spam the owner');
    // ------------------------------------------------------------------
    $second = DailyCompliance::evaluateAndNotify($day, false, $afterDeadline);
    check('the second run creates nothing', $second['created'] === 0, 'created=' . $second['created']);
    check('still exactly one alert exists', count(notificationsFor($ownerId, $day)) === 1);

    $third = DailyCompliance::evaluateAndNotify($day, false, $afterDeadline + 3600);
    check('a later run in the same day is also a no-op', $third['created'] === 0, 'created=' . $third['created']);

    $checkRows = Database::fetchAll('SELECT * FROM daily_upload_checks WHERE check_date = ?', [$day]);
    check('one check row per day', count($checkRows) === 1, 'rows=' . count($checkRows));
    check('the check row records the missing count', (int) $checkRows[0]['branches_missing'] === 1);

    // ------------------------------------------------------------------
    scenario('A cash-only branch gets a card-scoped warning, not "missing both"');
    // ------------------------------------------------------------------
    clearNotifications($ownerId);
    $partialDay = '2026-03-11';
    $partial = testBranch('B');
    onlyBranchActive($partial);
    testBill($partial, 'cash', $partialDay, $partialDay . ' 09:00:00');

    $partialNow = strtotime($partialDay . ' 00:00:00') + 86399;
    $result = DailyCompliance::evaluateAndNotify($partialDay, false, $partialNow);
    check('one alert for the partial branch', $result['created'] === 1, 'created=' . $result['created']);

    $rows = alertsForBranch($ownerId, $partial, $partialDay);
    check('exactly one alert row', count($rows) === 1, 'rows=' . count($rows));
    check('the body names only the card gap', ($rows[0]['body_key'] ?? '') === 'compliance.missing_card', $rows[0]['body_key'] ?? '');
    check('severity is warning, not danger', ($rows[0]['severity'] ?? '') === 'warning', $rows[0]['severity'] ?? '');

    $params = Notification::params($rows[0]);
    check('the branch name travels in the params', ($params['branch'] ?? '') === 'Compliance Test Branch B', json_encode($params));
    check('the body key resolves in English', t($rows[0]['body_key'], $params) === 'No Card bill uploaded', t($rows[0]['body_key'], $params));
    Lang::persist('de');
    check('the same alert resolves in German', t($rows[0]['body_key'], $params) === 'Kein Kartenbeleg hochgeladen', t($rows[0]['body_key'], $params));
    Lang::persist('en');

    // ------------------------------------------------------------------
    scenario('Uploading the missing bill closes the alert');
    // ------------------------------------------------------------------
    // The card bill is inserted first: settling an alert is only ever triggered
    // by a bill having landed, and the branch is still short of the card bill
    // until this row exists.
    testBill($partial, 'card', $partialDay, $partialDay . ' 09:30:00');
    $settled = DailyCompliance::settleAfterUpload($partial, $partialDay);
    check('one alert was closed', $settled['closed'] === 1, 'closed=' . $settled['closed']);
    check('the day is reported compliant', $settled['compliant'] === true);
    check('nothing is listed as missing', $settled['missing'] === [], json_encode($settled['missing']));

    $rows = notificationsFor($ownerId, $partialDay);
    check('the row is kept for history', count($rows) === 1);
    check('it is marked resolved', $rows[0]['resolved_at'] !== null);
    check('the badge stops counting it', Notification::unreadCount($ownerId) === 0, 'unread=' . Notification::unreadCount($ownerId));

    $search = Notification::search(['user_id' => $ownerId, 'state' => 'open']);
    check('an open-state search skips resolved alerts', $search['count'] === 0, 'count=' . $search['count']);
    $search = Notification::search(['user_id' => $ownerId, 'state' => 'resolved']);
    check('a resolved-state search finds it', $search['count'] === 1, 'count=' . $search['count']);

    // ------------------------------------------------------------------
    scenario('A half-finished day keeps an alert for what is still missing');
    // ------------------------------------------------------------------
    // Closing the alert on the first bill of two would leave the owner with no
    // badge at all for a day that is still incomplete, which is how an alert
    // badge becomes something people ignore.
    clearNotifications($ownerId);
    $halfDay = '2026-03-13';
    $half = testBranch('D');
    onlyBranchActive($half);

    $halfNow = strtotime($halfDay . ' 00:00:00') + 86399;
    $raised = DailyCompliance::evaluateAndNotify($halfDay, true, $halfNow);
    check('both gaps are alerted first', $raised['created'] === 1, 'created=' . $raised['created']);
    $rows = alertsForBranch($ownerId, $half, $halfDay);
    check('the first alert says both are missing', ($rows[0]['body_key'] ?? '') === 'compliance.missing_both', $rows[0]['body_key'] ?? '');
    $bothAlertId = (int) $rows[0]['id'];

    // The branch now uploads only its cash bill.
    testBill($half, 'cash', $halfDay, $halfDay . ' 11:00:00');
    $partial = DailyCompliance::settleAfterUpload($half, $halfDay);
    check('the stale alert is closed', $partial['closed'] === 1, 'closed=' . $partial['closed']);
    check('the day is not reported compliant', $partial['compliant'] === false);
    check('the card gap is still outstanding', $partial['missing'] === ['card'], json_encode($partial['missing']));

    $open = openAlertsForBranch($ownerId, $half, $halfDay);
    check('exactly one alert is open again', count($open) === 1, 'open=' . count($open));
    check('it is a new row, not the closed one', $open && (int) $open[0]['id'] !== $bothAlertId, 'id=' . ($open[0]['id'] ?? 'none'));
    check('it names only the card gap', ($open[0]['body_key'] ?? '') === 'compliance.missing_card', $open[0]['body_key'] ?? '');
    check('the badge counts the remaining gap', Notification::unreadCount($ownerId) === 1, 'unread=' . Notification::unreadCount($ownerId));

    // A bill that changes nothing must not churn the alert.
    $noChange = DailyCompliance::settleAfterUpload($half, $halfDay);
    check('an unchanged day closes nothing', $noChange['closed'] === 0, 'closed=' . $noChange['closed']);
    $open = openAlertsForBranch($ownerId, $half, $halfDay);
    check('the same alert is still the only open one', count($open) === 1 && (int) $open[0]['id'] !== $bothAlertId);
    check('and the history kept both rows', count(alertsForBranch($ownerId, $half, $halfDay)) === 2, 'rows=' . count(alertsForBranch($ownerId, $half, $halfDay)));

    // The last bill clears it for good.
    testBill($half, 'card', $halfDay, $halfDay . ' 12:00:00');
    $final = DailyCompliance::settleAfterUpload($half, $halfDay);
    check('the final bill closes the alert', $final['closed'] === 1, 'closed=' . $final['closed']);
    check('the day is now compliant', $final['compliant'] === true);
    check('the badge is clear', Notification::unreadCount($ownerId) === 0, 'unread=' . Notification::unreadCount($ownerId));

    // The partial toast must be a real sentence in both languages, not a
    // catalogue key with a missing placeholder. The fragment is re-resolved per
    // locale, because a cached English fragment would leak into the German page.
    check(
        'the partial message is complete in English',
        strpos(t('js.compliance_partial', ['what' => t('compliance.type_card_short')]), ':what') === false
            && strpos(t('js.compliance_partial', ['what' => t('compliance.type_card_short')]), 'Card bill') !== false,
        t('js.compliance_partial', ['what' => t('compliance.type_card_short')])
    );
    Lang::persist('de');
    check(
        'the partial message is complete in German',
        strpos(t('js.compliance_partial', ['what' => t('compliance.type_card_short')]), ':what') === false
            && strpos(t('js.compliance_partial', ['what' => t('compliance.type_card_short')]), 'Kartenbeleg') !== false,
        t('js.compliance_partial', ['what' => t('compliance.type_card_short')])
    );
    Lang::persist('en');

    // ------------------------------------------------------------------
    scenario('Compliance follows the submission date, not the printed date');
    // ------------------------------------------------------------------
    // A branch that back-dates its bill to last week still satisfies today:
    // the owner is asking "did each branch send something today".
    clearNotifications($ownerId);
    $backdateDay = '2026-03-12';
    $backdated = testBranch('C');
    onlyBranchActive($backdated);
    testBill($backdated, 'cash', $prevDay, $backdateDay . ' 10:00:00');
    testBill($backdated, 'card', $prevDay, $backdateDay . ' 10:05:00');

    $backdateNow = strtotime($backdateDay . ' 00:00:00') + 86399;
    $status = DailyCompliance::statusForDate($backdateDay, $backdateNow);
    $row = branchStatus($status, $backdated);
    check('the back-dated branch is in the day window', $row !== null);
    check('a back-dated bill counts for today', ($row['compliant'] ?? false) === true, 'missing=' . json_encode($row['missing'] ?? null));
    check('and the day reports nothing missing', $status['branches_missing'] === 0, 'missing=' . $status['branches_missing']);

    $result = DailyCompliance::evaluateAndNotify($backdateDay, false, $backdateNow);
    check('no alert is raised for a satisfied branch', $result['created'] === 0, 'created=' . $result['created']);

    // ...and the day it was printed for still shows the gap, because nothing
    // was submitted on that date.
    $status = DailyCompliance::statusForDate($prevDay, $backdateNow);
    check('the back-dated day is still incomplete', $status['branches_missing'] >= 1, 'missing=' . $status['branches_missing']);

    // ------------------------------------------------------------------
    scenario('A bill uploaded tomorrow does not satisfy today');
    // ------------------------------------------------------------------
    $status = DailyCompliance::statusForDate($backdateDay, $backdateNow);
    $row = branchStatus($status, $backdated);
    check(
        'only the two back-dated bills are counted',
        (int) ($row['cash_count'] ?? 0) === 1 && (int) ($row['card_count'] ?? 0) === 1,
        'cash=' . ($row['cash_count'] ?? '?') . ' card=' . ($row['card_count'] ?? '?')
    );

    $tomorrowDay = '2026-03-13';
    $tomorrowBranch = testBranch('D');
    onlyBranchActive($tomorrowBranch);
    testBill($tomorrowBranch, 'cash', $backdateDay, $tomorrowDay . ' 08:00:00');
    $status = DailyCompliance::statusForDate($backdateDay, $backdateNow);
    $row = branchStatus($status, $tomorrowBranch);
    check('the branch is listed for the earlier day', $row !== null);
    check(
        'but its later upload is outside the day window',
        (int) ($row['cash_count'] ?? -1) === 0 && $row['missing'] === ['cash', 'card'],
        'cash=' . ($row['cash_count'] ?? '?') . ' missing=' . json_encode($row['missing'] ?? null)
    );
    check('so it counts as missing', $status['branches_missing'] === 1, 'missing=' . $status['branches_missing']);

    $statusTomorrow = DailyCompliance::statusForDate($tomorrowDay, $backdateNow);
    $row = branchStatus($statusTomorrow, $tomorrowBranch);
    check('and the same bill satisfies the day it landed on', $row !== null && (int) $row['cash_count'] === 1, json_encode($row));
    check('but card is still outstanding the next day', $row['missing'] === ['card'], json_encode($row['missing'] ?? null));

    // ------------------------------------------------------------------
    scenario('Only active branches are evaluated');
    // ------------------------------------------------------------------
    clearNotifications($ownerId);
    $inactiveDay = '2026-03-14';
    $active = testBranch('E-active');
    onlyBranchActive($active);
    $inactive = testBranch('E-inactive', 'inactive');
    $inactiveNow = strtotime($inactiveDay . ' 00:00:00') + 86399;
    $result = DailyCompliance::evaluateAndNotify($inactiveDay, false, $inactiveNow);
    check('one alert, for the active branch', $result['created'] === 1, 'created=' . $result['created']);
    check('and one branch is missing', $result['branches_missing'] === 1, 'missing=' . $result['branches_missing']);
    check('no alert targets the inactive branch', alertsForBranch($ownerId, $inactive, $inactiveDay) === []);
    check('the alert targets the active branch', count(alertsForBranch($ownerId, $active, $inactiveDay)) === 1);

    $status = DailyCompliance::statusForDate($inactiveDay, $inactiveNow);
    $codes = array_map(static fn(array $b): string => (string) $b['branch_code'], $status['branches']);
    check('the inactive branch is not even listed', count($codes) === 1, implode(',', $codes));

    // ------------------------------------------------------------------
    scenario('Turning a requirement off stops the alert');
    // ------------------------------------------------------------------
    clearNotifications($ownerId);
    $relaxedDay = '2026-03-15';
    $relaxed = testBranch('F');
    onlyBranchActive($relaxed);
    testBill($relaxed, 'cash', $relaxedDay, $relaxedDay . ' 09:00:00');

    setSetting('daily_card_required', '0');
    $relaxedNow = strtotime($relaxedDay . ' 00:00:00') + 86399;
    $result = DailyCompliance::evaluateAndNotify($relaxedDay, false, $relaxedNow);
    check('cash-only is enough when card is not required', $result['branches_missing'] === 0, 'missing=' . $result['branches_missing']);
    check('and no alert is raised', $result['created'] === 0, 'created=' . $result['created']);
    setSetting('daily_card_required', '1');

    // ------------------------------------------------------------------
    scenario('With nothing required, nobody is ever non-compliant');
    // ------------------------------------------------------------------
    clearNotifications($ownerId);
    setSetting('daily_cash_required', '0');
    setSetting('daily_card_required', '0');
    $noneDay = '2026-03-16';
    $noneNow = strtotime($noneDay . ' 00:00:00') + 86399;
    $result = DailyCompliance::evaluateAndNotify($noneDay, false, $noneNow);
    check('an empty requirement set raises nothing', $result['created'] === 0, 'created=' . $result['created']);
    check('and reports no missing branches', $result['branches_missing'] === 0, 'missing=' . $result['branches_missing']);
    setSetting('daily_cash_required', '1');
    setSetting('daily_card_required', '1');

    // ------------------------------------------------------------------
    scenario('Alerts can be switched off entirely');
    // ------------------------------------------------------------------
    clearNotifications($ownerId);
    setSetting(DailyCompliance::SETTING_ENABLED, '0');
    $offDay = '2026-03-17';
    $offNow = strtotime($offDay . ' 00:00:00') + 86399;
    check('isEnforcedOn is false when disabled', DailyCompliance::isEnforcedOn($offDay, $offNow) === false);
    $result = DailyCompliance::evaluateAndNotify($offDay, false, $offNow);
    check('the sweep is skipped', $result['skipped'] === true);
    check('nothing is written', $result['created'] === 0);
    setSetting(DailyCompliance::SETTING_ENABLED, '1');

    // ------------------------------------------------------------------
    scenario('The database clock agrees with the application clock');
    // ------------------------------------------------------------------
    // bills.uploaded_at defaults to CURRENT_TIMESTAMP, and every day boundary and
    // deadline here is built by PHP in APP_TIMEZONE. A database on UTC makes an
    // upload made at 01:00 local land on the previous day, so a branch that did
    // upload is reported as not having - and the alert for the day it really
    // belongs to is never raised. Database::connection() pins the session
    // timezone; this fails loudly if that pin ever stops working.
    $dbNow = (string) (Database::fetch('SELECT NOW() n')['n'] ?? '');
    check('the database clock is readable', $dbNow !== '', 'now=' . $dbNow);
    $skew = $dbNow === '' ? null : abs(time() - (int) strtotime($dbNow));
    check('the database clock is not UTC', date('P', (int) strtotime($dbNow)) === date('P'), 'php offset=' . date('P'));
    check('the database clock agrees with PHP', $skew !== null && $skew < 120, 'skew=' . var_export($skew, true) . 's db=' . $dbNow . ' php=' . date('Y-m-d H:i:s'));

    // A bill "uploaded" at 01:00 local must belong to today, not to yesterday.
    clearNotifications($ownerId);
    $tzBranch = testBranch('F');
    onlyBranchActive($tzBranch);
    $tzDay = date('Y-m-d');
    testBill($tzBranch, 'cash', $tzDay, $tzDay . ' 01:00:00');
    $tzStatus = DailyCompliance::statusForDate($tzDay);
    $tzRow = branchStatus($tzStatus, $tzBranch);
    check('an 01:00 upload counts for the same day', ($tzRow['cash_count'] ?? 0) === 1, 'cash_count=' . ($tzRow['cash_count'] ?? 'n/a'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $tzPrev = branchStatus(DailyCompliance::statusForDate($yesterday), $tzBranch);
    check('and not for the previous day', ($tzPrev['cash_count'] ?? 0) === 0, 'prev cash_count=' . ($tzPrev['cash_count'] ?? 'n/a'));

    // ------------------------------------------------------------------
    scenario('A day with nothing required is not the same as a passing day');
    // ------------------------------------------------------------------
    // With both boxes unticked every branch is trivially compliant, because there
    // is no gap to have. The maths is right but the words are not: the status
    // table said "Uploaded" beside branches with zero bills. The pages now ask
    // hasRequirement() and say "Not required" instead.
    clearNotifications($ownerId);
    $noneDay = '2026-03-20';
    $noneBranch = testBranch('G');
    onlyBranchActive($noneBranch);
    testBill($noneBranch, 'cash', $noneDay, $noneDay . ' 10:00:00');
    testBill($noneBranch, 'card', $noneDay, $noneDay . ' 10:05:00');

    setSetting('daily_cash_required', '0');
    setSetting('daily_card_required', '0');

    check('the day reports no requirement', DailyCompliance::hasRequirement() === false);
    $noneStatus = DailyCompliance::statusForDate($noneDay);
    check('nothing is missing', $noneStatus['branches_missing'] === 0, 'missing=' . $noneStatus['branches_missing']);
    $noneRow = branchStatus($noneStatus, $noneBranch);
    check('the branch is trivially compliant', ($noneRow['compliant'] ?? false) === true);
    check('but it has an empty required list', $noneStatus['required'] === [], json_encode($noneStatus['required']));

    // A branch that uploaded NOTHING must not be describable as compliant in a
    // way the pages could render as "Uploaded"; the flag the pages key off is
    // hasRequirement(), which is false here.
    $emptyBranch = testBranch('H');
    onlyBranchActive($emptyBranch);
    $emptyStatus = DailyCompliance::statusForDate($noneDay);
    $emptyRow = branchStatus($emptyStatus, $emptyBranch);
    check('a branch with no bills is also "compliant" when nothing is required', ($emptyRow['compliant'] ?? false) === true);
    check('which is exactly why the pages check hasRequirement() first', DailyCompliance::hasRequirement() === false);

    $noneResult = DailyCompliance::evaluateAndNotify($noneDay, true, strtotime($noneDay . ' 00:00:00') + 86399);
    check('and no alert is raised', $noneResult['created'] === 0, 'created=' . $noneResult['created']);

    setSetting('daily_cash_required', '1');
    setSetting('daily_card_required', '1');
    check('ticking a box switches the check back on', DailyCompliance::hasRequirement() === true);

    // ------------------------------------------------------------------
    scenario('The branch that missed it is told, not just the owner');
    // ------------------------------------------------------------------
    // The owner cannot upload the missing bill, so an alert that only ever
    // reached them left the person who can actually fix it in the dark.
    clearNotifications($ownerId);
    $brDay = '2026-03-21';
    $brA = testBranch('I');
    $brB = testBranch('J');
    onlyBranchActive($brA);

    $adminA = testAdmin($brA);
    $adminB = testAdmin($brB);

    $brNow = strtotime($brDay . ' 00:00:00') + 86399;
    // Force "today" for the branch fan-out rule: branch admins are only told
    // about the current day, so the date has to be today for this to apply.
    $todayDay = date('Y-m-d');
    $brNow = strtotime($todayDay . ' 00:00:00') + 86399;

    $brResult = DailyCompliance::evaluateAndNotify($todayDay, true, $brNow);
    check('the owner is alerted', $brResult['created'] >= 1, 'created=' . $brResult['created']);
    check('the missed branch admin is alerted too', Notification::unreadCount($adminA) === 1, 'adminA unread=' . Notification::unreadCount($adminA));
    check('an admin of a compliant branch is NOT alerted', Notification::unreadCount($adminB) === 0, 'adminB unread=' . Notification::unreadCount($adminB));

    $adminARows = Notification::latest($adminA, 10);
    check('the admin sees exactly their own alert', count($adminARows) === 1, 'rows=' . count($adminARows));
    check('and it is about their own branch', (int) $adminARows[0]['branch_id'] === $brA, 'branch=' . ($adminARows[0]['branch_id'] ?? 'n/a'));
    // latest() deliberately does not select dedupe_key - the bell has no use for
    // it - so the stored key is asserted where it lives. The order of the missing
    // types inside the key is the model's business (it sorts them so cash+card and
    // card+cash cannot become two events), so only the parts that matter here are
    // checked: the owner namespace, and that the branch and day are the admin's.
    $storedKey = (string) Database::fetch('SELECT dedupe_key FROM notifications WHERE id = ?', [(int) $adminARows[0]['id']])['dedupe_key'];
    $keyPrefix = 'u' . $adminA . ':daily_upload_missing:' . $brA . ':' . $todayDay . ':';
    check('the stored dedupe key is namespaced to them', str_starts_with($storedKey, $keyPrefix), $storedKey);
    check('and names both missing types', in_array($storedKey, [
        $keyPrefix . 'card+cash',
        $keyPrefix . 'cash+card',
    ], true), $storedKey);
    check('it is not the owner\'s key', !str_starts_with($storedKey, 'u' . $ownerId . ':'), $storedKey);

    // The badge must never be able to read or clear somebody else's alert.
    Notification::markRead((int) $adminARows[0]['id'], $ownerId);
    check('a forged owner id cannot read the admin alert', Notification::unreadCount($adminA) === 1, 'unread=' . Notification::unreadCount($adminA));
    Notification::markRead((int) $adminARows[0]['id'], $adminA);
    check('the admin can read their own', Notification::unreadCount($adminA) === 0);

    // Uploading the missing bill must clear the branch admin's copy too, not
    // just the owner's.
    $brUnread = (int) Database::fetch('SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND resolved_at IS NULL', [$adminA])['c'];
    check('the admin has an open alert before the upload', $brUnread === 1, 'open=' . $brUnread);
    testBill($brA, 'cash', $todayDay, $todayDay . ' 12:00:00');
    testBill($brA, 'card', $todayDay, $todayDay . ' 12:05:00');
    DailyCompliance::settleAfterUpload($brA, $todayDay);
    $brUnread = (int) Database::fetch('SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND resolved_at IS NULL', [$adminA])['c'];
    check('uploading clears the admin alert as well', $brUnread === 0, 'open=' . $brUnread);
    check('and the owner copy too', Notification::unreadCount($ownerId) === 0, 'owner unread=' . Notification::unreadCount($ownerId));

    // A past day must not bury a new admin in alerts they cannot act on.
    clearNotifications($ownerId);
    $pastDay = '2026-03-22';
    $pastResult = DailyCompliance::evaluateAndNotify($pastDay, true, strtotime($pastDay . ' 00:00:00') + 86399);
    check('a past day still alerts the owner', $pastResult['created'] >= 1, 'created=' . $pastResult['created']);
    check('a past day does NOT alert the branch admin', Notification::unreadCount($adminA) === 0, 'adminA unread=' . Notification::unreadCount($adminA));

    // ------------------------------------------------------------------
    scenario('The pending deadline is only reported when something is really due');
    // ------------------------------------------------------------------
    // This drives the countdown on the owner pages and the self-refresh timer in
    // the browser, so "null when nothing is coming" is what stops the UI from
    // waiting for a moment that will never arrive.
    $dueDay = '2026-03-18';
    $dueNoon = strtotime($dueDay . ' 12:00:00');
    $due = DailyCompliance::pendingDeadlineFor($dueDay, $dueNoon);
    check('a future deadline is reported', is_int($due) && $due === DailyCompliance::deadlineTimestamp($dueDay), 'due=' . var_export($due, true));

    $dueAfter = DailyCompliance::pendingDeadlineFor($dueDay, $dueNoon + 90000);
    check('nothing is pending once the deadline has passed', $dueAfter === null, 'due=' . var_export($dueAfter, true));

    setSetting(DailyCompliance::SETTING_ENABLED, '0');
    check('nothing is pending while the feature is off', DailyCompliance::pendingDeadlineFor($dueDay, $dueNoon) === null);
    setSetting(DailyCompliance::SETTING_ENABLED, '1');

    setSetting('daily_cash_required', '0');
    setSetting('daily_card_required', '0');
    check('nothing is pending when no bill type is required', DailyCompliance::pendingDeadlineFor($dueDay, $dueNoon) === null);
    setSetting('daily_cash_required', '1');
    setSetting('daily_card_required', '1');

    // The status array carries the same instant for the browser timer.
    $dueStatus = DailyCompliance::statusForDate($dueDay, $dueNoon);
    check('the status carries the deadline timestamp', ($dueStatus['deadline_ts'] ?? null) === DailyCompliance::deadlineTimestamp($dueDay), json_encode($dueStatus['deadline_ts'] ?? null));

    // ------------------------------------------------------------------
    scenario('A resolved alert comes back when the gap returns');
    // ------------------------------------------------------------------
    // uq_dedupe is unique on the stored key, so a resolved row used to block any
    // new alert for the same branch/day/gap for good. A branch that uploads,
    // then has the bill deleted, stayed silently non-compliant.
    clearNotifications($ownerId);
    $againDay = '2026-03-19';
    $again = testBranch('E');
    onlyBranchActive($again);

    $againNow = strtotime($againDay . ' 00:00:00') + 86399;
    testBill($again, 'cash', $againDay, $againDay . ' 08:00:00');
    DailyCompliance::evaluateAndNotify($againDay, true, $againNow);
    $open = openAlertsForBranch($ownerId, $again, $againDay);
    check('the card gap is alerted', count($open) === 1 && $open[0]['body_key'] === 'compliance.missing_card');

    // The card bill arrives, so the alert closes.
    testBill($again, 'card', $againDay, $againDay . ' 08:30:00');
    DailyCompliance::settleAfterUpload($again, $againDay);
    check('uploading closes it', count(openAlertsForBranch($ownerId, $again, $againDay)) === 0);

    // Now the card bill is deleted again: the day is short once more.
    Database::execute(
        "UPDATE bills SET status = 'deleted' WHERE branch_id = ? AND payment_type = 'card' AND status = 'active'",
        [$again]
    );
    $reRaised = DailyCompliance::evaluateAndNotify($againDay, true, $againNow);
    check('the alert is raised a second time', $reRaised['created'] === 1, 'created=' . $reRaised['created']);

    $open = openAlertsForBranch($ownerId, $again, $againDay);
    check('exactly one alert is open again', count($open) === 1, 'open=' . count($open));
    check('it names the card gap', ($open[0]['body_key'] ?? '') === 'compliance.missing_card', $open[0]['body_key'] ?? '');
    check('it is unread, so the badge counts it', $open[0]['read_at'] === null);
    check('the badge shows it', Notification::unreadCount($ownerId) >= 1, 'unread=' . Notification::unreadCount($ownerId));

    // A re-run while it is still open must not resurrect read state or duplicate.
    Notification::markRead((int) $open[0]['id'], $ownerId);
    $againRun = DailyCompliance::evaluateAndNotify($againDay, true, $againNow);
    check('re-running creates nothing while the alert is open', $againRun['created'] === 0, 'created=' . $againRun['created']);
    $open = openAlertsForBranch($ownerId, $again, $againDay);
    check('still exactly one open alert', count($open) === 1, 'open=' . count($open));
    check('and it stays read', $open[0]['read_at'] !== null);

    // ------------------------------------------------------------------
    scenario('The enforcement window is honoured');
    // ------------------------------------------------------------------
    clearNotifications($ownerId);
    setSetting(DailyCompliance::SETTING_START_DATE, '2026-04-01');
    check('a date before the start is not enforced', DailyCompliance::isEnforcedOn('2026-03-20', strtotime('2026-04-05') + 90000) === false);
    check('a date inside the window is enforced', DailyCompliance::isEnforcedOn('2026-04-05', strtotime('2026-04-05') + 90000) === true);
    setSetting(DailyCompliance::SETTING_END_DATE, '2026-03-31');
    check('a date after the end is not enforced', DailyCompliance::isEnforcedOn('2026-04-10', strtotime('2026-04-11') + 90000) === false);
    setSetting(DailyCompliance::SETTING_START_DATE, '');
    setSetting(DailyCompliance::SETTING_END_DATE, '');

    // ------------------------------------------------------------------
    scenario('A per-weekday deadline overrides the global one');
    // ------------------------------------------------------------------
    clearNotifications($ownerId);
    setSetting(DailyCompliance::SETTING_DEADLINE_MODE, 'per_weekday');
    setSetting(DailyCompliance::SETTING_WEEKDAYS, (string) json_encode(['0' => '18:00', '6' => '16:00']));

    $sunday = '2026-03-22'; // a Sunday
    check('the fixture really is a Sunday', date('w', strtotime($sunday)) === '0', date('w', strtotime($sunday)));
    check('Sunday uses its own 18:00 deadline', DailyCompliance::deadlineFor($sunday) === '18:00', DailyCompliance::deadlineFor($sunday));
    check('17:00 on Sunday is not yet enforced', DailyCompliance::isEnforcedOn($sunday, strtotime($sunday . ' 17:00:00')) === false);
    check('18:30 on Sunday is enforced', DailyCompliance::isEnforcedOn($sunday, strtotime($sunday . ' 18:30:00')) === true);

    $wednesday = '2026-03-25';
    check('a day with no override falls back to the global time', DailyCompliance::deadlineFor($wednesday) === '23:30', DailyCompliance::deadlineFor($wednesday));

    setSetting(DailyCompliance::SETTING_WEEKDAYS, (string) json_encode(['0' => 'not-a-time']));
    check('a malformed override is ignored, not obeyed', DailyCompliance::deadlineFor($sunday) === '23:30', DailyCompliance::deadlineFor($sunday));

    setSetting(DailyCompliance::SETTING_DEADLINE_MODE, 'time');
    setSetting(DailyCompliance::SETTING_WEEKDAYS, '');

    // ------------------------------------------------------------------
    scenario('A nonsense deadline is rejected rather than obeyed');
    // ------------------------------------------------------------------
    setSetting(DailyCompliance::SETTING_DEADLINE_TIME, '99:99');
    check('an invalid time falls back to the default', DailyCompliance::deadlineTime() === '23:30', DailyCompliance::deadlineTime());
    setSetting(DailyCompliance::SETTING_DEADLINE_TIME, '6:5');
    check('a loose but valid time is normalised', DailyCompliance::deadlineTime() === '06:05', DailyCompliance::deadlineTime());
    setSetting(DailyCompliance::SETTING_DEADLINE_TIME, '23:30');

    // ------------------------------------------------------------------
    scenario('The same logical alert is unique per owner');
    // ------------------------------------------------------------------
    Database::execute(
        "INSERT INTO users (username, name, email, password_hash, role, status)
         VALUES (?, 'Second Owner', ?, 'x', 'owner', 'active')",
        [
            'compliance_owner2_' . bin2hex(random_bytes(4)),
            'compliance-owner2-' . bin2hex(random_bytes(4)) . '@test.invalid',
        ]
    );
    $secondOwnerId = Database::lastId();
    $sharedDay = '2026-03-28';
    $shared = testBranch('G');
    onlyBranchActive($shared);

    // The stored key is the bare event key with the owner id prepended, and
    // create() does that prefixing itself, so the fixture row has to carry it
    // while the createForOwners() call must not.
    $base  = 'shared:probe';
    $mineKey = Notification::ownerDedupeKey($ownerId, $base);
    $theirKey = Notification::ownerDedupeKey($secondOwnerId, $base);
    check('the two owners get different stored keys', $mineKey !== $theirKey, $mineKey . ' vs ' . $theirKey);

    Database::execute(
        "INSERT IGNORE INTO notifications
            (user_id, type, severity, title_key, body_key, branch_id, bill_date, dedupe_key)
         VALUES (?, 'daily_upload_missing', 'danger', 'compliance.alert_title',
                 'compliance.missing_both', ?, ?, ?)",
        [$ownerId, $shared, $sharedDay, $mineKey]
    );
    $created = Notification::createForOwners(
        Notification::TYPE_DAILY_UPLOAD_MISSING,
        'danger',
        'compliance.alert_title',
        'compliance.missing_both',
        ['branch' => 'x'],
        $shared,
        $sharedDay,
        $base
    );
    check('only the owner without the alert is notified', $created === 1, 'created=' . $created . ' (expected 1)');

    $mine  = (int) Database::fetch('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND dedupe_key = ?', [$ownerId, $mineKey])['c'];
    $their = (int) Database::fetch('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND dedupe_key = ?', [$secondOwnerId, $theirKey])['c'];
    check('the first owner has exactly one row', $mine === 1, 'rows=' . $mine);
    check('the second owner has exactly one row', $their === 1, 'rows=' . $their);
    check('both owners were reached', $mine === 1 && $their === 1);

    // ------------------------------------------------------------------
    scenario('Reading and clearing the badge is owner-scoped');
    // ------------------------------------------------------------------
    $openId = (int) Database::fetch(
        "SELECT id FROM notifications WHERE user_id = ? AND resolved_at IS NULL LIMIT 1",
        [$secondOwnerId]
    )['id'];
    Notification::markRead($openId, $ownerId);
    check(
        'a forged owner id cannot mark another owner\'s alert read',
        Database::fetch('SELECT read_at FROM notifications WHERE id = ?', [$openId])['read_at'] === null
    );
    Notification::markRead($openId, $secondOwnerId);
    check('the real owner can', Database::fetch('SELECT read_at FROM notifications WHERE id = ?', [$openId])['read_at'] !== null);
    check('markAllRead leaves no unread', Notification::unreadCount($secondOwnerId) === 0, 'unread=' . Notification::unreadCount($secondOwnerId));

    // ------------------------------------------------------------------
    scenario('The CLI sweep reports the same answer as the model');
    // ------------------------------------------------------------------
    $status = DailyCompliance::statusForDate($day, $afterDeadline);
    $nonCompliant = array_values(array_filter($status['branches'], static fn(array $b): bool => $b['missing'] !== []));
    check('the day used earlier still has a non-compliant branch', $nonCompliant !== []);
    check('the banner copy gets the branch count', tn('compliance.banner_title', count($nonCompliant), [
        'n' => count($nonCompliant),
        'date' => '2026-03-10',
        'deadline' => $status['deadline'],
    ]) !== '', 'empty plural string');

    // ------------------------------------------------------------------
    Database::connection()->rollBack();
} catch (Throwable $e) {
    Database::connection()->rollBack();
    echo "\n  ERROR: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo '  at ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $failed[] = 'exception: ' . $e->getMessage();
}

// ------------------------------------------------------------------
// Result
// ------------------------------------------------------------------

echo "\n" . str_repeat('=', 66) . "\n";

if ($failed) {
    echo "FAILED: " . count($failed) . " of " . ($passed + count($failed)) . " checks\n";
    foreach ($failed as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "OK: {$passed} checks passed, transaction rolled back\n";
exit(0);
