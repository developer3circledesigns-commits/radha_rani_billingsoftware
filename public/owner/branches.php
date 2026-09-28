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
        flash_set('success', t('branches.deleted', ['name' => $branch['branch_name']]));
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
        // The audit line above stays English on purpose: it is evidence for an
        // investigator, not UI. The flash is the visitor's copy.
        flash_set('success', t('branches.status_now', [
            'name'   => $branch['branch_name'],
            'status' => t($status === 'active' ? 'common.active' : 'common.inactive'),
        ]));
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
        'branch_code' => t('common.branch_code'),
        'branch_name' => t('branches.name_label'),
    ], $errors);
    if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $data['branch_code'])) {
        $errors['branch_code'] = t('branches.code_format');
    }
    if (Branch::findByCode($data['branch_code'])) {
        $errors['branch_code'] = t('branches.code_in_use');
    }
    validate_email($data, ['email' => t('common.email')], $errors);

    if ($errors) {
        flash_set('danger', t('common.fix_fields'));
        $showCreate = true;
        $createData = $data;
        $createErrors = $errors;
    } else {
        $newId = Branch::create($data);
        log_activity((int) $user['id'], null, 'BRANCH_CREATED', 'branch', $newId, 'Created branch ' . $data['branch_name'] . ' (' . $data['branch_code'] . ')');
        flash_set('success', t('branches.created_ok', ['name' => $data['branch_name']]));
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
        'branch_code' => t('common.branch_code'),
        'branch_name' => t('branches.name_label'),
    ], $errors);
    if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $data['branch_code'])) {
        $errors['branch_code'] = t('branches.code_format');
    }
    $dup = Database::fetch('SELECT id FROM branches WHERE branch_code = ? AND id != ? AND deleted_at IS NULL', [$data['branch_code'], $branchId]);
    if ($dup) {
        $errors['branch_code'] = t('branches.code_in_use');
    }
    validate_email($data, ['email' => t('common.email')], $errors);

    if ($errors) {
        flash_set('danger', t('common.fix_fields'));
        $showEdit = $existing;
        $editData = $data;
        $editErrors = $errors;
    } else {
        Branch::update($branchId, $data);
        log_activity((int) $user['id'], null, 'BRANCH_UPDATED', 'branch', $branchId, 'Updated branch ' . $data['branch_name']);
        flash_set('success', t('branches.updated_ok'));
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
        flash_set('danger', t('branches.not_found'));
        redirect('owner/branches.php');
    }
}

// ------------------------------------------------------------------
// LIST
// ------------------------------------------------------------------
$branches = Branch::listWithSummary();
$pageTitle = t('branches.title');
$pageSubtitle = t('branches.subtitle');
$activeMenu = 'branches';

ob_start();
?>

<?php if (($showCreate ?? false) || get('action') === 'create') : ?>
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i><?= e(t('branches.add_new')) ?></h5>
                <a href="<?= url('owner/branches.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="card-body">
                <?php partial('branch_form', [
                    'actionUrl' => url('owner/branches.php?action=create'),
                    'data' => $createData ?? [],
                    'errors' => $createErrors ?? [],
                    'submitLabel' => t('branches.create'),
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
                <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i><?= e(t('branches.edit_title', ['name' => $showEdit['branch_name']])) ?></h5>
                <a href="<?= url('owner/branches.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="card-body">
                <?php partial('branch_form', [
                    'actionUrl' => url('owner/branches.php?action=edit'),
                    'data' => array_merge($showEdit, $editData ?? []) ,
                    'errors' => $editErrors ?? [],
                    'submitLabel' => t('common.save_changes'),
                    'idField' => $showEdit['id'],
                ]); ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-buildings me-2"></i><?= e(tn('branches.count', count($branches))) ?></h5>
        <a href="<?= url('owner/branches.php?action=create') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i><?= e(t('branches.add')) ?></a>
    </div>
    <div class="card-body p-0">
        <?php if (!$branches) : ?>
            <div class="empty-state">
                <i class="bi bi-buildings"></i>
                <p class="mb-1"><?= e(t('branches.empty')) ?></p>
                <p class="mb-3 text-muted"><?= e(t('branches.empty_hint')) ?></p>
                <a href="<?= url('owner/branches.php?action=create') ?>" class="btn btn-sm btn-primary"><?= e(t('branches.create')) ?></a>
            </div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3"><?= e(t('col.code')) ?></th>
                        <th><?= e(t('common.branch')) ?></th>
                        <th class="text-center"><?= e(t('col.admins')) ?></th>
                        <th><?= e(t('col.last_upload')) ?></th>
                        <th><?= e(t('col.created')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                        <th class="pe-3 text-end"><?= e(t('common.actions')) ?></th>
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
                                <span class="badge bg-success-subtle text-success"><?= e(t('common.active')) ?></span>
                            <?php else : ?>
                                <span class="badge bg-secondary-subtle text-secondary"><?= e(t('common.inactive')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-3 text-end">
                            <a href="<?= url('owner/branch-view.php') ?>?id=<?= $b['id'] ?>" class="btn btn-xs btn-light" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
                            <a href="<?= url('owner/branches.php?action=edit&id=' . $b['id']) ?>" class="btn btn-xs btn-light" title="<?= e(t('common.edit')) ?>"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= url('owner/branches.php?action=status') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <input type="hidden" name="status" value="<?= $b['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <button type="submit" class="btn btn-xs btn-light" title="<?= e($b['status'] === 'active' ? t('branches.deactivate') : t('branches.activate')) ?>">
                                    <i class="bi <?= $b['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                                </button>
                            </form>
                            <form method="post" action="<?= url('owner/branches.php?action=delete') ?>" class="d-inline"
                                      data-confirm="<?= e(t('branches.delete_confirm')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
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

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';