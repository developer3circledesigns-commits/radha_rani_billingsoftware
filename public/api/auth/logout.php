<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!isPost()) {
    api_error('Method not allowed.', 405);
}

if (check_authentication()) {
    $user = current_user();
    log_activity((int) $user['id'], $user['branch_id'], 'LOGOUT', 'user', (int) $user['id'], 'API logout');
}

if (!csrf_verify()) {
    csrf_fail_api('Session token expired.');
}

logout_user();
api_ok(null, 'Logged out.');