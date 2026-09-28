<?php
/**
 * Notification bell (topbar).
 *
 * Shown to the owner AND to branch admins. An admin who has missed an upload
 * is the one person who can fix it, so an alert that only ever reached the owner
 * left the person accountable unable to see why they were being chased.
 *
 * Server-rendered, no polling: the list is correct at page load, and a new
 * alert shows up on the next navigation. app.js hides the badge and posts the
 * read form when the dropdown is opened, so opening the bell is what marks the
 * visible alerts as seen.
 *
 * Every link and form here is role-aware. The owner has a compliance report to
 * read; a branch admin does not, and pointing them at owner/ would be a 403.
 *
 * $currentUser is computed in header.php:7 and available here.
 */
$currentUser = $currentUser ?? current_user();

if (!is_array($currentUser) || ($currentUser['role'] ?? '') === '') {
    return;
}

// No notifications table means the migration has not been run on this host.
// Render nothing rather than letting the layout die: a missing bell is a far
// smaller problem than a 500 on every page.
if (!Notification::isAvailable()) {
    return;
}

$notifIsOwner = ($currentUser['role'] ?? '') === 'owner';

$notifUserId = (int) $currentUser['id'];
$notifUnread = Notification::unreadCount($notifUserId);
$notifItems  = Notification::latest($notifUserId, 8);

// Where an item leads: the owner reads the report, an admin goes straight to the
// upload form that can close the gap.
$notifItemUrl = $notifIsOwner
    ? url('owner/daily-compliance.php')
    : url('branch/upload.php');

$severityIcons = [
    'danger'  => ['bi-exclamation-octagon-fill', 'text-danger'],
    'warning' => ['bi-exclamation-triangle-fill', 'text-warning'],
    'info'    => ['bi-info-circle-fill', 'text-primary'],
];
// Not Bootstrap's .badge + .alert-* pair: this theme redefines .alert-* through
// the --bs-alert-* custom properties, which only .alert itself consumes, so on a
// span the label came out as white text on a transparent background. These reuse
// the readable colour pairs from .status-*.
$severityClasses = [
    'danger'  => 'notif-sev notif-sev-danger',
    'warning' => 'notif-sev notif-sev-warning',
    'info'    => 'notif-sev notif-sev-info',
];
?>
<div class="dropdown">
    <button class="btn btn-icon position-relative" type="button" id="notifBell"
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
            aria-label="<?= e(t('compliance.bell_label')) ?>">
        <i class="bi bi-bell"></i>
        <span class="notif-badge<?= $notifUnread === 0 ? ' d-none' : '' ?>" data-notif-badge
              aria-hidden="<?= $notifUnread === 0 ? 'true' : 'false' ?>"><?= $notifUnread > 99 ? '99+' : $notifUnread ?></span>
    </button>

    <div class="dropdown-menu dropdown-menu-end notif-menu p-0">
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <span class="fw-semibold small"><?= e(t('compliance.bell_title')) ?></span>
            <?php // posts to the shared endpoint, not to the owner report: a branch
                  // admin cannot reach that page, and a form pointing at a 403 is
                  // a control that looks alive and is not. ?>
            <form method="post" action="<?= url('notifications.php') ?>" id="notifReadForm" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <input type="hidden" name="return_to" value="<?= e($notifItemUrl) ?>">
                <?php if ($notifUnread > 0) : ?>
                <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none"><?= e(t('compliance.mark_all_read')) ?></button>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!$notifItems) : ?>
            <div class="px-3 py-4 text-center text-muted small">
                <i class="bi bi-check2-circle d-block mb-1 fs-5"></i>
                <?= e(t('compliance.bell_empty')) ?>
            </div>
        <?php else : ?>
            <div class="notif-list">
                <?php foreach ($notifItems as $n) :
                    $icon  = $severityIcons[$n['severity']] ?? $severityIcons['info'];
                    $badge = $severityClasses[$n['severity']] ?? $severityClasses['info'];
                    $unread = $n['read_at'] === null && $n['resolved_at'] === null;
                ?>
                <a class="notif-item<?= $unread ? ' is-unread' : '' ?><?= $n['resolved_at'] !== null ? ' is-resolved' : '' ?>"
                   href="<?= e($notifItemUrl) ?>" data-notif-id="<?= (int) $n['id'] ?>">
                    <i class="bi <?= e($icon[0]) ?> <?= e($icon[1]) ?>"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="small fw-semibold text-truncate"><?= e(t($n['title_key'], Notification::params($n))) ?></div>
                        <div class="small text-muted"><?= e(t($n['body_key'], Notification::params($n))) ?></div>
                        <div class="notif-meta">
                            <span class="<?= e($badge) ?>"><?= e(t('compliance.severity_' . $n['severity'])) ?></span>
                            <span><?= e(Lang::relative($n['created_at'])) ?></span>
                            <?php if ($n['resolved_at'] !== null) : ?>
                            <span class="text-success"><?= e(t('compliance.resolved_badge')) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <div class="px-3 py-2 border-top text-center">
                <?php // Only the owner has a full compliance report. A branch admin
                      // gets a link to the upload form instead, because "view all"
                      // has to lead somewhere they can actually act. ?>
                <a class="small text-decoration-none" href="<?= e($notifIsOwner ? url('owner/daily-compliance.php') : url('branch/my-uploads.php')) ?>">
                    <?= e(t($notifIsOwner ? 'compliance.view_all' : 'compliance.view_my_uploads')) ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
