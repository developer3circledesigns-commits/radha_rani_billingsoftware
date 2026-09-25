<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_PATH . '/validators/PdfValidator.php';

require_login();

$id   = (int) get('id', 0);
$bill = $id ? Bill::find($id) : null;

if (!$bill || $bill['status'] !== 'active') {
    http_response_code(404);
    require APP_PATH . '/views/errors/404.php';
    exit;
}

// Authorization: owner can access all; branch admin restricted to their branch.
$user = current_user();
if ($user['role'] === 'owner') {
    // allowed
} elseif ($user['role'] === 'branch_admin' && $user['branch_id'] && (int) $user['branch_id'] === (int) $bill['branch_id']) {
    // allowed - own branch only
} else {
    http_response_code(403);
    log_activity((int) $user['id'], $user['branch_id'], 'BILL_VIEW_DENIED', 'bill', $bill['id'], 'Unauthorized view attempt');
    require APP_PATH . '/views/errors/403.php';
    exit;
}

// The document is readable if EITHER the folder copy or the database copy is
// present; the viewer itself falls back the same way.
if (BillStorage::fileCopy($bill) === null && !BillStorage::hasDatabaseCopy($bill)) {
    error_log('[view] no readable copy for bill ' . $bill['id']);
    http_response_code(404);
    require APP_PATH . '/views/errors/404.php';
    exit;
}

$servedFromDb = BillStorage::fileCopy($bill) === null;
if ($servedFromDb) {
    log_activity((int) $user['id'], (int) $bill['branch_id'], 'BILL_SERVED_FROM_DB', 'bill', (int) $bill['id'],
        'Viewed using database copy: ' . $bill['original_filename']);
}

log_activity((int) $user['id'], $user['branch_id'], 'BILL_VIEWED', 'bill', $bill['id'], 'Viewed bill #' . $bill['id']);

$branch = Branch::find((int) $bill['branch_id']);
$uploader = User::find((int) $bill['uploaded_by']);

$pageTitle = 'Bill Document';
$pageSubtitle = $bill['original_filename'];
$activeMenu = $user['role'] === 'owner' ? 'bills' : 'my-uploads';

ob_start();
?>
<div class="mb-3">
    <a href="javascript:history.back()" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <?php if ($user['role'] === 'owner') : ?>
    <a href="<?= url('download.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1"></i>Download</a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="pdf-icon me-3"><i class="bi bi-file-earmark-pdf-fill fs-1 text-danger"></i></div>
                    <div class="flex-grow-1">
                        <h6 class="mb-0 text-truncate" title="<?= e($bill['original_filename']) ?>"><?= e($bill['original_filename']) ?></h6>
                        <small class="text-muted"><?= e(format_bytes((int)$bill['file_size'])) ?> · <?= e($bill['mime_type']) ?></small>
                    </div>
                </div>
                <hr>
                <dl class="row mb-0 small">
                    <dt class="col-6 text-muted">Bill ID</dt><dd class="col-6 text-end mb-2">#<?= $bill['id'] ?></dd>
                    <dt class="col-6 text-muted">Branch</dt><dd class="col-6 text-end mb-2"><?= e($branch['branch_name'] ?? '—') ?></dd>
                    <dt class="col-6 text-muted">Payment type</dt><dd class="col-6 text-end mb-2"><?= payment_type_badge($bill['payment_type']) ?></dd>
                    <dt class="col-6 text-muted">Business date</dt><dd class="col-6 text-end mb-2"><?= e(format_date($bill['business_date'])) ?></dd>
                    <dt class="col-6 text-muted">Uploaded by</dt><dd class="col-6 text-end mb-2"><?= e($uploader['name'] ?? '—') ?></dd>
                    <dt class="col-6 text-muted">Uploaded at</dt><dd class="col-6 text-end mb-2"><?= e(format_datetime($bill['uploaded_at'])) ?></dd>
                    <?php if (!empty($bill['description'])) : ?>
                    <dt class="col-6 text-muted">Note</dt><dd class="col-6 text-end mb-2"><?= e($bill['description']) ?></dd>
                    <?php endif; ?>
                    <dt class="col-6 text-muted">Status</dt><dd class="col-6 text-end mb-0"><span class="badge bg-success">Available</span></dd>
                    <?php if ($servedFromDb) : ?>
                    <dt class="col-6 text-muted">Storage</dt><dd class="col-6 text-end mb-0"><span class="badge bg-warning text-dark">Recovered from database backup</span></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0 pdf-viewer-frame">
                <iframe src="<?= url('view_pdf.php') ?>?id=<?= $bill['id'] ?>" title="PDF Viewer" loading="lazy"></iframe>
            </div>
        </div>
    </div>
</div>
<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';