<?php

declare(strict_types=1);

/**
 * Erase stored PDF copies of bills whose restore window has expired.
 *
 * The bill rows are kept (status stays 'deleted') so the audit trail remains
 * intact; only the file and database copies are destroyed.
 *
 * Safe to run repeatedly and from cron:
 *   php tools/purge-bills.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$retention = Bill::retentionDays();

$due = Database::fetchAll(
    "SELECT COUNT(*) AS c
     FROM bills
     WHERE status = 'deleted' AND purge_after IS NOT NULL AND purge_after <= NOW()"
);

$dueCount = (int) ($due[0]['c'] ?? 0);

echo 'Retention window: ' . $retention . ' day(s)' . PHP_EOL;
echo 'Bills due for purge: ' . $dueCount . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

if ($dueCount === 0) {
    echo "Nothing to purge.\n";
    exit(0);
}

$purged = Bill::purgeExpired();

echo "Purged {$purged} bill(s). Records retained for the audit trail.\n";
exit(0);
