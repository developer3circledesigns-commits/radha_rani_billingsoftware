<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!isPost()) {
    api_error('Method not allowed.', 405);
}

if (!csrf_verify()) {
    api_error('Session token expired. Please refresh the page and try again.', 419);
}

$login = trim((string) ($_POST['login'] ?? jsonBody()['login'] ?? ''));
$password = (string) ($_POST['password'] ?? jsonBody()['password'] ?? '');

if ($login === '' || $password === '') {
    api_error('Login ID and password are required.', 422);
}

// Throttling
if (!throttle_allow_login($login)) {
    api_error('Too many failed attempts. Please try again later.', 429);
}

$userRow = User::findByLogin($login);

if (!$userRow || !password_verify($password, $userRow['password_hash'])) {
    if ($userRow) {
        log_activity((int) $userRow['id'], $userRow['branch_id'], 'LOGIN_FAILED', 'user', (int) $userRow['id'], 'API: invalid credentials');
    }
    throttle_register_failure($login);
    api_error('Invalid login ID or password.', 401);
}

if ($userRow['status'] !== 'active') {
    api_error('This account is inactive. Contact the administrator.', 403);
}
if ($userRow['role'] === 'branch_admin' && $userRow['branch_status'] !== 'active') {
    api_error('The branch associated with this account is inactive.', 403);
}

session_regenerate_id(true);
$_SESSION['user_id']       = (int) $userRow['id'];
$_SESSION['logged_in_at']  = time();
$_SESSION['last_activity'] = time();

User::updateLastLogin((int) $userRow['id']);
throttle_reset($login);
log_activity((int) $userRow['id'], $userRow['branch_id'], 'LOGIN', 'user', (int) $userRow['id'], 'API login');

api_ok([
    'user_id' => (int) $userRow['id'],
    'name'    => $userRow['name'],
    'role'    => $userRow['role'],
    'redirect'=> $userRow['role'] === 'owner' ? url('owner/dashboard.php') : url('branch/dashboard.php'),
], 'Login successful.');