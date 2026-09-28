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
            flash_set('danger', t('trash.restore_gone', ['id' => $billId]));
        } elseif ($bill && $bill['status'] === 'deleted') {
            Bill::restore($billId);
            log_activity((int) $user['id'], $bill['branch_id'], 'BILL_RESTORED', 'bill', $billId,
                'Restored bill #' . $billId . ' (' . $bill['original_filename'] . ')');
            flash_set('success', t('trash.restored_ok', ['id' => $billId]));
        } else {
            flash_set('danger', t('trash.restore_failed'));
        }
    } elseif ($action === 'purge') {
        if ($bill && $bill['status'] === 'deleted') {
            $name = $bill['original_filename'];
            Bill::purge($billId);
            // Purge is irreversible: it removes the stored copies from disk
            // AND the database blob. Reported explicitly with the file
            // metadata (never the content) before the log_activity mirror
            // records the same action as file_deleted.
            SecurityLogger::log(SecurityLogger::FILE_DELETED, [
                'user_id'            => (int) $user['id'],
                'username'           => $user['username'],
                'role'               => $user['role'],
                'entity_type'        => 'bill',
                'entity_id'          => (int) $billId,
                'branch_id'          => $bill['branch_id'] !== null ? (int) $bill['branch_id'] : null,
                'original_filename'  => $name,
                'extension'          => strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
                'file_size'          => isset($bill['file_size']) ? (int) $bill['file_size'] : null,
                'deletion_type'      => 'permanent_purge',
                'result'             => 'success',
            ]);
            log_activity((int) $user['id'], $bill['branch_id'], 'BILL_PURGED', 'bill', $billId,
                'Permanently erased stored copies of bill #' . $billId . ' (' . $name . ')');
            flash_set('success', t('trash.purged_ok', ['id' => $billId]));
        } else {
            flash_set('danger', t('trash.purge_failed'));
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

$pageTitle    = t('trash.title');
$pageSubtitle = t('trash.subtitle', ['days' => $retentionDays]);
$activeMenu   = 'trash';

ob_start();
?>

<?php if ($purgedNow > 0) : ?>
<div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-trash"></i>
    <span><?= e(t('trash.purged_notice', ['n' => $purgedNow])) ?></span>
</div>
<?php endif; ?>

<div class="alert alert-light border d-flex align-items-center gap-2 small">
    <i class="bi bi-info-circle text-primary"></i>
    <span><?= e(t('trash.explain', ['days' => $retentionDays])) ?></span>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('owner/trash.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="f_branch"><?= e(t('bills.filter_branch')) ?></label>
                <select class="form-select form-select-sm" id="f_branch" name="branch_id">
                    <option value=""><?= e(t('common.all_branches')) ?></option>
                    <?php foreach ($branches as $b) : ?>
                        <option value="<?= $b['id'] ?>" <?= $filters['branch_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="f_type"><?= e(t('bills.filter_type')) ?></label>
                <select class="form-select form-select-sm" id="f_type" name="payment_type">
                    <option value=""><?= e(t('common.all_cash_card')) ?></option>
                    <option value="cash" <?= $filters['payment_type'] === 'cash' ? 'selected' : '' ?>><?= e(t('common.cash')) ?></option>
                    <option value="card" <?= $filters['payment_type'] === 'card' ? 'selected' : '' ?>><?= e(t('common.card')) ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="f_q"><?= e(t('bills.filter_filename')) ?></label>
                <input type="text" class="form-control form-control-sm" id="f_q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="<?= e(t('bills.search_placeholder')) ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?= e(t('common.filter')) ?></button>
                <a href="<?= url('owner/trash.php') ?>" class="btn btn-sm btn-light"><?= e(t('common.reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (!$result['rows']) : ?>
            <div class="empty-state">
                <i class="bi bi-trash"></i>
                <p class="mb-1"><?= e(t('trash.empty')) ?></p>
                <p class="mb-0 text-muted"><?= e(t('trash.empty_hint')) ?></p>
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
                        <th><?= e(t('col.deleted_at')) ?></th>
                        <th><?= e(t('col.purge_in')) ?></th>
                        <th><?= e(t('col.copies_stored')) ?></th>
                        <th class="pe-3 text-end"><?= e(t('common.actions')) ?></th>
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
                                <span class="badge bg-secondary"><?= e(t('trash.erasing')) ?></span>
                            <?php else : ?>
                                <span class="text-muted"><?= e(tn('common.days_left', $remaining)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e(t('trash.storage_' . ((string) $bill['storage_status']))) ?></td>
                        <td class="pe-3 text-end">
                            <form method="post" action="<?= url('owner/trash.php?action=restore') ?>" class="d-inline"
                                      data-confirm="<?= e(t('trash.restore_confirm', ['id' => $bill['id']])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $bill['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-light text-success" title="<?= e(t('trash.restore')) ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
                            </form>
                            <form method="post" action="<?= url('owner/trash.php?action=purge') ?>" class="d-inline"
                                      data-confirm="<?= e(t('trash.erase_confirm', ['id' => $bill['id']])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $bill['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-light text-danger" title="<?= e(t('trash.erase_forever')) ?>"><i class="bi bi-x-octagon"></i></button>
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
