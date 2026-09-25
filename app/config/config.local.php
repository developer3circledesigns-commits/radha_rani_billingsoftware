<?php

declare(strict_types=1);

/**
 * LOCAL OVERRIDES - safe to edit, never committed to a public repo.
 *
 * On shared hosting (Hostinger, cPanel, etc.) put your hPanel database
 * credentials here. This file is loaded BEFORE the defaults in config.php,
 * and anything you define wins over the built-in fallback.
 *
 * On Docker this file is ignored: docker-compose.yml supplies the same
 * values through environment variables, which take priority.
 *
 * The hPanel values are under:  hPanel -> Databases -> MySQL Databases
 *   - Database name  is prefixed with your account name,
 *     e.g. u123456789_radha_rani
 *   - Username       is the same prefixed name
 *   - Password       is the one hPanel generated for you
 *   - Host           is almost always "localhost" on shared hosting
 *
 * Delete the lines you do not need. Anything left commented keeps the
 * default from config.php.
 */

// Hostname of the MySQL server. Shared hosting: "localhost".
// Docker: leave commented, docker-compose.yml sets DB_HOST=db.
// define('DB_HOST', 'localhost');

// Port. Almost always 3306 on shared hosting.
// define('DB_PORT', '3306');

// Database name - USE THE PREFIXED NAME hPanel shows you.
// define('DB_NAME', 'u123456789_radha_rani');

// Database user - also prefixed by hPanel.
// define('DB_USER', 'u123456789_radha_rani');

// Database password - the one hPanel generated.
// define('DB_PASS', 'your-generated-password');

// ------------------------------------------------------------------
// Only needed if the app is installed in a SUB-FOLDER, for example
// https://example.com/portal. Leave commented when the app sits at
// the domain root, where the scheme+host is detected automatically.
// ------------------------------------------------------------------
// define('APP_URL', 'https://example.com/portal');

// Force production error handling (hides errors from visitors, writes
// them to storage/logs/app.log instead).
// define('APP_ENV', 'production');
