<?php
/**
 * Database connection failure page.
 *
 * Reached only when Database::connection() cannot reach MySQL. It is shown
 * ONLY when APP_ENV is not "production", because it names the host, port and
 * database - useful while setting up a new deployment, information you do not
 * want published on a live site.
 *
 * The password is never rendered here, and never goes in the query string.
 */
$dbTarget = e(DB_USER . '@' . DB_HOST . ':' . DB_PORT . '/' . DB_NAME);
$dbDriver = e($e instanceof PDOException ? $e->getMessage() : 'unknown error');
$showDetail = APP_ENV !== 'production';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Database connection failed · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= url('assets/images/logo.png') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <style>
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               background:#fdfbf7; color:#212a34; display:flex; min-height:100vh; align-items:center; justify-content:center; padding:24px; }
        .card { max-width:760px; width:100%; background:#fff; border:1px solid #ece5d8; border-top:4px solid #c9a24b;
                border-radius:14px; padding:32px 34px; box-shadow:0 18px 48px rgba(33,42,52,.10); }
        h1 { font-size:1.45rem; margin:0 0 6px; color:#6d1a36; }
        p.lead { color:#66707c; margin:0 0 22px; }
        ol { margin:0 0 22px; padding-left:20px; line-height:1.75; color:#212a34; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:.86rem; }
        pre { background:#f6f4ef; border:1px solid #ece5d8; border-radius:8px; padding:12px 14px;
              overflow-x:auto; white-space:pre-wrap; word-break:break-word; margin:0 0 18px; }
        .muted { color:#66707c; font-size:.85rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>Cannot connect to the database</h1>
    <p class="lead">The application loaded, but MySQL refused the connection. The sign-in form cannot work until this is fixed.</p>

    <?php if ($showDetail): ?>
        <p class="muted">Driver message (no password included):</p>
        <pre><?= $dbDriver ?></pre>
        <p class="muted">Attempted target:</p>
        <pre><?= $dbTarget ?></pre>
    <?php endif; ?>

    <p><strong>Fix it in this order:</strong></p>
    <ol>
        <li>
            Create <code>app/config/config.local.php</code> and uncomment these four lines with the
            values from hPanel &rarr; Databases &rarr; MySQL Databases:
            <pre>define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u123456789_radha_rani');
define('DB_USER', 'u123456789_radha_rani');
define('DB_PASS', 'the-password-hPanel-generated');</pre>
            The database name and user are both prefixed with your hPanel account name.
        </li>
        <li>
            Confirm the schema was imported: hPanel &rarr; phpMyAdmin &rarr; select the database &rarr;
            <strong>Import</strong> &rarr; choose <code>database/init.sql</code>.
        </li>
        <li>
            On shared hosting <code>DB_HOST</code> is <code>localhost</code>. If it still fails, try
            the full hostname hPanel shows for the database.
        </li>
    </ol>

    <p class="muted">
        Full details, including the driver error, are appended to
        <code>storage/logs/app.log</code> on every attempt.
    </p>
</div>
</body>
</html>
