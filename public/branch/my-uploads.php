<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_branch_admin();
$user = current_user();

$branchId = (int) ($user['branch_id'] ?? 0);

$filters = [
    'payment_type'  => in_array(get('payment_type'), ['cash', 'card'], true) ? get('payment_type') : null,
    'business_date' => get('business_date') ?: null,
    'q'             => trim((string) get('q', '')),
];

$page = max(1, (int) get('page', 1));
$result = Bill::forBranch($branchId, $filters, $page, 15);

$qs = [];
foreach ($filters as $k => $v) {
    if ($v) $qs[$k] = $v;
}
$baseUrl = url('branch/my-uploads.php') . (count($qs) ? '?' . http_build_query($qs) : '');

$pageTitle = t('myuploads.title');
$pageSubtitle = t('myuploads.subtitle');
$activeMenu = 'my-uploads';

ob_start();
?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('branch/my-uploads.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="m_type"><?= e(t('myuploads.payment_type')) ?></label>
                <select class="form-select form-select-sm" id="m_type" name="payment_type">
                    <option value=""><?= e(t('common.all_types')) ?></option>
                    <option value="cash" <?= $filters['payment_type'] === 'cash' ? 'selected' : '' ?>><?= e(t('common.cash')) ?></option>
                    <option value="card" <?= $filters['payment_type'] === 'card' ? 'selected' : '' ?>><?= e(t('common.card')) ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="m_date"><?= e(t('upload.business_date')) ?></label>
                <input type="date" class="form-control form-control-sm" id="m_date" name="business_date" value="<?= e($filters['business_date'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="m_q"><?= e(t('common.filename')) ?></label>
                <input type="text" class="form-control form-control-sm" id="m_q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="<?= e(t('common.search')) ?>…">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?= e(t('common.filter')) ?></button>
                <a href="<?= url('branch/my-uploads.php') ?>" class="btn btn-sm btn-light"><?= e(t('common.reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-folder2-open me-2"></i><?= e(t('myuploads.count', ['n' => $result['count']])) ?></h5>
        <a href="<?= url('branch/upload.php') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i><?= e(t('myuploads.upload_new')) ?></a>
    </div>
    <div class="card-body p-0">
        <?php if (!$result['rows']) : ?>
            <div class="empty-state">
                <i class="bi bi-file-earmark-pdf"></i>
                <p class="mb-1"><?= e(t('bdash.empty_title')) ?></p>
                <p class="mb-3 text-muted"><?= e(t('bdash.empty_hint')) ?></p>
                <a href="<?= url('branch/upload.php') ?>" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i><?= e(t('bdash.upload_bill')) ?></a>
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
                        <th><?= e(t('common.status')) ?></th>
                        <th class="pe-3 text-end"><?= e(t('common.actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $bill) : ?>
                    <tr>
                        <td class="ps-3">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-file-earmark-pdf-fill text-danger fs-5"></i>
                                <div class="text-truncate" style="max-width:260px" title="<?= e($bill['original_filename']) ?>">
                                    <?= e($bill['original_filename']) ?>
                                    <?php if (!empty($bill['description'])) : ?>
                                        <i class="bi bi-chat-left-text text-muted small ms-1" title="<?= e($bill['description']) ?>"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= payment_type_badge($bill['payment_type']) ?></td>
                        <td class="small"><?= e(format_date($bill['business_date'])) ?></td>
                        <td class="small text-muted"><?= e(format_bytes((int) $bill['file_size'])) ?></td>
                        <td class="small text-muted"><?= e(format_datetime($bill['uploaded_at'])) ?></td>
                        <td><span class="badge bg-success-subtle text-success"><?= e(t('common.available')) ?></span></td>
                        <td class="pe-3 text-end">
                            <a href="<?= url('view.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted"><?= e(t('myuploads.docs', ['n' => $result['count']])) ?> · <?= e(t('common.page_of', ['page' => $result['page'], 'pages' => $result['pages']])) ?></span>
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