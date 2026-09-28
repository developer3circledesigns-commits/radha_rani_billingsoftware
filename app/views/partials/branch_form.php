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
            <label class="form-label" for="branch_code"><?= e(t('common.branch_code')) ?> <span class="text-danger">*</span></label>
            <input type="text" class="form-control <?= isset($errors['branch_code']) ? 'is-invalid' : '' ?>" id="branch_code" name="branch_code"
                   value="<?= e($data['branch_code'] ?? '') ?>" placeholder="BR004" required>
            <?php if (isset($errors['branch_code'])) : ?><div class="invalid-feedback"><?= e($errors['branch_code']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="branch_name"><?= e(t('branches.name_label')) ?> <span class="text-danger">*</span></label>
            <input type="text" class="form-control <?= isset($errors['branch_name']) ? 'is-invalid' : '' ?>" id="branch_name" name="branch_name"
                   value="<?= e($data['branch_name'] ?? '') ?>" placeholder="Radha Rani Hotel - Coimbatore" required>
            <?php if (isset($errors['branch_name'])) : ?><div class="invalid-feedback"><?= e($errors['branch_name']) ?></div><?php endif; ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="address"><?= e(t('common.address')) ?></label>
            <textarea class="form-control" id="address" name="address" rows="2" placeholder="<?= e(t('form.address_placeholder')) ?>"><?= e($data['address'] ?? '') ?></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="phone"><?= e(t('form.contact_number')) ?></label>
            <input type="text" class="form-control" id="phone" name="phone" value="<?= e($data['phone'] ?? '') ?>" placeholder="+91 98 7654 3210">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="email"><?= e(t('form.contact_email')) ?></label>
            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" id="email" name="email" value="<?= e($data['email'] ?? '') ?>" placeholder="branch@radharani.local">
            <?php if (isset($errors['email'])) : ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="status"><?= e(t('common.status')) ?></label>
            <select class="form-select" id="status" name="status">
                <option value="active" <?= ($data['status'] ?? 'active') === 'active' ? 'selected' : '' ?>><?= e(t('common.active')) ?></option>
                <option value="inactive" <?= ($data['status'] ?? '') === 'inactive' ? 'selected' : '' ?>><?= e(t('common.inactive')) ?></option>
            </select>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i><?= e($submitLabel ?? t('common.save')) ?></button>
            <a href="<?= url('owner/branches.php') ?>" class="btn btn-light"><?= e(t('common.cancel')) ?></a>
        </div>
    </div>
</form>