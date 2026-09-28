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
    $todayParam = (string) get('today', '');
    redirect('owner/dashboard.php' . ($todayParam ? '?today=' . urlencode($todayParam) : ''));
}

$stats = Branch::dashboardStats();
$today = (string) get('today', date('Y-m-d'));
$dailyStatus = Branch::dailyUploadStatus($today);
$recentBills = Bill::recent([], 6);
$uploadsByBranch = Bill::uploadsByBranch(6);

$pageTitle = t('owner.dashboard.title');
$pageSubtitle = t('owner.dashboard.subtitle');
$activeMenu = 'dashboard';

ob_start();
?>

<div class="row g-3 mb-3">
    <!-- KPI cards -->
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-burgundy-subtle"><i class="bi bi-buildings text-burgundy"></i></div>
                <div>
                    <div class="kpi-value"><?= (int)$stats['total_branches'] ?></div>
                    <div class="kpi-label"><?= e(t('owner.kpi.total_branches')) ?></div>
                    <div class="kpi-sub"><?= e(t('owner.kpi.total_branches_sub', ['n' => (int) $stats['active_branches']])) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-gold-subtle"><i class="bi bi-person-badge text-gold"></i></div>
                <div>
                    <div class="kpi-value"><?= (int)$stats['total_admins'] ?></div>
                    <div class="kpi-label"><?= e(t('owner.kpi.branch_admins')) ?></div>
                    <div class="kpi-sub"><?= e(t('owner.kpi.branch_admins_sub', ['n' => (int) $stats['active_admins']])) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-cash-subtle"><i class="bi bi-file-earmark-pdf text-cash"></i></div>
                <div>
                    <div class="kpi-value"><?= (int)$stats['today_bills'] ?></div>
                    <div class="kpi-label"><?= e(t('owner.kpi.todays_uploads')) ?></div>
                    <div class="kpi-sub"><?= e(t('owner.kpi.cash_card', ['cash' => (int) $stats['today_cash'], 'card' => (int) $stats['today_card']])) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-muted-subtle"><i class="bi bi-archive text-muted"></i></div>
                <div>
                    <div class="kpi-value"><?= (int)$stats['total_bills'] ?></div>
                    <div class="kpi-label"><?= e(t('owner.kpi.stored_documents')) ?></div>
                    <div class="kpi-sub"><?= e(t('owner.kpi.stored_documents_sub')) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Daily upload status -->
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i><?= e(t('owner.daily_status.title')) ?></h5>
                <form method="get" action="<?= url('owner/dashboard.php') ?>" class="d-flex align-items-center gap-2">
                    <input type="date" class="form-control form-control-sm" name="today" value="<?= e($today) ?>" onchange="this.form.submit()" aria-label="<?= e(t('owner.daily_status.select_date')) ?>">
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (!$dailyStatus) : ?>
                    <div class="empty-state">
                        <i class="bi bi-buildings"></i>
                        <p class="mb-1"><?= e(t('branches.empty')) ?></p>
                        <p class="mb-2 text-muted"><?= e(t('branches.empty_hint')) ?></p>
                        <a href="<?= url('owner/branches.php?action=create') ?>" class="btn btn-sm btn-primary"><?= e(t('branches.create')) ?></a>
                    </div>
                <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3"><?= e(t('common.branch')) ?></th>
                                <th class="text-center"><?= e(t('common.cash')) ?></th>
                                <th class="text-center"><?= e(t('common.card')) ?></th>
                                <th><?= e(t('col.last_upload')) ?></th>
                                <th class="pe-3"><?= e(t('common.status')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dailyStatus as $b) :
                            $cashCount = (int)$b['cash_count'];
                            $cardCount = (int)$b['card_count'];
                            if ($cashCount > 0 && $cardCount > 0) {
                                $statusKey   = 'owner.status.uploaded';
                                $statusClass = 'status-uploaded';
                                $statusIcon  = 'bi-check-circle-fill';
                            } elseif ($cashCount > 0 || $cardCount > 0) {
                                $statusKey   = 'owner.status.partial';
                                $statusClass = 'status-partial';
                                $statusIcon  = 'bi-exclamation-triangle-fill';
                            } else {
                                $statusKey   = 'owner.status.no_upload';
                                $statusClass = 'status-empty';
                                $statusIcon  = 'bi-x-circle-fill';
                            }
                        ?>
                            <tr>
                                <td class="ps-3">
                                    <span class="fw-semibold"><?= e($b['branch_name']) ?></span>
                                    <div class="text-muted small"><?= e($b['branch_code']) ?></div>
                                </td>
                                <td class="text-center"><span class="fw-semibold text-cash"><?= $cashCount ?></span></td>
                                <td class="text-center"><span class="fw-semibold text-card"><?= $cardCount ?></span></td>
                                <td class="text-muted small"><?= e(format_datetime($b['last_upload'] ?? null)) ?></td>
                                <td class="pe-3"><span class="status-pill <?= $statusClass ?>"><i class="bi <?= $statusIcon ?> me-1"></i><?= e(t($statusKey)) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Uploads by branch chart -->
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i><?= e(t('owner.by_branch.title')) ?></h5>
            </div>
            <div class="card-body">
                <?php if (!$uploadsByBranch) : ?>
                    <div class="empty-state"><i class="bi bi-bar-chart"></i><p class="mb-0"><?= e(t('owner.by_branch.no_data')) ?></p></div>
                <?php else :
                    $max = max(array_map(fn($r) => (int)$r['total'], $uploadsByBranch));
                    $max = $max > 0 ? $max : 1;
                ?>
                <div class="chart-list">
                    <?php foreach ($uploadsByBranch as $r) : ?>
                    <div class="chart-row">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-semibold"><?= e($r['branch_name']) ?></span>
                            <span class="small text-muted"><?= e(tn('owner.by_branch.docs', (int) $r['total'])) ?></span>
                        </div>
                        <div class="progress" style="height:8px">
                            <div class="progress-bar bg-burgundy" style="width: <?= round(((int)$r['total'] / $max) * 100) ?>%"></div>
                        </div>
                        <div class="d-flex gap-3 mt-1 small text-muted">
                            <span class="text-cash"><i class="bi bi-circle-fill me-1"></i><?= e(t('common.cash')) ?> <?= (int)$r['cash'] ?></span>
                            <span class="text-card"><i class="bi bi-circle-fill me-1"></i><?= e(t('common.card')) ?> <?= (int)$r['card'] ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent uploads -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i><?= e(t('owner.recent_uploads.title')) ?></h5>
                <a href="<?= url('owner/bills.php') ?>" class="btn btn-sm btn-outline-primary"><?= e(t('owner.recent_uploads.view_all')) ?></a>
            </div>
            <div class="card-body p-0">
                <?php if (!$recentBills) : ?>
                    <div class="empty-state">
                        <i class="bi bi-folder2-open"></i>
                        <p class="mb-0"><?= e(t('owner.recent_uploads.empty')) ?></p>
                    </div>
                <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3"><?= e(t('col.document')) ?></th>
                                <th><?= e(t('common.branch')) ?></th>
                                <th><?= e(t('col.type')) ?></th>
                                <th><?= e(t('col.business_date')) ?></th>
                                <th><?= e(t('col.uploaded_by')) ?></th>
                                <th><?= e(t('col.uploaded_at')) ?></th>
                                <th class="pe-3"><?= e(t('common.actions')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentBills as $bill) : ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger fs-5"></i>
                                        <span class="text-truncate d-inline-block" style="max-width:230px" title="<?= e($bill['original_filename']) ?>"><?= e($bill['original_filename']) ?></span>
                                    </div>
                                </td>
                                <td class="small"><?= e($bill['branch_name']) ?></td>
                                <td><?= payment_type_badge($bill['payment_type']) ?></td>
                                <td class="small"><?= e(format_date($bill['business_date'])) ?></td>
                                <td class="small"><?= e($bill['uploaded_by_name']) ?></td>
                                <td class="small text-muted"><?= e(format_datetime($bill['uploaded_at'])) ?></td>
                                <td class="pe-3">
                                    <a href="<?= url('view.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
                                    <a href="<?= url('download.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light" title="<?= e(t('common.download')) ?>"><i class="bi bi-download"></i></a>
                                    <form method="post" action="<?= url('owner/dashboard.php?action=delete') ?>" class="d-inline"
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