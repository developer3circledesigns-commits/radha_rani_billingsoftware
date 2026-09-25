<?php $pageTitle = 'Something went wrong'; ?>
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
        <div class="error-code">500</div>
        <h1 class="error-title">Something went wrong</h1>
        <p class="error-message">An unexpected error occurred. Please try again. If the problem persists, contact your administrator.</p>
        <div class="error-actions">
            <a class="btn btn-primary" href="<?= url('login.php') ?>"><i class="bi bi-house-door me-2"></i>Return to Portal</a>
        </div>
    </div>
</body>
</html>