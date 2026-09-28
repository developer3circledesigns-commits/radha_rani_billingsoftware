<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();
$user = current_user();

/**
 * The feature's tables come from migration 003. Without them there is nothing to
 * show, and every query on this page would throw - so say what to run instead of
 * answering with a 500. This is the one page where the absence is the whole story,
 * which is why the check is visible here rather than silent.
 */
$complianceReady = DailyCompliance::isAvailable();

// ------------------------------------------------------------------
// Mark alerts as read
// ------------------------------------------------------------------
if (isPost() && post('action') === 'mark_all_read') {
    if (!csrf_verify()) csrf_fail();
    if (!$complianceReady) {
        if (isAjax()) {
            api_error(t('compliance.not_installed'), 409);
        }
        flash_set('danger', t('compliance.not_installed'));
        redirect('owner/daily-compliance.php');
    }

    $marked = Notification::markAllRead((int) $user['id']);

    // The bell marks the visible alerts as seen without leaving the page it is
    // open on, so the scripted path answers with JSON and no redirect. Setting a
    // flash here would surface "marked as read" on some later, unrelated page
    // load, which is worse than saying nothing at all.
    if (isAjax()) {
        api_ok(['marked' => $marked], t('compliance.marked_read'));
    }

    flash_set('success', t('compliance.marked_read'));
    $back = (string) post('return_to', '');
    redirect(
        $back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//')
            ? $back
            : 'owner/daily-compliance.php'
    );
}

if (isPost() && post('action') === 'mark_read') {
    if (!csrf_verify()) csrf_fail();
    Notification::markRead((int) post('id', 0), (int) $user['id']);
    redirect('owner/daily-compliance.php');
}

/**
 * Run the day's check immediately.
 *
 * The manual equivalent of the cron, for an owner who does not want to wait for
 * it and for anyone who has not set one up. It deliberately does NOT force:
 * alerting a day whose deadline has not arrived would contradict the rule the
 * whole feature rests on, and the banner would stay hidden while the bell showed
 * a badge.
 */
if (isPost() && post('action') === 'run_check') {
    if (!csrf_verify()) csrf_fail();

    if (!$complianceReady) {
        if (isAjax()) {
            api_error(t('compliance.not_installed'), 409);
        }
        flash_set('danger', t('compliance.not_installed'));
        redirect('owner/daily-compliance.php');
    }

    $checkDate = (string) post('date', '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate) !== 1) {
        $checkDate = date('Y-m-d');
    }

    $run = DailyCompliance::evaluateAndNotify($checkDate);

    if ($run['skipped']) {
        flash_set('info', t('compliance.check_not_due', [
            'time' => DailyCompliance::deadlineFor($checkDate),
        ]));
    } elseif ((int) $run['branches_missing'] > 0) {
        // The count reported is how many branches are missing an upload NOW, not
        // how many alerts this call happened to create. Using the created count
        // would say "everything is fine" on a re-run, when the same branches are
        // still short - the alerts simply already existed.
        flash_set('warning', tn('compliance.check_done_missing', (int) $run['branches_missing'], [
            'n' => (int) $run['branches_missing'],
        ]));
    } else {
        flash_set('success', t('compliance.check_done_none'));
    }

    redirect('owner/daily-compliance.php' . ($checkDate === date('Y-m-d') ? '' : '?date=' . $checkDate));
}

// ------------------------------------------------------------------
// Data
// ------------------------------------------------------------------
$dateFilter = (string) get('date', '');
if ($dateFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter) !== 1) {
    $dateFilter = '';
}

$viewDate = $dateFilter !== '' ? $dateFilter : date('Y-m-d');
$status   = DailyCompliance::statusForDate($viewDate);
$hasRequirement = DailyCompliance::hasRequirement();

/**
 * Not migrated: stop here and say so.
 *
 * The rest of this page reads the notifications and check-history tables, so
 * without them it cannot be rendered at all. Naming the command is far more use
 * to whoever deployed than a 500 with a database error behind it.
 */
if (!$complianceReady) {
    $pageTitle    = t('compliance.title');
    $pageSubtitle = t('compliance.subtitle');
    $activeMenu   = 'compliance';
    ob_start();
    ?>
    <div class="alert alert-danger border-0 shadow-sm d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-octagon-fill fs-5"></i>
        <div>
            <div class="fw-semibold mb-1"><?= e(t('compliance.not_installed_title')) ?></div>
            <p class="mb-2"><?= e(t('compliance.not_installed')) ?></p>
            <code class="d-inline-block p-2 rounded" style="background:rgba(0,0,0,.05)">php tools/migrate.php</code>
        </div>
    </div>
    <?php
    $bodyContent = ob_get_clean();
    require APP_PATH . '/views/layouts/header.php';
    echo $bodyContent;
    require APP_PATH . '/views/layouts/footer.php';
    exit;
}

// Same fallback sweep as the dashboard, so the countdown on this page is a real
// promise: the page refreshes itself at the deadline, and the alert is actually
// there when it comes back. Only today is swept - a past day in the date filter
// is a view, and backfilling history is the CLI's job, not a page load's.
if ($viewDate === date('Y-m-d')) {
    DailyCompliance::sweepIfNeeded($viewDate, $status);
}

$page   = max(1, (int) get('page', 1));
$result = Notification::search(
    ['user_id' => (int) $user['id'], 'state' => get('state') ?: null, 'from' => $dateFilter ?: null],
    $page,
    25
);

$history = DailyCompliance::checkHistory(30);

// The footer hands this to the browser so a page left open across the deadline
// refreshes itself and the alert appears without a manual reload.
$complianceDueAt = DailyCompliance::pendingDeadlineFor($viewDate);

$qs = [];
if ($dateFilter !== '') $qs['date'] = $dateFilter;
if (get('state'))     $qs['state'] = (string) get('state');
$baseUrl = url('owner/daily-compliance.php') . (count($qs) ? '?' . http_build_query($qs) : '');

$pageTitle    = t('compliance.title');
$pageSubtitle = t('compliance.subtitle');
$activeMenu   = 'compliance';

ob_start();
?>

<!-- Summary for the selected day -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-cash-subtle"><i class="bi bi-building text-burgundy"></i></div>
                <div>
                    <div class="kpi-value"><?= (int) $status['branches_total'] ?></div>
                    <div class="kpi-label"><?= e(t('compliance.active_branches')) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon <?= $status['branches_missing'] > 0 ? 'bg-card-subtle' : 'bg-cash-subtle' ?>">
                    <i class="bi bi-exclamation-triangle <?= $status['branches_missing'] > 0 ? 'text-card' : 'text-cash' ?>"></i>
                </div>
                <div>
                    <div class="kpi-value"><?= (int) $status['branches_missing'] ?></div>
                    <div class="kpi-label"><?= e(t('compliance.missing_branches')) ?></div>
                    <div class="kpi-sub"><?= e(t('compliance.deadline_was', ['time' => $status['deadline']])) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-cash-subtle"><i class="bi bi-cash-coin text-cash"></i></div>
                <div>
                    <div class="kpi-value"><?= (int) $status['missing_cash'] ?></div>
                    <div class="kpi-label"><?= e(t('compliance.missing_cash')) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="kpi-icon bg-card-subtle"><i class="bi bi-credit-card text-card"></i></div>
                <div>
                    <div class="kpi-value"><?= (int) $status['missing_card'] ?></div>
                    <div class="kpi-label"><?= e(t('compliance.missing_card')) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$hasRequirement) : ?>
<div class="alert alert-warning border-0 shadow-sm d-flex align-items-center gap-2 flex-wrap">
    <i class="bi bi-exclamation-triangle"></i>
    <span class="flex-grow-1"><?= e(t('compliance.nothing_required')) ?></span>
    <a class="btn btn-sm btn-light" href="<?= url('owner/settings.php') ?>">
        <i class="bi bi-sliders me-1"></i><?= e(t('compliance.open_settings')) ?>
    </a>
</div>
<?php endif; ?>

<?php if (!$status['evaluated'] && $hasRequirement) : ?>
<div class="alert alert-info border-0 shadow-sm d-flex align-items-center gap-2 flex-wrap">
    <i class="bi bi-clock"></i>
    <span class="flex-grow-1">
        <?php if ($complianceDueAt !== null) : ?>
            <?= e(t('compliance.check_due_at', [
                'time' => $status['deadline'],
                'rel'  => Lang::until((int) $complianceDueAt),
            ])) ?>
        <?php else : ?>
            <?= e(t('compliance.not_yet_enforced', ['time' => $status['deadline']])) ?>
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('owner/daily-compliance.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="dc_date"><?= e(t('owner.daily_status.select_date')) ?></label>
                <?php // Pre-filled with the day actually being shown. This page always
                      // displays one day - it defaults to today - so an empty box
                      // beside today's data was a lie, and the dashboard's
                      // equivalent control is pre-filled. ?>
                <input type="date" class="form-control form-control-sm" id="dc_date" name="date" value="<?= e($viewDate) ?>" max="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="dc_state"><?= e(t('compliance.filter_state')) ?></label>
                <select class="form-select form-select-sm" id="dc_state" name="state">
                    <option value=""><?= e(t('compliance.state_all')) ?></option>
                    <option value="open" <?= get('state') === 'open' ? 'selected' : '' ?>><?= e(t('compliance.state_open')) ?></option>
                    <option value="resolved" <?= get('state') === 'resolved' ? 'selected' : '' ?>><?= e(t('compliance.state_resolved')) ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?= e(t('audit.filter')) ?></button>
                <a href="<?= url('owner/daily-compliance.php') ?>" class="btn btn-sm btn-light"><?= e(t('common.reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Per-branch status for the selected day -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i><?= e(t('compliance.branch_status_title', ['date' => Lang::date($viewDate, 'date_format')])) ?></h5>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="small text-muted"><?= e(t('compliance.required_note', [
                'types' => $status['required'] === [] ? '—' : implode(', ', array_map(
                    static fn(string $type): string => t('common.' . $type),
                    $status['required']
                )),
            ])) ?></span>
            <?php // Always available, not only before the deadline. A button that
                  // only appears while the answer is already known to be "not due"
                  // is worse than no button at all. After the deadline this is a
                  // manual re-run, which is what an owner without a cron needs. ?>
            <form method="post" action="<?= url('owner/daily-compliance.php') ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="run_check">
                <input type="hidden" name="date" value="<?= e($viewDate) ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-arrow-repeat me-1"></i><?= e(t('compliance.check_now')) ?>
                </button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!$status['branches']) : ?>
            <div class="empty-state"><i class="bi bi-buildings"></i><p class="mb-0"><?= e(t('branches.empty')) ?></p></div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3"><?= e(t('common.branch')) ?></th>
                        <th class="text-center"><?= e(t('common.cash')) ?></th>
                        <th class="text-center"><?= e(t('common.card')) ?></th>
                        <th><?= e(t('col.last_upload')) ?></th>
                        <th class="pe-3"><?= e(t('common.status')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($status['branches'] as $b) : ?>
                    <tr class="<?= $b['compliant'] ? '' : 'table-danger' ?>">
                        <td class="ps-3">
                            <span class="fw-semibold"><?= e($b['branch_name']) ?></span>
                            <div class="text-muted small"><?= e($b['branch_code']) ?></div>
                        </td>
                        <td class="text-center"><span class="fw-semibold text-cash"><?= (int) $b['cash_count'] ?></span></td>
                        <td class="text-center"><span class="fw-semibold text-card"><?= (int) $b['card_count'] ?></span></td>
                        <td class="small text-muted"><?= e($b['last_upload'] ? Lang::relative((string) $b['last_upload']) : '—') ?></td>
                        <td class="pe-3">
                            <?php if (!$hasRequirement) : ?>
                                <?php // Never "Uploaded" when no bill type is required -
                                      // that would claim a bill arrived when none did. ?>
                                <span class="status-pill status-empty"><i class="bi bi-dash-circle-fill me-1"></i><?= e(t('owner.status.not_required')) ?></span>
                            <?php elseif ($b['compliant']) : ?>
                                <span class="status-pill status-uploaded"><i class="bi bi-check-circle-fill me-1"></i><?= e(t('owner.status.uploaded')) ?></span>
                            <?php else : ?>
                                <span class="status-pill status-empty">
                                    <i class="bi bi-x-circle-fill me-1"></i>
                                    <?= e(t('compliance.missing', [
                                        'types' => implode(', ', array_map(
                                            static fn(string $type): string => t('common.' . $type),
                                            $b['missing']
                                        )),
                                    ])) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Alert history -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-bell me-2"></i><?= e(t('compliance.history_title')) ?></h5></div>
    <div class="card-body p-0">
        <?php if (!$result['rows']) : ?>
            <div class="empty-state"><i class="bi bi-check2-circle"></i><p class="mb-0"><?= e(t('compliance.history_empty')) ?></p></div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3"><?= e(t('compliance.day')) ?></th>
                        <th><?= e(t('common.branch')) ?></th>
                        <th><?= e(t('compliance.alert')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                        <th class="pe-3"><?= e(t('col.time')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $n) :
                    $params = Notification::params($n);
                    $isOpen = $n['resolved_at'] === null;
                ?>
                    <tr>
                        <td class="ps-3 small text-muted"><?= e(Lang::date($n['bill_date'], 'date_format')) ?></td>
                        <td>
                            <span class="fw-semibold"><?= e($params['branch'] ?? ($n['branch_name'] ?? '—')) ?></span>
                            <div class="text-muted small"><?= e($params['code'] ?? ($n['branch_code'] ?? '')) ?></div>
                        </td>
                        <td class="small"><?= e(t($n['body_key'], $params)) ?></td>
                        <td>
                            <?php if ($isOpen) : ?>
                                <span class="status-pill status-partial">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e(t('compliance.state_open')) ?>
                                </span>
                            <?php else : ?>
                                <span class="status-pill status-uploaded">
                                    <i class="bi bi-check-circle-fill me-1"></i><?= e(t('compliance.state_resolved')) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($n['read_at'] === null && $isOpen) : ?>
                            <form method="post" action="<?= url('owner/daily-compliance.php') ?>" class="d-inline ms-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none"><?= e(t('compliance.mark_read')) ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <td class="pe-3 small text-muted"><?= e(Lang::relative($n['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted"><?= e(tn('common.count_records', (int) $result['count'])) ?> · <?= e(t('common.page_of', ['page' => $result['page'], 'pages' => $result['pages']])) ?></span>
            <?= pagination($result, $baseUrl) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($history) : ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="bi bi-clock-history me-2"></i><?= e(t('compliance.sweep_history')) ?></h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3"><?= e(t('compliance.day')) ?></th>
                        <th><?= e(t('compliance.checked_at')) ?></th>
                        <th class="text-center"><?= e(t('compliance.branches')) ?></th>
                        <th class="text-center"><?= e(t('compliance.missing_branches')) ?></th>
                        <th class="pe-3 text-center"><?= e(t('compliance.alerts_raised')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $h) : ?>
                    <tr>
                        <td class="ps-3 small"><?= e(Lang::date($h['check_date'], 'date_format')) ?></td>
                        <td class="small text-muted"><?= e(Lang::datetime($h['evaluated_at'])) ?></td>
                        <td class="text-center"><?= (int) $h['branches_total'] ?></td>
                        <td class="text-center"><?= (int) $h['branches_missing'] ?></td>
                        <td class="pe-3 text-center"><?= (int) $h['notifications_created'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';
