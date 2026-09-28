<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();
$user = current_user();

if (isPost() && post('action') === 'update_settings') {
    if (!csrf_verify()) csrf_fail();

    // Setting::all() returns [setting_key => setting_value]. One extra query,
    // on a POST that only an owner can make, and only so the event can say
    // WHICH setting changed instead of "settings were saved".
    $beforeByKey = array_map('strval', Setting::all());

    $settings = [
        'system_name'         => trim((string) post('system_name', '')),
        'max_file_size_mb'    => max(1, min(200, (int) post('max_file_size_mb', 20))),
        'daily_cash_required' => post('daily_cash_required') ? '1' : '0',
        'daily_card_required' => post('daily_card_required') ? '1' : '0',
        'deleted_bill_retention_days' => max(1, min(365, (int) post('deleted_bill_retention_days', 30))),
    ];

    foreach ($settings as $k => $v) {
        Setting::set($k, $v);
    }

    // Report the NAMES of the settings that actually changed, never their
    // values. A setting value could carry operational data an analyst has no
    // need to see in a SIEM, and "which knob was turned" is the actual
    // question an investigation asks.
    $changed = [];
    foreach ($settings as $k => $v) {
        if (($beforeByKey[$k] ?? null) !== $v) {
            $changed[] = $k;
        }
    }

    SecurityLogger::log(SecurityLogger::ADMIN_SETTINGS_CHANGED, [
        'changed_settings' => $changed,
        'settings_count'   => count($settings),
        'result'           => 'success',
    ]);

    // Mirrored to security.log as admin_settings_changed.
    log_activity((int) $user['id'], null, 'SETTINGS_UPDATED', 'settings', null, 'Updated system settings');
    flash_set('success', t('settings.saved_ok'));
    redirect('owner/settings.php');
}

$settings = Setting::all();

// The portal cannot accept more than the database and PHP allow.
$dbCapBytes = BillStorage::effectiveMaxUploadBytes();
$phpCapBytes = (int) ini_get('upload_max_filesize');
$phpCapBytes = $phpCapBytes > 0 ? $phpCapBytes * 1024 * 1024 : $dbCapBytes;
$effectiveCapBytes = (int) min($dbCapBytes, $phpCapBytes);
$configuredCapBytes = (int) ($settings['max_file_size_mb'] ?? 20) * 1024 * 1024;
$capIsReduced = $configuredCapBytes > $effectiveCapBytes;

$pageTitle = t('settings.title');
$pageSubtitle = t('settings.subtitle');
$activeMenu = 'settings';

ob_start();
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-sliders me-2"></i><?= e(t('settings.general')) ?></h5></div>
            <div class="card-body">
                <form method="post" action="<?= url('owner/settings.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_settings">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="s_name"><?= e(t('settings.system_name')) ?></label>
                            <input type="text" class="form-control" id="s_name" name="system_name" value="<?= e($settings['system_name'] ?? APP_NAME) ?>">
                            <div class="form-text"><?= e(t('settings.system_name_help')) ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="s_max"><?= e(t('settings.max_size')) ?></label>
                            <input type="number" class="form-control" id="s_max" name="max_file_size_mb" min="1" max="200" value="<?= (int) ($settings['max_file_size_mb'] ?? 20) ?>">
                            <div class="form-text"><?= e(t('settings.max_size_help')) ?></div>
                            <?php if ($capIsReduced) : ?>
                            <div class="form-text text-warning-emphasis">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <?= e(t('settings.server_limit', [
                                    'effective' => format_bytes($effectiveCapBytes),
                                    'php'      => format_bytes($phpCapBytes),
                                    'db'       => format_bytes($dbCapBytes),
                                ])) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="s_retention"><?= e(t('settings.restore_window')) ?></label>
                            <input type="number" class="form-control" id="s_retention" name="deleted_bill_retention_days" min="1" max="365" value="<?= (int) ($settings['deleted_bill_retention_days'] ?? 30) ?>">
                            <div class="form-text"><?= e(t('settings.restore_window_help')) ?></div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="s_cash" name="daily_cash_required" <?= ($settings['daily_cash_required'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="s_cash"><?= e(t('settings.require_cash')) ?></label>
                            </div>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="s_card" name="daily_card_required" <?= ($settings['daily_card_required'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="s_card"><?= e(t('settings.require_card')) ?></label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i><?= e(t('settings.save')) ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-info-circle me-2"></i><?= e(t('settings.system_info')) ?></h5></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-4 text-muted"><?= e(t('settings.app')) ?></dt><dd class="col-8"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></dd>
                    <dt class="col-4 text-muted"><?= e(t('settings.environment')) ?></dt><dd class="col-8"><?= e(APP_ENV) ?></dd>
                    <dt class="col-4 text-muted"><?= e(t('settings.database')) ?></dt><dd class="col-8"><?= e(DB_NAME) ?> @ <?= e(DB_HOST) ?></dd>
                    <dt class="col-4 text-muted"><?= e(t('settings.session_lifetime')) ?></dt><dd class="col-8"><?= e(tn('common.minutes', (int) ($settings['session_lifetime_min'] ?? 30))) ?></dd>
                    <dt class="col-4 text-muted"><?= e(t('settings.pdf_storage')) ?></dt><dd class="col-8"><?= e(ROOT_PATH . '/storage/uploads') ?></dd>
                    <dt class="col-4 text-muted"><?= e(t('settings.pdf_safety_copy')) ?></dt><dd class="col-8"><?= e(t('settings.database')) ?> (<code>bills.pdf_bytes</code>)</dd>
                    <dt class="col-4 text-muted"><?= e(t('settings.archive_location')) ?></dt><dd class="col-8"><?= e(ROOT_PATH . '/storage/archive/bills') ?></dd>
                    <dt class="col-4 text-muted"><?= e(t('settings.restore_window_short')) ?></dt><dd class="col-8"><?= e(tn('common.days', (int) ($settings['deleted_bill_retention_days'] ?? 30))) ?></dd>
                    <dt class="col-4 text-muted"><?= e(t('settings.effective_max')) ?></dt><dd class="col-8"><?= e(format_bytes($effectiveCapBytes)) ?></dd>
                    <!-- <dt class="col-4 text-muted">DB max_allowed_packet</dt><dd class="col-8"><?= e(format_bytes(BillStorage::maxPacketBytes())) ?></dd> -->
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-shield-check me-2"></i><?= e(t('settings.security_policy')) ?></h5></div>
            <div class="card-body small">
                <ul class="list-unstyled mb-0 d-grid gap-2">
                    <?php for ($i = 1; $i <= 9; $i++) : ?>
                    <li><i class="bi bi-check2-circle text-success me-2"></i><?= e(t('settings.sp_' . $i)) ?></li>
                    <?php endfor; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';