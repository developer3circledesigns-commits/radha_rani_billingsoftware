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
        log_activity((int) $user['id'], $bill['branch_id'], 'BILL_DELETED', 'bill', $billId, 'Deleted bill #' . $billId . ' (' . $bill['original_filename'] . ')');
        flash_set('success', 'Bill moved to Recently Deleted. It can be restored for ' . Bill::retentionDays() . ' day(s).');
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
        'label' => $d->format('d M'),
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

$pageTitle = 'Upload Activity';
$pageSubtitle = 'Daily document upload monitoring';
$activeMenu = 'upload-activity';

ob_start();
?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('owner/upload-activity.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="ua_from">From</label>
                <input type="date" class="form-control form-control-sm" id="ua_from" name="from" value="<?= e($filters['from']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="ua_to">To</label>
                <input type="date" class="form-control form-control-sm" id="ua_to" name="to" value="<?= e($filters['to'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="ua_branch">Branch</label>
                <select class="form-select form-select-sm" id="ua_branch" name="branch_id">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $b) : ?>
                        <option value="<?= $b['id'] ?>" <?= $filters['branch_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="ua_type">Type</label>
                <select class="form-select form-select-sm" id="ua_type" name="payment_type">
                    <option value="">All Types</option>
                    <option value="cash" <?= $filters['payment_type'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="card" <?= $filters['payment_type'] === 'card' ? 'selected' : '' ?>>Card</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter Activity</button>
                <a href="<?= url('owner/upload-activity.php') ?>" class="btn btn-sm btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    <!-- Chart -->
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i>Daily Uploads</h5>
            </div>
            <div class="card-body">
                <?php if (!$series) : ?>
                    <div class="empty-state"><i class="bi bi-bar-chart"></i><p class="mb-0">No data for the selected range.</p></div>
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
                    <span class="text-cash"><i class="bi bi-square-fill me-1"></i>Cash</span>
                    <span class="text-card"><i class="bi bi-square-fill me-1"></i>Card</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent uploads list -->
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i><?= count($uploads) ?> Recent Uploads</h5>
            </div>
            <div class="card-body p-0">
                <?php if (!$uploads) : ?>
                    <div class="empty-state"><i class="bi bi-arrow-up-circle"></i><p class="mb-0">No upload activity in this range.</p></div>
                <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Document</th>
                                <th>Branch</th>
                                <th>Type</th>
                                <th>Uploaded By</th>
                                <th>When</th>
                                <th class="pe-3 text-end">Actions</th>
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
                                    <a href="<?= url('view.php') ?>?id=<?= $u['id'] ?>" class="btn btn-xs btn-light"><i class="bi bi-eye"></i></a>
                                    <a href="<?= url('download.php') ?>?id=<?= $u['id'] ?>" class="btn btn-xs btn-light"><i class="bi bi-download"></i></a>
                                    <form method="post" action="<?= url('owner/upload-activity.php?action=delete') ?>" class="d-inline"
                                          data-confirm="Delete bill #<?= $u['id'] ?> (<?= e($u['original_filename']) ?>)? It will move to Recently Deleted, where it stays restorable for <?= Bill::retentionDays() ?> day(s).">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-xs btn-light text-danger" title="Delete"><i class="bi bi-trash"></i></button>
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