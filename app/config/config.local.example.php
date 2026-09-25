<?php

declare(strict_types=1);

/**
 * TEMPLATE for the credentials file - copy, do not edit in place.
 *
 * This file is safe to commit: it contains no real credentials.
 *
 * PREFER THE OUTSIDE-CHECKOUT LOCATION. Create this file one level ABOVE the
 * project folder, named radha-rani-credentials.php:
 *
 *   /home/u123456789/radha_rani/        <- Git checkout (deploys touch this)
 *   /home/u123456789/radha-rani-credentials.php   <- put the credentials here
 *
 * Nothing inside the checkout can be overwritten by a deploy, because a deploy
 * only ever rewrites the checkout. This matters because Hostinger's Git deploy
 * can restore files that Git still tracks, and an earlier version of this
 * project did track config.local.php - which is how a deploy silently replaced
 * real credentials with an empty, all-commented file.
 *
 * If you cannot create a file outside the checkout, use this template as
 * app/config/config.local.php instead. It is gitignored, so Git will not upload
 * it, but it does sit inside the folder a deploy rewrites.
 *
 * On the server (hPanel > File Manager):
 *   1. Create the file at the location above
 *   2. Paste the five DB_* lines from this template
 *   3. Set your real values
 *
 * Values come from hPanel > Databases > MySQL Databases. Both the database
 * name and the username carry your u-prefixed account name, e.g.
 * u123456789_radha_rani. The host is "localhost" on shared hosting and the
 * port is 3306.
 *
 * Precedence: environment variable > outside-credentials file > this file
 * inside app/config/ > built-in default. Under Docker, docker-compose.yml
 * supplies the values as environment variables, so no credentials file is
 * needed there.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u123456789_radha_rani');
define('DB_USER', 'u123456789_radha_rani');
define('DB_PASS', 'paste-the-password-hpanel-generated');

// Only needed when the app is installed in a sub-folder such as
// https://example.com/portal. Leave commented at the domain root.
// define('APP_URL', 'https://example.com/portal');

// Uncomment once login works. This hides error detail from visitors and writes
// it to storage/logs/app.log instead. config.php already defaults to production,
// so leaving this commented is safe; setting it explicitly just makes the
// intent obvious and survives a future change to that default.
// define('APP_ENV', 'production');

// On your own machine you can instead opt in to visible errors:
// define('APP_ENV', 'development');
