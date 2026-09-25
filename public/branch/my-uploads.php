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

$pageTitle = 'My Uploads';
$pageSubtitle = 'Documents uploaded by your branch';
$activeMenu = 'my-uploads';

ob_start();
?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('branch/my-uploads.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="m_type">Payment Type</label>
                <select class="form-select form-select-sm" id="m_type" name="payment_type">
                    <option value="">All Types</option>
                    <option value="cash" <?= $filters['payment_type'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="card" <?= $filters['payment_type'] === 'card' ? 'selected' : '' ?>>Card</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="m_date">Business Date</label>
                <input type="date" class="form-control form-control-sm" id="m_date" name="business_date" value="<?= e($filters['business_date'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="m_q">Filename</label>
                <input type="text" class="form-control form-control-sm" id="m_q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search…">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= url('branch/my-uploads.php') ?>" class="btn btn-sm btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-folder2-open me-2"></i><?= $result['count'] ?> Uploaded Document<?= $result['count'] === 1 ? '' : 's' ?></h5>
        <a href="<?= url('branch/upload.php') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Upload New</a>
    </div>
    <div class="card-body p-0">
        <?php if (!$result['rows']) : ?>
            <div class="empty-state">
                <i class="bi bi-file-earmark-pdf"></i>
                <p class="mb-1">No bills uploaded yet.</p>
                <p class="mb-3 text-muted">Upload today's cash or card bill PDF.</p>
                <a href="<?= url('branch/upload.php') ?>" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i>Upload Bill</a>
            </div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Document</th>
                        <th>Type</th>
                        <th>Business Date</th>
                        <th>File Size</th>
                        <th>Uploaded At</th>
                        <th>Status</th>
                        <th class="pe-3 text-end">Actions</th>
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
                        <td><span class="badge bg-success-subtle text-success">Available</span></td>
                        <td class="pe-3 text-end">
                            <a href="<?= url('view.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light" title="View"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted"><?= $result['count'] ?> document(s) · Page <?= $result['page'] ?> of <?= $result['pages'] ?></span>
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