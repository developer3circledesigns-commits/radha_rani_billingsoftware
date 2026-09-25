<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!isPost()) {
    api_error('Method not allowed.', 405);
}

if (!check_authentication()) {
    api_error('Authentication required. Please sign in.', 401);
}

if (!csrf_verify()) {
    api_error('Session token expired. Please refresh the page and try again.', 419);
}

$user = current_user();
if (($user['role'] ?? '') !== 'branch_admin') {
    api_error('You are not authorized to upload bills.', 403);
}

// Fail loudly and clearly if storage is not writable, instead of accepting
// the upload and losing the PDF.
$storageProblems = BillStorage::ensureStorageTree();
if ($storageProblems !== []) {
    error_log('[bill-upload] storage not writable: ' . implode(', ', $storageProblems));
    api_error('Bill storage is temporarily unavailable. Please contact the administrator.', 503);
}

$branch = Branch::find((int) $user['branch_id']);
if (!$branch) {
    api_error('Your account is not assigned to a valid branch.', 403);
}
if ($branch['status'] !== 'active') {
    api_error('Your branch is currently inactive. Uploads are disabled.', 403);
}

// Read settings-based size limit
$maxSizeMb = (int) (Setting::get('max_file_size_mb', '20') ?: 20);
$maxSize = $maxSizeMb * 1024 * 1024;
if ($maxSize > MAX_FILE_SIZE) {
    $maxSize = MAX_FILE_SIZE;
}

// Never promise more than MySQL can actually accept in one packet
// (shared hosts commonly cap max_allowed_packet at 16 MB).
$effectiveMax = BillStorage::effectiveMaxUploadBytes();
if ($maxSize > $effectiveMax) {
    error_log('[bill-upload] configured max ' . format_bytes($maxSize)
        . ' exceeds database limit ' . format_bytes($effectiveMax)
        . ' (max_allowed_packet=' . BillStorage::maxPacketBytes() . '); capping uploads.');
    $maxSize = $effectiveMax;
}

$paymentType = $_POST['payment_type'] ?? '';
$businessDate = $_POST['business_date'] ?? '';
$description = trim((string) ($_POST['description'] ?? ''));

if (!in_array($paymentType, ['cash', 'card'], true)) {
    api_error('Please select a valid payment type (Cash or Card).', 422, ['payment_type' => 'Select Cash or Card.']);
}

$d = DateTime::createFromFormat('Y-m-d', $businessDate);
if (!$d || $d->format('Y-m-d') !== $businessDate) {
    api_error('Please select a valid business date.', 422, ['business_date' => 'Select a valid date.']);
}
$today = (new DateTime())->format('Y-m-d');
if ($businessDate > $today) {
    api_error('Business date cannot be in the future.', 422, ['business_date' => 'Business date cannot be in the future.']);
}

if (empty($_FILES['pdf_file']) || ($_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    api_error('Please choose a PDF file to upload.', 422, ['pdf_file' => 'No PDF file selected.']);
}

$origName = sanitize_filename($_FILES['pdf_file']['name']);

// Override effective max size for the validator
$file = $_FILES['pdf_file'];
$validation = PdfValidator::validate($file, $origName);
if (!$validation['ok']) {
    api_error(implode(' ', $validation['errors']), 422, ['pdf_file' => $validation['errors']]);
}

// Extra size check vs configured limit
if ((int) $file['size'] > $maxSize) {
    api_error('PDF file is larger than the allowed limit (' . format_bytes($maxSize) . ').', 422, ['pdf_file' => 'PDF file exceeds the configured limit.']);
}

// ---- Move & store ----
$branchCode = preg_replace('/[^A-Za-z0-9_-]/', '', $branch['branch_code']);
if ($branchCode === '') {
    $branchCode = 'BR' . (int) $branch['id'];
}

$storedName = $branchCode . '_' . date('Ymd_His') . '_' . strtoupper($paymentType) . '_' . secure_rand_hex(4) . '.pdf';
$paths = BillStorage::buildUploadPath($branchCode, $paymentType, $storedName);

if (!is_uploaded_file($file['tmp_name'])) {
    api_error('Upload failed.', 500);
}

// Read the uploaded bytes once; they are used for BOTH copies and the hash.
$bytes = @file_get_contents($file['tmp_name']);
if ($bytes === false || $bytes === '') {
    api_error('Could not read the uploaded file. Please retry.', 500);
}
$hash = hash('sha256', $bytes);
$size = strlen($bytes);

// ---- Copy A: filesystem (primary) ----
$fileSaved = false;
if (BillStorage::ensureDirectory($paths['dir'])) {
    $fileSaved = @file_put_contents($paths['abs'], $bytes) !== false;
    if (!$fileSaved) {
        error_log('[bill-upload] folder write failed: ' . $paths['abs']);
    }
} else {
    error_log('[bill-upload] storage directory unavailable: ' . $paths['dir']);
}

// ---- Copy B: database (safety net) ----
// The database is the source of truth for the record, so a folder failure is
// tolerated (the DB copy still serves the bill), but a DB failure must abort
// and clean up any file we already wrote.
try {
    $billId = Bill::create([
        'branch_id'         => (int) $branch['id'],
        'uploaded_by'       => (int) $user['id'],
        'payment_type'      => $paymentType,
        'business_date'     => $businessDate,
        'original_filename' => $origName,
        'stored_filename'   => $storedName,
        'file_path'         => $paths['rel'],
        'mime_type'         => 'application/pdf',
        'file_size'         => $size,
        'description'       => $description !== '' ? $description : null,
        'pdf_bytes'         => $bytes,
        'pdf_hash'          => $hash,
        'storage_status'    => BillStorage::statusFor($fileSaved, true),
    ]);
} catch (Throwable $e) {
    if ($fileSaved && is_file($paths['abs'])) {
        @unlink($paths['abs']);
    }
    error_log('[bill-upload] database insert failed: ' . $e->getMessage());
    api_error('Could not save the uploaded PDF. Please retry.', 500);
}

// Folder write failed but the bill is safely stored in the database.
if (!$fileSaved) {
    log_activity((int) $user['id'], (int) $branch['id'], 'BILL_FS_SAVE_FAILED', 'bill', $billId,
        'Folder copy could not be written for ' . $origName . '; bill stored in database only.');
}

log_activity((int) $user['id'], (int) $branch['id'], 'BILL_UPLOADED', 'bill', $billId,
    'Uploaded ' . ($paymentType === 'cash' ? 'Cash' : 'Card') . ' bill for ' . $businessDate . ': ' . $origName);

api_ok([
    'bill_id'     => $billId,
    'filename'    => $origName,
    'payment'     => $paymentType,
    'biz_date'    => $businessDate,
    'size'        => format_bytes($size),
    'view_url'    => url('view.php') . '?id=' . $billId,
    'download_url'=> url('download.php') . '?id=' . $billId,
], 'Bill uploaded successfully.');