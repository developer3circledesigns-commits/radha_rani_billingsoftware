<?php
$data = $data ?? [];
$errors = $errors ?? [];
$idField = $idField ?? null;
?>
<form method="post" action="<?= e($actionUrl) ?>">
    <?= csrf_field() ?>
    <?php if ($idField) : ?><input type="hidden" name="id" value="<?= e($idField) ?>"><?php endif; ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="branch_code">Branch Code <span class="text-danger">*</span></label>
            <input type="text" class="form-control <?= isset($errors['branch_code']) ? 'is-invalid' : '' ?>" id="branch_code" name="branch_code"
                   value="<?= e($data['branch_code'] ?? '') ?>" placeholder="BR004" required>
            <?php if (isset($errors['branch_code'])) : ?><div class="invalid-feedback"><?= e($errors['branch_code']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="branch_name">Branch Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control <?= isset($errors['branch_name']) ? 'is-invalid' : '' ?>" id="branch_name" name="branch_name"
                   value="<?= e($data['branch_name'] ?? '') ?>" placeholder="Radha Rani Hotel - Coimbatore" required>
            <?php if (isset($errors['branch_name'])) : ?><div class="invalid-feedback"><?= e($errors['branch_name']) ?></div><?php endif; ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="address">Address</label>
            <textarea class="form-control" id="address" name="address" rows="2" placeholder="Street, City, State, PIN"><?= e($data['address'] ?? '') ?></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="phone">Contact Number</label>
            <input type="text" class="form-control" id="phone" name="phone" value="<?= e($data['phone'] ?? '') ?>" placeholder="+91 98 7654 3210">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="email">Contact Email</label>
            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" id="email" name="email" value="<?= e($data['email'] ?? '') ?>" placeholder="branch@radharani.local">
            <?php if (isset($errors['email'])) : ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="active" <?= ($data['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($data['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i><?= e($submitLabel ?? 'Save') ?></button>
            <a href="<?= url('owner/branches.php') ?>" class="btn btn-light">Cancel</a>
        </div>
    </div>
</form>