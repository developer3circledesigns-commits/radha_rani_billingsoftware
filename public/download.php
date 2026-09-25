<?php

declare(strict_types=1);

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
    http_response_code(403);
    log_activity((int) $user['id'], $user['branch_id'], 'BILL_DOWNLOAD_DENIED', 'bill', $bill['id'], 'Unauthorized download attempt');
    require APP_PATH . '/views/errors/403.php';
    exit;
}

$source = BillStorage::openRead($bill);
if ($source === null) {
    error_log('[download] no readable copy for bill ' . $bill['id']);
    http_response_code(404);
    require APP_PATH . '/views/errors/404.php';
    exit;
}

// Audit every time a bill has to be served from the database safety copy.
if ($source['source'] === 'db') {
    log_activity((int) $user['id'], (int) $bill['branch_id'], 'BILL_SERVED_FROM_DB', 'bill', (int) $bill['id'],
        'Served from database copy: ' . $bill['original_filename']);
}

log_activity((int) $user['id'], $user['branch_id'], 'BILL_DOWNLOADED', 'bill', $bill['id'], 'Downloaded bill #' . $bill['id']);

// Safe content-disposition filename
$safeName = sanitize_filename($bill['original_filename']);

// Stream with resumable range support.
// BillStorage::openRead() hands back either an on-disk handle or a php://temp
// handle, so range requests work identically for both copies.
$size = $source['size'];
$fp = $source['stream'];

if (isset($_SERVER['HTTP_RANGE'])) {
    parse_str(substr($_SERVER['HTTP_RANGE'], strlen('bytes=')), $range);
    $start = (int) ($range[0] ?? 0);
    $end = (int) ($range[1] ?? ($size - 1));
    $length = $end - $start + 1;
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Content-Length: ' . $length);
    if (fseek($fp, $start) !== 0) {
        // Seek failed: fall back to skipping forward manually.
        $skipped = 0;
        while ($skipped < $start && !feof($fp)) {
            $skipped += (int) fread($fp, 8192);
        }
    }
    if ($start >= $size || $start < 0 || $end < $start) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
} else {
    http_response_code(200);
    header('Content-Length: ' . $size);
    header('Accept-Ranges: bytes');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

$chunk = 8192;
while (!feof($fp)) {
    echo fread($fp, $chunk);
    flush();
}
fclose($fp);
exit;