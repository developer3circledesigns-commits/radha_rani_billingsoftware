<?php

declare(strict_types=1);

/**
 * User model.
 */
class User
{
    public static function findByLogin(string $login): ?array
    {
        return Database::fetch(
            'SELECT u.*, b.status AS branch_status, b.branch_code, b.branch_name
             FROM users u
             LEFT JOIN branches b ON b.id = u.branch_id
             WHERE (LOWER(u.email) = LOWER(?) OR LOWER(u.username) = LOWER(?))
               AND u.deleted_at IS NULL',
            [$login, $login]
        );
    }

    public static function find(int|string $id): ?array
    {
        return Database::fetch('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::fetch('SELECT * FROM users WHERE LOWER(email) = LOWER(?)', [$email]);
    }

    public static function findByUsername(string $username): ?array
    {
        return Database::fetch('SELECT * FROM users WHERE LOWER(username) = LOWER(?)', [$username]);
    }

    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO users (branch_id, name, email, username, password_hash, role, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['branch_id']    ?? null,
                $data['name'],
                $data['email'],
                $data['username'],
                $data['password_hash'],
                $data['role'],
                $data['status'] ?? 'active',
            ]
        );
        return Database::lastId();
    }

    public static function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE users SET branch_id = ?, name = ?, email = ?, username = ?, status = ? WHERE id = ?',
            [
                $data['branch_id'] ?? null,
                $data['name'],
                $data['email'],
                $data['username'],
                $data['status'] ?? 'active',
                $id,
            ]
        );
    }

    public static function updatePassword(int $id, string $hash): void
    {
        Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    public static function updateLastLogin(int $id): void
    {
        Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::execute('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
    }

    public static function softDelete(int $id): void
    {
        Database::execute(
            'UPDATE users SET deleted_at = NOW(), status = ? WHERE id = ?',
            ['inactive', $id]
        );
    }

    /**
     * Branch admins list with branch info (Owner admin table).
     */
    public static function admins(): array
    {
        return Database::fetchAll(
            "SELECT u.*, b.branch_code, b.branch_name
             FROM users u
             LEFT JOIN branches b ON b.id = u.branch_id
             WHERE u.role = 'branch_admin' AND u.deleted_at IS NULL
             ORDER BY u.created_at DESC"
        );
    }

    public static function countAdmins(): int
    {
        return (int) Database::fetch(
            "SELECT COUNT(*) AS c FROM users WHERE role = 'branch_admin' AND deleted_at IS NULL"
        )['c'];
    }

    public static function branchAdmins(int|string $branchId): array
    {
        return Database::fetchAll(
            "SELECT * FROM users WHERE branch_id = ? AND role = 'branch_admin' AND deleted_at IS NULL AND status = 'active'",
            [$branchId]
        );
    }

    /**
     * All branch admins for filter dropdowns.
     */
    public static function branchAdminsForFilter(): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.name
             FROM users u
             WHERE u.role = 'branch_admin' AND u.deleted_at IS NULL AND u.status = 'active'
             ORDER BY u.name ASC"
        );
    }

    /**
     * Owner account (there is exactly one).
     */
    public static function owner(): ?array
    {
        return Database::fetch("SELECT * FROM users WHERE role = 'owner' AND deleted_at IS NULL LIMIT 1");
    }
}