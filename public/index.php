<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = current_user();

if (!$user) {
    redirect('login.php');
}

if ($user['role'] === 'owner') {
    redirect('owner/dashboard.php');
}

redirect('branch/dashboard.php');