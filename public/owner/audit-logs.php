<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_owner();

$filters = [
    'action'  => get('action') ?: null,
    'user_id' => (int) get('user_id', 0) ?: null,
    'from'    => get('from') ?: null,
    'to'      => get('to') ?: null,
];

$page = max(1, (int) get('page', 1));
$result = AuditLog::search($filters, $page, 25);

$actions = Database::fetchAll('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC');

$qs = [];
foreach ($filters as $k => $v) {
    if ($v) $qs[$k] = $v;
}
$baseUrl = url('owner/audit-logs.php') . (count($qs) ? '?' . http_build_query($qs) : '');

// Map action codes to friendly labels
$actionLabels = [
    'LOGIN' => 'Login', 'LOGOUT' => 'Logout', 'LOGIN_FAILED' => 'Failed Login',
    'BRANCH_CREATED' => 'Branch Created', 'BRANCH_UPDATED' => 'Branch Updated',
    'BRANCH_DELETED' => 'Branch Deleted', 'BRANCH_ACTIVATED' => 'Branch Activated',
    'BRANCH_DEACTIVATED' => 'Branch Deactivated',
    'ADMIN_CREATED' => 'Admin Created', 'ADMIN_UPDATED' => 'Admin Updated',
    'ADMIN_DELETED' => 'Admin Deleted', 'ADMIN_DISABLED' => 'Admin Disabled',
    'ADMIN_ACTIVATED' => 'Admin Activated', 'ADMIN_PASSWORD_RESET' => 'Password Reset',
    'BILL_UPLOADED' => 'Bill Uploaded', 'BILL_VIEWED' => 'Bill Viewed',
    'BILL_DOWNLOADED' => 'Bill Downloaded', 'BILL_DELETED' => 'Bill Deleted',
    'BILL_VIEW_DENIED' => 'View Denied', 'BILL_DOWNLOAD_DENIED' => 'Download Denied',
    'PROFILE_UPDATED' => 'Profile Updated', 'PASSWORD_CHANGED' => 'Password Changed',
    'SETTINGS_UPDATED' => 'Settings Updated',
];

$pageTitle = 'Audit Logs';
$pageSubtitle = 'Full accountability trail';
$activeMenu = 'audit-logs';

ob_start();
?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="<?= url('owner/audit-logs.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="al_action">Action</label>
                <select class="form-select form-select-sm" id="al_action" name="action">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $a) : ?>
                        <option value="<?= e($a['action']) ?>" <?= $filters['action'] === $a['action'] ? 'selected' : '' ?>>
                            <?= e($actionLabels[$a['action']] ?? $a['action']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="al_from">From</label>
                <input type="date" class="form-control form-control-sm" id="al_from" name="from" value="<?= e($filters['from'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="al_to">To</label>
                <input type="date" class="form-control form-control-sm" id="al_to" name="to" value="<?= e($filters['to'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= url('owner/audit-logs.php') ?>" class="btn btn-sm btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (!$result['rows']) : ?>
            <div class="empty-state"><i class="bi bi-journal-check"></i><p class="mb-0">No audit records match the filters.</p></div>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>IP Address</th>
                        <th class="pe-3">User Agent</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $log) : ?>
                    <tr>
                        <td class="ps-3 small text-muted"><?= e(format_datetime($log['created_at'])) ?></td>
                        <td class="small">
                            <?= e($log['user_name'] ?? 'System') ?>
                            <div class="text-muted small"><?= e($log['branch_name'] ?? '') ?></div>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= e($actionLabels[$log['action']] ?? $log['action']) ?></span></td>
                        <td class="small"><?= e($log['description'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e($log['ip_address'] ?? '—') ?></td>
                        <td class="pe-3 small text-muted text-truncate" style="max-width:180px" title="<?= e($log['user_agent'] ?? '') ?>"><?= e($log['user_agent'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted"><?= $result['count'] ?> record(s) · Page <?= $result['page'] ?> of <?= $result['pages'] ?></span>
            <?= pagination($result, $baseUrl) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$bodyContent = ob_get_clean();
require APP_PATH . '/views/layouts/header.php';
echo $bodyContent;
require APP_PATH . '/views/layouts/footer.php';