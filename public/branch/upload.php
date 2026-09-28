<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_branch_admin();
$user = current_user();

$branch = Branch::find((int) $user['branch_id']);
if (!$branch) {
    flash_set('danger', t('upload.branch_invalid'));
    logout_user();
    redirect('login.php');
}
if ($branch['status'] !== 'active') {
    flash_set('danger', t('upload.branch_inactive'));
    redirect('branch/dashboard.php');
}

$maxSizeMb = (int) (Setting::get('max_file_size_mb', '20') ?: 20);
$maxSize = $maxSizeMb * 1024 * 1024;
if ($maxSize > MAX_FILE_SIZE) {
    $maxSize = MAX_FILE_SIZE;
    $maxSizeMb = (int) round($maxSize / 1048576);
}

$pageTitle = t('upload.title');
$pageSubtitle = $branch['branch_name'];
$activeMenu = 'upload';
$extraScripts = ['assets/js/upload.js'];

ob_start();
?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-cloud-arrow-up me-2"></i><?= e(t('upload.pdf_title')) ?></h5></div>
            <div class="card-body p-4">

                <div id="uploadFormWrap">
                    <form id="uploadForm" action="<?= url('api/bills/upload.php') ?>" method="post" enctype="multipart/form-data" novalidate>
                        <?= csrf_field() ?>

                        <!-- Payment type cards -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold"><?= e(t('upload.payment_type')) ?> <span class="text-danger">*</span></label>
                            <div class="row g-3">
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="payment_type" id="pt_cash" value="cash" checked>
                                    <label class="payment-type-card payment-cash" for="pt_cash">
                                        <i class="bi bi-cash-coin display-6"></i>
                                        <span class="fw-semibold"><?= e(t('upload.cash_bill')) ?></span>
                                        <small class="text-muted"><?= e(t('upload.cash_desc')) ?></small>
                                    </label>
                                </div>
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="payment_type" id="pt_card" value="card">
                                    <label class="payment-type-card payment-card" for="pt_card">
                                        <i class="bi bi-credit-card display-6"></i>
                                        <span class="fw-semibold"><?= e(t('upload.card_bill')) ?></span>
                                        <small class="text-muted"><?= e(t('upload.card_desc')) ?></small>
                                    </label>
                                </div>
                            </div>
                            <div class="invalid-feedback d-block" id="ptErr"></div>
                        </div>

                        <!-- Business date -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="business_date"><?= e(t('upload.business_date')) ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="business_date" name="business_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                            <div class="form-text"><?= e(t('upload.business_date_help')) ?></div>
                            <div class="invalid-feedback d-block" id="dateErr"></div>
                        </div>

                        <!-- Drag & drop zone -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold"><?= e(t('upload.pdf_file')) ?> <span class="text-danger">*</span></label>
                            <div class="upload-dropzone" id="dropZone">
                                <div class="dz-inner">
                                    <i class="bi bi-cloud-arrow-up-fill display-4 text-muted"></i>
                                    <p class="mb-1 fw-semibold"><?= e(t('upload.drag_drop')) ?></p>
                                    <p class="mb-2 text-muted small"><?= e(t('upload.or')) ?></p>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="browseBtn"><i class="bi bi-folder2-open me-1"></i><?= e(t('upload.browse')) ?></button>
                                    <p class="mb-0 mt-2 text-muted small"><?= e(t('upload.pdf_only_max', ['max' => $maxSizeMb])) ?></p>
                                </div>
                                <input type="file" id="fileInput" name="pdf_file" accept=".pdf,application/pdf" class="d-none">
                                <div class="dz-preview d-none" id="filePreview">
                                    <div class="d-flex align-items-center gap-3 p-3">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger fs-2"></i>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold text-truncate" id="filePreviewName">file.pdf</div>
                                            <div class="small text-muted" id="filePreviewSize">0 KB</div>
                                        </div>
                                         <button type="button" class="btn btn-xs btn-light" id="fileRemoveBtn" title="<?= e(t('common.remove_file')) ?>" aria-label="<?= e(t('common.remove_file')) ?>"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </div>
                            </div>
                            <div class="invalid-feedback d-block" id="fileErr"></div>
                        </div>

                        <!-- Description -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="description"><?= e(t('upload.optional_desc')) ?></label>
                            <textarea class="form-control" id="description" name="description" rows="2" maxlength="500"
                                      placeholder="<?= e(t('upload.desc_placeholder')) ?>"></textarea>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg" id="uploadBtn">
                                <span class="upload-btn-idle"><i class="bi bi-upload me-2"></i><?= e(t('upload.submit')) ?></span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Progress / result panel -->
                <div id="uploadProgressWrap" class="d-none">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <h5 id="progressLabel" class="mb-2"><?= e(t('upload.uploading')) ?></h5>
                        <div class="progress mx-auto" style="max-width:420px; height:10px">
                            <div class="progress-bar progress-bar-animated progress-bar-striped" id="progressBar" style="width:0%"></div>
                        </div>
                        <p class="text-muted small mt-2 mb-0" id="progressPercent">0%</p>
                    </div>
                </div>

                <div id="uploadResultWrap" class="d-none">
                    <div class="text-center py-4">
                        <i class="bi bi-check-circle-fill text-success display-4"></i>
                        <h5 class="mt-3 mb-2" id="resultTitle"><?= e(t('upload.success')) ?></h5>
                        <p class="text-muted mb-3" id="resultMessage"></p>
                        <div class="d-flex justify-content-center gap-2 flex-wrap" id="resultActions"></div>
                    </div>
                </div>

                <div id="uploadErrorWrap" class="d-none">
                    <div class="text-center py-4">
                        <i class="bi bi-x-octagon-fill text-danger display-4"></i>
                        <h5 class="mt-3 mb-2"><?= e(t('upload.failed_title')) ?></h5>
                        <p class="text-danger mb-3" id="errorMessage"></p>
                        <button type="button" class="btn btn-outline-primary" id="errorRetryBtn"><i class="bi bi-arrow-repeat me-1"></i><?= e(t('upload.try_again')) ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-info-circle me-2"></i><?= e(t('upload.guidance_title')) ?></h5></div>
            <div class="card-body small">
                <ul class="list-unstyled mb-0 d-grid gap-2">
                    <li><i class="bi bi-check2-circle text-success me-2"></i><?= e(t('upload.g1')) ?></li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i><?= e(t('upload.g2', ['max' => $maxSizeMb])) ?></li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i><?= e(t('upload.g3')) ?></li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i><?= e(t('upload.g4')) ?></li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i><?= e(t('upload.g5')) ?></li>
                </ul>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-building me-2"></i><?= e(t('upload.branch_title')) ?></h5></div>
            <div class="card-body">
                <p class="mb-1 fw-semibold"><?= e($branch['branch_name']) ?></p>
                <p class="text-muted small mb-0"><?= e($branch['branch_code']) ?> · <?= e(t('upload.branch_note')) ?></p>
            </div>
        </div>
    </div>
</div>

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';