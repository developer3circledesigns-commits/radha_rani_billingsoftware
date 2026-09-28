<?php

declare(strict_types=1);

/**
 * Raise owner alerts for branches that did not upload their daily bills.
 *
 * Idempotent, so it is safe on an hourly schedule and safe to run twice:
 *
 *   php tools/check-daily-uploads.php
 *   php tools/check-daily-uploads.php --date=2026-09-27
 *   php tools/check-daily-uploads.php --dry-run
 *   php tools/check-daily-uploads.php --backfill=7
 *
 * Without a date it evaluates today. isEnforcedOn() decides whether the owner
 * has asked to hear about that day yet, so running hourly from midnight is
 * harmless: nothing happens until the configured deadline passes.
 *
 * The same sweep also runs on the owner dashboard, which is the fallback for
 * hosts where cron was never configured.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$options = [
    'date'     => null,
    'dry_run'  => false,
    'backfill' => 0,
];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m) === 1) {
        $options['date'] = $m[1];
    } elseif ($arg === '--dry-run') {
        $options['dry_run'] = true;
    } elseif (preg_match('/^--backfill=(\d{1,3})$/', $arg, $m) === 1) {
        $options['backfill'] = (int) $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php tools/check-daily-uploads.php [--date=YYYY-MM-DD] [--dry-run] [--backfill=N]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
}

$mode = DailyCompliance::deadlineMode();
$deadline = DailyCompliance::deadlineTime();
$required = DailyCompliance::requiredTypes();

echo 'Daily upload compliance check' . PHP_EOL;
echo '  Alerts:       ' . (DailyCompliance::isEnabled() ? 'enabled' : 'DISABLED') . PHP_EOL;
echo '  Deadline:     ' . $deadline . ' (' . $mode . ', ' . date_default_timezone_get() . ')' . PHP_EOL;
echo '  Required:     ' . ($required === [] ? 'nothing' : implode(' + ', $required)) . PHP_EOL;
if ($options['dry_run']) {
    echo '  Mode:         DRY RUN, nothing will be written' . PHP_EOL;
}
echo str_repeat('-', 66) . PHP_EOL;

$dates = [];

if ($options['date'] !== null) {
    $dates[] = $options['date'];
} elseif ($options['backfill'] > 0) {
    // Backfill walks backwards from today, skipping days that were never
    // enforced (before the start date, after the end date, or a disabled span)
    // so a first run does not alert on months of history.
    for ($i = 0; $i < $options['backfill']; $i++) {
        $dates[] = date('Y-m-d', strtotime('-' . $i . ' day'));
    }
} else {
    $dates[] = date('Y-m-d');
}

$exit = 0;

foreach ($dates as $date) {
    $status = DailyCompliance::statusForDate($date);

    if (!$status['evaluated']) {
        printf(
            "%s  SKIPPED    deadline %s, not enforced yet%s",
            $date,
            $status['deadline'],
            PHP_EOL
        );
        continue;
    }

    $missing = array_values(array_filter(
        $status['branches'],
        static fn(array $b): bool => $b['missing'] !== []
    ));

    printf(
        "%s  %d/%d branches non-compliant (deadline %s)%s",
        $date,
        $status['branches_missing'],
        $status['branches_total'],
        $status['deadline'],
        PHP_EOL
    );

    foreach ($missing as $branch) {
        printf(
            "      %-18s %-22s missing: %s%s",
            $branch['branch_code'],
            mb_strimwidth($branch['branch_name'], 0, 22, '…'),
            implode(', ', $branch['missing']),
            PHP_EOL
        );
    }

    if ($status['branches_total'] === 0) {
        echo "      no active branches to check" . PHP_EOL;
    }

    if ($options['dry_run']) {
        continue;
    }

    $result = DailyCompliance::evaluateAndNotify($date);
    printf(
        "      %d notification(s) created%s",
        $result['created'],
        PHP_EOL
    );

    // A non-compliant day is an operational fact worth recording, so the exit
    // code lets a monitoring job notice without parsing this output.
    if ($result['branches_missing'] > 0) {
        $exit = 1;
    }
}

echo str_repeat('-', 66) . PHP_EOL;
echo $options['dry_run'] ? "Dry run complete, nothing written.\n" : "Done.\n";

exit($exit);
