<?php

declare(strict_types=1);

/**
 * Branch model.
 */
class Branch
{
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO branches (branch_code, branch_name, address, phone, email, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['branch_code'],
                $data['branch_name'],
                $data['address']  ?? null,
                $data['phone']    ?? null,
                $data['email']    ?? null,
                $data['status']   ?? 'active',
            ]
        );
        return Database::lastId();
    }

    public static function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE branches SET branch_code = ?, branch_name = ?, address = ?, phone = ?, email = ?, status = ?
             WHERE id = ?',
            [
                $data['branch_code'],
                $data['branch_name'],
                $data['address']  ?? null,
                $data['phone']    ?? null,
                $data['email']    ?? null,
                $data['status']   ?? 'active',
                $id,
            ]
        );
    }

    public static function find(int|string $id): ?array
    {
        return Database::fetch('SELECT * FROM branches WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function findByCode(string $code): ?array
    {
        return Database::fetch('SELECT * FROM branches WHERE branch_code = ? AND deleted_at IS NULL', [$code]);
    }

    public static function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM branches WHERE deleted_at IS NULL';
        $params = [];
        if ($onlyActive) {
            $sql .= ' AND status = ?';
            $params[] = 'active';
        }
        $sql .= ' ORDER BY branch_name ASC';
        return Database::fetchAll($sql, $params);
    }

    public static function count(): int
    {
        return (int) Database::fetch(
            'SELECT COUNT(*) AS c FROM branches WHERE deleted_at IS NULL'
        )['c'];
    }

    public static function countActive(): int
    {
        return (int) Database::fetch(
            'SELECT COUNT(*) AS c FROM branches WHERE deleted_at IS NULL AND status = ?',
            ['active']
        )['c'];
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::execute('UPDATE branches SET status = ? WHERE id = ?', [$status, $id]);
    }

    public static function softDelete(int $id): void
    {
        Database::execute(
            'UPDATE branches SET deleted_at = NOW(), status = ? WHERE id = ?',
            ['inactive', $id]
        );
    }

    /**
     * Branch list with admin + upload summary (Owner branch table).
     */
    public static function listWithSummary(): array
    {
        return Database::fetchAll(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.deleted_at IS NULL) AS admin_count,
                    (SELECT COUNT(*) FROM bills bl WHERE bl.branch_id = b.id AND bl.status = 'active') AS total_bills,
                    (SELECT COUNT(*) FROM bills bl2 WHERE bl2.branch_id = b.id AND bl2.status = 'active' AND DATE(bl2.uploaded_at) = CURDATE()) AS today_uploads,
                    (SELECT MAX(bl3.uploaded_at) FROM bills bl3 WHERE bl3.branch_id = b.id AND bl3.status = 'active') AS last_upload
             FROM branches b
             WHERE b.deleted_at IS NULL
             ORDER BY b.branch_name ASC"
        );
    }

    /**
     * Dashboard metrics for the owner dashboard cards.
     */
    public static function dashboardStats(): array
    {
        return Database::fetch(
            "SELECT
                (SELECT COUNT(*) FROM branches WHERE deleted_at IS NULL) AS total_branches,
                (SELECT COUNT(*) FROM branches WHERE deleted_at IS NULL AND status = 'active') AS active_branches,
                (SELECT COUNT(*) FROM branches WHERE deleted_at IS NULL AND status = 'inactive') AS inactive_branches,
                (SELECT COUNT(*) FROM users WHERE role = 'branch_admin' AND deleted_at IS NULL) AS total_admins,
                (SELECT COUNT(*) FROM users WHERE role = 'branch_admin' AND deleted_at IS NULL AND status = 'active') AS active_admins,
                (SELECT COUNT(*) FROM bills WHERE status = 'active') AS total_bills,
                (SELECT COUNT(*) FROM bills WHERE status = 'active' AND DATE(uploaded_at) = CURDATE()) AS today_bills,
                (SELECT COUNT(*) FROM bills WHERE status = 'active' AND DATE(uploaded_at) = CURDATE() AND payment_type = 'cash') AS today_cash,
                (SELECT COUNT(*) FROM bills WHERE status = 'active' AND DATE(uploaded_at) = CURDATE() AND payment_type = 'card') AS today_card"
        );
    }

    /**
     * Today's upload completion status per branch.
     */
    public static function dailyUploadStatus(string $date): array
    {
        return Database::fetchAll(
            "SELECT b.id, b.branch_code, b.branch_name, b.status,
                    (SELECT COUNT(*) FROM bills bl WHERE bl.branch_id = b.id AND bl.status = 'active' AND bl.business_date = ? AND bl.payment_type = 'cash') AS cash_count,
                    (SELECT COUNT(*) FROM bills bl WHERE bl.branch_id = b.id AND bl.status = 'active' AND bl.business_date = ? AND bl.payment_type = 'card') AS card_count,
                    (SELECT MAX(bl.uploaded_at) FROM bills bl WHERE bl.branch_id = b.id AND bl.status = 'active' AND bl.business_date = ?) AS last_upload
             FROM branches b
             WHERE b.deleted_at IS NULL
             ORDER BY b.branch_name ASC",
            [$date, $date, $date]
        );
    }
}