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
        log_activity((int) $user['id'], null, 'ADMIN_DELETED', 'user', $adminId, 'Deleted admin ' . $admin['name']);
        flash_set('success', 'Admin account "' . $admin['name'] . '" deleted.');
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
        log_activity((int) $user['id'], null, $status === 'active' ? 'ADMIN_ACTIVATED' : 'ADMIN_DISABLED', 'user', $adminId, ucfirst($status) . ' admin ' . $admin['name']);
        flash_set('success', 'Admin "' . $admin['name'] . '" is now ' . $status . '.');
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
            flash_set('danger', 'Password must be at least 8 characters.');
            redirect('owner/admins.php?action=reset&id=' . $adminId);
        }
        User::updatePassword($adminId, password_hash($newPass, PASSWORD_DEFAULT));
        log_activity((int) $user['id'], null, 'ADMIN_PASSWORD_RESET', 'user', $adminId, 'Reset password for ' . $admin['name']);
        flash_set('success', 'Password reset for "' . $admin['name'] . '".');
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
        'name'     => 'Full name',
        'email'    => 'Email',
        'username' => 'Username',
        'password' => 'Password',
    ], $errors);
    validate_email($data, ['email' => 'Email'], $errors);
    if (strlen($data['password']) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($data['branch_id'] <= 0 || !Branch::find($data['branch_id'])) {
        $errors['branch_id'] = 'Select a valid branch.';
    }
    if (User::findByEmail($data['email'])) {
        $errors['email'] = 'This email is already in use.';
    }
    if (User::findByUsername($data['username'])) {
        $errors['username'] = 'This username is already in use.';
    }

    if ($errors) {
        flash_set('danger', 'Please fix the highlighted fields.');
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
        log_activity((int) $user['id'], $data['branch_id'], 'ADMIN_CREATED', 'user', $newId, 'Created admin ' . $data['name']);
        flash_set('success', 'Branch admin "' . $data['name'] . '" created successfully.');
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
    validate_required($data, ['name' => 'Full name', 'email' => 'Email', 'username' => 'Username'], $errors);
    validate_email($data, ['email' => 'Email'], $errors);
    if ($data['branch_id'] <= 0 || !Branch::find($data['branch_id'])) {
        $errors['branch_id'] = 'Select a valid branch.';
    }
    $dup = Database::fetch('SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ? AND deleted_at IS NULL', [$data['email'], $data['username'], $adminId]);
    if ($dup) {
        $checkEmail = Database::fetch('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? AND deleted_at IS NULL', [$data['email'], $adminId]);
        $checkUser = Database::fetch('SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id != ? AND deleted_at IS NULL', [$data['username'], $adminId]);
        if ($checkEmail) $errors['email'] = 'This email is already in use.';
        if ($checkUser) $errors['username'] = 'This username is already in use.';
    }

    if ($errors) {
        flash_set('danger', 'Please fix the highlighted fields.');
        $showEdit = $existing;
        $editData = $data;
        $editErrors = $errors;
    } else {
        User::update($adminId, $data);
        log_activity((int) $user['id'], $data['branch_id'], 'ADMIN_UPDATED', 'user', $adminId, 'Updated admin ' . $data['name']);
        flash_set('success', 'Admin updated successfully.');
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
        flash_set('danger', 'Admin account not found.');
        redirect('owner/admins.php');
    }
}

// ------------------------------------------------------------------
// LIST
// ------------------------------------------------------------------
$admins = User::admins();
$branches = Branch::all(true);
$pageTitle = 'Branch Admins';
$pageSubtitle = 'Manage branch administrator accounts';
$activeMenu = 'admins';

ob_start();
?>

<?php if (($showCreate ?? false) || get('action') === 'create') : ?>
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>Create Branch Admin</h5>
                <a href="<?= url('owner/admins.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="card-body">
                <?php partial('admin_form', [
                    'actionUrl' => url('owner/admins.php?action=create'),
                    'data' => $createData ?? ['branch_id' => (int) get('branch_id', 0)],
                    'errors' => $createErrors ?? [],
                    'branches' => $branches,
                    'submitLabel' => 'Create Admin',
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
                <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Edit Admin — <?= e($showEdit['name']) ?></h5>
                <a href="<?= url('owner/admins.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="card-body">
                <?php partial('admin_form', [
                    'actionUrl' => url('owner/admins.php?action=edit'),
                    'data' => array_merge($showEdit, $editData ?? []),
                    'errors' => $editErrors ?? [],
                    'branches' => $branches,
                    'submitLabel' => 'Save Changes',
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
        <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i><?= count($admins) ?> Branch Admin<?= count($admins) === 1 ? '' : 's' ?></h5>
        <a href="<?= url('owner/admins.php?action=create') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Admin</a>
    </div>
    <div class="card-body p-0">
        <?php if (!$admins) : ?>
            <div class="empty-state">
                <i class="bi bi-person-badge"></i>
                <p class="mb-0">No branch admins created yet. Add one to get started.</p>
            </div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Admin</th>
                        <th>Login ID</th>
                        <th>Branch</th>
                        <th>Last Login</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th class="pe-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($admins as $a) : ?>
                    <tr>
                        <td class="ps-3">
                            <span class="avatar avatar-sm me-2"><?= e(strtoupper(substr($a['name'], 0, 1))) ?></span>
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
                                <span class="badge bg-success-subtle text-success">Active</span>
                            <?php else : ?>
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-3 text-end">
                            <button type="button" class="btn btn-xs btn-light" title="Reset Password"
                                    data-bs-toggle="modal" data-bs-target="#resetPwModal" data-id="<?= $a['id'] ?>" data-name="<?= e($a['name']) ?>">
                                <i class="bi bi-key"></i>
                            </button>
                            <a href="<?= url('owner/admins.php?action=edit&id=' . $a['id']) ?>" class="btn btn-xs btn-light" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= url('owner/admins.php?action=status') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <input type="hidden" name="status" value="<?= $a['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <button type="submit" class="btn btn-xs btn-light" title="<?= $a['status'] === 'active' ? 'Disable' : 'Enable' ?>">
                                    <i class="bi <?= $a['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                                </button>
                            </form>
                            <form method="post" action="<?= url('owner/admins.php?action=delete') ?>" class="d-inline"
                                      data-confirm="Delete this admin account? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
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

<!-- Reset password modal -->
<div class="modal fade" id="resetPwModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= url('owner/admins.php?action=reset') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="resetPwId">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Set a new password for <strong id="resetPwName"></strong>.</p>
                    <label class="form-label" for="reset_pw">New password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="reset_pw" name="password" minlength="8" placeholder="At least 8 characters" required>
                        <button class="btn btn-outline-secondary" type="button" data-pw-toggle="reset_pw" aria-label="Show password"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Reset Password</button>
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