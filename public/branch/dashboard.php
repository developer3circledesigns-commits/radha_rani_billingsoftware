<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_branch_admin();
$user = current_user();

$branch = Branch::find((int) $user['branch_id']);
if (!$branch) {
    flash_set('danger', t('bdash.no_branch'));
    logout_user();
    redirect('login.php');
}

$branchId = (int) $branch['id'];
$today = date('Y-m-d');
$todayCash = (int) Database::fetch(
    "SELECT COUNT(*) AS c FROM bills WHERE branch_id = ? AND status = 'active' AND payment_type = 'cash' AND DATE(uploaded_at) = ?",
    [$branchId, $today]
)['c'];
$todayCard = (int) Database::fetch(
    "SELECT COUNT(*) AS c FROM bills WHERE branch_id = ? AND status = 'active' AND payment_type = 'card' AND DATE(uploaded_at) = ?",
    [$branchId, $today]
)['c'];
$todayTotal = $todayCash + $todayCard;

$latest = Bill::latestByBranch($branchId);
$recent = Bill::forBranch($branchId, [], 1, 8);
$totalCash = Bill::countActiveByBranch($branchId, 'cash');
$totalCard = Bill::countActiveByBranch($branchId, 'card');
$cashRequired = Setting::get('daily_cash_required', '1') === '1';
$cardRequired = Setting::get('daily_card_required', '1') === '1';

if ($todayCash === 0 && $todayCard === 0) {
    $statusLabel = t('owner.status.no_upload');
    $statusClass = 'status-empty';
    $statusIcon = 'bi-x-circle-fill';
} elseif (($cashRequired && $todayCash === 0) || ($cardRequired && $todayCard === 0)) {
    $statusLabel = t('owner.status.partial');
    $statusClass = 'status-partial';
    $statusIcon = 'bi-exclamation-triangle-fill';
} else {
    $statusLabel = t('owner.status.uploaded');
    $statusClass = 'status-uploaded';
    $statusIcon = 'bi-check-circle-fill';
}

$pageTitle = t('nav.dashboard');
$pageSubtitle = $branch['branch_name'];
$activeMenu = 'dashboard';

ob_start();
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-cash-subtle"><i class="bi bi-cash-coin text-cash"></i></div>
                <div>
                    <div class="kpi-value"><?= $todayCash ?></div>
                    <div class="kpi-label"><?= e(t('bdash.cash_today')) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-card-subtle"><i class="bi bi-credit-card text-card"></i></div>
                <div>
                    <div class="kpi-value"><?= $todayCard ?></div>
                    <div class="kpi-label"><?= e(t('bdash.card_today')) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-gold-subtle"><i class="bi bi-files text-gold"></i></div>
                <div>
                    <div class="kpi-value"><?= $todayTotal ?></div>
                    <div class="kpi-label"><?= e(t('bdash.total_today')) ?></div>
                    <div class="kpi-sub"><?= e(t('bdash.stored_total', ['n' => $totalCash + $totalCard])) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="status-pill <?= $statusClass ?> mb-2"><i class="bi <?= $statusIcon ?> me-1"></i><?= $statusLabel ?></div>
                <div class="kpi-label"><?= e(t('bdash.upload_status')) ?></div>
                <div class="kpi-sub"><?= $latest
                    ? e(t('bdash.last_at', ['time' => format_datetime($latest['uploaded_at'])]))
                    : e(t('bdash.no_uploads_yet')) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i><?= e(t('owner.recent_uploads.title')) ?></h5>
                <a href="<?= url('branch/my-uploads.php') ?>" class="btn btn-sm btn-outline-primary"><?= e(t('owner.recent_uploads.view_all')) ?></a>
            </div>
            <div class="card-body p-0">
                <?php if (!$recent['rows']) : ?>
                    <div class="empty-state">
                        <i class="bi bi-file-earmark-pdf"></i>
                        <p class="mb-1"><?= e(t('bdash.empty_title')) ?></p>
                        <p class="mb-3 text-muted"><?= e(t('bdash.empty_hint')) ?></p>
                        <a href="<?= url('branch/upload.php') ?>" class="btn btn-sm btn-primary"><?= e(t('bdash.upload_bill')) ?></a>
                    </div>
                <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3"><?= e(t('col.document')) ?></th>
                                <th><?= e(t('col.type')) ?></th>
                                <th><?= e(t('col.business_date')) ?></th>
                                <th><?= e(t('col.file_size')) ?></th>
                                <th><?= e(t('col.uploaded_at')) ?></th>
                                <th class="pe-3 text-end"><?= e(t('common.actions')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent['rows'] as $bill) : ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger"></i>
                                        <span class="text-truncate d-inline-block" style="max-width:220px" title="<?= e($bill['original_filename']) ?>"><?= e($bill['original_filename']) ?></span>
                                    </div>
                                </td>
                                <td><?= payment_type_badge($bill['payment_type']) ?></td>
                                <td class="small"><?= e(format_date($bill['business_date'])) ?></td>
                                <td class="small text-muted"><?= e(format_bytes((int) $bill['file_size'])) ?></td>
                                <td class="small text-muted"><?= e(format_datetime($bill['uploaded_at'])) ?></td>
                                <td class="pe-3 text-end">
                                    <a href="<?= url('view.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
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

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-building me-2"></i><?= e(t('bdash.my_branch')) ?></h5></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted"><?= e(t('common.branch_code')) ?></dt><dd class="col-7 text-end"><span class="badge bg-light text-dark border"><?= e($branch['branch_code']) ?></span></dd>
                    <dt class="col-5 text-muted"><?= e(t('common.name')) ?></dt><dd class="col-7 text-end"><?= e($branch['branch_name']) ?></dd>
                    <dt class="col-5 text-muted"><?= e(t('common.phone')) ?></dt><dd class="col-7 text-end"><?= e($branch['phone'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted"><?= e(t('common.email')) ?></dt><dd class="col-7 text-end text-break"><?= e($branch['email'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted"><?= e(t('common.status')) ?></dt><dd class="col-7 text-end">
                        <?php if ($branch['status'] === 'active') : ?><span class="badge bg-success-subtle text-success"><?= e(t('common.active')) ?></span>
                        <?php else : ?><span class="badge bg-secondary-subtle text-secondary"><?= e(t('common.inactive')) ?></span><?php endif; ?>
                    </dd>
                </dl>
                <hr>
                <div class="d-flex justify-content-between small">
                    <span class="text-muted"><?= e(t('bdash.total_cash_pdfs')) ?></span><strong class="text-cash"><?= $totalCash ?></strong>
                </div>
                <div class="d-flex justify-content-between small mt-1">
                    <span class="text-muted"><?= e(t('bdash.total_card_pdfs')) ?></span><strong class="text-card"><?= $totalCard ?></strong>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-journal-check me-2"></i><?= e(t('bdash.daily_requirement')) ?></h5></div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <i class="bi bi-cash-coin text-cash fs-4"></i>
                    <div class="flex-grow-1">
                        <div class="small fw-semibold"><?= e(t('upload.cash_bill')) ?></div>
                        <div class="small text-muted"><?= e($cashRequired ? t('bdash.expected_daily') : t('bdash.optional')) ?> · <?= e(t('bdash.uploaded_today', ['n' => $todayCash])) ?></div>
                    </div>
                    <?php if ($todayCash > 0) : ?><i class="bi bi-check-circle-fill text-success"></i>
                    <?php else : ?><i class="bi bi-circle text-muted"></i><?php endif; ?>
                </div>
                <hr>
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-credit-card text-card fs-4"></i>
                    <div class="flex-grow-1">
                        <div class="small fw-semibold"><?= e(t('upload.card_bill')) ?></div>
                        <div class="small text-muted"><?= e($cardRequired ? t('bdash.expected_daily') : t('bdash.optional')) ?> · <?= e(t('bdash.uploaded_today', ['n' => $todayCard])) ?></div>
                    </div>
                    <?php if ($todayCard > 0) : ?><i class="bi bi-check-circle-fill text-success"></i>
                    <?php else : ?><i class="bi bi-circle text-muted"></i><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';