<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();
$user = current_user();

if (isPost() && post('action') === 'update_settings') {
    if (!csrf_verify()) csrf_fail();

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

    log_activity((int) $user['id'], null, 'SETTINGS_UPDATED', 'settings', null, 'Updated system settings');
    flash_set('success', 'Settings saved successfully.');
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

$pageTitle = 'Settings';
$pageSubtitle = 'System configuration';
$activeMenu = 'settings';

ob_start();
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-sliders me-2"></i>General Settings</h5></div>
            <div class="card-body">
                <form method="post" action="<?= url('owner/settings.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_settings">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="s_name">System Name</label>
                            <input type="text" class="form-control" id="s_name" name="system_name" value="<?= e($settings['system_name'] ?? APP_NAME) ?>">
                            <div class="form-text">Shown in the login page and browser title.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="s_max">Maximum PDF Size (MB)</label>
                            <input type="number" class="form-control" id="s_max" name="max_file_size_mb" min="1" max="200" value="<?= (int) ($settings['max_file_size_mb'] ?? 20) ?>">
                            <div class="form-text">Between 1 and 200 MB.</div>
                            <?php if ($capIsReduced) : ?>
                            <div class="form-text text-warning-emphasis">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Server limit in effect: <strong><?= e(format_bytes($effectiveCapBytes)) ?></strong>
                                (PHP upload limit <?= e(format_bytes($phpCapBytes)) ?>, database packet limit
                                <?= e(format_bytes($dbCapBytes)) ?>). Uploads above this are rejected.
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="s_retention">Deleted Bill Restore Window (days)</label>
                            <input type="number" class="form-control" id="s_retention" name="deleted_bill_retention_days" min="1" max="365" value="<?= (int) ($settings['deleted_bill_retention_days'] ?? 30) ?>">
                            <div class="form-text">Between 1 and 365 days. Deleted bills stay restorable in
                                <em>Recently Deleted</em> for this long, then their stored PDF copies are erased automatically.
                                The bill record is always kept for the audit trail.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="s_cash" name="daily_cash_required" <?= ($settings['daily_cash_required'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="s_cash">Require one Cash PDF per branch per business day</label>
                            </div>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="s_card" name="daily_card_required" <?= ($settings['daily_card_required'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="s_card">Require one Card PDF per branch per business day</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>System Information</h5></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-4 text-muted">Application</dt><dd class="col-8"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></dd>
                    <dt class="col-4 text-muted">Environment</dt><dd class="col-8"><?= e(APP_ENV) ?></dd>
                    <dt class="col-4 text-muted">Database</dt><dd class="col-8"><?= e(DB_NAME) ?> @ <?= e(DB_HOST) ?></dd>
                    <dt class="col-4 text-muted">Session Lifetime</dt><dd class="col-8"><?= (int) ($settings['session_lifetime_min'] ?? 30) ?> minutes</dd>
                    <dt class="col-4 text-muted">PDF Storage</dt><dd class="col-8"><?= e(ROOT_PATH . '/storage/uploads') ?></dd>
                    <dt class="col-4 text-muted">PDF Safety Copy</dt><dd class="col-8">Database (<code>bills.pdf_bytes</code>)</dd>
                    <dt class="col-4 text-muted">Archive Location</dt><dd class="col-8"><?= e(ROOT_PATH . '/storage/archive/bills') ?></dd>
                    <dt class="col-4 text-muted">Restore Window</dt><dd class="col-8"><?= (int) ($settings['deleted_bill_retention_days'] ?? 30) ?> days</dd>
                    <dt class="col-4 text-muted">Effective Upload Max</dt><dd class="col-8"><?= e(format_bytes($effectiveCapBytes)) ?></dd>
                    <dt class="col-4 text-muted">DB max_allowed_packet</dt><dd class="col-8"><?= e(format_bytes(BillStorage::maxPacketBytes())) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Security Policy</h5></div>
            <div class="card-body small">
                <ul class="list-unstyled mb-0 d-grid gap-2">
                    <li><i class="bi bi-check2-circle text-success me-2"></i>Passwords secured with bcrypt hashing</li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i>CSRF protection on all forms</li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i>SQL injection prevented via PDO</li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i>PDF access verified per branch</li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i>Upload restricted to valid PDF files</li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i>Login throttling enabled</li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i>Full audit trail recorded</li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i>Every PDF kept in two places (folder + database)</li>
                    <li><i class="bi bi-check2-circle text-success me-2"></i>Deleted bills recoverable from the safety copy</li>
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