<?php

declare(strict_types=1);

/**
 * Bill model.
 */
class Bill
{
    /**
     * Every bill column EXCEPT the pdf_bytes blob.
     *
     * List and detail queries must never SELECT the LONGBLOB: it would pull
     * every PDF into PHP memory for a page that only shows metadata.
     */
    public const LIST_COLUMNS = 'b.id, b.branch_id, b.uploaded_by, b.payment_type, b.business_date,
                                b.original_filename, b.stored_filename, b.file_path, b.archived_path,
                                b.mime_type, b.storage_status, b.file_size, b.description, b.pdf_hash,
                                b.status, b.uploaded_at, b.updated_at, b.deleted_at, b.purge_after';

    /** Same column set without the b. alias, for single-row lookups. */
    public const ROW_COLUMNS = 'id, branch_id, uploaded_by, payment_type, business_date,
                                original_filename, stored_filename, file_path, archived_path,
                                mime_type, storage_status, file_size, description, pdf_hash,
                                status, uploaded_at, updated_at, deleted_at, purge_after';

    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO bills (branch_id, uploaded_by, payment_type, business_date,
                                original_filename, stored_filename, file_path, mime_type, file_size, description,
                                pdf_bytes, pdf_hash, storage_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['branch_id'],
                $data['uploaded_by'],
                $data['payment_type'],
                $data['business_date'],
                $data['original_filename'],
                $data['stored_filename'],
                $data['file_path'],
                $data['mime_type'],
                $data['file_size'],
                $data['description'] ?? null,
                $data['pdf_bytes'] ?? null,
                $data['pdf_hash'] ?? null,
                $data['storage_status'] ?? 'file_only',
            ],
            [10] // pdf_bytes -> bind as a LOB
        );
        return Database::lastId();
    }

    public static function find(int|string $id): ?array
    {
        return Database::fetch('SELECT ' . self::ROW_COLUMNS . ' FROM bills WHERE id = ?', [$id]);
    }

    public static function findActive(int|string $id): ?array
    {
        return Database::fetch(
            "SELECT " . self::ROW_COLUMNS . " FROM bills WHERE id = ? AND status = 'active'",
            [$id]
        );
    }

    /**
     * Fetch a single bill including the database PDF copy.
     * Only used by the storage gateway when the file copy is unavailable.
     */
    public static function findWithBlob(int|string $id): ?array
    {
        return Database::fetch('SELECT * FROM bills WHERE id = ?', [$id]);
    }

    /**
     * Owner bill list with filters + pagination.
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $where  = ["b.status = 'active'"];
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where[] = 'b.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['payment_type'])) {
            $where[] = 'b.payment_type = ?';
            $params[] = $filters['payment_type'];
        }
        if (!empty($filters['business_date'])) {
            $where[] = 'b.business_date = ?';
            $params[] = $filters['business_date'];
        }
        if (!empty($filters['uploaded_at'])) {
            $where[] = 'DATE(b.uploaded_at) = ?';
            $params[] = $filters['uploaded_at'];
        }
        if (!empty($filters['uploaded_by'])) {
            $where[] = 'b.uploaded_by = ?';
            $params[] = (int) $filters['uploaded_by'];
        }
        if (!empty($filters['q'])) {
            $where[] = 'b.original_filename LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $count = (int) Database::fetch(
            "SELECT COUNT(*) AS c FROM bills b WHERE {$whereSql}",
            $params
        )['c'];

        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            "SELECT " . self::LIST_COLUMNS . ", br.branch_code, br.branch_name, u.name AS uploaded_by_name
             FROM bills b
             INNER JOIN branches br ON br.id = b.branch_id
             INNER JOIN users u ON u.id = b.uploaded_by
             WHERE {$whereSql}
             ORDER BY b.uploaded_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $pages = max(1, (int) ceil($count / $perPage));

        return [
            'rows'  => $rows,
            'count' => $count,
            'page'  => $page,
            'pages' => $pages,
            'per'   => $perPage,
        ];
    }

    /**
     * Branch-admin bill list restricted to their branch.
     */
    public static function forBranch(
        int|string $branchId,
        array $filters = [],
        int $page = 1,
        int $perPage = 15
    ): array {
        $filters['branch_id'] = $branchId;
        return self::search($filters, $page, $perPage);
    }

    /**
     * Number of days a deleted bill can be restored, from settings.
     */
    public static function retentionDays(): int
    {
        $days = (int) (Setting::get('deleted_bill_retention_days', '30') ?: 30);
        return max(1, min(365, $days));
    }

    /**
     * Reversible delete: the file is moved into storage/archive and the
     * database copy is kept, so the bill can be restored until purge_after.
     * The row is never physically removed here.
     */
    public static function softDelete(int|string $id): void
    {
        $bill = self::find($id);
        if (!$bill) {
            return;
        }

        $archivedPath = BillStorage::archiveFile($bill);

        Database::execute(
            'UPDATE bills
             SET status = ?, deleted_at = NOW(), archived_path = ?,
                 purge_after = DATE_ADD(NOW(), INTERVAL ? DAY)
             WHERE id = ?',
            ['deleted', $archivedPath, self::retentionDays(), $id]
        );
    }

    /**
     * Restore a soft-deleted bill. Moves the archived file back when present;
     * if it is missing the bill is still restorable from the database copy.
     *
     * @return bool
     */
    public static function restore(int|string $id): bool
    {
        $bill = self::find($id);
        if (!$bill || $bill['status'] !== 'deleted') {
            return false;
        }
        // Nothing left to restore: both copies were already erased.
        if ($bill['storage_status'] === 'none') {
            return false;
        }

        $fileRestored = BillStorage::restoreFile($bill);
        $hasDb = self::hasPdfBlob($id);

        Database::execute(
            'UPDATE bills
             SET status = ?, deleted_at = NULL, purge_after = NULL, archived_path = NULL,
                 storage_status = ?
             WHERE id = ?',
            ['active', BillStorage::statusFor($fileRestored, $hasDb), $id]
        );

        return true;
    }

    /**
     * Permanently erase a bill's stored copies (files + database blob) but
     * keep the row as a record for the audit trail.
     */
    public static function purge(int|string $id): void
    {
        $bill = self::find($id);
        if ($bill) {
            BillStorage::deleteFile($bill);
        }

        Database::execute(
            'UPDATE bills
             SET pdf_bytes = NULL, storage_status = ?, archived_path = NULL, purge_after = NULL
             WHERE id = ?',
            ['none', $id]
        );
    }

    /**
     * Purge every deleted bill whose retention window has expired.
     * Returns the number of bills purged.
     */
    public static function purgeExpired(): int
    {
        $rows = Database::fetchAll(
            "SELECT " . self::ROW_COLUMNS . " FROM bills
             WHERE status = 'deleted' AND purge_after IS NOT NULL AND purge_after <= NOW()"
        );

        foreach ($rows as $row) {
            self::purge($row['id']);
        }

        return count($rows);
    }

    /**
     * True when the bill still has a database copy.
     */
    public static function hasPdfBlob(int|string $id): bool
    {
        $row = Database::fetch('SELECT pdf_bytes IS NOT NULL AS has FROM bills WHERE id = ?', [$id]);
        return $row !== null && (int) $row['has'] === 1;
    }

    /**
     * Recently Deleted list (owner) with filters + pagination.
     *
     * Bills whose stored copies were already erased are excluded: there is
     * nothing left to restore, so they belong to the audit log, not here.
     */
    public static function deleted(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ["b.status = 'deleted'", "b.storage_status <> 'none'"];
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where[] = 'b.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['payment_type'])) {
            $where[] = 'b.payment_type = ?';
            $params[] = $filters['payment_type'];
        }
        if (!empty($filters['q'])) {
            $where[] = 'b.original_filename LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $count = (int) Database::fetch(
            "SELECT COUNT(*) AS c FROM bills b WHERE {$whereSql}",
            $params
        )['c'];

        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            "SELECT " . self::LIST_COLUMNS . ", br.branch_code, br.branch_name, u.name AS uploaded_by_name
             FROM bills b
             INNER JOIN branches br ON br.id = b.branch_id
             INNER JOIN users u ON u.id = b.uploaded_by
             WHERE {$whereSql}
             ORDER BY b.deleted_at DESC
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

    public static function countActive(): int
    {
        return (int) Database::fetch(
            "SELECT COUNT(*) AS c FROM bills WHERE status = 'active'"
        )['c'];
    }

    public static function countActiveByBranch(int|string $branchId, string $type): int
    {
        return (int) Database::fetch(
            "SELECT COUNT(*) AS c FROM bills WHERE branch_id = ? AND payment_type = ? AND status = 'active'",
            [$branchId, $type]
        )['c'];
    }

    public static function latestByBranch(int|string $branchId): ?array
    {
        return Database::fetch(
            "SELECT " . self::ROW_COLUMNS . " FROM bills WHERE branch_id = ? AND status = 'active' ORDER BY uploaded_at DESC LIMIT 1",
            [$branchId]
        );
    }

    /**
     * Recent uploads (Upload Activity page, Owner).
     */
    public static function recent(array $filters = [], int $limit = 20): array
    {
        $where  = ["b.status = 'active'"];
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where[] = 'b.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['payment_type'])) {
            $where[] = 'b.payment_type = ?';
            $params[] = $filters['payment_type'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'DATE(b.uploaded_at) >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'DATE(b.uploaded_at) <= ?';
            $params[] = $filters['to'];
        }

        $whereSql = implode(' AND ', $where);

        return Database::fetchAll(
            "SELECT " . self::LIST_COLUMNS . ", br.branch_code, br.branch_name, u.name AS uploaded_by_name
             FROM bills b
             INNER JOIN branches br ON br.id = b.branch_id
             INNER JOIN users u ON u.id = b.uploaded_by
             WHERE {$whereSql}
             ORDER BY b.uploaded_at DESC
             LIMIT " . (int) $limit,
            $params
        );
    }

    /**
     * Upload counts per day for charts.
     */
    public static function countsByDay(string $from, string $to): array
    {
        return Database::fetchAll(
            "SELECT DATE(uploaded_at) AS day,
                    SUM(payment_type = 'cash') AS cash,
                    SUM(payment_type = 'card') AS card,
                    COUNT(*) AS total
             FROM bills
             WHERE status = 'active' AND DATE(uploaded_at) BETWEEN ? AND ?
             GROUP BY DATE(uploaded_at)
             ORDER BY day ASC",
            [$from, $to]
        );
    }

    public static function uploadsByBranch(int $limit = 10): array
    {
        return Database::fetchAll(
            "SELECT br.id, br.branch_code, br.branch_name,
                    COUNT(b.id) AS total,
                    SUM(b.payment_type = 'cash') AS cash,
                    SUM(b.payment_type = 'card') AS card
             FROM branches br
             LEFT JOIN bills b ON b.branch_id = br.id AND b.status = 'active'
             WHERE br.deleted_at IS NULL
             GROUP BY br.id, br.branch_code, br.branch_name
             ORDER BY total DESC
             LIMIT " . (int) $limit
        );
    }
}