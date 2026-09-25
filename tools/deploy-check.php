<?php

declare(strict_types=1);

/**
 * Deployment preflight check.
 *
 * Verifies everything that commonly breaks when this app is moved from
 * Docker to shared hosting (Hostinger, cPanel, any Apache/LiteSpeed host).
 * Run it from the project root AFTER uploading:
 *
 *   php tools/deploy-check.php
 *
 * Exit code 0 = ready, 1 = at least one blocking problem.
 * Warnings do not fail the check.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);

// Buffer everything: bootstrap.php starts a session, and this script prints
// section headers first, which would otherwise trip "headers already sent".
ob_start();

$errors   = [];
$warnings = [];
$oks      = [];

// NOTE: these must be `function () use (&$x)` closures, not `fn () =>`.
// Arrow functions capture by value, so every finding would be written to a
// throwaway copy and the report would always read "0 ok, 0 errors".
$ok   = function (string $m) use (&$oks): void { $oks[] = $m; };
$warn = function (string $m) use (&$warnings): void { $warnings[] = $m; };
$err  = function (string $m) use (&$errors): void { $errors[] = $m; };

// ------------------------------------------------------------------
// 1. PHP version and extensions
// ------------------------------------------------------------------
echo "== PHP runtime ==\n";

if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
    $ok("PHP " . PHP_VERSION . " (needs 8.0+)");
} else {
    $err("PHP " . PHP_VERSION . " is too old - the app requires PHP 8.0 or newer.");
}

foreach (['pdo_mysql' => 'database access', 'fileinfo' => 'PDF MIME validation', 'json' => 'JSON API', 'mbstring' => 'UTF-8 handling'] as $ext => $why) {
    if (extension_loaded($ext)) {
        $ok("extension {$ext} ({$why})");
    } else {
        $err("extension {$ext} is missing ({$why}) - enable it in hPanel > Select PHP Version.");
    }
}

// ------------------------------------------------------------------
// 2. PHP limits (must come from .user.ini or hPanel, never php_value)
// ------------------------------------------------------------------
echo "== PHP limits ==\n";

$uploadIni  = ini_get('upload_max_filesize');
$postIni    = ini_get('post_max_size');
$memoryIni  = ini_get('memory_limit');
$execIni    = (int) ini_get('max_execution_time');

$toBytes = static function (string $v): int {
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $unit = strtolower($v[strlen($v) - 1]);
    $num  = (float) $v;
    return (int) match ($unit) {
        'g'     => $num * 1024 * 1024 * 1024,
        'm'     => $num * 1024 * 1024,
        'k'     => $num * 1024,
        default => $num,
    };
};

$uploadBytes = $toBytes($uploadIni);
$postBytes   = $toBytes($postIni);
$memoryBytes = $toBytes($memoryIni);

if ($uploadBytes >= 20 * 1024 * 1024) {
    $ok("upload_max_filesize = {$uploadIni}");
} else {
    $warn("upload_max_filesize = {$uploadIni} - below the portal's 20 MB default. Raise it in public/.user.ini or hPanel.");
}
if ($postBytes > $uploadBytes) {
    $ok("post_max_size = {$postIni}");
} else {
    $warn("post_max_size = {$postIni} must be LARGER than upload_max_filesize ({$uploadIni}), otherwise big uploads are truncated.");
}
if ($memoryBytes >= 128 * 1024 * 1024) {
    $ok("memory_limit = {$memoryIni}");
} else {
    $warn("memory_limit = {$memoryIni} - PDFs are buffered in memory; 256M is recommended.");
}
if ($execIni === 0 || $execIni >= 60) {
    $ok("max_execution_time = {$execIni}");
} else {
    $warn("max_execution_time = {$execIni} may be too short for large PDF writes.");
}

if (ini_get('display_errors') === '1' || ini_get('display_errors') === 'On') {
    $warn('display_errors is ON - PHP errors are shown to visitors. Set APP_ENV=production.');
} else {
    $ok('display_errors is off');
}

// ------------------------------------------------------------------
// 3. Filesystem layout
// ------------------------------------------------------------------
echo "== Filesystem layout ==\n";

/**
 * Scan one .htaccess for directives that work on the developer's machine but
 * return HTTP 500 on a shared host. Expects comment lines to be stripped.
 */
$checkHtaccess = function (string $ht, string $label) use ($ok, $err, $warn): void {
    if (preg_match('/^\s*php_value\s/mi', $ht)) {
        $err("{$label} uses \"php_value\" - this needs mod_php and returns HTTP 500 on LiteSpeed/PHP-FPM. Move it to public/.user.ini.");
    } else {
        $ok("{$label} has no php_value (LiteSpeed safe)");
    }
    if (preg_match('/^\s*Options\s/mi', $ht)) {
        $warn("{$label} sets \"Options\" - needs \"AllowOverride Options\" and can 500 on strict hosts.");
    }
    if (preg_match('/^\s*ServerTokens\s/mi', $ht)) {
        $err("{$label} sets \"ServerTokens\" - server-config only, returns HTTP 500 from .htaccess.");
    }
    if (preg_match('/<Directory\b/i', $ht)) {
        $err("{$label} contains a <Directory> block - server-config only, returns HTTP 500 from .htaccess.");
    }

    // AddOutputFilterByType is provided by mod_filter, NOT mod_deflate.
    // Guarding it with <IfModule mod_deflate.c> alone causes a 500.
    if (preg_match('/AddOutputFilterByType/i', $ht)) {
        if (preg_match('/<IfModule\s+mod_filter\.c>/i', $ht)) {
            $ok("{$label} guards AddOutputFilterByType with mod_filter");
        } else {
            $err("{$label} uses AddOutputFilterByType without a mod_filter guard - returns HTTP 500 where mod_filter is absent.");
        }
    }

    // Other module-provided directives must sit inside an IfModule guard.
    foreach ([
        'ExpiresByType' => 'mod_expires',
        'ExpiresActive' => 'mod_expires',
        'Header'        => 'mod_headers',
        'RemoveHandler' => 'mod_mime',
        'RemoveType'    => 'mod_mime',
    ] as $directive => $module) {
        if (preg_match('/^\s*' . $directive . '\b/mi', $ht)
            && !preg_match('/<IfModule\s+' . preg_quote($module, '/') . '\.c>/i', $ht)) {
            $err("{$label}: {$directive} has no <IfModule {$module}.c> guard - it will 500 where {$module} is not loaded.");
        }
    }

    foreach (['app', 'storage', 'tools', 'database'] as $dir) {
        if (stripos($ht, $dir) === false) {
            $warn("{$label} does not mention {$dir}/ - confirm it is blocked from the web.");
        }
    }
};

$publicDir = $root . '/public';

foreach ([
    'public/'          => $publicDir,
    'app/'             => $root . '/app',
    'app/config/'      => $root . '/app/config',
    'storage/uploads/' => $root . '/storage/uploads',
    'storage/archive/' => $root . '/storage/archive/bills',
    'storage/logs/'    => $root . '/storage/logs',
    'tools/'           => $root . '/tools',
] as $label => $path) {
    if (is_dir($path)) {
        $ok("directory {$label} exists");
    } else {
        $err("directory {$label} is MISSING ({$path}) - create it and chmod 755.");
    }
}

foreach ([
    'storage/uploads' => $root . '/storage/uploads',
    'storage/archive/bills' => $root . '/storage/archive/bills',
    'storage/logs'   => $root . '/storage/logs',
] as $label => $path) {
    if (is_dir($path) && is_writable($path)) {
        $ok("{$label} is writable");
    } elseif (is_dir($path)) {
        $err("{$label} is NOT writable - run: chmod -R 755 {$path} (or 775)");
    }
}

// ------------------------------------------------------------------
// 4. Web-server config files
// ------------------------------------------------------------------
echo "== Web server config ==\n";

$htaccess = $publicDir . '/.htaccess';
if (is_file($htaccess)) {
    $ht = (string) file_get_contents($htaccess);

    // Apache ignores "#" comment lines, so they must be removed before any
    // directive check - otherwise explanatory comments (e.g. a line that
    // literally says "<Directory> is server-config only") trip the scanner.
    $htCode = preg_replace('/^\s*#.*$/m', '', $ht);

    $checkHtaccess($htCode, 'public/.htaccess');
    $ok('public/.htaccess present');
} else {
    $warn('public/.htaccess is missing - hardening rules are not applied.');
}

// The project-root .htaccess only matters for the flat-upload layout, but it
// is checked the same way so a copy/paste mistake cannot ship a 500.
$rootHtaccess = $root . '/.htaccess';
if (is_file($rootHtaccess)) {
    $checkHtaccess(
        preg_replace('/^\s*#.*$/m', '', (string) file_get_contents($rootHtaccess)),
        '.htaccess'
    );
}

$userIni = $publicDir . '/.user.ini';
if (is_file($userIni)) {
    $ok('public/.user.ini present');
} else {
    $warn('public/.user.ini is missing - PHP limits rely on hPanel settings only.');
}

$storageHt = $root . '/storage/.htaccess';
if (is_file($storageHt)) {
    $ok('storage/.htaccess present (uploads cannot execute)');
} else {
    $warn('storage/.htaccess is missing - uploaded files are not explicitly blocked from executing.');
}

// composer install is not required, but warn if vendor is half-present.
$vendor = $root . '/vendor';
if (is_dir($vendor)) {
    $entries = array_values(array_diff(scandir($vendor) ?: [], ['.', '..']));
    if ($entries === []) {
        $ok('vendor/ empty - no Composer packages required');
    } else {
        $ok('vendor/ contains ' . count($entries) . ' entries');
    }
}

// ------------------------------------------------------------------
// 5. Secrets
// ------------------------------------------------------------------
echo "== Secrets ==\n";

$localConfig = $root . '/app/config/config.local.php';
if (is_file($localConfig)) {
    $ok('app/config/config.local.php present (credentials kept out of config.php)');
} else {
    $warn('app/config/config.local.php is absent - shared-hosting DB credentials will have nowhere to live.');
}

// A real password baked into config.php would be a leak risk.
$configSrc = (string) file_get_contents($root . '/app/config/config.php');
if (preg_match("/define\('DB_PASS',\s*env\('DB_PASS',\s*'[^']+'\s*\)\)/", $configSrc)) {
    $err("config.php has a hard-coded database password. Move it to app/config/config.local.php.");
} else {
    $ok('config.php has no hard-coded database password');
}

// ------------------------------------------------------------------
// 6. Database
// ------------------------------------------------------------------
echo "== Database ==\n";

require_once $root . '/app/bootstrap.php';

try {
    $db = Database::connection();
    $ok('connected to ' . DB_NAME . ' @ ' . DB_HOST . ':' . DB_PORT);

    $row = Database::fetch('SELECT VERSION() AS v');
    $ok('server ' . ($row['v'] ?? '?'));

    // Required tables
    $tables = array_column(
        Database::fetchAll('SHOW TABLES'),
        'Tables_in_' . DB_NAME
    );
    foreach (['users', 'branches', 'bills', 'settings', 'audit_logs'] as $t) {
        if (in_array($t, $tables, true)) {
            $ok("table {$t}");
        } else {
            $err("table {$t} is missing - import database/init.sql or run: php tools/migrate.php");
        }
    }

    // Dual-storage columns
    if (in_array('bills', $tables, true)) {
        $billCols = array_column(Database::fetchAll('SHOW COLUMNS FROM bills'), 'Field');
        foreach (['pdf_bytes', 'pdf_hash', 'storage_status', 'archived_path', 'purge_after'] as $c) {
            if (in_array($c, $billCols, true)) {
                $ok("bills.{$c}");
            } else {
                $err("bills.{$c} is missing - run: php tools/migrate.php");
            }
        }
    }

    // The single most common shared-hosting failure.
    $packet = BillStorage::maxPacketBytes();
    $effective = BillStorage::effectiveMaxUploadBytes();
    $mib = static fn(int $b): string => round($b / 1024 / 1024, 1) . ' MB';
    if ($packet <= 0) {
        $warn('could not read max_allowed_packet');
    } elseif ($packet >= 64 * 1024 * 1024) {
        $ok("max_allowed_packet = {$mib($packet)} (full 20 MB bills supported)");
    } else {
        $warn("max_allowed_packet = {$mib($packet)} - uploads are capped at {$mib($effective)}. Ask the host to raise it, or lower the portal's Maximum PDF Size setting.");
    }

    // Storage health summary
    $counts = Database::fetch(
        "SELECT SUM(status = 'active') AS active,
                SUM(status = 'deleted') AS deleted,
                SUM(status = 'deleted' AND storage_status <> 'none') AS restorable,
                SUM(storage_status = 'db_only') AS db_only,
                SUM(pdf_bytes IS NULL AND status = 'active') AS missing_db_copy
         FROM bills"
    );
    $ok(sprintf(
        'bills: %d active, %d deleted (%d restorable), %d db-only, %d active without DB copy',
        (int) ($counts['active'] ?? 0),
        (int) ($counts['deleted'] ?? 0),
        (int) ($counts['restorable'] ?? 0),
        (int) ($counts['db_only'] ?? 0),
        (int) ($counts['missing_db_copy'] ?? 0)
    ));

    if ((int) ($counts['missing_db_copy'] ?? 0) > 0) {
        $warn("some active bills have no database copy - run: php tools/backfill-pdf-copies.php --apply");
    }

    $retention = Bill::retentionDays();
    $ok("deleted-bill restore window: {$retention} day(s)");
} catch (Throwable $e) {
    $err('database check failed: ' . $e->getMessage());
    $err('check app/config/config.local.php against the hPanel MySQL values');
}

// ------------------------------------------------------------------
// Report
// ------------------------------------------------------------------
echo "\n";
echo str_repeat('=', 64) . "\n";
foreach ($oks as $m) {
    echo "  OK    {$m}\n";
}
foreach ($warnings as $m) {
    echo "  WARN  {$m}\n";
}
foreach ($errors as $m) {
    echo "  ERROR {$m}\n";
}
echo str_repeat('=', 64) . "\n";
echo sprintf("%d ok, %d warning(s), %d error(s)\n", count($oks), count($warnings), count($errors));

ob_end_flush();

exit($errors === [] ? 0 : 1);
