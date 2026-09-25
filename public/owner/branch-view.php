<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();

$id = (int) get('id', 0);
$branch = $id ? Branch::find($id) : null;

if (!$branch) {
    http_response_code(404);
    require APP_PATH . '/views/errors/404.php';
    exit;
}

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
    redirect('owner/branch-view.php?id=' . $branch['id']);
}

$admins = User::branchAdmins($branch['id']);
$cashCount = Bill::countActiveByBranch($branch['id'], 'cash');
$cardCount = Bill::countActiveByBranch($branch['id'], 'card');
$latest = Bill::latestByBranch($branch['id']);
$todayCash = (int) Database::fetch(
    "SELECT COUNT(*) AS c FROM bills WHERE branch_id = ? AND status = 'active' AND payment_type = 'cash' AND DATE(uploaded_at) = CURDATE()",
    [$branch['id']]
)['c'];
$todayCard = (int) Database::fetch(
    "SELECT COUNT(*) AS c FROM bills WHERE branch_id = ? AND status = 'active' AND payment_type = 'card' AND DATE(uploaded_at) = CURDATE()",
    [$branch['id']]
)['c'];
$recentBills = Bill::search(['branch_id' => $branch['id']], 1, 8);

$pageTitle = 'Branch Details';
$pageSubtitle = $branch['branch_name'];
$activeMenu = 'branches';

ob_start();
?>
<div class="d-flex flex-wrap gap-2 mb-4">
    <a href="<?= url('owner/branches.php') ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i>All Branches</a>
    <a href="<?= url('owner/branches.php?action=edit&id=' . $branch['id']) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
    <?php if ($branch['status'] === 'active') : ?>
        <a href="<?= url('owner/bills.php') ?>?branch_id=<?= $branch['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-files me-1"></i>View All Bills</a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-cash-subtle"><i class="bi bi-cash-coin text-cash"></i></div>
                <div>
                    <div class="kpi-value"><?= $cashCount ?></div>
                    <div class="kpi-label">Cash PDFs</div>
                    <div class="kpi-sub"><?= $todayCash ?> uploaded today</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-card-subtle"><i class="bi bi-credit-card text-card"></i></div>
                <div>
                    <div class="kpi-value"><?= $cardCount ?></div>
                    <div class="kpi-label">Card PDFs</div>
                    <div class="kpi-sub"><?= $todayCard ?> uploaded today</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-gold-subtle"><i class="bi bi-person-badge text-gold"></i></div>
                <div>
                    <div class="kpi-value"><?= count($admins) ?></div>
                    <div class="kpi-label">Branch Admins</div>
                    <div class="kpi-sub">assigned to this branch</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-muted-subtle"><i class="bi bi-clock text-muted"></i></div>
                <div>
                    <div class="kpi-label">Latest Upload</div>
                    <div class="kpi-sub"><?= e(format_datetime($latest['uploaded_at'] ?? null)) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Branch Information</h5></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-4 text-muted">Branch Code</dt><dd class="col-8"><span class="badge bg-light text-dark border"><?= e($branch['branch_code']) ?></span></dd>
                    <dt class="col-4 text-muted">Name</dt><dd class="col-8"><?= e($branch['branch_name']) ?></dd>
                    <dt class="col-4 text-muted">Address</dt><dd class="col-8"><?= e($branch['address'] ?? '—') ?></dd>
                    <dt class="col-4 text-muted">Phone</dt><dd class="col-8"><?= e($branch['phone'] ?? '—') ?></dd>
                    <dt class="col-4 text-muted">Email</dt><dd class="col-8"><?= e($branch['email'] ?? '—') ?></dd>
                    <dt class="col-4 text-muted">Status</dt><dd class="col-8">
                        <?php if ($branch['status'] === 'active') : ?><span class="badge bg-success-subtle text-success">Active</span>
                        <?php else : ?><span class="badge bg-secondary-subtle text-secondary">Inactive</span><?php endif; ?>
                    </dd>
                    <dt class="col-4 text-muted">Created</dt><dd class="col-8"><?= e(format_datetime($branch['created_at'])) ?></dd>
                    <dt class="col-4 text-muted">Updated</dt><dd class="col-8"><?= e(format_datetime($branch['updated_at'])) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Admins</h5>
                <a href="<?= url('owner/admins.php?action=create&branch_id=' . $branch['id']) ?>" class="btn btn-sm btn-outline-primary">Add Admin</a>
            </div>
            <div class="card-body p-0">
                <?php if (!$admins) : ?>
                    <div class="empty-state py-4">
                        <i class="bi bi-person-badge"></i>
                        <p class="mb-0 text-muted small">No branch admins assigned yet.</p>
                    </div>
                <?php else : ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($admins as $a) : ?>
                    <li class="list-group-item d-flex align-items-center gap-2 py-2">
                        <span class="avatar avatar-sm"><?= e(strtoupper(substr($a['name'], 0, 1))) ?></span>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= e($a['name']) ?></div>
                            <div class="text-muted small"><?= e($a['email']) ?></div>
                        </div>
                        <a href="<?= url('owner/admins.php?action=edit&id=' . $a['id']) ?>" class="btn btn-xs btn-light"><i class="bi bi-pencil"></i></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Bills</h5>
                <a href="<?= url('owner/bills.php') ?>?branch_id=<?= $branch['id'] ?>" class="btn btn-sm btn-light">View all</a>
            </div>
            <div class="card-body p-0">
                <?php if (!$recentBills['rows']) : ?>
                    <div class="empty-state py-4">
                        <i class="bi bi-file-earmark-pdf"></i>
                        <p class="mb-0">No bills uploaded for this branch yet.</p>
                    </div>
                <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Document</th>
                                <th>Type</th>
                                <th>Business Date</th>
                                <th>Uploaded By</th>
                                <th>Uploaded At</th>
                                <th class="pe-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentBills['rows'] as $bill) : ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger"></i>
                                        <span class="text-truncate"><?= e($bill['original_filename']) ?></span>
                                    </div>
                                </td>
                                <td><?= payment_type_badge($bill['payment_type']) ?></td>
                                <td class="small"><?= e(format_date($bill['business_date'])) ?></td>
                                <td class="small"><?= e($bill['uploaded_by_name']) ?></td>
                                <td class="small text-muted"><?= e(format_datetime($bill['uploaded_at'])) ?></td>
                                <td class="pe-3 text-end">
                                    <a href="<?= url('view.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light"><i class="bi bi-eye"></i></a>
                                    <a href="<?= url('download.php') ?>?id=<?= $bill['id'] ?>" class="btn btn-xs btn-light"><i class="bi bi-download"></i></a>
                                    <form method="post" action="<?= url('owner/branch-view.php?action=delete') ?>" class="d-inline"
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