<?php

declare(strict_types=1);

/**
 * Application bootstrap - load once at the top of every entry point.
 */

// Composer autoload if present (for third-party libs), otherwise our own.
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

require_once __DIR__ . '/config/config.php';

// Session (secure bootstrap)
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/helpers/Database.php';
require_once __DIR__ . '/helpers/functions.php';
require_once __DIR__ . '/helpers/view.php';
require_once __DIR__ . '/helpers/BillStorage.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/models/Branch.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Bill.php';
require_once __DIR__ . '/models/AuditLog.php';
require_once __DIR__ . '/models/Setting.php';
require_once __DIR__ . '/validators/PdfValidator.php';

// Shared hosts can drop empty folders during deployment, which would make
// the error handler below write nowhere. One stat call per request is cheap.
if (!is_dir(LOG_PATH)) {
    @mkdir(LOG_PATH, 0775, true);
}

// Global error/exception handler -> log, no raw output.
function bootstrap_error_handler(int $severity, string $message, string $file, int $line): void
{
    $log = sprintf("[%s] %s in %s:%d%s", date('Y-m-d H:i:s'), $message, $file, $line, PHP_EOL);
    @file_put_contents(LOG_PATH . '/app.log', $log, FILE_APPEND);
    if (APP_ENV !== 'production') {
        // still render, but the caller will handle display
    }
}

function bootstrap_exception_handler(Throwable $e): void
{
    $log = sprintf(
        "[%s] [%s] %s in %s:%d\n%s%s",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString(),
        PHP_EOL
    );
    @file_put_contents(LOG_PATH . '/app.log', $log, FILE_APPEND);

    if (APP_ENV !== 'production') {
        error_log($log);
    }

    // CLI tools (tools/migrate.php, tools/deploy-check.php, tools/purge-bills.php)
    // must never print an HTML error page into the terminal.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "FATAL: " . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, "  in " . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
        fwrite(STDERR, "  full details: " . LOG_PATH . '/app.log' . PHP_EOL);
        exit(1);
    }

    http_response_code(500);

    // Off production, show the real exception so a setup mistake is
    // diagnosable. In production a bare 500 with no detail.
    $view = APP_ENV !== 'production' && is_file(APP_PATH . '/views/errors/500-debug.php')
        ? '/views/errors/500-debug.php'
        : '/views/errors/500.php';

    require APP_PATH . $view;
    exit;
}

set_error_handler('bootstrap_error_handler');
set_exception_handler('bootstrap_exception_handler');