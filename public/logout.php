<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (check_authentication()) {
    $user = current_user();
    log_activity((int) $user['id'], $user['branch_id'], 'LOGOUT', 'user', (int) $user['id'], 'Logout');
}

logout_user();
redirect('login.php');