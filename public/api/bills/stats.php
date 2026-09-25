<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_owner();

switch (method()) {
    case 'GET':
        api_ok(['stats' => Branch::dashboardStats(), 'counts' => Bill::countsByDay(date('Y-m-d', strtotime('-13 days')), date('Y-m-d'))]);
        break;
    default:
        api_error('Method not allowed.', 405);
}