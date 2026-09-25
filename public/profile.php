<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_login();
$user = current_user();

// Only the owner may edit profile / password; branch admin accounts are
// managed entirely by the owner (owner/admins.php).
$isOwner = ($user['role'] ?? '') === 'owner';

$errors = [];
$success = '';

// Change password
if ($isOwner && isPost() && post('action') === 'change_password') {
    if (!csrf_verify()) {
        csrf_fail();
    }
    $current = (string) post('current_password', '');
    $new     = (string) post('new_password', '');
    $confirm = (string) post('confirm_password', '');

    if (!password_verify($current, $user['password_hash'])) {
        $errors['current_password'] = 'Your current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors['new_password'] = 'New password must be at least 8 characters long.';
    }
    if ($new !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!$errors) {
        User::updatePassword((int) $user['id'], password_hash($new, PASSWORD_DEFAULT));
        log_activity((int) $user['id'], $user['branch_id'], 'PASSWORD_CHANGED', 'user', (int) $user['id'], 'Password changed');
        flash_set('success', 'Your password has been updated.');
        redirect('profile.php');
    }
}

// Update profile (name) for owner only; branch admins are managed by the owner
if ($isOwner && isPost() && post('action') === 'update_profile') {
    if (!csrf_verify()) {
        csrf_fail();
    }
    $name  = trim((string) post('name', ''));
    $email = trim((string) post('email', ''));

    $errors = [];
    if ($name === '') {
        $errors['name'] = 'Full name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'A valid email is required.';
    } else {
        $dup = Database::fetch('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? AND deleted_at IS NULL', [$email, $user['id']]);
        if ($dup) {
            $errors['email'] = 'That email is already in use.';
        }
    }

    if (!$errors) {
        $username = trim((string) post('username', ''));
        $dupU = Database::fetch('SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id != ? AND deleted_at IS NULL', [$username, $user['id']]);
        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif ($dupU) {
            $errors['username'] = 'That username is already in use.';
        }
    }

    if (!$errors) {
        Database::execute('UPDATE users SET name = ?, email = ?, username = ? WHERE id = ?', [$name, $email, $username, $user['id']]);
        log_activity((int) $user['id'], $user['branch_id'], 'PROFILE_UPDATED', 'user', (int) $user['id'], 'Profile updated');
        flash_set('success', 'Profile updated.');
        // Refresh session user
        $_SESSION['user_id'] = (int) $user['id'];
        redirect('profile.php');
    }
}

if ($isOwner && isPost() && post('action') === 'update_profile') {
    $user = User::find((int) $user['id']) ?: $user;
}

$pageTitle = 'Profile & Settings';
$pageSubtitle = $user['role'] === 'owner' ? 'Owner account' : 'Branch admin account';
$activeMenu = 'profile';

ob_start();
?>
<div class="row g-4">
    <!-- Account card -->
    <div class="col-lg-5 col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <div class="avatar avatar-xl mx-auto mb-3"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
                <h4 class="mb-1"><?= e($user['name']) ?></h4>
                <p class="text-muted mb-2"><?= e($user['email']) ?></p>
                <?php if ($user['role'] === 'owner') : ?>
                    <span class="badge bg-dark">Owner</span>
                <?php else : ?>
                    <span class="badge bg-primary">Branch Admin</span>
                <?php endif; ?>
                <hr>
                <div class="text-start small">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Username</dt>
                        <dd class="col-7 text-end mb-2"><?= e($user['username']) ?></dd>
                        <dt class="col-5 text-muted">Branch</dt>
                        <dd class="col-7 text-end mb-2"><?= $user['branch_name'] ? e($user['branch_name']) : '—' ?></dd>
                        <dt class="col-5 text-muted">Account status</dt>
                        <dd class="col-7 text-end mb-2"><span class="badge <?= $user['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= e(ucfirst($user['status'])) ?></span></dd>
                        <dt class="col-5 text-muted">Last login</dt>
                        <dd class="col-7 text-end mb-0"><?= e(format_datetime($user['last_login_at'])) ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7 col-xl-8">
        <?php if ($isOwner) : ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Update Profile</h5>
            </div>
            <div class="card-body">
                <form method="post" action="<?= url('profile.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="p_name">Full name</label>
                            <input class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="p_name" name="name" value="<?= e($user['name']) ?>">
                            <?php if (isset($errors['name'])) : ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="p_username">Username</label>
                            <input class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" id="p_username" name="username" value="<?= e($user['username']) ?>">
                            <?php if (isset($errors['username'])) : ?><div class="invalid-feedback"><?= e($errors['username']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="p_email">Email</label>
                            <input class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" id="p_email" name="email" value="<?= e($user['email']) ?>">
                            <?php if (isset($errors['email'])) : ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-2"></i>Save Changes</button></div>
                    </div>
                </form>
            </div>
        </div>
        <?php else : ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi bi-shield-lock-fill text-primary fs-3"></i>
                    <div>
                        <h6 class="mb-1">Profile managed by the owner</h6>
                        <p class="text-muted mb-0 small">
                            Your profile details are maintained by the portal owner (Radha Rani Hotel). To update your name,
                            username, email, assigned branch, or password, please contact the owner.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Change Password</h5>
            </div>
            <?php if ($isOwner) : ?>
            <div class="card-body">
                <form method="post" action="<?= url('profile.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="pw_current">Current password</label>
                            <div class="input-group">
                                <input type="password" class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>" id="pw_current" name="current_password">
                                <button class="btn btn-outline-secondary" type="button" data-pw-toggle="pw_current" aria-label="Show password"><i class="bi bi-eye"></i></button>
                            </div>
                            <?php if (isset($errors['current_password'])) : ?><div class="invalid-feedback"><?= e($errors['current_password']) ?></div><?php endif; ?>
                        </div>
                        <div class="w-100"></div>
                        <div class="col-md-6">
                            <label class="form-label" for="pw_new">New password</label>
                            <div class="input-group">
                                <input type="password" class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>" id="pw_new" name="new_password" minlength="8">
                                <button class="btn btn-outline-secondary" type="button" data-pw-toggle="pw_new" aria-label="Show password"><i class="bi bi-eye"></i></button>
                            </div>
                            <?php if (isset($errors['new_password'])) : ?><div class="invalid-feedback"><?= e($errors['new_password']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="pw_confirm">Confirm new password</label>
                            <div class="input-group">
                                <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" id="pw_confirm" name="confirm_password" minlength="8">
                                <button class="btn btn-outline-secondary" type="button" data-pw-toggle="pw_confirm" aria-label="Show password"><i class="bi bi-eye"></i></button>
                            </div>
                            <?php if (isset($errors['confirm_password'])) : ?><div class="invalid-feedback"><?= e($errors['confirm_password']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-outline-danger" type="submit"><i class="bi bi-key me-2"></i>Update Password</button>
                        </div>
                    </div>
                </form>
            </div>
            <?php else : ?>
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi bi-shield-lock-fill text-primary fs-3"></i>
                    <div>
                        <h6 class="mb-1">Password managed by the owner</h6>
                        <p class="text-muted mb-0 small">
                            Your password is reset by the portal owner (Radha Rani Hotel). To change your password, please contact the owner.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';