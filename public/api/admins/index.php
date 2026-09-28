<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_owner();
$user = current_user();

if (method() === 'GET') {
    api_ok(User::admins());
}

if (method() === 'POST') {
    if (!csrf_verify()) csrf_fail_api(t('api.csrf_expired_short'));
    $data = jsonBody() ?: $_POST;
    $branchId = (int) ($data['branch_id'] ?? 0);
    if (!$branchId || !Branch::find($branchId)) api_error(t('api.select_valid_branch'), 422);

    $name     = trim((string) ($data['name'] ?? ''));
    $email    = strtolower(trim((string) ($data['email'] ?? '')));
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($name === '') api_error(t('api.name_required'), 422);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error(t('api.valid_email'), 422);
    if ($username === '') api_error(t('api.username_required'), 422);
    if (strlen($password) < 8) api_error(t('api.password_min'), 422);

    if (User::findByEmail($email)) api_error(t('api.email_in_use'), 422);
    if (User::findByUsername($username)) api_error(t('api.username_in_use'), 422);
    if (strlen($password) < 8) api_error(t('api.password_min'), 422);

    $newId = User::create([
        'branch_id'     => $branchId,
        'name'          => trim((string) ($data['name'] ?? '')),
        'email'         => $email,
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role'          => 'branch_admin',
        'status'        => ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
    ]);
    log_activity((int) $user['id'], $branchId, 'ADMIN_CREATED', 'user', $newId, 'API: created admin ' . $data['name'], [
        'id'       => (int) $newId,
        'username' => $username,
        'role'     => 'branch_admin',
    ]);
    api_ok(['id' => $newId], t('api.admin_created'));
}

api_error(t('api.method_not_allowed'), 405);