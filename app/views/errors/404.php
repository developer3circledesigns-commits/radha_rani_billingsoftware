<?php $pageTitle = t('error.404.title'); ?>
<!DOCTYPE html>
<html lang="<?= e(Lang::code()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= url('assets/images/logo.png') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="error-body">
    <div class="error-wrap">
        <div class="error-code">404</div>
        <h1 class="error-title"><?= e(t('error.404.heading')) ?></h1>
        <p class="error-message"><?= e(t('error.404.message')) ?></p>
        <div class="error-actions">
            <a class="btn btn-primary" href="<?= url('index.php') ?>"><i class="bi bi-house-door me-2"></i><?= e(t('error.go_dashboard')) ?></a>
        </div>
    </div>
</body>
</html>