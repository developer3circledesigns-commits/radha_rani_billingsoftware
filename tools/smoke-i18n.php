<?php

declare(strict_types=1);

/**
 * Runtime smoke test for the translation layer.
 *
 * Loads the real bootstrap in CLI, then renders representative strings in every
 * catalogue. Run it after touching Lang.php, a catalogue, or the format helpers:
 *
 *     php tools/smoke-i18n.php
 *
 * It exits non-zero if a probe key is missing from any catalogue, since a missing
 * key means the UI renders a dotted key to the user.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = [];

// Keys that must exist in every catalogue, with the placeholder signature each
// one is expected to accept.
$probes = [
    'common.cash'             => [],
    'common.card'             => [],
    'common.branch'           => [],
    'common.all_branches'     => [],
    'common.page_of'          => ['page', 'pages'],
    'common.showing_of'       => ['shown', 'total'],
    'common.remove_file'      => [],
    'col.document'            => [],
    'col.business_date'       => [],
    'nav.dashboard'           => [],
    'login.heading'           => [],
    'upload.submit'           => [],
    'bdash.cash_today'        => [],
    'myuploads.count'         => ['n'],
    'owner.by_branch.docs'    => ['n'],
    'activity.recent_uploads' => ['n'],
    'bills.delete_confirm'    => ['id', 'filename', 'days'],
    'bills.moved_to_trash'    => ['days'],
    'branches.edit_title'     => ['name'],
    'admins.status_now'       => ['name', 'status'],
    'error.404.message'       => [],
    'error.go_dashboard'      => [],
    'api.file_too_large'      => ['size'],
    'api.future_date'         => [],
    'js.only_pdf'             => [],
    'js.show_password'        => [],
    'js.hide_password'        => [],
    // Audit labels are looked up dynamically as audit.a.<CODE>, so every code
    // the app can log needs a string or the row renders a dotted key.
    'audit.a.BILL_UPLOADED'   => [],
    'audit.a.BILL_RESTORED'   => [],
    'audit.a.BILL_PURGED'     => [],
    'audit.a.LOGIN_DENIED'    => [],

    // Daily compliance. The banner, the bell and the report page are all built
    // from these, and the body keys are stored in notifications.body_key, so a
    // missing one does not fail a build - it ships an alert reading
    // "compliance.missing_card" to the owner months later.
    'nav.daily_compliance'      => [],
    'compliance.alert_title'    => [],
    'compliance.missing_cash'   => [],
    'compliance.missing_card'   => [],
    'compliance.missing_both'   => [],
    'compliance.missing_types'  => ['types'],
    'compliance.missing'        => ['types'],
    'compliance.last_upload_was' => ['when'],
    'compliance.branch_status_title' => ['date'],
    'compliance.required_note'  => ['types'],
    'compliance.not_yet_enforced' => ['time'],
    'compliance.deadline_was'   => ['time'],
    'compliance.severity_danger' => [],
    'compliance.severity_warning' => [],
    'compliance.severity_info'  => [],
    'compliance.weekday_mon'    => [],
    'settings.compliance_enabled' => [],
    'settings.compliance_deadline_time' => [],
    'settings.compliance_mode'  => [],
    'compliance.view_all' => [],
    'compliance.view_my_uploads' => [],
    'compliance.nothing_required' => [],
    'compliance.open_settings' => [],
    'owner.status.not_required' => [],
    'settings.saved_but_no_requirement' => [],
    'js.compliance_restored'    => [],
    'js.compliance_partial'     => ['what'],
    'js.compliance_partial_many' => ['what'],
    'compliance.type_cash_short' => [],
    'compliance.type_card_short' => [],
    'audit.a.BILL_UPLOAD_COMPLIANCE_RESTORED' => [],
    'audit.a.BILL_UPLOAD_COMPLIANCE_PARTIAL'  => [],
];

foreach (array_keys(Lang::available()) as $code) {
    if (!Lang::persist($code)) {
        $failures[] = "$code: Lang::persist() rejected a locale the switcher offers";
        continue;
    }

    foreach ($probes as $key => $placeholders) {
        $value = t($key);
        if ($value === $key) {
            $failures[] = "$code: missing key $key";
            continue;
        }
        foreach ($placeholders as $placeholder) {
            if (!str_contains($value, ':' . $placeholder)) {
                $failures[] = "$code: $key is missing the :$placeholder placeholder";
            }
        }
    }

    // Locale-formatted output must follow the active locale, not the server's.
    printf(
        "%-4s date=%-16s time=%-9s axis=%-10s number=%-12s bytes=%s\n",
        $code,
        Lang::date('2026-09-28', 'date_format'),
        Lang::time('2026-09-28 14:05:00'),
        Lang::date('2026-09-28', 'date_axis_format'),
        Lang::number(1234567.5, 1),
        Lang::bytes(1572864)
    );
}

// Plurals have to actually branch, otherwise "1 documents" ships.
Lang::persist('en');
if (tn('myuploads.count', 1) === tn('myuploads.count', 5)) {
    $failures[] = 'en: myuploads.count does not change with the count';
}
Lang::persist('de');
if (tn('myuploads.count', 1) === tn('myuploads.count', 5)) {
    $failures[] = 'de: myuploads.count does not change with the count';
}

// The compliance banner is the one string where a broken plural is glaring: it
// sits at the top of the owner dashboard, above the KPIs.
foreach (['en', 'de'] as $code) {
    Lang::persist($code);
    if (tn('compliance.banner_title', 1) === tn('compliance.banner_title', 5)) {
        $failures[] = "$code: compliance.banner_title does not branch on the branch count";
    }
    $one = tn('compliance.banner_title', 1, ['n' => 1, 'date' => '2026-09-28', 'deadline' => '23:30']);
    $many = tn('compliance.banner_title', 5, ['n' => 5, 'date' => '2026-09-28', 'deadline' => '23:30']);
    foreach (['1', '5'] as $n) {
        if (!str_contains($n === '1' ? $one : $many, $n)) {
            $failures[] = "$code: compliance.banner_title dropped the :n count";
        }
    }
}

// A missing key must degrade to the key itself and be recorded for diagnosis.
Lang::raw('no.such.key.probe');
if (!in_array('no.such.key.probe', Lang::misses(), true)) {
    $failures[] = 'Lang::misses() did not record a missing key';
}

// HTML escaping still has to work on top of a translated string.
Lang::persist('de');
$name = 'B<&>"X';
if (!str_contains(e(t('branches.edit_title', ['name' => $name])), '&lt;&amp;')) {
    $failures[] = 'e() did not escape a substituted value';
}

if ($failures) {
    echo "\nFAILED:\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}

echo "\nOK: translation layer smoke test passed\n";
