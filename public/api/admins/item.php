<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_owner();
$user = current_user();

$id = (int) (get('id') ?: (int) ($_POST['id'] ?? 0));
$admin = $id ? User::find($id) : null;
if (!$admin || $admin['role'] !== 'branch_admin') api_error('Admin not found.', 404);

switch (method()) {
    case 'GET':
        api_ok($admin);
        break;

    case 'PUT':
    case 'POST':
        if (!csrf_verify()) api_error('Session token expired.', 419);
        $data = jsonBody() ?: $_POST;
        $name     = trim((string) ($data['name'] ?? $admin['name']));
        $email    = strtolower(trim((string) ($data['email'] ?? $admin['email'])));
        $username = trim((string) ($data['username'] ?? $admin['username']));
        $branchId = (int) ($data['branch_id'] ?? $admin['branch_id']);
        $status   = ($data['status'] ?? $admin['status']) === 'inactive' ? 'inactive' : 'active';

        if ($name === '') api_error('Full name is required.', 422);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('Enter a valid email address.', 422);
        if ($username === '') api_error('Username is required.', 422);
        if ($branchId <= 0 || !Branch::find($branchId)) api_error('Select a valid branch.', 422);

        $dup = Database::fetch(
            'SELECT id FROM users WHERE (LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)) AND id != ? AND deleted_at IS NULL',
            [$email, $username, $id]
        );
        if ($dup) api_error('Email or username already in use.', 422);

        User::update($id, [
            'branch_id' => $branchId,
            'name'      => $name,
            'email'     => $email,
            'username'  => $username,
            'status'    => $status,
        ]);
        log_activity((int) $user['id'], $branchId, 'ADMIN_UPDATED', 'user', $id, 'API: updated admin ' . $name);
        api_ok(null, 'Admin updated.');
        break;

    case 'DELETE':
        if (!csrf_verify()) api_error('Session token expired.', 419);
        User::softDelete($id);
        log_activity((int) $user['id'], $admin['branch_id'], 'ADMIN_DELETED', 'user', $id, 'API: deleted admin ' . $admin['name']);
        api_ok(null, 'Admin deleted.');
        break;

    default:
        api_error('Method not allowed.', 405);
}
