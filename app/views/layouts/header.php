<?php
/**
 * Layout header - opens the SaaS shell.
 * Expects: $pageTitle, $user (current_user array)
 * Optional: $activeMenu (string key for sidebar highlight)
 */
$currentUser = current_user();
$activeMenu = $activeMenu ?? '';
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::code()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle ?? APP_NAME) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= url('assets/images/logo.png') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="app-body">

<div class="app-shell">

    <!-- ======================= SIDEBAR ======================= -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo"><img src="<?= url('assets/images/logo.png') ?>" alt="<?= e(APP_NAME) ?> logo"></div>
        </div>

        <?php if (($currentUser['role'] ?? '') === 'owner') : ?>
        <nav class="sidebar-nav" aria-label="<?= e(t('nav.area_owner')) ?>">
            <div class="sidebar-section-label"><?= e(t('nav.overview')) ?></div>
            <a href="<?= url('owner/dashboard.php') ?>" class="sidebar-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2-fill"></i><span><?= e(t('nav.dashboard')) ?></span>
            </a>

            <div class="sidebar-section-label"><?= e(t('nav.management')) ?></div>
            <a href="<?= url('owner/branches.php') ?>" class="sidebar-link <?= $activeMenu === 'branches' ? 'active' : '' ?>">
                <i class="bi bi-buildings-fill"></i><span><?= e(t('nav.branches')) ?></span>
            </a>
            <a href="<?= url('owner/admins.php') ?>" class="sidebar-link <?= $activeMenu === 'admins' ? 'active' : '' ?>">
                <i class="bi bi-person-badge-fill"></i><span><?= e(t('nav.branch_admins')) ?></span>
            </a>

            <div class="sidebar-section-label"><?= e(t('nav.documents')) ?></div>
            <a href="<?= url('owner/bills.php') ?>" class="sidebar-link <?= $activeMenu === 'bills' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-pdf-fill"></i><span><?= e(t('nav.bills')) ?></span>
            </a>
            <a href="<?= url('owner/upload-activity.php') ?>" class="sidebar-link <?= $activeMenu === 'upload-activity' ? 'active' : '' ?>">
                <i class="bi bi-arrow-up-circle-fill"></i><span><?= e(t('nav.upload_activity')) ?></span>
            </a>
            <a href="<?= url('owner/trash.php') ?>" class="sidebar-link <?= $activeMenu === 'trash' ? 'active' : '' ?>">
                <i class="bi bi-trash"></i><span><?= e(t('nav.recently_deleted')) ?></span>
            </a>

            <div class="sidebar-section-label"><?= e(t('common.system')) ?></div>
            <a href="<?= url('owner/audit-logs.php') ?>" class="sidebar-link <?= $activeMenu === 'audit-logs' ? 'active' : '' ?>">
                <i class="bi bi-journal-check"></i><span><?= e(t('nav.audit_logs')) ?></span>
            </a>
            <a href="<?= url('owner/settings.php') ?>" class="sidebar-link <?= $activeMenu === 'settings' ? 'active' : '' ?>">
                <i class="bi bi-gear-fill"></i><span><?= e(t('nav.settings')) ?></span>
            </a>
        </nav>

        <?php else : ?>
        <nav class="sidebar-nav" aria-label="<?= e(t('nav.area_branch')) ?>">
            <div class="sidebar-section-label"><?= e(t('nav.overview')) ?></div>
            <a href="<?= url('branch/dashboard.php') ?>" class="sidebar-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2-fill"></i><span><?= e(t('nav.dashboard')) ?></span>
            </a>

            <div class="sidebar-section-label"><?= e(t('nav.documents')) ?></div>
            <a href="<?= url('branch/upload.php') ?>" class="sidebar-link <?= $activeMenu === 'upload' ? 'active' : '' ?>">
                <i class="bi bi-cloud-arrow-up-fill"></i><span><?= e(t('nav.upload_bills')) ?></span>
            </a>
            <a href="<?= url('branch/my-uploads.php') ?>" class="sidebar-link <?= $activeMenu === 'my-uploads' ? 'active' : '' ?>">
                <i class="bi bi-folder2-open"></i><span><?= e(t('nav.my_uploads')) ?></span>
            </a>
        </nav>
        <?php endif; ?>

        <div class="sidebar-footer">
            <a href="<?= url('profile.php') ?>" class="sidebar-link <?= $activeMenu === 'profile' ? 'active' : '' ?>">
                <i class="bi bi-person-circle"></i><span><?= e(t('nav.profile_settings')) ?></span>
            </a>
            <a href="<?= url('logout.php') ?>" class="sidebar-link text-danger">
                <i class="bi bi-box-arrow-right"></i><span><?= e(t('nav.logout')) ?></span>
            </a>

            <?php partial('language_switcher'); ?>
        </div>
    </aside>

    <!-- ======================= SIDEBAR OVERLAY (mobile) ======================= -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ======================= MAIN ======================= -->
    <div class="app-main">

        <!-- ======================= TOPBAR ======================= -->
        <header class="app-topbar">
            <button class="btn btn-icon sidebar-toggler" id="sidebarToggle" aria-label="<?= e(t('nav.toggle_sidebar')) ?>">
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-title">
                <h1 class="mb-0"><?= e($pageTitle ?? APP_NAME) ?></h1>
                <span class="topbar-sub"><?= e($pageSubtitle ?? '') ?></span>
            </div>

            <div class="ms-auto d-flex align-items-center gap-2">
                <?php if (($currentUser['role'] ?? '') === 'branch_admin' && !empty($currentUser['branch_name'])) : ?>
                <span class="topbar-branch d-none d-md-inline-flex">
                    <i class="bi bi-building"></i> <?= e($currentUser['branch_name']) ?>
                </span>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn btn-icon dropdown-toggle-split-no-care d-flex align-items-center gap-2 topbar-user" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= e(t('nav.account_menu')) ?>">
                        <span class="avatar avatar-sm"><?= e(mb_strtoupper(mb_substr($currentUser['name'] ?? '?', 0, 1))) ?></span>
                        <span class="d-none d-lg-inline topbar-user-name"><?= e($currentUser['name'] ?? '') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted"><?= e(t('nav.signed_in_as')) ?><br><strong><?= e($currentUser['email'] ?? '') ?></strong></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= url('profile.php') ?>"><i class="bi bi-person me-2"></i><?= e(t('nav.profile')) ?></a></li>
                        <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i><?= e(t('nav.logout')) ?></a></li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- ======================= CONTENT ======================= -->
        <main class="app-content">

            <?php foreach (flash_get() as $flash) : ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show toast-flash" role="alert">
                <i class="bi bi-info-circle me-2"></i><?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= e(t('common.close')) ?>"></button>
            </div>
            <?php endforeach; ?>