<?php
/**
 * Detailed 500 page.
 *
 * Shown only when APP_ENV is not "production". A first deploy fails for
 * ordinary setup reasons (schema not imported, wrong DB name, missing
 * extension) and a bare "Something went wrong" gives the operator nothing
 * to act on. The message below is the actual exception, so the log and this
 * page agree.
 */
$errorClass   = e(get_class($e));
$errorMessage = e($e->getMessage());
$errorWhere   = e($e->getFile() . ':' . $e->getLine());
$showTrace    = APP_ENV !== 'production';
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::code()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(t('error.debug.title')) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= url('assets/images/logo.png') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <style>
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               background:#fdfbf7; color:#212a34; padding:28px; }
        .card { max-width:900px; margin:0 auto; background:#fff; border:1px solid #ece5d8;
                border-top:4px solid #c0392b; border-radius:14px; padding:28px 30px;
                box-shadow:0 18px 48px rgba(33,42,52,.10); }
        h1 { font-size:1.4rem; margin:0 0 4px; color:#6d1a36; }
        p.sub { color:#66707c; margin:0 0 20px; }
        .row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
        .tag { background:#f6f4ef; border:1px solid #ece5d8; border-radius:7px; padding:5px 11px;
               font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:.82rem; }
        .tag b { color:#66707c; font-family:inherit; font-weight:600; margin-right:6px; }
        pre { background:#f6f4ef; border:1px solid #ece5d8; border-radius:8px; padding:12px 14px;
              font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:.84rem;
              overflow-x:auto; white-space:pre-wrap; word-break:break-word; margin:0 0 16px; }
        details summary { cursor:pointer; color:#6d1a36; font-weight:600; margin-bottom:10px; }
        ol { margin:0; padding-left:20px; line-height:1.7; }
    </style>
</head>
<body>
<div class="card">
    <h1><?= e(t('error.debug.heading')) ?></h1>
    <p class="sub"><?= e(t('error.debug.sub')) ?></p>

    <div class="row">
        <span class="tag"><b><?= e(t('error.debug.type')) ?></b><?= $errorClass ?></span>
        <span class="tag"><b><?= e(t('error.debug.at')) ?></b><?= $errorWhere ?></span>
    </div>

    <pre><?= $errorMessage ?></pre>

    <?php
    // Matched against the raw message: the escaped copy turns the apostrophe
    // in "doesn't exist" into an HTML entity and would never match.
    $rawMessage = $e->getMessage();
    $missingTable = stripos($rawMessage, "doesn't exist") !== false
        || stripos($rawMessage, 'Base table or view not found') !== false
        || stripos($rawMessage, 'Unknown database') !== false;
    ?>
    <?php if ($missingTable): ?>
        <p><?= t('error.debug.cause', [
            'sql'    => 'database/init.sql',
            'config' => 'app/config/config.local.php',
        ]) ?></p>
    <?php endif; ?>

    <?php if ($showTrace): ?>
        <details>
            <summary><?= e(t('error.debug.trace')) ?></summary>
            <pre><?= e($e->getTraceAsString()) ?></pre>
        </details>
    <?php endif; ?>

    <p class="sub" style="margin:18px 0 0">
        <?= t('error.debug.hide', ['env' => 'APP_ENV', 'mode' => 'production']) ?>
    </p>
</div>
</body>
</html>
