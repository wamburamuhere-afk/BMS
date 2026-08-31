<?php
/**
 * app/superadmin/index.php — the platform panel's landing page.
 *
 * Phase 4 ships the guard and a read-only overview. Phase 6 turns the tenant
 * list below into the full lifecycle panel (activate / suspend / delete).
 *
 * Reads ONLY the control database via getControlPdo(). It never opens a tenant
 * database, so nothing here can display one company's business data.
 */
require_once __DIR__ . '/../../core/superadmin_auth.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();                 // 404 from a tenant host; redirect if signed out

$me = currentSuperadmin();

$tenants = [];
$counts  = ['active' => 0, 'trial' => 0, 'suspended' => 0, 'deleted' => 0];
$dbError = null;

try {
    $cpdo = getControlPdo();
    $tenants = $cpdo->query("
        SELECT id, company_name, subdomain, status, plan, owner_email, created_at
        FROM tenants
        ORDER BY created_at DESC, id DESC
    ")->fetchAll();

    foreach ($cpdo->query("SELECT status, COUNT(*) AS n FROM tenants GROUP BY status") as $r) {
        $counts[$r['status']] = (int)$r['n'];
    }
} catch (Throwable $e) {
    error_log('superadmin index: ' . $e->getMessage());
    $dbError = 'The tenant registry could not be read.';
}

/** Status badge colours, per .claude/ui-constants.md §UI-1 (blue scale only). */
function saStatusBadge(string $status): string
{
    $map = [
        'active'    => ['#0d6efd', '#fff'],
        'trial'     => ['#cfe2ff', '#084298'],
        'suspended' => ['#6c757d', '#fff'],
        'deleted'   => ['#dc3545', '#fff'],
    ];
    [$bg, $fg] = $map[$status] ?? ['#e9ecef', '#495057'];
    return '<span class="badge" style="background:' . $bg . ';color:' . $fg . '">'
         . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .page-header { position: sticky; top: 0; z-index: 1020; background: #fff; border-bottom: 1px solid #e9ecef; }
    .stat-card { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; }
    .stat-card .value { font-size: 1.75rem; font-weight: 600; }
    @media (max-width: 767px) { #tableView { display: none; } }
    @media (min-width: 768px) { #cardView { display: none; } }
</style>
</head>
<body>

<div class="page-header px-3 py-2 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock-fill text-primary fs-4"></i>
        <div>
            <div class="fw-bold">Platform Administration</div>
            <small class="text-muted">Signed in as <?= safe_output($me['name'] ?? '') ?></small>
        </div>
    </div>
    <a href="logout.php" class="btn btn-sm btn-secondary">
        <i class="bi bi-box-arrow-right me-1"></i> Sign out
    </a>
</div>

<div class="container-fluid p-3">

    <?php if ($dbError): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($dbError) ?></div>
    <?php endif; ?>

    <div class="row g-2 mb-3">
        <?php foreach ([
            'active' => ['Active', 'bi-check-circle'],
            'trial' => ['Trial', 'bi-hourglass-split'],
            'suspended' => ['Suspended', 'bi-pause-circle'],
            'deleted' => ['Closed', 'bi-x-circle'],
        ] as $key => [$label, $icon]): ?>
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small"><i class="bi <?= $icon ?> text-primary me-1"></i><?= $label ?></div>
                <div class="value"><?= (int)($counts[$key] ?? 0) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="mb-0"><i class="bi bi-building text-primary me-1"></i> Tenants</h6>
        <span class="text-muted small">Lifecycle actions arrive in Phase 6</span>
    </div>

    <?php if (!$tenants): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            No tenants registered yet.
        </div>
    <?php else: ?>

    <div id="tableView" class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>#</th><th>Company</th><th>Subdomain</th>
                    <th>Status</th><th>Plan</th><th>Owner</th><th>Created</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $t): ?>
                <tr>
                    <td><?= (int)$t['id'] ?></td>
                    <td><?= safe_output($t['company_name']) ?></td>
                    <td><code><?= safe_output($t['subdomain']) ?></code></td>
                    <td><?= saStatusBadge((string)$t['status']) ?></td>
                    <td><?= safe_output($t['plan'] ?? '—') ?></td>
                    <td><?= safe_output($t['owner_email']) ?></td>
                    <td><?= safe_output($t['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="cardView" class="row g-2">
        <?php foreach ($tenants as $t): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="fw-bold"><?= safe_output($t['company_name']) ?></div>
                        <?= saStatusBadge((string)$t['status']) ?>
                    </div>
                    <div><code><?= safe_output($t['subdomain']) ?></code></div>
                    <small class="text-muted"><?= safe_output($t['owner_email']) ?></small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
