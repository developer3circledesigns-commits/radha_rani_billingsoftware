<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_owner();

$filters = [
    'branch_id'     => (int) get('branch_id', 0) ?: null,
    'payment_type'  => in_array(get('payment_type'), ['cash', 'card'], true) ? get('payment_type') : null,
    'business_date' => get('business_date') ?: null,
    'uploaded_at'   => get('uploaded_at') ?: null,
    'q'             => get('q') ?: null,
];
$page = max(1, (int) get('page', 1));
$per = min(100, max(1, (int) get('per', 15)));

$result = Bill::search($filters, $page, $per);
api_ok($result);