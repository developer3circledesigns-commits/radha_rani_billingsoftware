<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_owner();
$user = current_user();

$id = (int) (get('id') ?: (int) ($_POST['id'] ?? 0));
$admin = $id ? User::find($id) : null;
if (!$admin || $admin['role'] !== 'branch_admin') api_error(t('api.admin_not_found'), 404);

switch (method()) {
    case 'GET':
        api_ok($admin);
        break;

    case 'PUT':
    case 'POST':
        if (!csrf_verify()) csrf_fail_api(t('api.csrf_expired_short'));
        $data = jsonBody() ?: $_POST;
        $name     = trim((string) ($data['name'] ?? $admin['name']));
        $email    = strtolower(trim((string) ($data['email'] ?? $admin['email'])));
        $username = trim((string) ($data['username'] ?? $admin['username']));
        $branchId = (int) ($data['branch_id'] ?? $admin['branch_id']);
        $status   = ($data['status'] ?? $admin['status']) === 'inactive' ? 'inactive' : 'active';

        if ($name === '') api_error(t('api.name_required'), 422);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error(t('api.valid_email'), 422);
        if ($username === '') api_error(t('api.username_required'), 422);
        if ($branchId <= 0 || !Branch::find($branchId)) api_error(t('api.select_valid_branch'), 422);

        $dup = Database::fetch(
            'SELECT id FROM users WHERE (LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)) AND id != ? AND deleted_at IS NULL',
            [$email, $username, $id]
        );
        if ($dup) api_error(t('api.creds_in_use'), 422);

        User::update($id, [
            'branch_id' => $branchId,
            'name'      => $name,
            'email'     => $email,
            'username'  => $username,
            'status'    => $status,
        ]);
        log_activity((int) $user['id'], $branchId, 'ADMIN_UPDATED', 'user', $id, 'API: updated admin ' . $name, [
            'id'       => (int) $id,
            'username' => $username,
            'role'     => $admin['role'],
        ]);

        // Branch reassignment changes this admin's entire data scope, which in
        // this application is the privilege boundary.
        if ((int) $admin['branch_id'] !== $branchId) {
            SecurityLogger::log(SecurityLogger::ROLE_CHANGED, [
                'target_user_id'  => (int) $id,
                'target_username' => $username,
                'target_role'     => $admin['role'],
                'previous_branch' => $admin['branch_id'] !== null ? (int) $admin['branch_id'] : null,
                'new_branch'      => $branchId,
                'reason'          => 'branch_reassignment',
                'result'          => 'success',
            ]);
        }
        api_ok(null, t('api.admin_updated'));
        break;

    case 'DELETE':
        if (!csrf_verify()) csrf_fail_api(t('api.csrf_expired_short'));
        User::softDelete($id);
        log_activity((int) $user['id'], $admin['branch_id'], 'ADMIN_DELETED', 'user', $id, 'API: deleted admin ' . $admin['name'], $admin);
        api_ok(null, t('api.admin_deleted'));
        break;

    default:
        api_error(t('api.method_not_allowed'), 405);
}
