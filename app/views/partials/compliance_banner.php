<?php
/**
 * Daily upload compliance banner (owner dashboard).
 *
 * Expects: $compliance - the array from DailyCompliance::statusForDate().
 * Optional: $complianceDueAt - unix ts of today's pending check, or null.
 *
 * Rendered from the live evaluation rather than from stored alerts, so this can
 * never disagree with the Daily Upload Status table below it. Only shows once
 * the configured deadline has passed, which is why an owner opening the page at
 * 09:00 sees nothing: the day is still in progress and nobody has missed
 * anything yet.
 *
 * Before the deadline it does not stay silent, though - silence there reads as a
 * broken feature, especially right after changing the deadline in Settings. It
 * says when the check is due instead.
 *
 * partial() uses EXTR_SKIP, so $compliance must be passed in explicitly rather
 * than picked up from the including scope.
 */
$compliance = $compliance ?? null;
if (!is_array($compliance)) {
    return;
}

if (!$compliance['evaluated']) {
    if (($complianceDueAt ?? null) === null) {
        return;
    }
    ?>
    <div class="alert alert-info border-0 shadow-sm compliance-banner" role="status">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-clock"></i>
            <span class="small">
                <?= e(t('compliance.check_due_at', [
                    'time' => $compliance['deadline'],
                    'rel'  => Lang::until((int) $complianceDueAt),
                ])) ?>
            </span>
            <a class="btn btn-sm btn-light ms-auto" href="<?= url('owner/daily-compliance.php') ?>">
                <i class="bi bi-sliders me-1"></i><?= e(t('compliance.view_report')) ?>
            </a>
        </div>
    </div>
    <?php
    return;
}

if ($compliance['branches_missing'] === 0) {
    return;
}

$missingBranches = array_values(array_filter(
    $compliance['branches'],
    static fn(array $b): bool => $b['missing'] !== []
));

$typeLabels = [
    'cash' => t('common.cash'),
    'card' => t('common.card'),
];
?>
<div class="alert alert-danger border-0 shadow-sm compliance-banner" role="alert">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-octagon-fill fs-5"></i>
        <div class="flex-grow-1">
            <div class="fw-semibold mb-1">
                <?= e(tn('compliance.banner_title', $compliance['branches_missing'], [
                    'n'       => $compliance['branches_missing'],
                    'date'    => Lang::date($compliance['date'], 'date_format'),
                    'deadline'=> $compliance['deadline'],
                ])) ?>
            </div>
            <ul class="list-unstyled mb-2 small">
                <?php foreach ($missingBranches as $b) : ?>
                <li class="mb-1">
                    <i class="bi bi-dot"></i>
                    <span class="fw-semibold"><?= e($b['branch_name']) ?></span>
                    <span class="text-muted">(<?= e($b['branch_code']) ?>)</span>
                    &mdash;
                    <?= e(t('compliance.missing_types', [
                        'types' => implode(', ', array_map(
                            static fn(string $type): string => (string) ($typeLabels[$type] ?? $type),
                            $b['missing']
                        )),
                    ])) ?>
                    <?php if (!empty($b['last_upload'])) : ?>
                    <span class="text-muted"><?= e(t('compliance.last_upload_was', [
                        'when' => Lang::relative((string) $b['last_upload']),
                    ])) ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <a class="btn btn-sm btn-light" href="<?= url('owner/daily-compliance.php') ?>">
                    <i class="bi bi-list-check me-1"></i><?= e(t('compliance.view_report')) ?>
                </a>
                <?php // No "acknowledge" button on purpose. The banner is drawn from
                      // the live evaluation, so a button that resolved the stored
                      // alert would leave the banner on screen with an empty bell
                      // next to it. The alert is closed by the branch uploading
                      // the bill, which is the only thing that should clear it. ?>
            </div>
        </div>
    </div>
</div>
