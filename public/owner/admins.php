<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();
$user = current_user();

$action = get('action', 'list');

// ------------------------------------------------------------------
// DELETE / STATUS
// ------------------------------------------------------------------
if ($action === 'delete' && isPost()) {
    if (!csrf_verify()) csrf_fail();
    $adminId = (int) post('id', 0);
    $admin = $adminId ? User::find($adminId) : null;
    if ($admin && $admin['role'] === 'branch_admin') {
        User::softDelete($adminId);
        log_activity((int) $user['id'], null, 'ADMIN_DELETED', 'user', $adminId, 'Deleted admin ' . $admin['name'], $admin);
        flash_set('success', t('admins.deleted', ['name' => $admin['name']]));
    }
    redirect('owner/admins.php');
}

if ($action === 'status' && isPost()) {
    if (!csrf_verify()) csrf_fail();
    $adminId = (int) post('id', 0);
    $status = post('status', '') === 'active' ? 'active' : 'inactive';
    $admin = $adminId ? User::find($adminId) : null;
    if ($admin && $admin['role'] === 'branch_admin') {
        User::setStatus($adminId, $status);
        log_activity((int) $user['id'], null, $status === 'active' ? 'ADMIN_ACTIVATED' : 'ADMIN_DISABLED', 'user', $adminId, ucfirst($status) . ' admin ' . $admin['name'], $admin);

        // Disabling an account is a privilege change an investigator needs
        // attributed, and $admin supplies the affected identity.
        SecurityLogger::log(SecurityLogger::ADMIN_ACTION, [
            'admin_operation' => $status === 'active' ? 'admin_activated' : 'admin_disabled',
            'target_user_id'  => (int) $adminId,
            'target_username' => $admin['username'],
            'target_role'     => $admin['role'],
            'target_branch'   => $admin['branch_id'] !== null ? (int) $admin['branch_id'] : null,
            'new_status'      => $status,
            'result'          => 'success',
        ]);
        flash_set('success', t('admins.status_now', [
            'name'   => $admin['name'],
            'status' => t($status === 'active' ? 'common.active' : 'common.inactive'),
        ]));
    }
    redirect('owner/admins.php');
}

// ------------------------------------------------------------------
// RESET PASSWORD
// ------------------------------------------------------------------
if ($action === 'reset' && isPost()) {
    if (!csrf_verify()) csrf_fail();
    $adminId = (int) post('id', 0);
    $newPass = (string) post('password', '');
    $admin = $adminId ? User::find($adminId) : null;
    if ($admin && $admin['role'] === 'branch_admin') {
        if (strlen($newPass) < 8) {
            flash_set('danger', t('admins.password_min'));
            redirect('owner/admins.php?action=reset&id=' . $adminId);
        }
        User::updatePassword($adminId, password_hash($newPass, PASSWORD_DEFAULT));
        log_activity((int) $user['id'], null, 'ADMIN_PASSWORD_RESET', 'user', $adminId, 'Reset password for ' . $admin['name'], $admin);

        // An owner resetting someone's password is account takeover by design.
        // The new password is never logged.
        SecurityLogger::log(SecurityLogger::ADMIN_ACTION, [
            'admin_operation' => 'admin_password_reset',
            'target_user_id'  => (int) $adminId,
            'target_username' => $admin['username'],
            'target_role'     => $admin['role'],
            'result'          => 'success',
        ]);
        flash_set('success', t('admins.password_reset', ['name' => $admin['name']]));
    }
    redirect('owner/admins.php');
}

// ------------------------------------------------------------------
// CREATE
// ------------------------------------------------------------------
if ($action === 'create' && isPost()) {
    if (!csrf_verify()) csrf_fail();
    $data = [
        'name'      => trim((string) post('name', '')),
        'email'     => trim((string) post('email', '')),
        'username'  => trim((string) post('username', '')),
        'password'  => (string) post('password', ''),
        'branch_id' => (int) post('branch_id', 0),
    ];
    $errors = [];
    validate_required($data, [
        'name'     => t('form.full_name'),
        'email'    => t('common.email'),
        'username' => t('form.username'),
        'password' => t('form.password'),
    ], $errors);
    validate_email($data, ['email' => t('common.email')], $errors);
    if (strlen($data['password']) < 8) {
        $errors['password'] = t('admins.password_min');
    }
    if ($data['branch_id'] <= 0 || !Branch::find($data['branch_id'])) {
        $errors['branch_id'] = t('admins.select_branch');
    }
    if (User::findByEmail($data['email'])) {
        $errors['email'] = t('admins.email_in_use');
    }
    if (User::findByUsername($data['username'])) {
        $errors['username'] = t('admins.username_in_use');
    }

    if ($errors) {
        flash_set('danger', t('common.fix_fields'));
        $showCreate = true;
        $createData = $data;
        $createErrors = $errors;
    } else {
        $newId = User::create([
            'branch_id' => $data['branch_id'],
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => 'branch_admin',
            'status' => 'active',
        ]);
        log_activity((int) $user['id'], $data['branch_id'], 'ADMIN_CREATED', 'user', $newId, 'Created admin ' . $data['name'], [
            'id'       => (int) $newId,
            'username' => $data['username'],
            'role'     => 'branch_admin',
        ]);
        flash_set('success', t('admins.created_ok', ['name' => $data['name']]));
        redirect('owner/admins.php');
    }
}

// ------------------------------------------------------------------
// EDIT
// ------------------------------------------------------------------
if ($action === 'edit' && isPost()) {
    if (!csrf_verify()) csrf_fail();
    $adminId = (int) post('id', 0);
    $existing = $adminId ? User::find($adminId) : null;
    if (!$existing) redirect('owner/admins.php');

    $data = [
        'name'      => trim((string) post('name', '')),
        'email'     => trim((string) post('email', '')),
        'username'  => trim((string) post('username', '')),
        'branch_id' => (int) post('branch_id', 0),
        'status'    => post('status', '') === 'inactive' ? 'inactive' : 'active',
    ];
    $errors = [];
    validate_required($data, [
        'name'     => t('form.full_name'),
        'email'    => t('common.email'),
        'username' => t('form.username'),
    ], $errors);
    validate_email($data, ['email' => t('common.email')], $errors);
    if ($data['branch_id'] <= 0 || !Branch::find($data['branch_id'])) {
        $errors['branch_id'] = t('admins.select_branch');
    }
    $dup = Database::fetch('SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ? AND deleted_at IS NULL', [$data['email'], $data['username'], $adminId]);
    if ($dup) {
        $checkEmail = Database::fetch('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? AND deleted_at IS NULL', [$data['email'], $adminId]);
        $checkUser = Database::fetch('SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id != ? AND deleted_at IS NULL', [$data['username'], $adminId]);
        if ($checkEmail) $errors['email'] = t('admins.email_in_use');
        if ($checkUser) $errors['username'] = t('admins.username_in_use');
    }

    if ($errors) {
        flash_set('danger', t('common.fix_fields'));
        $showEdit = $existing;
        $editData = $data;
        $editErrors = $errors;
    } else {
        User::update($adminId, $data);
        log_activity((int) $user['id'], $data['branch_id'], 'ADMIN_UPDATED', 'user', $adminId, 'Updated admin ' . $data['name'], [
            'id'       => (int) $adminId,
            'username' => $data['username'],
            'role'     => $existing['role'],
        ]);

        // Moving an admin to a different branch moves their entire data scope,
        // which in this application IS the privilege boundary - so a branch
        // change is reported as role_changed, not as a routine profile edit.
        if ((int) $existing['branch_id'] !== $data['branch_id']) {
            SecurityLogger::log(SecurityLogger::ROLE_CHANGED, [
                'target_user_id'    => (int) $adminId,
                'target_username'   => $data['username'],
                'target_role'       => $existing['role'],
                'previous_branch'   => $existing['branch_id'] !== null ? (int) $existing['branch_id'] : null,
                'new_branch'        => (int) $data['branch_id'],
                'status_change'     => $existing['status'] . '->' . $data['status'],
                'reason'            => 'branch_reassignment',
                'result'            => 'success',
            ]);
        }
        flash_set('success', t('admins.updated_ok'));
        redirect('owner/admins.php');
    }
}

// ------------------------------------------------------------------
// EDIT (GET) — load an existing admin into the edit form
// ------------------------------------------------------------------
if ($action === 'edit' && isGet()) {
    $adminId = (int) get('id', 0);
    $showEdit = $adminId ? User::find($adminId) : null;
    if (!$showEdit) {
        flash_set('danger', t('admins.not_found'));
        redirect('owner/admins.php');
    }
}

// ------------------------------------------------------------------
// LIST
// ------------------------------------------------------------------
$admins = User::admins();
$branches = Branch::all(true);
$pageTitle = t('admins.title');
$pageSubtitle = t('admins.subtitle');
$activeMenu = 'admins';

ob_start();
?>

<?php if (($showCreate ?? false) || get('action') === 'create') : ?>
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i><?= e(t('admins.create_title')) ?></h5>
                <a href="<?= url('owner/admins.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="card-body">
                <?php partial('admin_form', [
                    'actionUrl' => url('owner/admins.php?action=create'),
                    'data' => $createData ?? ['branch_id' => (int) get('branch_id', 0)],
                    'errors' => $createErrors ?? [],
                    'branches' => $branches,
                    'submitLabel' => t('admins.create'),
                    'showPassword' => true,
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
                <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i><?= e(t('admins.edit_title', ['name' => $showEdit['name']])) ?></h5>
                <a href="<?= url('owner/admins.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="card-body">
                <?php partial('admin_form', [
                    'actionUrl' => url('owner/admins.php?action=edit'),
                    'data' => array_merge($showEdit, $editData ?? []),
                    'errors' => $editErrors ?? [],
                    'branches' => $branches,
                    'submitLabel' => t('common.save_changes'),
                    'idField' => $showEdit['id'],
                    'showPassword' => false,
                ]); ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i><?= e(tn('admins.count', count($admins))) ?></h5>
        <a href="<?= url('owner/admins.php?action=create') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i><?= e(t('admins.add')) ?></a>
    </div>
    <div class="card-body p-0">
        <?php if (!$admins) : ?>
            <div class="empty-state">
                <i class="bi bi-person-badge"></i>
                <p class="mb-0"><?= e(t('admins.empty')) ?></p>
            </div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3"><?= e(t('col.admin')) ?></th>
                        <th><?= e(t('col.login_id')) ?></th>
                        <th><?= e(t('common.branch')) ?></th>
                        <th><?= e(t('col.last_login')) ?></th>
                        <th><?= e(t('col.created')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                        <th class="pe-3 text-end"><?= e(t('common.actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($admins as $a) : ?>
                    <tr>
                        <td class="ps-3">
                            <span class="avatar avatar-sm me-2"><?= e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span>
                            <span class="fw-semibold"><?= e($a['name']) ?></span>
                        </td>
                        <td class="small">
                            <div><?= e($a['username']) ?></div>
                            <div class="text-muted"><?= e($a['email']) ?></div>
                        </td>
                        <td class="small"><?= e($a['branch_name'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e(format_datetime($a['last_login_at'])) ?></td>
                        <td class="small text-muted"><?= e(format_date($a['created_at'])) ?></td>
                        <td>
                            <?php if ($a['status'] === 'active') : ?>
                                <span class="badge bg-success-subtle text-success"><?= e(t('common.active')) ?></span>
                            <?php else : ?>
                                <span class="badge bg-secondary-subtle text-secondary"><?= e(t('common.inactive')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-3 text-end">
                            <button type="button" class="btn btn-xs btn-light" title="<?= e(t('admins.reset_password')) ?>"
                                    data-bs-toggle="modal" data-bs-target="#resetPwModal" data-id="<?= $a['id'] ?>" data-name="<?= e($a['name']) ?>">
                                <i class="bi bi-key"></i>
                            </button>
                            <a href="<?= url('owner/admins.php?action=edit&id=' . $a['id']) ?>" class="btn btn-xs btn-light" title="<?= e(t('common.edit')) ?>"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= url('owner/admins.php?action=status') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <input type="hidden" name="status" value="<?= $a['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <button type="submit" class="btn btn-xs btn-light" title="<?= e($a['status'] === 'active' ? t('admins.disable') : t('admins.enable')) ?>">
                                    <i class="bi <?= $a['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                                </button>
                            </form>
                            <form method="post" action="<?= url('owner/admins.php?action=delete') ?>" class="d-inline"
                                      data-confirm="<?= e(t('admins.delete_confirm')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
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

<!-- Reset password modal -->
<div class="modal fade" id="resetPwModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= url('owner/admins.php?action=reset') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="resetPwId">
                <div class="modal-header">
                    <h5 class="modal-title"><?= e(t('admins.reset_password')) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted"><?= e(t('admins.new_password_for')) ?> <strong id="resetPwName"></strong>.</p>
                    <label class="form-label" for="reset_pw"><?= e(t('admins.new_password')) ?></label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="reset_pw" name="password" minlength="8" placeholder="<?= e(t('form.password_placeholder')) ?>" required>
                        <button class="btn btn-outline-secondary" type="button" data-pw-toggle="reset_pw" aria-label="<?= e(t('form.show_password')) ?>"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i><?= e(t('admins.reset_password')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('resetPwModal');
    modal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        document.getElementById('resetPwId').value = btn.dataset.id;
        document.getElementById('resetPwName').textContent = btn.dataset.name;
    });
});
</script>