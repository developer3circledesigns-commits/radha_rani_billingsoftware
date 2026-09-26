<?php

declare(strict_types=1);

/**
 * Inline PDF streaming for the embedded viewer (iframe).
 * Applies the same authorization checks as download.php.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_login();

$id   = (int) get('id', 0);
$bill = $id ? Bill::find($id) : null;

if (!$bill || $bill['status'] !== 'active') {
    http_response_code(404);
    require APP_PATH . '/views/errors/404.php';
    exit;
}

$user = current_user();
if ($user['role'] === 'owner') {
    // allowed
} elseif ($user['role'] === 'branch_admin' && $user['branch_id'] && (int) $user['branch_id'] === (int) $bill['branch_id']) {
    // allowed - own branch only
} else {
    // Same cross-tenant signal as view.php. This endpoint returns a bare 403
    // with no body, so without this event a probe against the raw PDF stream
    // would leave no trace in the SIEM at all.
    SecurityLogger::denied(
        SecurityLogger::FORBIDDEN_ACCESS,
        'cross_branch_bill_access',
        $user,
        [
            'entity_type'   => 'bill',
            'entity_id'     => (int) $bill['id'],
            'actor_branch'  => $user['branch_id'] !== null ? (int) $user['branch_id'] : null,
            'target_branch' => (int) $bill['branch_id'],
            'endpoint'      => 'bill_pdf_stream',
        ]
    );
    http_response_code(403);
    exit;
}

$source = BillStorage::openRead($bill);
if ($source === null) {
    error_log('[view_pdf] no readable copy for bill ' . $bill['id']);
    http_response_code(404);
    require APP_PATH . '/views/errors/404.php';
    exit;
}

// Audit every time a bill has to be served from the database safety copy.
if ($source['source'] === 'db') {
    log_activity((int) $user['id'], (int) $bill['branch_id'], 'BILL_SERVED_FROM_DB', 'bill', (int) $bill['id'],
        'Served from database copy: ' . $bill['original_filename']);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . sanitize_filename($bill['original_filename']) . '"');
header('Content-Length: ' . $source['size']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

fpassthru($source['stream']);
fclose($source['stream']);
exit;