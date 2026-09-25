<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

// Already logged in? Send to correct area.
if (check_authentication()) {
    $user = current_user();
    redirect($user['role'] === 'owner' ? 'owner/dashboard.php' : 'branch/dashboard.php');
}

$errors    = [];
$old       = [];
$lockUntil = null;

if (isPost()) {
    $username = trim((string) post('login', ''));
    $password = (string) post('password', '');

    if (!csrf_verify()) {
        csrf_fail();
    }

    // Login throttling
    if (strlen($username)) {
        if (!throttle_allow_login($username)) {
            $lockMins = LOGIN_LOCKOUT_MINUTES;
            flash_set('danger', "Too many failed attempts. Account locked for {$lockMins} minutes. Please try again later.");
            redirect('login.php');
        }
    }

    if ($username === '' || $password === '') {
        flash_set('danger', 'Please enter both login ID and password.');
        $old['login'] = $username;
        redirect('login.php');
    }

    $userRow = User::findByLogin($username);

    if ($userRow && password_verify($password, $userRow['password_hash'])) {
        if ($userRow['status'] !== 'active') {
            log_activity($userRow['id'], $userRow['branch_id'], 'LOGIN_DENIED', 'user', $userRow['id'], 'Inactive account attempted login');
            flash_set('danger', 'This account is inactive. Contact the administrator.');
            redirect('login.php');
        }

        if ($userRow['role'] === 'branch_admin' && $userRow['branch_status'] !== 'active') {
            log_activity($userRow['id'], $userRow['branch_id'], 'LOGIN_DENIED', 'user', $userRow['id'], 'Inactive branch attempted login');
            flash_set('danger', 'The branch associated with this account is inactive. Contact the administrator.');
            redirect('login.php');
        }

        // Successful login
        session_regenerate_id(true);
        $_SESSION['user_id']       = (int) $userRow['id'];
        $_SESSION['logged_in_at']  = time();
        $_SESSION['last_activity'] = time();

        User::updateLastLogin((int) $userRow['id']);
        throttle_reset($username);

        log_activity((int) $userRow['id'], $userRow['branch_id'], 'LOGIN', 'user', (int) $userRow['id'], 'Login successful');

        flash_set('success', 'Welcome back, ' . $userRow['name'] . '!');
        redirect($userRow['role'] === 'owner' ? 'owner/dashboard.php' : 'branch/dashboard.php');
    }

    // Failed
    $attempts = 0;
    if ($userRow) {
        log_activity((int) $userRow['id'], $userRow['branch_id'], 'LOGIN_FAILED', 'user', (int) $userRow['id'], 'Invalid credentials');
    }
    $attempts = throttle_register_failure($username);

    flash_set('danger', 'Invalid login ID or password.');
    $old['login'] = $username;
    redirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= url('assets/images/logo.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500;1,600&family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        :root {
            --l1-burgundy: #6d1a36;
            --l1-burgundy-dark: #4a0f22;
            --l1-burgundy-deep: #370a18;
            --l1-gold: #c9a24b;
            --l1-gold-soft: rgba(201, 162, 75, 0.14);
            --l1-ink: #212a34;
            --l1-muted: #66707c;
            --l1-surface: #fdfbf7;
            --l1-line: #ece5d8;
            --bs-font-sans-serif: "Lato", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        html { height: 100%; overflow: hidden; }

        body.l1 {
            margin: 0;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
            display: flex;
            background: var(--l1-surface);
            color: var(--l1-ink);
            font-family: "Lato", system-ui, sans-serif;
        }

        /* ---------------- Brand panel ---------------- */
        .l1-brand {
            position: relative;
            width: 54%;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            padding: 0 58px;
            color: #fff;
            overflow: hidden;
            isolation: isolate;
            background:
                radial-gradient(120% 90% at 15% 0%, rgba(201, 162, 75, 0.16) 0%, transparent 52%),
                linear-gradient(158deg, var(--l1-burgundy-deep) 0%, var(--l1-burgundy-dark) 44%, var(--l1-burgundy) 100%);
        }
        .l1-brand::before,
        .l1-brand::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            filter: blur(2px);
            z-index: -1;
        }
        .l1-brand::before {
            width: 480px; height: 480px;
            right: -200px; bottom: -140px;
            background: radial-gradient(circle, rgba(201, 162, 75, 0.22) 0%, transparent 68%);
        }
        .l1-brand::after {
            width: 220px; height: 220px;
            left: 30%; top: 8%;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .l1-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: clamp(10px, 2.2vh, 30px) 0 clamp(6px, 1vh, 10px);
        }
        .l1-logo {
            display: inline-flex;
            align-items: center;
            color: #fff;
            text-decoration: none;
        }
        .l1-logo img {
            width: clamp(84px, 16vh, 150px); height: clamp(84px, 16vh, 150px);
            object-fit: contain;
            background: transparent;
            filter: brightness(1.2) saturate(1.15) drop-shadow(0 1px 5px rgba(201, 162, 75, 0.3));
        }
        .l1-nav-links { display: flex; align-items: center; gap: 26px; }
        .l1-nav-links a { color: rgba(255, 255, 255, 0.78); text-decoration: none; font-size: 0.86rem; font-weight: 500; transition: color 0.15s ease; }
        .l1-nav-links a:hover { color: #fff; }
        .l1-nav-links .l1-cta {
            padding: 8px 18px;
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 999px;
            color: #fff;
        }
        .l1-nav-links .l1-cta:hover { background: #fff; color: var(--l1-burgundy); }

        .l1-copy {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            max-width: 580px;
            padding: clamp(20px, 4.4vh, 70px) 0 clamp(16px, 3.2vh, 50px);
        }
        .l1-overline {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: clamp(10px, 1.9vh, 22px);
            color: var(--rr-gold-light);
            font-size: clamp(0.64rem, 1.2vh, 0.72rem);
            letter-spacing: 3px;
            text-transform: uppercase;
            font-weight: 700;
            line-height: 1;
        }
        .l1-overline::before { content: ""; width: 34px; height: 1px; background: var(--l1-gold); }
        .l1-copy h1 {
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: clamp(1.75rem, 5.2vh, 4.1rem);
            font-weight: 600;
            line-height: 1.14;
            letter-spacing: 0.25px;
            text-wrap: balance;
            margin: 0 0 clamp(10px, 1.9vh, 20px);
            color: #fff;
        }
        .l1-copy h1 em { font-style: italic; font-weight: 600; color: var(--rr-gold-light); }
        .l1-lead { font-size: clamp(0.8rem, 1.55vh, 1rem); line-height: 1.6; letter-spacing: 0.15px; color: rgba(255, 255, 255, 0.78); max-width: 50ch; margin-bottom: clamp(12px, 2.4vh, 28px); }
        .l1-features { list-style: none; margin: 0 0 clamp(12px, 2.6vh, 34px); padding: 0; display: grid; gap: clamp(6px, 1.2vh, 14px); }
        .l1-features li { display: flex; align-items: flex-start; gap: 12px; color: rgba(255, 255, 255, 0.94); font-size: clamp(0.78rem, 1.5vh, 0.95rem); font-weight: 500; line-height: 1.45; }
        .l1-features i {
            width: clamp(20px, 3.4vh, 26px); height: clamp(20px, 3.4vh, 26px);
            margin-top: 1px;
            flex-shrink: 0;
            border-radius: 50%;
            background: var(--l1-gold-soft);
            color: var(--rr-gold-light);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: clamp(0.72rem, 1.4vh, 0.9rem);
        }
        .l1-quote {
            margin: 0;
            padding: clamp(11px, 1.9vh, 20px) clamp(15px, 2.2vh, 24px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-left: 3px solid var(--l1-gold);
            border-radius: 4px 14px 14px 4px;
            background: rgba(255, 255, 255, 0.05);
        }
        .l1-quote blockquote { margin: 0 0 clamp(5px, 0.9vh, 10px); font-family: "Cormorant Garamond", Georgia, serif; font-style: italic; font-weight: 500; font-size: clamp(0.9rem, 1.9vh, 1.15rem); line-height: 1.4; color: rgba(255, 255, 255, 0.92); }
        .l1-quote figcaption { font-size: clamp(0.66rem, 1.1vh, 0.76rem); letter-spacing: 1.2px; text-transform: uppercase; color: rgba(255, 255, 255, 0.6); }

        .l1-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: clamp(12px, 2.2vh, 26px);
            padding: clamp(12px, 2.4vh, 26px) 0 clamp(14px, 3vh, 34px);
            border-top: 1px solid rgba(255, 255, 255, 0.14);
        }
        .l1-stats span { display: block; font-family: "Cormorant Garamond", Georgia, serif; font-size: clamp(1.45rem, 3.2vh, 2.1rem); font-weight: 700; color: #fff; line-height: 1.05; letter-spacing: 0.5px; }
        .l1-stats span i { font-size: 0.8rem; color: var(--rr-gold-light); margin-left: 6px; }
        .l1-stats small { display: block; margin-top: clamp(3px, 0.6vh, 6px); color: rgba(255, 255, 255, 0.55); font-size: clamp(0.62rem, 1.05vh, 0.74rem); letter-spacing: 1px; text-transform: uppercase; }

        /* ---------------- Form panel ---------------- */
        .l1-panel {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: clamp(24px, 4.6vh, 60px) 40px;
            background:
                radial-gradient(90% 60% at 100% 0%, rgba(201, 162, 75, 0.08) 0%, transparent 60%),
                var(--l1-surface);
        }
        .l1-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border: 1px solid var(--l1-line);
            border-top: 3px solid var(--l1-gold);
            border-radius: 0;
            box-shadow: 0 24px 60px rgba(33, 42, 52, 0.12);
            padding: clamp(20px, 3.2vh, 38px) 36px clamp(18px, 2.6vh, 32px);
        }
        .l1-card h2 { font-family: "Cormorant Garamond", Georgia, serif; font-size: 1.65rem; font-weight: 600; margin: 0 0 6px; }
        .l1-card-sub { font-size: 0.88rem; color: var(--l1-muted); margin-bottom: 26px; }
        .l1-card .form-label { font-size: 0.82rem; font-weight: 600; }
        .l1-card .form-control,
        .l1-card .input-group-text,
        .l1-card .btn,
        .l1-card .alert { border-radius: 0; }
        .l1-card .form-control {
            padding: 10px 14px;
            border-color: var(--l1-line);
            font-size: 0.92rem;
        }
        .l1-card .input-group-text {
            background: #faf7f1;
            border-color: var(--l1-line);
            color: var(--l1-muted);
        }
        .l1-submit {
            --bs-btn-bg: var(--l1-gold);
            --bs-btn-border-color: var(--l1-gold);
            --bs-btn-color: var(--l1-burgundy-dark);
            --bs-btn-hover-bg: var(--rr-gold-light);
            --bs-btn-hover-border-color: var(--rr-gold-light);
            --bs-btn-hover-color: var(--l1-burgundy-dark);
            --bs-btn-active-bg: var(--rr-gold-light);
            --bs-btn-active-border-color: var(--rr-gold-light);
            --bs-btn-active-color: var(--l1-burgundy-dark);
            padding: 11px 16px;
            font-weight: 700;
            margin-top: 4px;
        }
        .l1-help { text-align: center; font-size: 0.8rem; color: var(--l1-muted); margin: 18px 0 0; }
        .l1-help a { color: var(--l1-burgundy); font-weight: 600; text-decoration: none; }
        .l1-help a:hover { text-decoration: underline; }

        .l1-after {
            width: 100%;
            max-width: 420px;
            margin-top: clamp(12px, 2.4vh, 30px);
            display: grid;
            gap: clamp(7px, 1.3vh, 12px);
        }
        .l1-after-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: clamp(9px, 1.6vh, 14px) 16px;
            background: #fff;
            border: 1px solid var(--l1-line);
            border-radius: 12px;
        }
        .l1-after-row i {
            width: clamp(28px, 4.8vh, 38px); height: clamp(28px, 4.8vh, 38px);
            border-radius: 10px;
            background: var(--l1-gold-soft);
            color: var(--l1-burgundy);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: clamp(0.95rem, 1.7vh, 1.1rem);
            flex-shrink: 0;
        }
        .l1-after-row strong { display: block; font-size: 0.86rem; }
        .l1-after-row small { color: var(--l1-muted); font-size: clamp(0.72rem, 1.25vh, 0.78rem); }
        .l1-foot { margin-top: auto; padding-top: clamp(12px, 2.4vh, 30px); color: var(--l1-muted); font-size: 0.76rem; text-align: center; }

        /* ---------------- Responsive ---------------- */
        @media (max-width: 991.98px) {
            html { overflow: auto; }
            body.l1 { display: block; height: auto; min-height: 100vh; overflow: visible; }
            .l1-brand { width: 100%; min-height: auto; padding: 0 26px; }
            .l1-nav { padding-top: 22px; }
            .l1-nav-links { display: none; }
            .l1-copy { padding: 56px 0 30px; }
            .l1-quote { display: none; }
            .l1-stats { padding-bottom: 24px; }
        }
        @media (max-width: 575.98px) {
            .l1-panel { padding: 44px 20px 90px; }
            .l1-card { padding: 30px 22px 26px; }
        }
        /* Short desktop windows */
        @media (min-width: 992px) and (max-height: 700px) {
            .l1-quote { display: none; }
        }
        @media (min-width: 992px) and (max-height: 680px) {
            .l1-panel { padding-top: 14px; padding-bottom: 14px; }
            .l1-card { padding: 18px 32px 16px; }
            .l1-card h2 { font-size: 1.45rem; }
            .l1-card-sub { margin-bottom: 16px; }
            .l1-card .form-control { padding: 7px 12px; }
            .l1-submit { padding: 8px 16px; }
            .l1-help { margin-top: 12px; }
            .l1-after { margin-top: 12px; gap: 8px; }
            .l1-after-row { padding: 9px 14px; }
            .l1-foot { padding-top: 10px; }
        }
        @media (min-width: 992px) and (max-height: 560px) {
            .l1-features { display: none; }
            .l1-after { display: none; }
        }
    </style>
</head>
<body class="l1">

<!-- ===================== LEFT: BRAND PANEL ===================== -->
<aside class="l1-brand">
    <div class="l1-nav">
        <a class="l1-logo" href="<?= url('login.php') ?>">
            <img src="<?= url('assets/images/logo.png') ?>" alt="Radha Rani Hotel logo">
        </a>
        <nav class="l1-nav-links" aria-label="Portal links">
            <!-- <a href="#portal">The Portal</a> -->
            <a href="<?= url('login.php') ?>" class="l1-cta">Sign in</a>
        </nav>
    </div>

    <div class="l1-copy" id="portal">
        <p class="l1-overline">The Branch Bill Portal</p>
        <h1>Every branch, every bill —<br><em>one calm dashboard.</em></h1>
        <p class="l1-lead">
            Radha Rani Hotel gathers daily cash and card bill PDFs from every branch,
            securely archived, audited, and ready the moment you need to review them.
        </p>
        <ul class="l1-features">
            <li><i class="bi bi-check2"></i>Drag-and-drop PDF upload from any branch</li>
            <li><i class="bi bi-check2"></i>Cash &amp; card billing captured daily</li>
            <li><i class="bi bi-check2"></i>Audit trail on every sign-in and upload</li>
        </ul>
        <!-- <figure class="l1-quote">
            <blockquote>"One place for the whole hotel ledger — no more chasing branch emails."</blockquote>
            <figcaption>Operations Desk · Radha Rani Hotel</figcaption>
        </figure> -->
    </div>

    <div class="l1-stats">
        <div><span>100%<i class="bi bi-check-circle-fill"></i></span><small>Digital archive</small></div>
        <div><span>24/7</span><small>Branch access</small></div>
        <div><span>1</span><small>Unified dashboard</small></div>
    </div>
</aside>

<!-- ===================== RIGHT: SIGN IN ===================== -->
<main class="l1-panel">
    <div class="l1-card">
        <h2>Sign in</h2>
        <p class="l1-card-sub">Use your portal credentials below.</p>

        <?php foreach (flash_get() as $flash) : ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 small" role="alert">
            <?= e($flash['message']) ?>
        </div>
        <?php endforeach; ?>

        <form method="post" action="<?= url('login.php') ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="login" class="form-label">Email or username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="login" name="login"
                           value="<?= e($old['login'] ?? '') ?>" placeholder="you@branch.local" required autofocus autocomplete="username">
                </div>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password"
                           placeholder="••••••••" required autocomplete="current-password">
                    <button class="btn btn-outline-secondary" type="button" data-pw-toggle="password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="btn l1-submit w-100 btn-lg">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign in
            </button>
        </form>

        <!-- <p class="l1-help">New branch admin? <a href="<?= url('login.php') ?>">Contact the owner</a> for your credentials.</p> -->
    </div>

    <!-- <div class="l1-after">
        <div class="l1-after-row">
            <i class="bi bi-shield-lock"></i>
            <span><strong>Throttled &amp; logged</strong><small>Failed attempts lock an account for <?= LOGIN_LOCKOUT_MINUTES ?> minutes.</small></span>
        </div>
        <div class="l1-after-row">
            <i class="bi bi-person-badge"></i>
            <span><strong>Role-based areas</strong><small>Owners and branch admins reach their own dashboards.</small></span>
        </div>
    </div>

    <p class="l1-foot">© <?= date('Y') ?> <?= e(APP_ORG) ?> · Secure document portal</p> -->
</main>

<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>