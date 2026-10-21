<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!isPost()) {
    api_error(t('api.method_not_allowed'), 405);
}

if (!check_authentication()) {
    SecurityLogger::denied(
        SecurityLogger::UNAUTHORIZED_ACCESS,
        'no_valid_session',
        null,
        ['required_auth' => 'branch_admin', 'endpoint' => 'bills_upload']
    );
    api_error(t('api.auth_required'), 401);
}

if (!csrf_verify()) {
    csrf_fail_api(t('api.csrf_expired'));
}

$user = current_user();
if (($user['role'] ?? '') !== 'branch_admin') {
    SecurityLogger::denied(
        SecurityLogger::FORBIDDEN_ACCESS,
        'role_not_permitted',
        $user,
        ['required_role' => 'branch_admin', 'endpoint' => 'bills_upload']
    );
    api_error(t('api.not_authorized_upload'), 403);
}

// Fail loudly and clearly if storage is not writable, instead of accepting
// the upload and losing the PDF.
$storageProblems = BillStorage::ensureStorageTree();
if ($storageProblems !== []) {
    error_log('[bill-upload] storage not writable: ' . implode(', ', $storageProblems));
    api_error(t('api.storage_unavailable'), 503);
}

$branch = Branch::find((int) $user['branch_id']);
if (!$branch) {
    api_error(t('api.invalid_branch'), 403);
}
if ($branch['status'] !== 'active') {
    api_error(t('api.branch_inactive'), 403);
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
    api_error(t('api.select_payment_type'), 422, ['payment_type' => t('api.select_cash_or_card')]);
}

$d = DateTime::createFromFormat('Y-m-d', $businessDate);
if (!$d || $d->format('Y-m-d') !== $businessDate) {
    api_error(t('api.select_business_date'), 422, ['business_date' => t('api.select_valid_date')]);
}
$today = (new DateTime())->format('Y-m-d');
if ($businessDate > $today) {
    api_error(t('api.future_date'), 422, ['business_date' => t('api.future_date')]);
}

$uploadMode = $_POST['upload_mode'] ?? 'pdf';
if (!in_array($uploadMode, ['pdf', 'photo'], true)) {
    api_error(t('api.invalid_upload_mode'), 422);
}
$uploadSource = $uploadMode;
$sourceMime = null;

if ($uploadSource === 'photo') {
    // A photo is normalised to JPEG by the browser, embedded into a PDF and
    // then enters the exact same storage path as a PDF upload.
    $fileField = 'photo_file';
    if (empty($_FILES[$fileField]) || ($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        api_error(t('api.choose_photo'), 422, [$fileField => t('api.no_photo_selected')]);
    }

    $file = $_FILES[$fileField];
    $photoName = sanitize_filename((string) $file['name']);
    $validation = PhotoValidator::validate($file, $photoName);
    if (!$validation['ok']) {
        SecurityLogger::fileUploaded(
            $file,
            $photoName,
            'rejected',
            'rejected',
            $user,
            [
                'reason'        => 'photo_validation_failed',
                'branch_id'     => (int) $user['branch_id'],
                'upload_source' => 'photo',
            ]
        );
        api_error(implode(' ', $validation['errors']), 422, [$fileField => $validation['errors']]);
    }

    // Reserve room for the PDF wrapper and object table, so a photo that just
    // fits the JPEG limit cannot produce a PDF that then exceeds it.
    $photoMax = max(1, $maxSize - 65536);
    if ((int) $file['size'] > $photoMax) {
        api_error(
            t('api.photo_too_large', ['size' => format_bytes($photoMax)]),
            422,
            [$fileField => t('api.photo_exceeds_limit')]
        );
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        api_error(t('api.upload_failed'), 500);
    }

    $jpeg = @file_get_contents($file['tmp_name']);
    if ($jpeg === false || $jpeg === '') {
        api_error(t('api.unreadable_file'), 500);
    }

    $base = pathinfo($photoName, PATHINFO_FILENAME);
    $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $base), '._-');
    if ($base === '') {
        $base = 'Photo';
    }
    if (!str_starts_with(strtolower($base), 'photo')) {
        $base = 'Photo_' . $base;
    }
    $origName = substr($base, 0, 180) . '.pdf';

    try {
        $bytes = BillPhotoPdf::fromJpeg($jpeg, ['title' => $origName]);
    } catch (Throwable $e) {
        error_log('[bill-upload] photo conversion failed: ' . $e->getMessage());
        api_error(t('api.photo_convert_failed'), 500);
    }

    if (strlen($bytes) > $maxSize) {
        api_error(
            t('api.photo_too_large', ['size' => format_bytes($maxSize)]),
            422,
            [$fileField => t('api.photo_exceeds_limit')]
        );
    }

    $sourceMime = 'image/jpeg';
} else {
    $fileField = 'pdf_file';
    if (empty($_FILES[$fileField]) || ($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        api_error(t('api.choose_pdf'), 422, [$fileField => t('api.no_pdf_selected')]);
    }

    $file = $_FILES[$fileField];
    $origName = sanitize_filename((string) $file['name']);

    $validation = PdfValidator::validate($file, $origName);
    if (!$validation['ok']) {
        // A rejected upload is still an upload attempt and is worth an analyst
        // seeing: repeated failures against the PDF-only allow-list are how a
        // probe for an executable upload looks from the server side. Metadata
        // only - the file is never read here and never described further.
        SecurityLogger::fileUploaded(
            $file,
            $origName,
            'rejected',
            'rejected',
            $user,
            ['reason' => 'pdf_validation_failed', 'branch_id' => (int) $user['branch_id']]
        );
        api_error(implode(' ', $validation['errors']), 422, [$fileField => $validation['errors']]);
    }

    // Extra size check vs configured limit
    if ((int) $file['size'] > $maxSize) {
        api_error(t('api.file_too_large', ['size' => format_bytes($maxSize)]), 422, [$fileField => t('api.file_exceeds_limit')]);
    }
}

// ---- Move & store ----
$branchCode = preg_replace('/[^A-Za-z0-9_-]/', '', $branch['branch_code']);
if ($branchCode === '') {
    $branchCode = 'BR' . (int) $branch['id'];
}

$storedName = $branchCode . '_' . date('Ymd_His') . '_' . strtoupper($paymentType)
    . ($uploadSource === 'photo' ? '_PHOTO' : '') . '_' . secure_rand_hex(4) . '.pdf';
$paths = BillStorage::buildUploadPath($branchCode, $paymentType, $storedName);

if (!is_uploaded_file($file['tmp_name'])) {
    api_error(t('api.upload_failed'), 500);
}

// Read the uploaded bytes once; they are used for BOTH copies and the hash.
// In photo mode the PDF was already produced from the JPEG above.
if ($uploadSource !== 'photo') {
    $bytes = @file_get_contents($file['tmp_name']);
    if ($bytes === false || $bytes === '') {
        api_error(t('api.unreadable_file'), 500);
    }
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
        'upload_source'     => $uploadSource,
        'source_mime'       => $sourceMime,
    ]);
} catch (Throwable $e) {
    if ($fileSaved && is_file($paths['abs'])) {
        @unlink($paths['abs']);
    }
    error_log('[bill-upload] database insert failed: ' . $e->getMessage());
    api_error(t('api.save_failed'), 500);
}

// Folder write failed but the bill is safely stored in the database.
if (!$fileSaved) {
    log_activity((int) $user['id'], (int) $branch['id'], 'BILL_FS_SAVE_FAILED', 'bill', $billId,
        'Folder copy could not be written for ' . $origName . '; bill stored in database only.');
}

// File uploaded successfully. The destination is reported as a category, never
// a full path, so the event does not disclose the storage layout; the bill id
// is the stable identifier an investigator actually needs.
SecurityLogger::fileUploaded(
    $file,
    $storedName,
    $paymentType,
    'success',
    $user,
    [
        'branch_id'     => (int) $branch['id'],
        'bill_id'       => (int) $billId,
        'payment_type'  => $paymentType,
        'business_date' => $businessDate,
        'upload_source' => $uploadSource,
        'source_mime'   => $sourceMime,
    ]
);

// Audit detail stays English on purpose: the activity log is evidence, and
// translating its free text would make cross-locale searching unreliable.
log_activity(
    (int) $user['id'],
    (int) $branch['id'],
    $uploadSource === 'photo' ? 'BILL_PHOTO_UPLOADED' : 'BILL_UPLOADED',
    'bill',
    $billId,
    ($uploadSource === 'photo' ? 'Captured photo of ' : 'Uploaded ')
        . ($paymentType === 'cash' ? 'Cash' : 'Card') . ' bill for ' . $businessDate . ': ' . $origName
);

/**
 * Settle the "bills not uploaded" alert this bill has just changed.
 *
 * Compliance is measured on the submission timestamp, not business_date, so
 * the alert being resolved is keyed on the date the bill landed - not the date
 * printed on the document. Uploading a back-dated bill therefore satisfies
 * yesterday's alert, not today's, which is the behaviour the owner expects.
 *
 * A day can be only partly fixed - cash arrives, the card bill does not - so the
 * response reports what is still outstanding instead of claiming victory. The
 * toast below then tells the branch admin whether the owner is off the hook or
 * still waiting on something.
 */
$compliance = DailyCompliance::settleAfterUpload((int) $branch['id'], date('Y-m-d'));

$complianceMessage = '';
if ($compliance['closed'] > 0) {
    if ($compliance['compliant']) {
        $complianceMessage = t('js.compliance_restored');
    } else {
        // The brief noun forms are used here rather than compliance.missing_*,
        // which are full sentences and would read as "the No Card bill uploaded
        // is still missing".
        $short = array_map(
            static fn (string $type): string => t('compliance.type_' . $type . '_short'),
            $compliance['missing']
        );
        $complianceMessage = count($short) === 1
            ? t('js.compliance_partial', ['what' => $short[0]])
            : t('js.compliance_partial_many', [
                'what' => t('compliance.missing_types', ['types' => implode(', ', $short)]),
            ]);
    }

    log_activity(
        (int) $user['id'],
        (int) $branch['id'],
        $compliance['compliant'] ? 'BILL_UPLOAD_COMPLIANCE_RESTORED' : 'BILL_UPLOAD_COMPLIANCE_PARTIAL',
        'branch',
        (int) $branch['id'],
        'Daily upload compliance for ' . $branch['branch_code'] . ' on ' . date('Y-m-d') . ': '
            . ($compliance['compliant']
                ? 'restored, ' . $compliance['closed'] . ' alert(s) closed'
                : 'still missing ' . implode('+', $compliance['missing'])
                    . ', ' . $compliance['closed'] . ' alert(s) closed and re-raised')
    );
}

api_ok([
    'bill_id'     => $billId,
    'filename'    => $origName,
    'payment'     => $paymentType,
    'biz_date'    => $businessDate,
    'source'      => $uploadSource,
    'size'        => format_bytes($size),
    'view_url'    => url('view.php') . '?id=' . $billId,
    'download_url'=> url('download.php') . '?id=' . $billId,
    'compliance'  => [
        'closed'    => $compliance['closed'],
        'compliant' => $compliance['compliant'],
        'missing'   => $compliance['missing'],
        'message'   => $complianceMessage,
    ],
], t('api.ok_bill_uploaded'));
