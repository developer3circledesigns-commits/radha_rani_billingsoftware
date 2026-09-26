<?php

declare(strict_types=1);

/**
 * Radha Rani Hotel Portal - Application Configuration
 */

// ------------------------------------------------------------------
// Environment / Docker overrides
// ------------------------------------------------------------------
function env(string $key, $default = null)
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}

// ------------------------------------------------------------------
// Local overrides (shared hosting credentials live here)
// ------------------------------------------------------------------
// Loaded FIRST, before any constant is defined, so that anything it
// defines wins over the built-in defaults below. Optional: if the file
// does not exist the defaults are used unchanged (that is the Docker case,
// where compose supplies environment variables instead).
//
// Precedence everywhere below is:
//   environment variable  >  external credentials file  >  config.local.php
//   >  built-in default
$hasEnvDb = getenv('DB_NAME') !== false;

if (!$hasEnvDb) {
    $externalCredentialsFile = dirname(__DIR__, 3) . '/radha-rani-credentials.php';
    if (is_file($externalCredentialsFile)) {
        require_once $externalCredentialsFile;
    }
}

if (!$hasEnvDb && !defined('DB_NAME')) {
    $localConfigFile = __DIR__ . '/config.local.php';
    if (is_file($localConfigFile)) {
        require_once $localConfigFile;
    }
}

// ------------------------------------------------------------------
// Core settings
// ------------------------------------------------------------------
define('APP_NAME', 'Radha Rani Hotel Portal');
define('APP_ORG', 'Radha Rani Hotel');
define('APP_VERSION', '1.0.0');
// Default to 'production', not 'development'. A deployment that never sets
// APP_ENV (shared hosting normally does not) must fail CLOSED: with the old
// 'development' default, display_errors stayed on and every PHP warning
// rendered to visitors absolute paths, SQL fragments and the DB DSN.
// Docker opts in explicitly with APP_ENV=development (docker-compose.yml), and
// a developer can set APP_ENV=development in app/config/config.local.php.
define('APP_ENV', env('APP_ENV', defined('APP_ENV') ? APP_ENV : 'production'));

// Base URL. When deployed at the web root (container / shared-hosting docroot)
// the app's own files are all at the site root, so BASE_URL is scheme + host.
// For sub-directory hosting, set APP_URL explicitly (e.g. https://domain/portal).
$detectedBase = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

define('BASE_URL', rtrim(env('APP_URL', defined('APP_URL') ? APP_URL : $detectedBase), '/'));

// ------------------------------------------------------------------
// Paths
// ------------------------------------------------------------------
define('ROOT_PATH', dirname(__DIR__, 2));
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('APP_PATH', ROOT_PATH . '/app');
define('UPLOAD_PATH', ROOT_PATH . '/storage/uploads');
define('LOG_PATH', ROOT_PATH . '/storage/logs');

// ------------------------------------------------------------------
// Database
//
// Precedence: environment variable (Docker / CI) > app/config/config.local.php
// (shared hosting) > built-in default. config.local.php is loaded at the
// top of this file; it is denied from the web by the .htaccess rules.
// ------------------------------------------------------------------
// config.local.php may already have defined these. define() on an existing
// constant emits "Constant X already defined" and is ignored, so guard each one
// or the log fills with five warnings on every single request.
$dbSettings = [
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'radha_rani',
    'DB_USER' => 'radha',
    'DB_PASS' => '',
];
foreach ($dbSettings as $dbConst => $dbFallback) {
    if (!defined($dbConst)) {
        define($dbConst, env($dbConst, $dbFallback));
    }
}

// ------------------------------------------------------------------
// Session security
// ------------------------------------------------------------------
define('SESSION_NAME', 'radha_rani_session');
define('SESSION_LIFETIME', (int) env('SESSION_LIFETIME', 1800)); // 30 minutes
define('SESSION_TIMEOUT', (int) env('SESSION_TIMEOUT', 14400));  // absolute 4h

// ------------------------------------------------------------------
// Upload rules
// ------------------------------------------------------------------
define('MAX_FILE_SIZE', (int) env('MAX_FILE_SIZE', 20971520)); // 20 MB
define('ALLOWED_MIME', 'application/pdf');
define('ALLOWED_EXT', 'pdf');

// ------------------------------------------------------------------
// Login throttling
// ------------------------------------------------------------------
define('LOGIN_MAX_ATTEMPTS', (int) env('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_MINUTES', (int) env('LOGIN_LOCKOUT_MINUTES', 15));

// ------------------------------------------------------------------
// Security event log (Wazuh feed)
//
// One JSON object per line, appended by app/helpers/SecurityLogger.php and
// tailed by the Wazuh Windows agent. It sits in LOG_PATH, which is already
// unreachable over HTTP (denied by .htaccess in the project root, in public/
// and in storage/, and by nginx.conf in the container stack).
//
// SECURITY_LOG_ENABLED is the kill switch for the whole integration: set it
// to 0 - here, or as an environment variable - and every security event is
// dropped before it reaches the filesystem. The application is unaffected.
// ------------------------------------------------------------------
define('SECURITY_LOG_ENABLED', env('SECURITY_LOG_ENABLED', '1') !== '0');
define('SECURITY_LOG_FILE', LOG_PATH . '/security.log');
// Rotate at 20 MB to keep security.log.1 bounded. Set 0 to disable rotation.
define('SECURITY_LOG_MAX_BYTES', (int) env('SECURITY_LOG_MAX_BYTES', 20971520));

// ------------------------------------------------------------------
// Timezone / locale
// ------------------------------------------------------------------
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata'));

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// Error reporting.
// Production: never render errors to the response, always write them to
// storage/logs/app.log so problems stay diagnosable.
// CLI is the one place showing errors is safe - no web server ever serves it -
// and the preflight tools in tools/ need to see their own failures, so keep
// display_errors on there regardless of APP_ENV.
$showErrors = PHP_SAPI === 'cli' || APP_ENV !== 'production';
ini_set('display_errors', $showErrors ? '1' : '0');
ini_set('log_errors', '1');
if ($showErrors) {
    ini_set('error_reporting', E_ALL);
}