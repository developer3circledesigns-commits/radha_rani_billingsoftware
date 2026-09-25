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
    if (!csrf_verify()) api_error('Session token expired.', 419);
    $data = jsonBody() ?: $_POST;
    $branchId = (int) ($data['branch_id'] ?? 0);
    if (!$branchId || !Branch::find($branchId)) api_error('Select a valid branch.', 422);

    $name     = trim((string) ($data['name'] ?? ''));
    $email    = strtolower(trim((string) ($data['email'] ?? '')));
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($name === '') api_error('Full name is required.', 422);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('Enter a valid email address.', 422);
    if ($username === '') api_error('Username is required.', 422);
    if (strlen($password) < 8) api_error('Password must be at least 8 characters.', 422);

    if (User::findByEmail($email)) api_error('Email already in use.', 422);
    if (User::findByUsername($username)) api_error('Username already in use.', 422);
    if (strlen($password) < 8) api_error('Password must be at least 8 characters.', 422);

    $newId = User::create([
        'branch_id'     => $branchId,
        'name'          => trim((string) ($data['name'] ?? '')),
        'email'         => $email,
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role'          => 'branch_admin',
        'status'        => ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
    ]);
    log_activity((int) $user['id'], $branchId, 'ADMIN_CREATED', 'user', $newId, 'API: created admin ' . $data['name']);
    api_ok(['id' => $newId], 'Admin created.');
}

api_error('Method not allowed.', 405);