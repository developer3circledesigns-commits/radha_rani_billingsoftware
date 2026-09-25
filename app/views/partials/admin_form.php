<?php
$data = $data ?? [];
$errors = $errors ?? [];
$idField = $idField ?? null;
$showPassword = $showPassword ?? false;
$submittedPassword = $data['password'] ?? '';
?>
<form method="post" action="<?= e($actionUrl) ?>">
    <?= csrf_field() ?>
    <?php if ($idField) : ?><input type="hidden" name="id" value="<?= e($idField) ?>"><?php endif; ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="a_name">Full Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="a_name" name="name" value="<?= e($data['name'] ?? '') ?>" required>
            <?php if (isset($errors['name'])) : ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="a_username">Username <span class="text-danger">*</span></label>
            <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" id="a_username" name="username" value="<?= e($data['username'] ?? '') ?>" required>
            <?php if (isset($errors['username'])) : ?><div class="invalid-feedback"><?= e($errors['username']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="a_email">Email <span class="text-danger">*</span></label>
            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" id="a_email" name="email" value="<?= e($data['email'] ?? '') ?>" required>
            <?php if (isset($errors['email'])) : ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="a_branch">Assigned Branch <span class="text-danger">*</span></label>
            <select class="form-select <?= isset($errors['branch_id']) ? 'is-invalid' : '' ?>" id="a_branch" name="branch_id" required>
                <option value="">Select branch…</option>
                <?php foreach ($branches as $b) : ?>
                    <option value="<?= (int) $b['id'] ?>" <?= ((int) ($data['branch_id'] ?? 0)) === (int) $b['id'] ? 'selected' : '' ?>>
                        <?= e($b['branch_name']) ?> (<?= e($b['branch_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['branch_id'])) : ?><div class="invalid-feedback"><?= e($errors['branch_id']) ?></div><?php endif; ?>
        </div>
        <?php if ($showPassword) : ?>
        <div class="col-md-6">
            <label class="form-label" for="a_password">Password <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" id="a_password" name="password" minlength="8" placeholder="At least 8 characters" required>
                <button class="btn btn-outline-secondary" type="button" data-pw-toggle="a_password" aria-label="Show password"><i class="bi bi-eye"></i></button>
            </div>
            <?php if (isset($errors['password'])) : ?><div class="invalid-feedback"><?= e($errors['password']) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!$showPassword) : ?>
        <div class="col-md-6">
            <label class="form-label" for="a_status">Status</label>
            <select class="form-select" id="a_status" name="status">
                <option value="active" <?= ($data['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($data['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i><?= e($submitLabel ?? 'Save') ?></button>
            <a href="<?= url('owner/admins.php') ?>" class="btn btn-light">Cancel</a>
        </div>
    </div>
</form>