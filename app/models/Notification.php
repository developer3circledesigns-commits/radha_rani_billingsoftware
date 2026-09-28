<?php

declare(strict_types=1);

/**
 * Owner-facing notifications.
 *
 * WHY NO RENDERED TEXT IS STORED
 * A row keeps title_key / body_key (catalogue keys) and body_params (JSON), and
 * the string is produced by Lang::t() at render time. That way an alert written
 * while the portal was in English still reads correctly to a German owner, and
 * an owner who switches language sees the new language rather than a frozen
 * translation from whenever the alert was generated.
 *
 * read_at versus resolved_at
 * read_at      - the owner has looked at it.
 * resolved_at  - the condition is no longer true, i.e. the missing bill arrived
 *                and public/api/bills/upload.php closed the alert. Without this
 *                an alert could never go away, and a badge that can only be
 *                cleared by the reader is a badge people learn to ignore.
 *
 * EVERY QUERY IS SCOPED BY user_id
 * The session decides the owner; the caller never passes a user id to read or
 * write on the browser's behalf. Only createForOwners() fans out, and that runs
 * from the server side, not from a request parameter.
 */
class Notification
{
    public const TYPE_DAILY_UPLOAD_MISSING = 'daily_upload_missing';

    /**
     * Is the notification feature installed on this database?
     *
     * The tables arrive with migration 003. A host that received new code before
     * running the migration has no such table, and every read here would throw -
     * which, because the bell sits in the layout, would turn a missing migration
     * into a 500 on every single page. So the check is asked first, and the UI
     * simply has no bell instead of failing.
     */
    public static function isAvailable(): bool
    {
        return Database::tableExists('notifications');
    }

    /** Latest alerts for the bell dropdown, newest first. */
    public static function latest(int $userId, int $limit = 8, bool $onlyUnread = false): array
    {
        $limit = max(1, min(50, $limit));

        $sql = "SELECT n.id, n.type, n.severity, n.title_key, n.body_key, n.body_params,
                       n.branch_id, n.bill_date, n.read_at, n.resolved_at, n.created_at,
                       b.branch_name, b.branch_code
                FROM notifications n
                LEFT JOIN branches b ON b.id = n.branch_id
                WHERE n.user_id = ?";
        $params = [$userId];

        if ($onlyUnread) {
            $sql .= ' AND n.read_at IS NULL AND n.resolved_at IS NULL';
        }

        // Resolved alerts sink below open ones rather than disappearing, so the
        // owner can see that yesterday's problem was dealt with.
        $sql .= " ORDER BY (n.resolved_at IS NULL) DESC, n.created_at DESC LIMIT {$limit}";

        return Database::fetchAll($sql, $params);
    }

    /** Count for the badge: seen-by-nobody and not already fixed. */
    public static function unreadCount(int $userId): int
    {
        return (int) Database::fetch(
            'SELECT COUNT(*) AS c FROM notifications
             WHERE user_id = ? AND read_at IS NULL AND resolved_at IS NULL',
            [$userId]
        )['c'];
    }

    /** Owner-scoped on purpose: a forged id must not mark someone else's alert. */
    public static function markRead(int $id, int $userId): void
    {
        Database::execute(
            'UPDATE notifications SET read_at = NOW()
             WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [$id, $userId]
        );
    }

    public static function markAllRead(int $userId): int
    {
        return Database::execute(
            'UPDATE notifications SET read_at = NOW()
             WHERE user_id = ? AND read_at IS NULL AND resolved_at IS NULL',
            [$userId]
        );
    }

    /**
     * Create one alert, or return the existing id if it is already there.
     *
     * uq_dedupe is a UNIQUE index on the stored key, so a logical event can only
     * ever have one row. That makes a resolved row a trap: a branch that uploads
     * its bills closes the alert, and if the bill is later deleted - or the
     * requirement is widened - the condition is true again but the key still
     * exists, so a plain INSERT IGNORE would drop the alert silently and the
     * owner would never hear about a day that is genuinely short.
     *
     * So this is an upsert that REOPENS a resolved alert: a condition that has
     * become true again deserves a fresh, unread alert, and the history of the
     * previous one is kept by created_at. An alert that is still open is left
     * exactly as it is, so re-running a sweep cannot reset read_at and make the
     * badge flicker.
     *
     * The assignments are ordered deliberately: MySQL evaluates them left to
     * right and later ones see earlier results, so created_at has to be set
     * BEFORE resolved_at is cleared, or its IF() would always read NULL.
     *
     * $dedupeBase is the *logical* event key - "which branch, which day, which
     * gap" - not the key as stored. The owner id is prepended here, in the one
     * place that knows about it. Callers must therefore pass the bare key;
     * passing an already-namespaced one would prefix it twice and defeat the
     * guard, which is why this is not done in createForOwners() instead.
     *
     * @param array<string,scalar|null> $params
     */
    public static function create(int $userId, string $type, string $severity, string $titleKey, string $bodyKey, array $params, ?int $branchId, ?string $billDate, string $dedupeBase): int
    {
        $severity = in_array($severity, ['info', 'warning', 'danger'], true) ? $severity : 'warning';

        $dedupeKey = self::ownerDedupeKey($userId, $dedupeBase);

        Database::execute(
            'INSERT INTO notifications
                (user_id, type, severity, title_key, body_key, body_params, branch_id, bill_date, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                severity     = IF(resolved_at IS NULL, severity, VALUES(severity)),
                title_key    = IF(resolved_at IS NULL, title_key, VALUES(title_key)),
                body_key     = IF(resolved_at IS NULL, body_key, VALUES(body_key)),
                body_params  = IF(resolved_at IS NULL, body_params, VALUES(body_params)),
                branch_id    = IF(resolved_at IS NULL, branch_id, VALUES(branch_id)),
                bill_date    = IF(resolved_at IS NULL, bill_date, VALUES(bill_date)),
                read_at      = IF(resolved_at IS NULL, read_at, NULL),
                created_at   = IF(resolved_at IS NULL, created_at, NOW()),
                resolved_at  = NULL',
            [
                $userId,
                $type,
                $severity,
                $titleKey,
                $bodyKey,
                $params === [] ? null : (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $branchId,
                $billDate,
                $dedupeKey,
            ]
        );

        $row = Database::fetch('SELECT id FROM notifications WHERE dedupe_key = ?', [$dedupeKey]);
        return (int) ($row['id'] ?? 0);
    }

    /** Namespaced stored key, so the same logical event is unique per owner. */
    public static function ownerDedupeKey(int $userId, string $base): string
    {
        return 'u' . $userId . ':' . $base;
    }

    /**
     * Fan an alert out to every active owner.
     *
     * There is normally exactly one owner (the portal is seeded with a single
     * account), but the schema allows more and the query does not assume
     * otherwise, so adding a second owner later needs no change here.
     *
     * $dedupeBase is passed through to create() unprefixed - see there.
     *
     * @param array<string,scalar|null> $params
     * @return int Number of rows actually created (0 when all were duplicates).
     */
    public static function createForOwners(string $type, string $severity, string $titleKey, string $bodyKey, array $params, ?int $branchId, ?string $billDate, string $dedupeBase): int
    {
        $owners = Database::fetchAll(
            "SELECT id FROM users WHERE role = 'owner' AND status = 'active' AND deleted_at IS NULL"
        );

        $created = 0;
        foreach ($owners as $owner) {
            $userId = (int) $owner['id'];
            // Only an alert that is STILL OPEN blocks a new one. A resolved row
            // for the same event means the condition was fixed and has come
            // back, so it has to be reopened rather than quietly ignored - the
            // owner has to hear about it a second time.
            //
            // Asking the database rather than inferring it from the unread count:
            // unreadCount() only moves when the new row is unread, so a
            // previously-read duplicate would be miscounted.
            $existing = Database::fetch(
                'SELECT id, resolved_at FROM notifications WHERE dedupe_key = ?',
                [self::ownerDedupeKey($userId, $dedupeBase)]
            );
            if (!empty($existing['id']) && $existing['resolved_at'] === null) {
                continue;
            }
            self::create($userId, $type, $severity, $titleKey, $bodyKey, $params, $branchId, $billDate, $dedupeBase);
            $created++;
        }
        return $created;
    }

    /**
     * Fan an alert out to the admins of one branch.
     *
     * A branch that missed an upload has to be told, not just the owner: the
     * owner cannot upload the missing bill, and an admin who never hears about
     * it goes on being told off by a badge they cannot see the cause of.
     *
     * Scoped by branch_id on the USER, so an admin can only ever be handed an
     * alert about their own branch. Combined with the per-user dedupe key there
     * is no path by which one branch's admin can receive another branch's alert,
     * which is why no caller ever passes a user id here.
     *
     * @param array<string,scalar|null> $params
     * @return int Rows created. 0 is normal, not an error: every alert may
     *         already exist, and a branch can legitimately have no active admin.
     */
    public static function createForBranch(int $branchId, string $type, string $severity, string $titleKey, string $bodyKey, array $params, ?string $billDate, string $dedupeBase): int
    {
        $admins = Database::fetchAll(
            "SELECT id FROM users
              WHERE branch_id = ?
                AND role = 'branch_admin'
                AND status = 'active'
                AND deleted_at IS NULL",
            [$branchId]
        );

        $created = 0;
        foreach ($admins as $admin) {
            $userId = (int) $admin['id'];
            $exists = Database::fetch(
                'SELECT id, resolved_at FROM notifications WHERE dedupe_key = ?',
                [self::ownerDedupeKey($userId, $dedupeBase)]
            );
            if (!empty($exists['id']) && $exists['resolved_at'] === null) {
                continue;
            }
            self::create($userId, $type, $severity, $titleKey, $bodyKey, $params, $branchId, $billDate, $dedupeBase);
            $created++;
        }

        return $created;
    }

    /**
     * Close any open alert for a branch and day.
     *
     * Called from the upload endpoint once a bill lands. Marks the row resolved
     * rather than deleting it: the owner needs to see that the gap was closed,
     * and an operator investigating a slow branch needs the history.
     */
    public static function resolveForBranchDate(int $branchId, string $billDate): int
    {
        return Database::execute(
            'UPDATE notifications SET resolved_at = NOW()
             WHERE branch_id = ? AND bill_date = ? AND resolved_at IS NULL',
            [$branchId, $billDate]
        );
    }

    /**
     * Is this logical event still open for at least one active owner?
     *
     * createForOwners() treats a row as "already there" whether it is open or
     * resolved, because the unique index is on the stored key and a resolved
     * alert must not be duplicated. That is right for a sweep, which should never
     * re-alert on a day it has already reported, but it means a caller that wants
     * to re-open an event after a partial fix has to ask first - otherwise the
     * re-raise would be swallowed and the owner would be left with no alert at
     * all for a day that is still incomplete.
     *
     * @param string $dedupeBase Logical key, unprefixed, as passed to create().
     */
    public static function hasOpenForOwners(string $dedupeBase): bool
    {
        $row = Database::fetch(
            "SELECT n.id
               FROM notifications n
               JOIN users u ON u.id = n.user_id
              WHERE n.dedupe_key = CONCAT('u', u.id, ':', ?)
                AND n.resolved_at IS NULL
                AND u.role = 'owner'
                AND u.status = 'active'
                AND u.deleted_at IS NULL
              LIMIT 1",
            [$dedupeBase]
        );
        return !empty($row['id']);
    }

    /** Paginated feed for the compliance report page. */
    public static function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['n.user_id = ?'];
        $params = [(int) ($filters['user_id'] ?? 0)];

        if (!empty($filters['branch_id'])) {
            $where[]  = 'n.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['from'])) {
            $where[]  = 'n.bill_date >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'n.bill_date <= ?';
            $params[] = (string) $filters['to'];
        }
        if (($filters['state'] ?? '') === 'open') {
            $where[] = 'n.resolved_at IS NULL';
        } elseif (($filters['state'] ?? '') === 'resolved') {
            $where[] = 'n.resolved_at IS NOT NULL';
        }

        $whereSql = implode(' AND ', $where);

        $count = (int) Database::fetch(
            "SELECT COUNT(*) AS c FROM notifications n WHERE {$whereSql}",
            $params
        )['c'];

        $offset  = ($page - 1) * $perPage;
        $perPage = max(1, min(100, $perPage));

        $rows = Database::fetchAll(
            "SELECT n.*, b.branch_name, b.branch_code
             FROM notifications n
             LEFT JOIN branches b ON b.id = n.branch_id
             WHERE {$whereSql}
             ORDER BY n.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'rows'  => $rows,
            'count' => $count,
            'page'  => $page,
            'pages' => max(1, (int) ceil($count / $perPage)),
            'per'   => $perPage,
        ];
    }

    /** Decode the stored JSON placeholder bag for one row. */
    public static function params(array $row): array
    {
        if (empty($row['body_params'])) {
            return [];
        }
        $decoded = json_decode((string) $row['body_params'], true);
        return is_array($decoded) ? $decoded : [];
    }
}
