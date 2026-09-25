<?php

declare(strict_types=1);

/**
 * TEMPLATE for app/config/config.local.php - copy, do not edit in place.
 *
 * This file is safe to commit: it contains no real credentials. The file you
 * create from it, app/config/config.local.php, is gitignored, so Git will
 * never overwrite your live credentials and never upload them.
 *
 * On the server (hPanel > File Manager):
 *   1. Copy this file to app/config/config.local.php
 *   2. Uncomment the four DB_* lines and paste your values
 *   3. Delete nothing else
 *
 * Values come from hPanel > Databases > MySQL Databases. Both the database
 * name and the username carry your u-prefixed account name, e.g.
 * u123456789_radha_rani. The host is "localhost" on shared hosting and the
 * port is 3306.
 *
 * Precedence: environment variable > config.local.php > built-in default.
 * Under Docker, docker-compose.yml supplies the values as environment
 * variables, so this file is not needed there.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u123456789_radha_rani');
define('DB_USER', 'u123456789_radha_rani');
define('DB_PASS', 'paste-the-password-hpanel-generated');

// Only needed when the app is installed in a sub-folder such as
// https://example.com/portal. Leave commented at the domain root.
// define('APP_URL', 'https://example.com/portal');

// Uncomment only after login works. This hides error detail from visitors and
// writes it to storage/logs/app.log instead.
// define('APP_ENV', 'production');
