<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();
$user = current_user();

// ------------------------------------------------------------------
// DELETE
// ------------------------------------------------------------------
if (isPost() && get('action') === 'delete') {
    if (!csrf_verify()) csrf_fail();
    $billId = (int) post('id', 0);
    $bill = $billId ? Bill::find($billId) : null;
    if ($bill) {
        Bill::softDelete($billId);
        // Metadata only. The document name and size are enough to identify the
    // record; the content is never read and never logged.
    SecurityLogger::log(SecurityLogger::RECORD_DELETED, [
        'entity_type'       => 'bill',
        'entity_id'         => (int) $billId,
        'branch_id'         => (int) $bill['branch_id'],
        'payment_type'      => $bill['payment_type'],
        'business_date'     => $bill['business_date'],
        'original_filename' => $bill['original_filename'],
        'file_size'         => (int) $bill['file_size'],
        'deletion_type'     => 'soft_delete',
        'result'            => 'success',
    ]);

    log_activity((int) $user['id'], $bill['branch_id'], 'BILL_DELETED', 'bill', $billId, 'Deleted bill #' . $billId . ' (' . $bill['original_filename'] . ')');
        flash_set('success', 'Bill moved to Recently Deleted. It can be restored for ' . Bill::retentionDays() . ' day(s).');
    }
    redirect('owner/bills.php');
}

// ------------------------------------------------------------------
// FILTERS
// ------------------------------------------------------------------
$filters = [
    'branch_id'     => (int) get('branch_id', 0) ?: null,
    'payment_type'  => in_array(get('payment_type'), ['cash', 'card'], true) ? get('payment_type') : null,
    'business_date' => get('business_date') ?: null,
    'uploaded_at'   => get('uploaded_at') ?: null,
    'uploaded_by'   => (int) get('uploaded_by', 0) ?: null,
    'q'             => trim((string) get('q', '')),
];

$page = max(1, (int) get('page', 1));
$result = Bill::search($filters, $page, 15);

$branches = Branch::all();
$admins = User::branchAdminsForFilter();

// Build query string for pagination (preserve filters)
$qs = [];
foreach ($filters as $k => $v) {
    if ($v !== null && $v !== '') {
        $qs[$k] = $v;
    }
}
$baseUrl = url('owner/bills.php') . (count($qs) ? '?' . http_build_query($qs) : '');

$pageTitle = 'Bill Management';
$pageSubtitle = count($result['rows']) ? $result['count'] . ' document(s) found' : 'All uploaded bill documents';
$activeMenu = 'bills';

ob_start();
?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('owner/bills.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_branch">Branch</label>
                <select class="form-select form-select-sm" id="f_branch" name="branch_id">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $b) : ?>
                        <option value="<?= $b['id'] ?>" <?= $filters['branch_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_type">Type</label>
                <select class="form-select form-select-sm" id="f_type" name="payment_type">
                    <option value="">All / Cash / Card</option>
                    <option value="cash" <?= $filters['payment_type'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="card" <?= $filters['payment_type'] === 'card' ? 'selected' : '' ?>>Card</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_bdate">Business Date</label>
                <input type="date" class="form-control form-control-sm" id="f_bdate" name="business_date" value="<?= e($filters['business_date'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_udate">Upload Date</label>
                <input type="date" class="form-control form-control-sm" id="f_udate" name="uploaded_at" value="<?= e($filters['uploaded_at'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_admin">Uploaded By</label>
                <select class="form-select form-select-sm" id="f_admin" name="uploaded_by">
                    <option value="">Any Admin</option>
                    <?php foreach ($admins as $a) : ?>
                        <option value="<?= $a['id'] ?>" <?= $filters['uploaded_by'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_q">Filename</label>
                <input type="text" class="form-control form-control-sm" id="f_q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search file name…">
            </div>
            <div class="col-12 d-flex gap-2 pt-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                <a href="<?= url('owner/bills.php') ?>" class="btn btn-sm btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Bill table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (!$result['rows']) : ?>
            <div class="empty-state">
                <i class="bi bi-file-earmark-pdf"></i>
                <p class="mb-1">No bills found for the selected filters.</p>
                <p class="mb-0 text-muted">Try adjusting your filters or upload a bill first.</p>
            </div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Bill ID</th>
                        <th>Document</th>
                        <th>Branch</th>
                        <th>Type</th>
                        <th>Business Date</th>
                        <th>File Size</th>
                        <th>Uploaded By</th>
                        <th>Uploaded At</th>
                        <th class="pe-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $bill) : ?>
                    <tr>
                        <td class="ps-3 text-muted small">#<?= $bill['id'] ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-file-earmark-pdf-fill text-danger fs-5"></i>
                                <div class="text-truncate" style="max-width:220px" title="<?= e($bill['original_filename']) ?>">
                                    <span><?= e($bill['original_filename']) ?></span>
                                    <?php if (!empty($bill['description'])) : ?>
                                        <i class="bi bi-chat-left-text text-muted small ms-1" title="<?= e($bill['description']) ?>"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="small"><?= e($bill['branch_name']) ?></td>
                        <td><?= payment_type_badge($bill['payment_type']) ?></td>
                        <td class="small"><?= e(format_date($bill['business_date'])) ?></td>
                        <td class="small text-muted"><?= e(format_bytes((int) $bill['file_size'])) ?></td>
                        <td class="small"><?= e($bill['uploaded_by_name']) ?></td>
                        <td class="small text-muted"><?= e(format_datetime($bill['uploaded_at'])) ?></td>
                        <td class="pe-3 text-end">
                            <a href="<?= url('view.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light" title="View"><i class="bi bi-eye"></i></a>
                            <a href="<?= url('download.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light" title="Download"><i class="bi bi-download"></i></a>
                            <form method="post" action="<?= url('owner/bills.php?action=delete') ?>" class="d-inline"
                                      data-confirm="Delete bill #<?= $bill['id'] ?> (<?= e($bill['original_filename']) ?>)? It will move to Recently Deleted, where it stays restorable for <?= Bill::retentionDays() ?> day(s).">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $bill['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-light text-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted">Showing <?= count($result['rows']) ?> of <?= $result['count'] ?> · Page <?= $result['page'] ?> of <?= $result['pages'] ?></span>
            <?= pagination($result, $baseUrl) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';