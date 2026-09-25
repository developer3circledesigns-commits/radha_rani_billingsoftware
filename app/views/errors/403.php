<?php $pageTitle = 'Forbidden'; ?>
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
        <div class="error-code">403</div>
        <h1 class="error-title">Access denied</h1>
        <p class="error-message">You are not authorized to view this page or document.</p>
        <div class="error-actions">
            <a class="btn btn-primary" href="<?= url('index.php') ?>"><i class="bi bi-house-door me-2"></i>Go to Dashboard</a>
            <a class="btn btn-light" href="<?= url('logout.php') ?>">Sign in as different user</a>
        </div>
    </div>
</body>
</html>