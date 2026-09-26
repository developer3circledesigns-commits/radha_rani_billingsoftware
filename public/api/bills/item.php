<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_owner();
$user = current_user();

$id = (int) (get('id') ?: (int) ($_POST['id'] ?? 0));
$bill = $id ? Bill::findActive($id) : null;
if (!$bill) api_error('Bill not found.', 404);

switch (method()) {
    case 'GET':
        api_ok($bill);
        break;

    case 'DELETE':
        if (!csrf_verify()) csrf_fail_api('Session token expired.');
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
            'Bill moved to Recently Deleted. It can be restored for ' . Bill::retentionDays() . ' day(s).'
        );
        break;

    default:
        api_error('Method not allowed.', 405);
}
