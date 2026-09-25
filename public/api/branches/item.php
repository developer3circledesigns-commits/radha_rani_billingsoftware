<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_owner();
$user = current_user();

$id = (int) (get('id') ?: (int) ($_POST['id'] ?? 0));

switch (method()) {
    case 'PUT':
    case 'POST':
        if (!csrf_verify()) api_error('Session token expired.', 419);
        $branch = $id ? Branch::find($id) : null;
        if (!$branch) api_error('Branch not found.', 404);

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
            api_error('Branch code and name are required.', 422);
        }
        $dup = Database::fetch('SELECT id FROM branches WHERE branch_code = ? AND id != ? AND deleted_at IS NULL', [$data['branch_code'], $id]);
        if ($dup) api_error('Branch code already in use.', 422);

        $data['email'] = strtolower(trim((string) ($data['email'] ?? $branch['email'] ?? '')));
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            api_error('Enter a valid email address.', 422);
        }

        Branch::update($id, $data);
        log_activity((int) $user['id'], null, 'BRANCH_UPDATED', 'branch', $id, 'API: updated branch ' . $data['branch_name']);
        api_ok(null, 'Branch updated.');

    case 'DELETE':
        if (!csrf_verify()) api_error('Session token expired.', 419);
        $branch = $id ? Branch::find($id) : null;
        if (!$branch) api_error('Branch not found.', 404);
        Branch::softDelete($id);
        log_activity((int) $user['id'], null, 'BRANCH_DELETED', 'branch', $id, 'API: deleted branch ' . $branch['branch_name']);
        api_ok(null, 'Branch deleted.');

    default:
        api_error('Method not allowed.', 405);
}