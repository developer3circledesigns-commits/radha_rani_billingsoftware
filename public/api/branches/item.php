<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_owner();
$user = current_user();

$id = (int) (get('id') ?: (int) ($_POST['id'] ?? 0));

// Each case below ends in api_ok()/api_error(), and api_json() exits. That exit
// is the only thing stopping the update case from running on into the delete
// case, so it is load-bearing: if a response helper ever stops exiting, this
// switch has to grow explicit breaks before it can be trusted again.
switch (method()) {
    case 'PUT':
    case 'POST':
        if (!csrf_verify()) csrf_fail_api(t('api.csrf_expired_short'));
        $branch = $id ? Branch::find($id) : null;
        if (!$branch) api_error(t('api.branch_not_found'), 404);

        $data = jsonBody() ?: $_POST;
        $data = [
            'branch_code' => strtoupper(trim((string) ($data['branch_code'] ?? $branch['branch_code']))),
            'branch_name' => trim((string) ($data['branch_name'] ?? $branch['branch_name'])),
            'address'     => trim((string) ($data['address'] ?? $branch['address'] ?? '')),
            'phone'       => trim((string) ($data['phone'] ?? $branch['phone'] ?? '')),
            'email'       => trim((string) ($data['email'] ?? $branch['email'] ?? '')),
            'status'      => ($data['status'] ?? $branch['status']) === 'inactive' ? 'inactive' : 'active',
        ];
        if ($data['branch_code'] === '' || $data['branch_name'] === '') {
            api_error(t('api.branch_code_name_req'), 422);
        }
        $dup = Database::fetch('SELECT id FROM branches WHERE branch_code = ? AND id != ? AND deleted_at IS NULL', [$data['branch_code'], $id]);
        if ($dup) api_error(t('api.branch_code_in_use'), 422);

        $data['email'] = strtolower(trim((string) ($data['email'] ?? $branch['email'] ?? '')));
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            api_error(t('api.valid_email'), 422);
        }

        Branch::update($id, $data);
        log_activity((int) $user['id'], null, 'BRANCH_UPDATED', 'branch', $id, 'API: updated branch ' . $data['branch_name']);

        // Deactivating a branch terminates the sessions of every admin bound to
        // it, so it is an administrative action as well as a record change.
        if ($branch['status'] === 'active' && $data['status'] === 'inactive') {
            SecurityLogger::log(SecurityLogger::ADMIN_ACTION, [
                'admin_operation' => 'branch_deactivated',
                'entity_type'     => 'branch',
                'entity_id'       => (int) $id,
                'target_branch'   => $data['branch_code'],
                'result'          => 'success',
            ]);
        }
        api_ok(null, t('api.branch_updated'));

    case 'DELETE':
        if (!csrf_verify()) csrf_fail_api(t('api.csrf_expired_short'));
        $branch = $id ? Branch::find($id) : null;
        if (!$branch) api_error(t('api.branch_not_found'), 404);
        Branch::softDelete($id);
        log_activity((int) $user['id'], null, 'BRANCH_DELETED', 'branch', $id, 'API: deleted branch ' . $branch['branch_name']);
        api_ok(null, t('api.branch_deleted'));

    default:
        api_error(t('api.method_not_allowed'), 405);
}