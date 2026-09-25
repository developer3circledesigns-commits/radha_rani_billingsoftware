<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();
$user = current_user();

$action = get('action', 'list');

// ------------------------------------------------------------------
// DELETE (soft delete)
// ------------------------------------------------------------------
if ($action === 'delete' && isPost()) {
    if (!csrf_verify()) {
        csrf_fail();
    }
    $branchId = (int) post('id', 0);
    $branch = $branchId ? Branch::find($branchId) : null;
    if ($branch) {
        Branch::softDelete($branchId);
        log_activity((int) $user['id'], null, 'BRANCH_DELETED', 'branch', $branchId, 'Deleted branch ' . $branch['branch_name']);
        flash_set('success', 'Branch "' . $branch['branch_name'] . '" deleted.');
    }
    redirect('owner/branches.php');
}

// ------------------------------------------------------------------
// STATUS TOGGLE
// ------------------------------------------------------------------
if ($action === 'status' && isPost()) {
    if (!csrf_verify()) {
        csrf_fail();
    }
    $branchId = (int) post('id', 0);
    $status = post('status', '') === 'active' ? 'active' : 'inactive';
    $branch = $branchId ? Branch::find($branchId) : null;
    if ($branch) {
        Branch::setStatus($branchId, $status);
        log_activity((int) $user['id'], null, $status === 'active' ? 'BRANCH_ACTIVATED' : 'BRANCH_DEACTIVATED', 'branch', $branchId, ucfirst($status) . ' branch ' . $branch['branch_name']);
        flash_set('success', 'Branch "' . $branch['branch_name'] . '" is now ' . $status . '.');
    }
    redirect('owner/branches.php');
}

// ------------------------------------------------------------------
// CREATE
// ------------------------------------------------------------------
if ($action === 'create' && isPost()) {
    if (!csrf_verify()) {
        csrf_fail();
    }
    $data = [
        'branch_code' => strtoupper(trim((string) post('branch_code', ''))),
        'branch_name' => trim((string) post('branch_name', '')),
        'address'     => trim((string) post('address', '')),
        'phone'       => trim((string) post('phone', '')),
        'email'       => trim((string) post('email', '')),
        'status'      => post('status', '') === 'inactive' ? 'inactive' : 'active',
    ];
    $errors = [];
    validate_required($data, [
        'branch_code' => 'Branch code',
        'branch_name' => 'Branch name',
    ], $errors);
    if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $data['branch_code'])) {
        $errors['branch_code'] = 'Branch code must be 2–20 letters, numbers, dashes or underscores.';
    }
    if (Branch::findByCode($data['branch_code'])) {
        $errors['branch_code'] = 'This branch code is already in use.';
    }
    validate_email($data, ['email' => 'Email'], $errors);

    if ($errors) {
        flash_set('danger', 'Please fix the highlighted fields.');
        $showCreate = true;
        $createData = $data;
        $createErrors = $errors;
    } else {
        $newId = Branch::create($data);
        log_activity((int) $user['id'], null, 'BRANCH_CREATED', 'branch', $newId, 'Created branch ' . $data['branch_name'] . ' (' . $data['branch_code'] . ')');
        flash_set('success', 'Branch "' . $data['branch_name'] . '" created successfully.');
        redirect('owner/branches.php');
    }
}

// ------------------------------------------------------------------
// EDIT
// ------------------------------------------------------------------
if ($action === 'edit' && isPost()) {
    if (!csrf_verify()) {
        csrf_fail();
    }
    $branchId = (int) post('id', 0);
    $existing = $branchId ? Branch::find($branchId) : null;
    if (!$existing) {
        redirect('owner/branches.php');
    }
    $data = [
        'branch_code' => strtoupper(trim((string) post('branch_code', ''))),
        'branch_name' => trim((string) post('branch_name', '')),
        'address'     => trim((string) post('address', '')),
        'phone'       => trim((string) post('phone', '')),
        'email'       => trim((string) post('email', '')),
        'status'      => post('status', '') === 'inactive' ? 'inactive' : 'active',
    ];
    $errors = [];
    validate_required($data, [
        'branch_code' => 'Branch code',
        'branch_name' => 'Branch name',
    ], $errors);
    if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $data['branch_code'])) {
        $errors['branch_code'] = 'Branch code must be 2–20 letters, numbers, dashes or underscores.';
    }
    $dup = Database::fetch('SELECT id FROM branches WHERE branch_code = ? AND id != ? AND deleted_at IS NULL', [$data['branch_code'], $branchId]);
    if ($dup) {
        $errors['branch_code'] = 'This branch code is already in use.';
    }
    validate_email($data, ['email' => 'Email'], $errors);

    if ($errors) {
        flash_set('danger', 'Please fix the highlighted fields.');
        $showEdit = $existing;
        $editData = $data;
        $editErrors = $errors;
    } else {
        Branch::update($branchId, $data);
        log_activity((int) $user['id'], null, 'BRANCH_UPDATED', 'branch', $branchId, 'Updated branch ' . $data['branch_name']);
        flash_set('success', 'Branch updated successfully.');
        redirect('owner/branches.php');
    }
}

// ------------------------------------------------------------------
// EDIT (GET) — load an existing branch into the edit form
// ------------------------------------------------------------------
if ($action === 'edit' && isGet()) {
    $branchId = (int) get('id', 0);
    $showEdit = $branchId ? Branch::find($branchId) : null;
    if (!$showEdit) {
        flash_set('danger', 'Branch not found.');
        redirect('owner/branches.php');
    }
}

// ------------------------------------------------------------------
// LIST
// ------------------------------------------------------------------
$branches = Branch::listWithSummary();
$pageTitle = 'Branches';
$pageSubtitle = 'Manage all hotel branches';
$activeMenu = 'branches';

ob_start();
?>

<?php if (($showCreate ?? false) || get('action') === 'create') : ?>
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add New Branch</h5>
                <a href="<?= url('owner/branches.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="card-body">
                <?php partial('branch_form', [
                    'actionUrl' => url('owner/branches.php?action=create'),
                    'data' => $createData ?? [],
                    'errors' => $createErrors ?? [],
                    'submitLabel' => 'Create Branch',
                ]); ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (($showEdit ?? false)) : ?>
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Edit Branch — <?= e($showEdit['branch_name']) ?></h5>
                <a href="<?= url('owner/branches.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="card-body">
                <?php partial('branch_form', [
                    'actionUrl' => url('owner/branches.php?action=edit'),
                    'data' => array_merge($showEdit, $editData ?? []) ,
                    'errors' => $editErrors ?? [],
                    'submitLabel' => 'Save Changes',
                    'idField' => $showEdit['id'],
                ]); ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-buildings me-2"></i><?= count($branches) ?> Branch<?= count($branches) === 1 ? '' : 'es' ?></h5>
        <a href="<?= url('owner/branches.php?action=create') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Branch</a>
    </div>
    <div class="card-body p-0">
        <?php if (!$branches) : ?>
            <div class="empty-state">
                <i class="bi bi-buildings"></i>
                <p class="mb-1">No branches found.</p>
                <p class="mb-3 text-muted">Create your first branch to get started.</p>
                <a href="<?= url('owner/branches.php?action=create') ?>" class="btn btn-sm btn-primary">Create Branch</a>
            </div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Code</th>
                        <th>Branch</th>
                        <th class="text-center">Admins</th>
                        <th>Last Upload</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th class="pe-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($branches as $b) : ?>
                    <tr>
                        <td class="ps-3"><span class="badge bg-light text-dark border"><?= e($b['branch_code']) ?></span></td>
                        <td>
                            <a href="<?= url('owner/branch-view.php') ?>?id=<?= $b['id'] ?>" class="fw-semibold text-decoration-none"><?= e($b['branch_name']) ?></a>
                            <div class="text-muted small"><?= e($b['address'] ?? '') ?></div>
                        </td>
                        <td class="text-center"><?= (int)$b['admin_count'] ?></td>
                        <td class="text-muted small"><?= e(format_datetime($b['last_upload'] ?? null)) ?></td>
                        <td class="text-muted small"><?= e(format_date($b['created_at'])) ?></td>
                        <td>
                            <?php if ($b['status'] === 'active') : ?>
                                <span class="badge bg-success-subtle text-success">Active</span>
                            <?php else : ?>
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-3 text-end">
                            <a href="<?= url('owner/branch-view.php') ?>?id=<?= $b['id'] ?>" class="btn btn-xs btn-light" title="View"><i class="bi bi-eye"></i></a>
                            <a href="<?= url('owner/branches.php?action=edit&id=' . $b['id']) ?>" class="btn btn-xs btn-light" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= url('owner/branches.php?action=status') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <input type="hidden" name="status" value="<?= $b['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <button type="submit" class="btn btn-xs btn-light" title="<?= $b['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                    <i class="bi <?= $b['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                                </button>
                            </form>
                            <form method="post" action="<?= url('owner/branches.php?action=delete') ?>" class="d-inline"
                                      data-confirm="Delete this branch? Its bills will be removed from view. This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
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

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';