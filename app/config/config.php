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
//   environment variable  >  config.local.php  >  built-in default
$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    require_once $localConfigFile;
}

// ------------------------------------------------------------------
// Core settings
// ------------------------------------------------------------------
define('APP_NAME', 'Radha Rani Hotel Portal');
define('APP_ORG', 'Radha Rani Hotel');
define('APP_VERSION', '1.0.0');
define('APP_ENV', env('APP_ENV', defined('APP_ENV') ? APP_ENV : 'development'));

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
define('DB_HOST', env('DB_HOST', defined('DB_HOST') ? DB_HOST : 'localhost'));
define('DB_PORT', env('DB_PORT', defined('DB_PORT') ? DB_PORT : '3306'));
define('DB_NAME', env('DB_NAME', defined('DB_NAME') ? DB_NAME : 'radha_rani'));
define('DB_USER', env('DB_USER', defined('DB_USER') ? DB_USER : 'radha'));
define('DB_PASS', env('DB_PASS', defined('DB_PASS') ? DB_PASS : ''));

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
// Timezone / locale
// ------------------------------------------------------------------
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata'));

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// Disable display_errors in production
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    ini_set('display_errors', '1');
    ini_set('error_reporting', E_ALL);
}