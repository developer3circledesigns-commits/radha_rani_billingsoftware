</main>

        <footer class="app-footer">
            <span>© <?= date('Y') ?> <?= e(APP_ORG) ?> · <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></span>
        </footer>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="appToasts"></div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalTitle"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmModalOk">Yes, continue</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
    window.APP = window.APP || {};
    window.APP.BASE_URL = <?= json_encode(BASE_URL) ?>;
    window.APP.CSRF = <?= json_encode(csrf_token()) ?>;
    window.APP.MAX_UPLOAD_MB = <?= json_encode((int) Setting::get('max_file_size_mb', 20)) ?>;
</script>
<script src="<?= url('assets/js/app.js') ?>"></script>
<?php if (!empty($extraScripts)) : foreach ($extraScripts as $s) : ?>
<script src="<?= url($s) ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>