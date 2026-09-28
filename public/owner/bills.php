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
        flash_set('success', t('bills.moved_to_trash', ['days' => Bill::retentionDays()]));
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

$pageTitle = t('bills.title');
$pageSubtitle = count($result['rows'])
    ? tn('bills.subtitle_found', (int) $result['count'])
    : t('bills.subtitle_all');
$activeMenu = 'bills';

ob_start();
?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('owner/bills.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_branch"><?= e(t('bills.filter_branch')) ?></label>
                <select class="form-select form-select-sm" id="f_branch" name="branch_id">
                    <option value=""><?= e(t('common.all_branches')) ?></option>
                    <?php foreach ($branches as $b) : ?>
                        <option value="<?= $b['id'] ?>" <?= $filters['branch_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_type"><?= e(t('bills.filter_type')) ?></label>
                <select class="form-select form-select-sm" id="f_type" name="payment_type">
                    <option value=""><?= e(t('common.all_cash_card')) ?></option>
                    <option value="cash" <?= $filters['payment_type'] === 'cash' ? 'selected' : '' ?>><?= e(t('common.cash')) ?></option>
                    <option value="card" <?= $filters['payment_type'] === 'card' ? 'selected' : '' ?>><?= e(t('common.card')) ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_bdate"><?= e(t('bills.filter_bdate')) ?></label>
                <input type="date" class="form-control form-control-sm" id="f_bdate" name="business_date" value="<?= e($filters['business_date'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_udate"><?= e(t('bills.filter_udate')) ?></label>
                <input type="date" class="form-control form-control-sm" id="f_udate" name="uploaded_at" value="<?= e($filters['uploaded_at'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_admin"><?= e(t('bills.filter_uploader')) ?></label>
                <select class="form-select form-select-sm" id="f_admin" name="uploaded_by">
                    <option value=""><?= e(t('common.any_admin')) ?></option>
                    <?php foreach ($admins as $a) : ?>
                        <option value="<?= $a['id'] ?>" <?= $filters['uploaded_by'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1" for="f_q"><?= e(t('bills.filter_filename')) ?></label>
                <input type="text" class="form-control form-control-sm" id="f_q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="<?= e(t('bills.search_placeholder')) ?>">
            </div>
            <div class="col-12 d-flex gap-2 pt-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?= e(t('common.apply_filters')) ?></button>
                <a href="<?= url('owner/bills.php') ?>" class="btn btn-sm btn-light"><?= e(t('common.reset')) ?></a>
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
                <p class="mb-1"><?= e(t('bills.empty')) ?></p>
                <p class="mb-0 text-muted"><?= e(t('bills.empty_hint')) ?></p>
            </div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3"><?= e(t('col.bill_id')) ?></th>
                        <th><?= e(t('col.document')) ?></th>
                        <th><?= e(t('common.branch')) ?></th>
                        <th><?= e(t('col.type')) ?></th>
                        <th><?= e(t('col.business_date')) ?></th>
                        <th><?= e(t('col.file_size')) ?></th>
                        <th><?= e(t('col.uploaded_by')) ?></th>
                        <th><?= e(t('col.uploaded_at')) ?></th>
                        <th class="pe-3 text-end"><?= e(t('common.actions')) ?></th>
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
                            <a href="<?= url('view.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
                            <a href="<?= url('download.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light" title="<?= e(t('common.download')) ?>"><i class="bi bi-download"></i></a>
                            <form method="post" action="<?= url('owner/bills.php?action=delete') ?>" class="d-inline"
                                      data-confirm="<?= e(t('bills.delete_confirm', [
                                          'id'       => $bill['id'],
                                          'filename' => $bill['original_filename'],
                                          'days'     => Bill::retentionDays(),
                                      ])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $bill['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-light text-danger" title="<?= e(t('common.delete')) ?>"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted"><?= e(t('common.showing_of', ['shown' => count($result['rows']), 'total' => $result['count']])) ?> · <?= e(t('common.page_of', ['page' => $result['page'], 'pages' => $result['pages']])) ?></span>
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