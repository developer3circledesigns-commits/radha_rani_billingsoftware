<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();
$user = current_user();

$retentionDays = Bill::retentionDays();

// ------------------------------------------------------------------
// Sweep expired bills (idempotent, cheap, runs on each page load)
// ------------------------------------------------------------------
$purgedNow = Bill::purgeExpired();

// ------------------------------------------------------------------
// ACTIONS
// ------------------------------------------------------------------
if (isPost()) {
    if (!csrf_verify()) csrf_fail();

    $action = get('action');
    $billId = (int) post('id', 0);
    $bill   = $billId ? Bill::find($billId) : null;

    if ($action === 'restore') {
        if ($bill && $bill['status'] === 'deleted' && $bill['storage_status'] === 'none') {
            flash_set('danger', 'Bill #' . $billId . ' can no longer be restored - its stored copies were already erased.');
        } elseif ($bill && $bill['status'] === 'deleted') {
            Bill::restore($billId);
            log_activity((int) $user['id'], $bill['branch_id'], 'BILL_RESTORED', 'bill', $billId,
                'Restored bill #' . $billId . ' (' . $bill['original_filename'] . ')');
            flash_set('success', 'Bill #' . $billId . ' has been restored.');
        } else {
            flash_set('danger', 'That bill could not be restored.');
        }
    } elseif ($action === 'purge') {
        if ($bill && $bill['status'] === 'deleted') {
            $name = $bill['original_filename'];
            Bill::purge($billId);
            log_activity((int) $user['id'], $bill['branch_id'], 'BILL_PURGED', 'bill', $billId,
                'Permanently erased stored copies of bill #' . $billId . ' (' . $name . ')');
            flash_set('success', 'Bill #' . $billId . ' and its stored copies were permanently erased.');
        } else {
            flash_set('danger', 'That bill could not be erased.');
        }
    }

    redirect('owner/trash.php');
}

// ------------------------------------------------------------------
// FILTERS
// ------------------------------------------------------------------
$filters = [
    'branch_id'    => (int) get('branch_id', 0) ?: null,
    'payment_type' => in_array(get('payment_type'), ['cash', 'card'], true) ? get('payment_type') : null,
    'q'            => trim((string) get('q', '')),
];

$page   = max(1, (int) get('page', 1));
$result = Bill::deleted($filters, $page, 20);
$branches = Branch::all();

$qs = [];
foreach ($filters as $k => $v) {
    if ($v !== null && $v !== '') {
        $qs[$k] = $v;
    }
}
$baseUrl = url('owner/trash.php') . (count($qs) ? '?' . http_build_query($qs) : '');

$pageTitle    = 'Recently Deleted';
$pageSubtitle = 'Deleted bills are kept for ' . $retentionDays . ' day(s) before being erased.';
$activeMenu   = 'trash';

ob_start();
?>

<?php if ($purgedNow > 0) : ?>
<div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-trash"></i>
    <span><?= $purgedNow ?> expired bill(s) were automatically erased during this visit.</span>
</div>
<?php endif; ?>

<div class="alert alert-light border d-flex align-items-center gap-2 small">
    <i class="bi bi-info-circle text-primary"></i>
    <span>Deleted bills move to <code>storage/archive</code> and stay restorable for <strong><?= $retentionDays ?> day(s)</strong>. After that the stored PDF copies are erased automatically; the record itself is kept for the audit trail.</span>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('owner/trash.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="f_branch">Branch</label>
                <select class="form-select form-select-sm" id="f_branch" name="branch_id">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $b) : ?>
                        <option value="<?= $b['id'] ?>" <?= $filters['branch_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="f_type">Type</label>
                <select class="form-select form-select-sm" id="f_type" name="payment_type">
                    <option value="">All / Cash / Card</option>
                    <option value="cash" <?= $filters['payment_type'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="card" <?= $filters['payment_type'] === 'card' ? 'selected' : '' ?>>Card</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="f_q">Filename</label>
                <input type="text" class="form-control form-control-sm" id="f_q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search file name…">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a href="<?= url('owner/trash.php') ?>" class="btn btn-sm btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (!$result['rows']) : ?>
            <div class="empty-state">
                <i class="bi bi-trash"></i>
                <p class="mb-1">No deleted bills.</p>
                <p class="mb-0 text-muted">Bills you delete will appear here while they can still be restored.</p>
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
                        <th>Deleted At</th>
                        <th>Purge In</th>
                        <th>Copies Stored</th>
                        <th class="pe-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $bill) : ?>
                    <?php
                    $expired   = !empty($bill['purge_after']) && strtotime($bill['purge_after']) <= time();
                    $remaining = (!empty($bill['purge_after']) && !$expired)
                        ? max(0, (int) ceil((strtotime($bill['purge_after']) - time()) / 86400))
                        : 0;
                    ?>
                    <tr>
                        <td class="ps-3 text-muted small">#<?= $bill['id'] ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-file-earmark-pdf-fill text-secondary fs-5"></i>
                                <span class="text-truncate" style="max-width:220px" title="<?= e($bill['original_filename']) ?>"><?= e($bill['original_filename']) ?></span>
                            </div>
                        </td>
                        <td class="small"><?= e($bill['branch_name']) ?></td>
                        <td><?= payment_type_badge($bill['payment_type']) ?></td>
                        <td class="small"><?= e(format_date($bill['business_date'])) ?></td>
                        <td class="small text-muted"><?= e(format_datetime($bill['deleted_at'])) ?></td>
                        <td class="small">
                            <?php if ($expired) : ?>
                                <span class="badge bg-secondary">Erasing…</span>
                            <?php else : ?>
                                <span class="text-muted"><?= $remaining ?> day(s)</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e(str_replace('_', ' ', (string) $bill['storage_status'])) ?></td>
                        <td class="pe-3 text-end">
                            <form method="post" action="<?= url('owner/trash.php?action=restore') ?>" class="d-inline"
                                      data-confirm="Restore bill #<?= $bill['id'] ?>? It will return to the active bill list.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $bill['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-light text-success" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                            </form>
                            <form method="post" action="<?= url('owner/trash.php?action=purge') ?>" class="d-inline"
                                      data-confirm="Erase bill #<?= $bill['id'] ?> forever? Both the archived file and the database copy will be permanently destroyed. This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $bill['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-light text-danger" title="Erase forever"><i class="bi bi-x-octagon"></i></button>
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
