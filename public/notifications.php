<?php

declare(strict_types=1);

/**
 * Notification actions for whoever is signed in, owner or branch admin.
 *
 * WHY A SHARED ENDPOINT
 * The bell is rendered in the topbar for both roles, but the owner's alert
 * actions lived on owner/daily-compliance.php behind require_owner(). A branch
 * admin's "Mark all read" would therefore have posted into a 403 - a control
 * that is visible and silently broken. Actions that belong to a PERSON, not to
 * the owner report, belong here where both roles can reach them.
 *
 * Marking is scoped by the session, never by a posted user id: markRead() takes
 * the current user and matches on it, so a forged id cannot touch somebody
 * else's alert.
 *
 * Responds with JSON for the bell's background request and redirects otherwise,
 * so the same URL works with JavaScript off.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_login();

$user = current_user();
$isOwner = ($user['role'] ?? '') === 'owner';

/**
 * Where to send the visitor when no explicit target was given.
 *
 * A branch admin has no compliance report to read, so they are sent to the page
 * where the missing bill can actually be uploaded. Sending them to an owner page
 * would be a 403 with no way back.
 */
$defaultTarget = $isOwner ? 'owner/daily-compliance.php' : 'branch/upload.php';

/** Only a path on this host, so return_to cannot be used as an open redirect. */
$target = static function (?string $requested, string $fallback): string {
    $path = (string) $requested;
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return $fallback;
    }
    if (preg_match('#[\r\n]#', $path) === 1) {
        return $fallback;
    }
    return $path;
};

if (isGet()) {
    redirect($defaultTarget);
}

// ------------------------------------------------------------------
// Mark alerts as read
// ------------------------------------------------------------------
if (!csrf_verify()) {
    if (isAjax()) {
        csrf_fail_api();
    }
    csrf_fail();
}

$action = (string) post('action', '');
$marked = 0;

if ($action === 'mark_all_read') {
    $marked = Notification::markAllRead((int) $user['id']);
} elseif ($action === 'mark_read') {
    Notification::markRead((int) post('id', 0), (int) $user['id']);
    $marked = 1;
} else {
    if (isAjax()) {
        api_error(t('api.method_not_allowed'), 405);
    }
    api_error(t('api.method_not_allowed'), 405);
}

if (isAjax()) {
    api_ok([
        'marked'    => $marked,
        'remaining' => Notification::unreadCount((int) $user['id']),
    ], t('compliance.marked_read'));
}

flash_set('success', t('compliance.marked_read'));
redirect($target(post('return_to', ''), $defaultTarget));
