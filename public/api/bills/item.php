<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$isOwner = ($user['role'] ?? '') === 'owner';

$id = (int) (get('id') ?: (int) ($_POST['id'] ?? 0));
$bill = $id ? Bill::find($id) : null;
if (!$bill) api_error(t('api.bill_not_found'), 404);

// Branch admins can only rename their own bills
if (!$isOwner && ($bill['uploaded_by'] ?? 0) != ($user['id'] ?? 0)) {
    api_error(t('api.unauthorized'), 403);
}

switch (method()) {
    case 'GET':
        api_ok($bill);
        break;

    case 'PUT':
        if (!$isOwner && !csrf_verify()) csrf_fail_api(t('api.csrf_expired_short'));
        $newName = trim((string) ($_POST['file_name'] ?? get('file_name', '')));
        if ($newName === '') {
            api_error(t('api.invalid_data'), 422);
        }
        if (strlen($newName) > 255) {
            api_error(t('api.invalid_data'), 422);
        }
        // Basic sanitization: allow alphanumeric, spaces, dots, underscores, hyphens
        if (!preg_match('/^[a-zA-Z0-9._-\s]+$/', $newName)) {
            api_error(t('api.invalid_filename'), 422);
        }
        Database::execute(
            'UPDATE bills SET file_name = ? WHERE id = ?',
            [$newName, $id]
        );
        $updatedBill = Bill::find($id);
        log_activity((int) $user['id'], $bill['branch_id'], 'BILL_RENAMED', 'bill', $id, 'API: renamed bill #' . $id . ' to "' . $newName . '"');
        api_ok($updatedBill, t('api.bill_renamed'));
        break;

    case 'DELETE':
        if (!csrf_verify()) csrf_fail_api(t('api.csrf_expired_short'));
        Bill::softDelete($id);
        SecurityLogger::log(SecurityLogger::RECORD_DELETED, [
            'entity_type'       => 'bill',
            'entity_id'         => (int) $id,
            'branch_id'         => (int) $bill['branch_id'],
            'payment_type'      => $bill['payment_type'],
            'business_date'     => $bill['business_date'],
            'original_filename' => $bill['original_filename'],
            'file_size'         => (int) $bill['file_size'],
            'deletion_type'     => 'soft_delete',
            'result'            => 'success',
        ]);
        log_activity((int) $user['id'], $bill['branch_id'], 'BILL_DELETED', 'bill', $id, 'API: deleted bill #' . $id . ' (' . $bill['original_filename'] . ')');
        api_ok(
            ['restore_until' => Bill::find($id)['purge_after'] ?? null],
            t('bills.moved_to_trash', ['days' => Bill::retentionDays()])
        );
        break;

    default:
        api_error(t('api.method_not_allowed'), 405);
}
