<?php

declare(strict_types=1);

/**
 * Audit log model.
 */
class AuditLog
{
    public static function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['action'])) {
            $where[] = 'a.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'DATE(a.created_at) >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'DATE(a.created_at) <= ?';
            $params[] = $filters['to'];
        }

        $whereSql = implode(' AND ', $where);
        $count = (int) Database::fetch(
            "SELECT COUNT(*) AS c FROM audit_logs a WHERE {$whereSql}",
            $params
        )['c'];

        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            "SELECT a.*, u.name AS user_name, u.role AS user_role, b.branch_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN branches b ON b.id = a.branch_id
             WHERE {$whereSql}
             ORDER BY a.created_at DESC
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

    public static function count(): int
    {
        return (int) Database::fetch('SELECT COUNT(*) AS c FROM audit_logs')['c'];
    }
}