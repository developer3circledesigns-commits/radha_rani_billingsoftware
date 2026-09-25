<?php

declare(strict_types=1);

/**
 * Backfill the database safety copy for bills uploaded before dual storage.
 *
 * Reads each active bill's PDF from storage/uploads and stores it in
 * bills.pdf_bytes, so existing documents gain the same protection as new ones.
 *
 * Usage (from the project root):
 *   php tools/backfill-pdf-copies.php            # report only, changes nothing
 *   php tools/backfill-pdf-copies.php --apply    # actually write the copies
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$apply = in_array('--apply', $argv, true);

$rows = Database::fetchAll(
    "SELECT b.id, b.original_filename, b.stored_filename, b.file_path, b.file_size,
            b.storage_status, b.pdf_hash,
            b.pdf_bytes IS NOT NULL AS has_blob
     FROM bills b
     WHERE b.status = 'active'
     ORDER BY b.id"
);

echo ($apply ? 'Applying' : 'Dry run') . ' - ' . count($rows) . ' active bill(s)' . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

$filled = 0;
$skipped = 0;
$failed  = 0;

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $label = '#' . $id . ' ' . $row['original_filename'];

    if ((int) $row['has_blob'] === 1) {
        echo "  skip    {$label} (already has a database copy)\n";
        $skipped++;
        continue;
    }

    $absolute = BillStorage::absoluteFor((string) $row['file_path']);
    if ($absolute === null) {
        echo "  MISSING {$label} (no readable file at {$row['file_path']})\n";
        $failed++;
        continue;
    }

    $bytes = @file_get_contents($absolute);
    if ($bytes === false || $bytes === '') {
        echo "  FAILED   {$label} (could not read file)\n";
        $failed++;
        continue;
    }

    // Only trust the copy if the byte count matches what was recorded.
    $expected = (int) $row['file_size'];
    if ($expected > 0 && strlen($bytes) !== $expected) {
        echo "  MISMATCH {$label} (file is " . strlen($bytes) . " bytes, expected {$expected})\n";
        $failed++;
        continue;
    }

    $hash = hash('sha256', $bytes);

    if (!$apply) {
        echo "  would fill {$label} (" . number_format(strlen($bytes) / 1024, 1) . " KB)\n";
        $filled++;
        continue;
    }

    try {
        Database::execute(
            'UPDATE bills SET pdf_bytes = ?, pdf_hash = ?, storage_status = ? WHERE id = ?',
            [$bytes, $hash, BillStorage::statusFor(true, true), $id],
            [0] // pdf_bytes -> bind as a LOB
        );
        echo "  filled   {$label} (" . number_format(strlen($bytes) / 1024, 1) . " KB)\n";
        $filled++;
    } catch (Throwable $e) {
        echo "  FAILED   {$label} -> " . $e->getMessage() . PHP_EOL;
        $failed++;
    }
}

echo str_repeat('-', 60) . PHP_EOL;
echo "filled={$filled} skipped={$skipped} failed={$failed}\n";

if (!$apply && $filled > 0) {
    echo "No changes made. Re-run with --apply to store these copies.\n";
}

exit($failed > 0 ? 1 : 0);
