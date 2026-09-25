<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_owner();

switch (method()) {
    case 'GET':
        $id = (int) get('id', 0);
        if ($id) {
            $branch = Branch::find($id);
            if (!$branch) api_error('Branch not found.', 404);
            $branch['admin_count'] = (int) Database::fetch('SELECT COUNT(*) AS c FROM users WHERE branch_id = ? AND deleted_at IS NULL', [$id])['c'];
            $branch['total_bills'] = (int) Database::fetch("SELECT COUNT(*) AS c FROM bills WHERE branch_id = ? AND status = 'active'", [$id])['c'];
            api_ok($branch);
        }
        api_ok(Branch::listWithSummary());
        break;

    case 'POST':
        if (!csrf_verify()) api_error('Session token expired.', 419);
        $data = [
            'branch_code' => strtoupper(trim((string) ($_POST['branch_code'] ?? ''))),
            'branch_name' => trim((string) ($_POST['branch_name'] ?? '')),
            'address'     => trim((string) ($_POST['address'] ?? '')),
            'phone'       => trim((string) ($_POST['phone'] ?? '')),
            'email'       => trim((string) ($_POST['email'] ?? '')),
            'status'      => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ];
        if ($data['branch_code'] === '' || $data['branch_name'] === '') {
            api_error('Branch code and name are required.', 422);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $data['branch_code'])) {
            api_error('Branch code must be 2-20 letters, numbers, dashes or underscores.', 422);
        }
        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            api_error('Enter a valid email address.', 422);
        }
        if (Branch::findByCode($data['branch_code'])) {
            api_error('Branch code already in use.', 422);
        }
        $user = current_user();
        $newId = Branch::create($data);
        log_activity((int) $user['id'], null, 'BRANCH_CREATED', 'branch', $newId, 'API: created branch ' . $data['branch_name']);
        api_ok(['id' => $newId], 'Branch created.');
        break;

    default:
        api_error('Method not allowed.', 405);
}