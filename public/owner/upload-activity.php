<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();
$user = current_user();

// ------------------------------------------------------------------
// DELETE (soft delete a bill)
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
    $keep = [];
    foreach (['branch_id', 'payment_type', 'from', 'to'] as $k) {
        $v = get($k);
        if ($v !== '' && $v !== null) $keep[$k] = $v;
    }
    redirect('owner/upload-activity.php' . ($keep ? '?' . http_build_query($keep) : ''));
}

$filters = [
    'branch_id'     => (int) get('branch_id', 0) ?: null,
    'payment_type'  => in_array(get('payment_type'), ['cash', 'card'], true) ? get('payment_type') : null,
    'from'          => get('from') ?: null,
    'to'            => get('to') ?: null,
];

if (!$filters['from']) {
    $filters['from'] = date('Y-m-d', strtotime('-14 days'));
}

$uploads = Bill::recent($filters, 100);
$branches = Branch::all();
$daily = Bill::countsByDay($filters['from'], $filters['to'] ?: date('Y-m-d'));

// Build a continuous date series for the chart
$dayMap = [];
if ($daily) {
    foreach ($daily as $d) {
        $dayMap[$d['day']] = $d;
    }
}
$dateCursor = new DateTime($filters['from']);
$dateEnd = new DateTime($filters['to'] ?: date('Y-m-d'));
$series = [];
for ($d = clone $dateCursor; $d <= $dateEnd; $d->modify('+1 day')) {
    $key = $d->format('Y-m-d');
    $series[] = [
        // Locale axis label: "28 Sep" in English, "28. Sep." in German. date()
        // always emits English month names, so this goes through Lang.
        'label' => Lang::date($key, 'date_axis_format'),
        'cash'  => (int) ($dayMap[$key]['cash'] ?? 0),
        'card'  => (int) ($dayMap[$key]['card'] ?? 0),
    ];
}
$maxDay = max(array_map(fn($s) => $s['cash'] + $s['card'], $series));
if ($maxDay < 1) $maxDay = 1;

$qs = [];
foreach ($filters as $k => $v) {
    if ($v) $qs[$k] = $v;
}

$pageTitle = t('activity.title');
$pageSubtitle = t('activity.subtitle');
$activeMenu = 'upload-activity';

ob_start();
?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('owner/upload-activity.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="ua_from"><?= e(t('activity.from')) ?></label>
                <input type="date" class="form-control form-control-sm" id="ua_from" name="from" value="<?= e($filters['from']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="ua_to"><?= e(t('activity.to')) ?></label>
                <input type="date" class="form-control form-control-sm" id="ua_to" name="to" value="<?= e($filters['to'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="ua_branch"><?= e(t('bills.filter_branch')) ?></label>
                <select class="form-select form-select-sm" id="ua_branch" name="branch_id">
                    <option value=""><?= e(t('common.all_branches')) ?></option>
                    <?php foreach ($branches as $b) : ?>
                        <option value="<?= $b['id'] ?>" <?= $filters['branch_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="ua_type"><?= e(t('bills.filter_type')) ?></label>
                <select class="form-select form-select-sm" id="ua_type" name="payment_type">
                    <option value=""><?= e(t('common.all_types')) ?></option>
                    <option value="cash" <?= $filters['payment_type'] === 'cash' ? 'selected' : '' ?>><?= e(t('common.cash')) ?></option>
                    <option value="card" <?= $filters['payment_type'] === 'card' ? 'selected' : '' ?>><?= e(t('common.card')) ?></option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?= e(t('activity.filter')) ?></button>
                <a href="<?= url('owner/upload-activity.php') ?>" class="btn btn-sm btn-light"><?= e(t('common.reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    <!-- Chart -->
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i><?= e(t('activity.daily_uploads')) ?></h5>
            </div>
            <div class="card-body">
                <?php if (!$series) : ?>
                    <div class="empty-state"><i class="bi bi-bar-chart"></i><p class="mb-0"><?= e(t('activity.no_data_range')) ?></p></div>
                <?php else : ?>
                <div class="chart-bars d-flex align-items-end justify-content-between gap-1" style="height:220px">
                    <?php foreach ($series as $s) : ?>
                    <div class="chart-bar-col flex-grow-1 d-flex flex-column justify-content-end align-items-center gap-1" title="<?= e($s['label']) ?>">
                        <span class="chart-bar-seg bg-cash-bar" style="height: <?= round(($s['cash'] / $maxDay) * 160) ?>px"></span>
                        <span class="chart-bar-seg bg-card-bar" style="height: <?= round(($s['card'] / $maxDay) * 160) ?>px"></span>
                        <span class="chart-bar-label small"><?= e($s['label']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-3 mt-2 small text-muted">
                    <span class="text-cash"><i class="bi bi-square-fill me-1"></i><?= e(t('common.cash')) ?></span>
                    <span class="text-card"><i class="bi bi-square-fill me-1"></i><?= e(t('common.card')) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent uploads list -->
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i><?= e(tn('activity.recent_uploads', count($uploads))) ?></h5>
            </div>
            <div class="card-body p-0">
                <?php if (!$uploads) : ?>
                    <div class="empty-state"><i class="bi bi-arrow-up-circle"></i><p class="mb-0"><?= e(t('activity.no_uploads_range')) ?></p></div>
                <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3"><?= e(t('col.document')) ?></th>
                                <th><?= e(t('common.branch')) ?></th>
                                <th><?= e(t('col.type')) ?></th>
                                <th><?= e(t('col.uploaded_by')) ?></th>
                                <th><?= e(t('col.when')) ?></th>
                                <th class="pe-3 text-end"><?= e(t('common.actions')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($uploads as $u) : ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger"></i>
                                        <span class="text-truncate d-inline-block" style="max-width:200px" title="<?= e($u['original_filename']) ?>"><?= e($u['original_filename']) ?></span>
                                    </div>
                                </td>
                                <td class="small"><?= e($u['branch_name']) ?></td>
                                <td><?= payment_type_badge($u['payment_type']) ?></td>
                                <td class="small"><?= e($u['uploaded_by_name']) ?></td>
                                <td class="small text-muted"><?= e(format_datetime($u['uploaded_at'])) ?></td>
                                <td class="pe-3 text-end">
                                    <a href="<?= url('view.php') ?>?id=<?= $u['id'] ?>" class="btn btn-xs btn-light" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
                                    <a href="<?= url('download.php') ?>?id=<?= $u['id'] ?>" class="btn btn-xs btn-light" title="<?= e(t('common.download')) ?>"><i class="bi bi-download"></i></a>
                                    <form method="post" action="<?= url('owner/upload-activity.php?action=delete') ?>" class="d-inline"
                                          data-confirm="<?= e(t('bills.delete_confirm', [
                                              'id'       => $u['id'],
                                              'filename' => $u['original_filename'],
                                              'days'     => Bill::retentionDays(),
                                          ])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-xs btn-light text-danger" title="<?= e(t('common.delete')) ?>"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';