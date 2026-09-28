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
                <h5 class="modal-title" id="confirmModalTitle"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i><?= e(t('common.confirm')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button>
            </div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                <button type="button" class="btn btn-danger" id="confirmModalOk"><?= e(t('common.yes_continue')) ?></button>
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
    // Client-side strings. Inlined rather than fetched from a JSON file
    // because the .htaccess rules return 404 for /lang/*.json, so a request
    // for a translation file would fail on every deployment layout. Only the
    // js.* keys travel to the browser; the rest of the catalogue stays server
    // side. The HEX flags keep a catalogue string from closing the <script>
    // element, since app/lang/*.php is editable on shared hosting.
    window.APP.i18n = <?= json_encode(client_i18n_strings(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= url('assets/js/app.js') ?>"></script>
<?php if (!empty($extraScripts)) : foreach ($extraScripts as $s) : ?>
<script src="<?= url($s) ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>