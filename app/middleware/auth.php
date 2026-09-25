<?php

declare(strict_types=1);

/**
 * Authentication & authorization middleware.
 */

// Current authenticated user (lazy-loaded).
function current_user(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    if (empty($_SESSION['user_id'])) {
        $user = null;
        return $user;
    }

    $row = Database::fetch(
        'SELECT u.*, b.branch_code, b.branch_name, b.status AS branch_status
         FROM users u
         LEFT JOIN branches b ON b.id = u.branch_id
         WHERE u.id = ? AND u.deleted_at IS NULL',
        [$_SESSION['user_id']]
    );

    $user = $row ?: null;
    return $user;
}

function check_authentication(): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $user = current_user();
    if (!$user) {
        return false;
    }

    // Absolute session lifetime
    if (!empty($_SESSION['logged_in_at']) && (time() - (int) $_SESSION['logged_in_at']) > SESSION_TIMEOUT) {
        logout_user();
        return false;
    }

    // Idle timeout (rolling)
    if (!empty($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > SESSION_LIFETIME) {
        logout_user();
        return false;
    }

    // Account/branch must be active
    if ($user['status'] !== 'active') {
        logout_user();
        return false;
    }
    if ($user['role'] === 'branch_admin' && $user['branch_status'] !== 'active') {
        logout_user();
        return false;
    }

    $_SESSION['last_activity'] = time();
    return true;
}

function require_login(): void
{
    if (!check_authentication()) {
        redirect('login.php');
    }
}

function require_owner(): void
{
    require_login();
    $user = current_user();
    if (($user['role'] ?? '') !== 'owner') {
        http_response_code(403);
        require APP_PATH . '/views/errors/403.php';
        exit;
    }
}

function require_branch_admin(): void
{
    require_login();
    $user = current_user();
    if (($user['role'] ?? '') !== 'branch_admin') {
        http_response_code(403);
        require APP_PATH . '/views/errors/403.php';
        exit;
    }
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Login throttling - persistent per-username+IP lockout.
 */
function throttle_allow_login(string $username): bool
{
    $key = 'throttle_' . md5(strtolower($username) . '|' . client_ip());
    $row = Database::fetch(
        'SELECT * FROM audit_logs WHERE entity_type = ? AND description = ? ORDER BY id DESC LIMIT 1',
        ['login_throttle', $key]
    );
    if (!$row) {
        return true;
    }
    $lockMins = LOGIN_LOCKOUT_MINUTES;
    $allowedAfter = strtotime($row['created_at'] . " +{$lockMins} minutes");
    return time() > $allowedAfter;
}

function throttle_register_failure(string $username): int
{
    $key = 'throttle_' . md5(strtolower($username) . '|' . client_ip());
    $data = json_encode([
        'username' => $username,
        'ip'       => client_ip(),
    ]);
    Database::execute(
        'INSERT INTO audit_logs (user_id, branch_id, action, entity_type, entity_id, description, ip_address, user_agent)
         VALUES (NULL, NULL, ?, ?, NULL, ?, ?, ?)',
        ['login_failed', 'login_throttle', $key, client_ip(), user_agent()]
    );
    $count = (int) Database::fetch(
        'SELECT COUNT(*) AS c FROM audit_logs
         WHERE entity_type = ? AND description = ? AND action = ? AND created_at >= (NOW() - INTERVAL ? MINUTE)',
        ['login_throttle', $key, 'login_failed', LOGIN_LOCKOUT_MINUTES]
    )['c'];
    return $count;
}

function throttle_reset(string $username): void
{
    $key = 'throttle_' . md5(strtolower($username) . '|' . client_ip());
    Database::execute(
        'DELETE FROM audit_logs WHERE entity_type = ? AND description = ?',
        ['login_throttle', $key]
    );
}

/**
 * Audit trail helper.
 */
function log_activity(
    int $userId,
    ?int $branchId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $description = null
): void {
    Database::execute(
        'INSERT INTO audit_logs (user_id, branch_id, action, entity_type, entity_id, description, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $branchId, $action, $entityType, $entityId, $description, client_ip(), user_agent()]
    );
}