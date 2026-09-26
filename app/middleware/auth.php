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

    // A session the server tears down by itself is security-relevant, because
    // the same code path fires when an administrator disables an account or a
    // branch - the one case where an analyst most needs to know. Reported with
    // the reason, at low severity, and never on a normal page view.
    $terminate = static function (string $reason) use ($user): void {
        SecurityLogger::log('session_terminated', [
            'user_id'  => $user['id']       ?? null,
            'username' => $user['username'] ?? null,
            'role'     => $user['role']     ?? null,
            'reason'   => $reason,
            'result'   => 'terminated',
        ]);
        logout_user();
    };

    // Absolute session lifetime
    if (!empty($_SESSION['logged_in_at']) && (time() - (int) $_SESSION['logged_in_at']) > SESSION_TIMEOUT) {
        $terminate('absolute_timeout');
        return false;
    }

    // Idle timeout (rolling)
    if (!empty($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > SESSION_LIFETIME) {
        $terminate('idle_timeout');
        return false;
    }

    // Account/branch must be active
    if ($user['status'] !== 'active') {
        $terminate('account_inactive');
        return false;
    }
    if ($user['role'] === 'branch_admin' && $user['branch_status'] !== 'active') {
        $terminate('branch_inactive');
        return false;
    }

    $_SESSION['last_activity'] = time();
    return true;
}

function require_login(): void
{
    if (!check_authentication()) {
        // Reaching a protected page with no usable session is the single most
        // common unauthorized request in the application. It is reported as
        // unauthorized_access (not an error) because an anonymous visitor
        // following a stale bookmark looks identical to an attacker probing
        // for pages - the rule severity reflects that ambiguity.
        SecurityLogger::denied(
            SecurityLogger::UNAUTHORIZED_ACCESS,
            'no_valid_session',
            null,
            ['required_auth' => 'any']
        );
        redirect('login.php');
    }
}

function require_owner(): void
{
    require_login();
    $user = current_user();
    if (($user['role'] ?? '') !== 'owner') {
        // A real session holding the wrong role: this is an authorization
        // decision, not a missing login, so it is forbidden_access and names
        // the role that was actually held.
        SecurityLogger::denied(
            SecurityLogger::FORBIDDEN_ACCESS,
            'role_not_permitted',
            $user,
            ['required_role' => 'owner']
        );
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
        SecurityLogger::denied(
            SecurityLogger::FORBIDDEN_ACCESS,
            'role_not_permitted',
            $user,
            ['required_role' => 'branch_admin']
        );
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
    $allowed = time() > $allowedAfter;

    if (!$allowed) {
        // The application's own lockout engaging is a real, already-verified
        // signal (it required LOGIN_MAX_ATTEMPTS real credential failures), so
        // it is reported as rate_limit_triggered rather than left implicit in
        // the Wazuh frequency rules.
        SecurityLogger::authFailed(
            SecurityLogger::RATE_LIMIT_TRIGGERED,
            $username,
            'account_lockout_active',
            null
        );
    }

    return $allowed;
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

    // The moment the attempt count reaches the configured maximum, the very
    // next request will be locked out. Announcing it here is what makes the
    // Wazuh alert land on the attempt that caused it, not on the one after.
    if ($count === LOGIN_MAX_ATTEMPTS) {
        SecurityLogger::authFailed(
            SecurityLogger::ACCOUNT_LOCKED,
            $username,
            'login_attempts_exhausted',
            null
        );
    }

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
 *
 * Writes the MySQL audit_logs row exactly as before, and additionally mirrors
 * the action into security.log for Wazuh. The mapping is a table rather than
 * a heuristic so the same action always produces the same event name, and so
 * an unmapped action is visible as a decision to make rather than silently
 * lost. Mirroring here means no call site has to change, and no extra query is
 * added: the row is already being written.
 *
 * $target is optional and appended to the signature, so every existing call
 * site keeps working untouched. It carries the AFFECTED account ("which admin
 * was disabled") as opposed to $userId, which is always the actor - without it
 * the SIEM can say an owner disabled someone but not who.
 *
 * @param array{id?: int, username?: string, role?: string}|null $target
 */
function log_activity(
    int $userId,
    ?int $branchId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $description = null,
    ?array $target = null
): void {
    Database::execute(
        'INSERT INTO audit_logs (user_id, branch_id, action, entity_type, entity_id, description, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $branchId, $action, $entityType, $entityId, $description, client_ip(), user_agent()]
    );

    security_log_from_audit_action($action, $userId, $branchId, $entityType, $entityId, $description, $target);
}

/**
 * audit_logs action -> security.log event.
 *
 * Only actions that are security-relevant appear here. Normal operational
 * reads (LIST/VIEW) are deliberately absent: the SIEM should carry events
 * worth investigating, not a transcript of page views.
 *
 * @param array{id?: int, username?: string, role?: string}|null $target
 */
function security_log_from_audit_action(
    string $action,
    int $userId,
    ?int $branchId,
    ?string $entityType,
    ?int $entityId,
    ?string $description,
    ?array $target = null
): void {
    static $map = [
        // Authentication
        'LOGIN'                => SecurityLogger::LOGIN_SUCCESS,
        'LOGOUT'               => SecurityLogger::LOGOUT,
        'LOGIN_FAILED'         => SecurityLogger::LOGIN_FAILED,
        'LOGIN_DENIED'         => SecurityLogger::LOGIN_FAILED,
        'PASSWORD_CHANGED'     => SecurityLogger::PASSWORD_CHANGE,
        'ADMIN_PASSWORD_RESET' => SecurityLogger::PASSWORD_RESET,

        // User / role management
        'ADMIN_CREATED'        => SecurityLogger::USER_CREATED,
        'ADMIN_UPDATED'        => SecurityLogger::USER_UPDATED,
        'ADMIN_DELETED'        => SecurityLogger::USER_DELETED,
        'ADMIN_ACTIVATED'      => SecurityLogger::USER_UPDATED,
        'ADMIN_DISABLED'       => SecurityLogger::USER_UPDATED,

        // Administration
        //
        // SETTINGS_UPDATED is intentionally absent: owner/settings.php emits
        // admin_settings_changed directly so the event can list which settings
        // actually changed instead of only saying "settings were saved".
        'BRANCH_CREATED'       => SecurityLogger::RECORD_CREATED,
        'BRANCH_UPDATED'       => SecurityLogger::RECORD_UPDATED,
        'BRANCH_DELETED'       => SecurityLogger::RECORD_DELETED,
        'BRANCH_ACTIVATED'     => SecurityLogger::RECORD_UPDATED,
        'BRANCH_DEACTIVATED'   => SecurityLogger::RECORD_UPDATED,

        // Data
        //
        // BILL_UPLOADED is intentionally absent: api/bills/upload.php emits
        // file_uploaded directly, because the Wazuh feed needs the upload
        // metadata (extension, size, mime, stored name, destination) that the
        // audit_logs description does not carry. Mapping it here as well would
        // produce two events per upload.
        // BILL_DELETED is also emitted explicitly by the five owner pages that
        // delete a bill, so the event carries payment type, business date and
        // size; mapping it here too would double every deletion.
        'BILL_DOWNLOADED'      => SecurityLogger::FILE_DOWNLOADED,
        'BILL_DOWNLOAD_DENIED' => SecurityLogger::SENSITIVE_RECORD_ACCESS,
        'BILL_VIEWED'          => SecurityLogger::FILE_DOWNLOADED,
        'BILL_VIEW_DENIED'     => SecurityLogger::SENSITIVE_RECORD_ACCESS,
        'BILL_RESTORED'        => SecurityLogger::RECORD_RESTORED,
        // BILL_PURGED is also absent: owner/trash.php emits file_deleted
        // directly so the event carries the filename, size and deletion type
        // that the audit description does not.

        // Storage anomalies - not attacks, but they explain a missing file
        'BILL_FS_SAVE_FAILED'    => SecurityLogger::APPLICATION_ERROR,
        'BILL_SERVED_FROM_DB'    => SecurityLogger::SENSITIVE_RECORD_ACCESS,
    ];

    if (!isset($map[$action])) {
        return;
    }

    // "result" describes the OUTCOME OF THE EVENT, not the success of the
    // audit insert. Hardcoding it to "success" would have labelled every
    // login_failed and every authorization denial as "success", which is
    // worse than not logging it at all: a rule filtering on result=failed
    // would find nothing and an analyst would conclude nothing was wrong.
    static $results = [
        SecurityLogger::LOGIN_FAILED            => 'failed',
        SecurityLogger::UNAUTHORIZED_ACCESS     => 'denied',
        SecurityLogger::FORBIDDEN_ACCESS        => 'denied',
        SecurityLogger::SENSITIVE_RECORD_ACCESS => 'denied',
        SecurityLogger::ACCOUNT_LOCKED          => 'blocked',
        SecurityLogger::RATE_LIMIT_TRIGGERED    => 'blocked',
    ];

    $event = $map[$action];

    $actor = current_user();

    SecurityLogger::log($event, [
        'user_id'         => $userId,
        'username'        => $actor['username'] ?? null,
        'role'            => $actor['role']     ?? null,
        'branch_id'       => $branchId,
        'audit_action'    => $action,
        'entity_type'     => $entityType,
        'entity_id'       => $entityId,
        'target_user_id'  => $target['id']       ?? null,
        'target_username' => $target['username'] ?? null,
        'target_role'     => $target['role']     ?? null,
        'detail'          => $description,
        'result'          => $results[$event] ?? 'success',
    ]);
}