<?php $pageTitle = 'Session expired'; ?>
<!DOCTYPE html>
<html lang="en">
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
        <div class="error-code">419</div>
        <h1 class="error-title">Session expired</h1>
        <p class="error-message">Your session has timed out. Please sign in again to continue. Your request was not completed.</p>
        <div class="error-actions">
            <a class="btn btn-primary" href="<?= url('login.php') ?>"><i class="bi bi-box-arrow-in-right me-2"></i>Sign in again</a>
        </div>
    </div>
</body>
</html>