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

    // Compliance deadlines. The weekday grid is stored as JSON keyed by PHP
    // date('w') (0 = Sunday), and is only written when that mode is selected, so
    // switching back to the single-time mode leaves the grid intact for later.
    $deadlineMode = post('daily_upload_deadline_mode') === 'per_weekday' ? 'per_weekday' : 'time';

    $weekdayDeadlines = [];
    if ($deadlineMode === 'per_weekday') {
        foreach (range(0, 6) as $day) {
            $value = trim((string) post('dl_sun_mon_tue_wed_thu_fri_sat_' . $day, ''));
            // A blank weekday means "use the default time", so it is simply left
            // out of the map rather than stored as an empty string.
            if ($value !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1) {
                $weekdayDeadlines[(string) $day] = $value;
            }
        }
    }

    $settings = [
        'system_name'         => trim((string) post('system_name', '')),
        'max_file_size_mb'    => max(1, min(200, (int) post('max_file_size_mb', 20))),
        'daily_cash_required' => post('daily_cash_required') ? '1' : '0',
        'daily_card_required' => post('daily_card_required') ? '1' : '0',
        'deleted_bill_retention_days' => max(1, min(365, (int) post('deleted_bill_retention_days', 30))),
        'daily_upload_alert_enabled'     => post('daily_upload_alert_enabled') ? '1' : '0',
        'daily_upload_deadline_time'     => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) post('daily_upload_deadline_time', '')) === 1
            ? (string) post('daily_upload_deadline_time')
            : '23:30',
        'daily_upload_deadline_mode'     => $deadlineMode,
        'daily_upload_deadline_weekdays' => $weekdayDeadlines === []
            ? ''
            : (string) json_encode($weekdayDeadlines, JSON_UNESCAPED_UNICODE),
        'daily_upload_alert_start_date'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) post('daily_upload_alert_start_date', '')) === 1
            ? (string) post('daily_upload_alert_start_date')
            : '',
        'daily_upload_alert_end_date'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) post('daily_upload_alert_end_date', '')) === 1
            ? (string) post('daily_upload_alert_end_date')
            : '',
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
    // A silent "saved" is how a whole feature ends up looking broken: unticking
    // both requirement boxes switches the daily check off, and nothing on screen
    // said so. Say it plainly when the save leaves nothing to check.
    if ($settings['daily_cash_required'] === '0' && $settings['daily_card_required'] === '0') {
        flash_set('warning', t('settings.saved_but_no_requirement'));
    } else {
        flash_set('success', t('settings.saved_ok'));
    }
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
        <!-- One form spans both cards: the handler writes every setting key from
             the submitted set, so splitting the fields across two forms would
             let a compliance-only save blank out the system name. -->
        <form method="post" action="<?= url('owner/settings.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_settings">

            <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-sliders me-2"></i><?= e(t('settings.general')) ?></h5></div>
            <div class="card-body">
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
                            <div class="form-text"><?= e(t('settings.require_help')) ?></div>
                            <?php // Warn where the decision is made, not only after
                                  // saving. Untick both and the daily check silently
                                  // stops flagging anything, which reads as a broken
                                  // feature rather than a switched-off one. ?>
                            <div class="alert alert-warning border-0 shadow-sm d-none mt-2 mb-0 py-2 px-3 small" id="requireNoneWarning" role="status">
                                <i class="bi bi-exclamation-triangle me-1"></i><?= e(t('settings.require_none_warning')) ?>
                            </div>
                        </div>
                    </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-bell me-2"></i><?= e(t('settings.compliance_title')) ?></h5></div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="c_enabled" name="daily_upload_alert_enabled" <?= ($settings['daily_upload_alert_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="c_enabled"><?= e(t('settings.compliance_enabled')) ?></label>
                </div>
                <div class="form-text mb-3"><?= e(t('settings.compliance_enabled_help')) ?></div>

                <?php // The deadline is a clock time, not a scheduled job, and that
                      // is the single most surprising thing about this form: setting
                      // it to 13:00 does not by itself run anything at 13:00. Say so
                      // here rather than letting the owner discover it by waiting. ?>
                <?php $settingsDueAt = DailyCompliance::pendingDeadlineFor(date('Y-m-d')); ?>
                <div class="alert alert-light border d-flex align-items-start gap-2 mb-3 small">
                    <i class="bi bi-info-circle mt-1"></i>
                    <div>
                        <?php if ($settingsDueAt !== null) : ?>
                            <?= e(t('settings.compliance_when_help', [
                                'time' => DailyCompliance::deadlineFor(date('Y-m-d')),
                                'rel'  => Lang::until($settingsDueAt),
                            ])) ?>
                        <?php else : ?>
                            <?= e(t('settings.compliance_when_help_now', [
                                'time' => DailyCompliance::deadlineFor(date('Y-m-d')),
                            ])) ?>
                        <?php endif; ?>
                        <div class="mt-1">
                            <a href="<?= url('owner/daily-compliance.php') ?>"><?= e(t('compliance.view_report')) ?></a>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="c_time"><?= e(t('settings.compliance_deadline_time')) ?></label>
                        <input type="time" class="form-control" id="c_time" name="daily_upload_deadline_time"
                               value="<?= e(DailyCompliance::deadlineTime()) ?>">
                        <div class="form-text"><?= e(t('settings.compliance_deadline_help')) ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="c_mode"><?= e(t('settings.compliance_mode')) ?></label>
                        <select class="form-select" id="c_mode" name="daily_upload_deadline_mode">
                            <option value="time" <?= DailyCompliance::deadlineMode() === 'time' ? 'selected' : '' ?>><?= e(t('settings.compliance_mode_same')) ?></option>
                            <option value="per_weekday" <?= DailyCompliance::deadlineMode() === 'per_weekday' ? 'selected' : '' ?>><?= e(t('settings.compliance_mode_per_weekday')) ?></option>
                        </select>
                        <div class="form-text"><?= e(t('settings.compliance_mode_help')) ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="c_start"><?= e(t('settings.compliance_start')) ?></label>
                        <input type="date" class="form-control" id="c_start" name="daily_upload_alert_start_date"
                               value="<?= e($settings['daily_upload_alert_start_date'] ?? '') ?>">
                        <div class="form-text"><?= e(t('settings.compliance_window_help')) ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="c_end"><?= e(t('settings.compliance_end')) ?></label>
                        <input type="date" class="form-control" id="c_end" name="daily_upload_alert_end_date"
                               value="<?= e($settings['daily_upload_alert_end_date'] ?? '') ?>">
                        <div class="form-text"><?= e(t('settings.compliance_window_help')) ?></div>
                    </div>
                </div>

                <?php
                $weekdays = DailyCompliance::weekdayDeadlines();
                $dayNames = [
                    0 => t('compliance.weekday_sun'),
                    1 => t('compliance.weekday_mon'),
                    2 => t('compliance.weekday_tue'),
                    3 => t('compliance.weekday_wed'),
                    4 => t('compliance.weekday_thu'),
                    5 => t('compliance.weekday_fri'),
                    6 => t('compliance.weekday_sat'),
                ];
                ?>
                <div class="mt-3" id="weekdayWrap" <?= DailyCompliance::deadlineMode() === 'per_weekday' ? '' : 'hidden' ?>>
                    <label class="form-label"><?= e(t('settings.compliance_weekdays')) ?></label>
                    <div class="row g-2">
                        <?php foreach ($dayNames as $dayNum => $dayLabel) : ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1" for="dl_<?= (int) $dayNum ?>"><?= e($dayLabel) ?></label>
                            <input type="time" class="form-control form-control-sm" id="dl_<?= (int) $dayNum ?>"
                                   name="dl_sun_mon_tue_wed_thu_fri_sat_<?= (int) $dayNum ?>"
                                   value="<?= e($weekdays[$dayNum] ?? '') ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text"><?= e(t('settings.compliance_weekdays_help')) ?></div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i><?= e(t('settings.save')) ?></button>
        </div>
        </form>

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