<?php
/**
 * app/superadmin/dashboard.php — the platform panel's real landing page.
 *
 * Before this file, index.php redirected straight to tenants.php — a list, not
 * an overview. This is the overview: fleet-wide counts, growth and module
 * adoption charts, an operational "requires attention" feed, quick actions, and
 * recent operator activity.
 *
 * Control database ONLY — like every other page in this panel, it never opens a
 * tenant's own database (tenantUsageSnapshotFor() stays a one-tenant, on-demand,
 * explicit-click operation per core/tenant_quotas.php; a dashboard that looped it
 * over every tenant on every load would be exactly the "constant" use its own
 * docblock rules out).
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me = currentSuperadmin();

$stats = ['active' => 0, 'trial' => 0, 'suspended' => 0, 'deleted' => 0, 'total' => 0];
$error = null;
try {
    $stats = tenantStats();
} catch (Throwable $e) {
    error_log('superadmin dashboard (stats): ' . $e->getMessage());
    $error = 'The tenant registry could not be read.';
}

// ── Growth: signups per month, last 12 months ───────────────────────────────
$growthLabels = [];
$growthData   = [];
try {
    $raw = [];
    $st = getControlPdo()->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n
        FROM tenants
        WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
        GROUP BY ym
    ");
    foreach ($st as $r) { $raw[$r['ym']] = (int)$r['n']; }
    for ($i = 11; $i >= 0; $i--) {
        $ts = strtotime("-{$i} months");
        $growthLabels[] = date('M', $ts);
        $growthData[]   = $raw[date('Y-m', $ts)] ?? 0;
    }
} catch (Throwable $e) {
    error_log('superadmin dashboard (growth): ' . $e->getMessage());
}

$newLast30 = 0;
try {
    $newLast30 = (int)getControlPdo()
        ->query("SELECT COUNT(*) FROM tenants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")
        ->fetchColumn();
} catch (Throwable $e) {
    error_log('superadmin dashboard (new30): ' . $e->getMessage());
}

// ── Module adoption across live tenants ─────────────────────────────────────
$featureLabels = [];
$featureData   = [];
$featuresSetup = false;
try {
    if (featureTablesReady()) {
        $pf = platformFeatures();
        usort($pf, fn($a, $b) => $b['tenants_using'] <=> $a['tenants_using']);
        foreach ($pf as $f) {
            $featureLabels[] = $f['label'];
            $featureData[]   = (int)$f['tenants_using'];
        }
    } else {
        $featuresSetup = true;
    }
} catch (Throwable $e) {
    error_log('superadmin dashboard (features): ' . $e->getMessage());
}

// ── System requires attention ───────────────────────────────────────────────
// Each category is its own try/catch so one missing/broken table costs the
// dashboard that one category, never the whole page — same discipline
// tenant_view.php already uses for entitlements vs. identity.
$attention = [];

try {
    $rows = getControlPdo()->query("
        SELECT ml.tenant_id, ml.subdomain, t.company_name, ml.migration_name, ml.created_at
        FROM tenant_migration_log ml
        JOIN (
            SELECT tenant_id, migration_name, MAX(id) AS max_id
            FROM tenant_migration_log
            GROUP BY tenant_id, migration_name
        ) latest ON latest.max_id = ml.id
        JOIN tenants t ON t.id = ml.tenant_id AND t.status != 'deleted'
        WHERE ml.status = 'failed'
        ORDER BY ml.created_at DESC
    ")->fetchAll();

    // Only a migration file that STILL EXISTS on disk is something an operator
    // can act on — a since-removed file (a cleaned-up throwaway, a renamed
    // migration) has nothing left to fix and would just be permanent noise.
    $migDir = dirname(__DIR__, 2) . '/migrations/tenant/';
    $items = [];
    foreach ($rows as $r) {
        if (is_file($migDir . basename((string)$r['migration_name']))) {
            $items[] = $r;
        }
    }
    if ($items) {
        $attention['migrations'] = [
            'title' => 'Tenants with a failing migration',
            'icon'  => 'bi-exclamation-octagon',
            'color' => 'danger',
            'items' => $items,
        ];
    }
} catch (Throwable $e) {
    error_log('superadmin dashboard (migrations): ' . $e->getMessage());
}

try {
    $rows = getControlPdo()->query("
        SELECT tenant_id, subdomain, step, status, created_at
        FROM tenant_provisioning_log
        WHERE status IN ('failed','rolled_back')
          AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY created_at DESC
        LIMIT 30
    ")->fetchAll();
    if ($rows) {
        $attention['provisioning'] = [
            'title' => 'Provisioning attempts that failed (30d)',
            'icon'  => 'bi-x-octagon',
            'color' => 'danger',
            'items' => $rows,
        ];
    }
} catch (Throwable $e) {
    error_log('superadmin dashboard (provisioning): ' . $e->getMessage());
}

try {
    $rows = getControlPdo()->query("
        SELECT id, company_name, subdomain, owner_email,
               DATEDIFF(NOW(), created_at) AS days_old
        FROM tenants
        WHERE status = 'trial' AND created_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
        ORDER BY created_at ASC
        LIMIT 30
    ")->fetchAll();
    if ($rows) {
        $attention['stale_trials'] = [
            'title' => 'Trials open 14+ days with no decision',
            'icon'  => 'bi-hourglass-split',
            'color' => 'warning',
            'items' => $rows,
        ];
    }
} catch (Throwable $e) {
    error_log('superadmin dashboard (stale trials): ' . $e->getMessage());
}

try {
    $rows = getControlPdo()->query("
        SELECT id, company_name, subdomain, owner_email,
               DATEDIFF(NOW(), suspended_at) AS days_suspended
        FROM tenants
        WHERE status = 'suspended' AND suspended_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
        ORDER BY suspended_at ASC
        LIMIT 30
    ")->fetchAll();
    if ($rows) {
        $attention['long_suspended'] = [
            'title' => 'Suspended 14+ days — reactivate or close out',
            'icon'  => 'bi-pause-circle',
            'color' => 'secondary',
            'items' => $rows,
        ];
    }
} catch (Throwable $e) {
    error_log('superadmin dashboard (suspended): ' . $e->getMessage());
}

try {
    $rows = getControlPdo()->query("
        SELECT tenant_id, subdomain, detail, created_at
        FROM tenant_admin_log
        WHERE action = 'delete_partial'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY created_at DESC
        LIMIT 20
    ")->fetchAll();
    if ($rows) {
        $attention['delete_debris'] = [
            'title' => 'Deletions that left manual cleanup behind',
            'icon'  => 'bi-exclamation-triangle',
            'color' => 'danger',
            'items' => $rows,
        ];
    }
} catch (Throwable $e) {
    error_log('superadmin dashboard (delete debris): ' . $e->getMessage());
}

try {
    $rows = getControlPdo()->query("
        SELECT ip_address, COUNT(*) AS attempts, MAX(created_at) AS last_attempt
        FROM registration_attempts
        WHERE outcome IN ('throttled','rejected')
          AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY ip_address
        HAVING attempts >= 3
        ORDER BY attempts DESC
        LIMIT 20
    ")->fetchAll();
    if ($rows) {
        $attention['signup_abuse'] = [
            'title' => 'Repeated blocked signup attempts (24h)',
            'icon'  => 'bi-shield-exclamation',
            'color' => 'dark',
            'items' => $rows,
        ];
    }
} catch (Throwable $e) {
    error_log('superadmin dashboard (signup abuse): ' . $e->getMessage());
}

$attentionTotal = array_sum(array_map(fn($g) => count($g['items']), $attention));

// ── Recent platform activity ────────────────────────────────────────────────
$recentLog = [];
try {
    $recentLog = tenantAdminLog(null, 12);
} catch (Throwable $e) {
    error_log('superadmin dashboard (recent log): ' . $e->getMessage());
}

function saTimeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   { $m = (int)floor($diff / 60);    return $m . ($m > 1 ? ' minutes ago' : ' minute ago'); }
    if ($diff < 86400)  { $h = (int)floor($diff / 3600);  return $h . ($h > 1 ? ' hours ago'   : ' hour ago'); }
    if ($diff < 604800) { $d = (int)floor($diff / 86400); return $d . ($d > 1 ? ' days ago'    : ' day ago'); }
    return date('d M Y', strtotime($datetime));
}

/** action => [icon, bootstrap color, human label] */
function saActionMeta(string $action): array
{
    static $map = [
        'create'               => ['bi-plus-circle',           'success',   'Created tenant'],
        'create_failed'        => ['bi-x-circle',               'danger',    'Failed to create tenant'],
        'suspend'               => ['bi-pause-circle',           'warning',   'Suspended tenant'],
        'activate'              => ['bi-play-circle',            'primary',   'Activated tenant'],
        'delete'                => ['bi-trash',                  'danger',    'Deleted tenant'],
        'delete_refused'        => ['bi-shield-x',                'secondary', 'Delete refused (name mismatch)'],
        'delete_partial'        => ['bi-exclamation-triangle',   'danger',    'Delete left cleanup debris'],
        'update_features'       => ['bi-grid',                   'primary',   'Updated modules'],
        'update_quotas'         => ['bi-speedometer',            'primary',   'Updated quotas'],
        'platform_feature'      => ['bi-toggles',                'primary',   'Changed a platform-wide module'],
        'sa_credential_change'  => ['bi-person-gear',            'secondary', 'Updated their own account'],
        'plan_create'            => ['bi-box-seam',               'success',   'Created a plan'],
        'plan_update'            => ['bi-box-seam',               'primary',   'Updated a plan'],
        'plan_activate'          => ['bi-play-circle',            'primary',   'Restored a plan'],
        'plan_deactivate'        => ['bi-pause-circle',           'secondary', 'Retired a plan'],
        'apply_plan'             => ['bi-check2-circle',          'success',   'Applied a plan to tenant'],
        'platform_settings'      => ['bi-gear-wide-connected',    'primary',   'Updated platform settings'],
    ];
    return $map[$action] ?? ['bi-activity', 'secondary', ucfirst(str_replace('_', ' ', $action))];
}

/** One attention-list item's detail line, per category shape. */
function saAttentionItemHtml(string $key, array $it): string
{
    $tenantLink = isset($it['tenant_id']) && $it['tenant_id']
        ? saUrl('tenants/view') . '?id=' . (int)$it['tenant_id']
        : null;

    switch ($key) {
        case 'migrations':
            $who  = safe_output($it['company_name'] ?? $it['subdomain'], 'Unknown tenant');
            $body = '<div class="fw-semibold small">' . $who . ' <span class="text-muted fw-normal">(' . safe_output($it['subdomain'], '') . ')</span></div>'
                  . '<div class="text-muted" style="font-size:.75rem;"><code>' . safe_output($it['migration_name'], '') . '</code> · failed ' . saTimeAgo((string)$it['created_at']) . '</div>';
            break;
        case 'provisioning':
            $body = '<div class="fw-semibold small">' . safe_output($it['subdomain'], 'Unknown') . '</div>'
                  . '<div class="text-muted" style="font-size:.75rem;">step "' . safe_output($it['step'], '') . '" — ' . safe_output($it['status'], '') . ' · ' . saTimeAgo((string)$it['created_at']) . '</div>';
            break;
        case 'stale_trials':
            $body = '<div class="fw-semibold small">' . safe_output($it['company_name'], '') . ' <span class="text-muted fw-normal">(' . safe_output($it['subdomain'], '') . ')</span></div>'
                  . '<div class="text-muted" style="font-size:.75rem;">' . safe_output($it['owner_email'], '') . ' · on trial ' . (int)$it['days_old'] . 'd</div>';
            break;
        case 'long_suspended':
            $body = '<div class="fw-semibold small">' . safe_output($it['company_name'], '') . ' <span class="text-muted fw-normal">(' . safe_output($it['subdomain'], '') . ')</span></div>'
                  . '<div class="text-muted" style="font-size:.75rem;">' . safe_output($it['owner_email'], '') . ' · suspended ' . (int)$it['days_suspended'] . 'd</div>';
            break;
        case 'delete_debris':
            $body = '<div class="fw-semibold small">' . safe_output($it['subdomain'], 'Unknown') . '</div>'
                  . '<div class="text-muted" style="font-size:.75rem;">' . safe_output($it['detail'], '') . ' · ' . saTimeAgo((string)$it['created_at']) . '</div>';
            break;
        case 'signup_abuse':
            $body = '<div class="fw-semibold small">IP ' . safe_output($it['ip_address'], '') . '</div>'
                  . '<div class="text-muted" style="font-size:.75rem;">' . (int)$it['attempts'] . ' blocked attempts · last ' . saTimeAgo((string)$it['last_attempt']) . '</div>';
            $tenantLink = null; // no single tenant to jump to
            break;
        default:
            $body = '<div class="small text-muted">' . safe_output(json_encode($it), '') . '</div>';
    }

    $link = $tenantLink
        ? '<a href="' . htmlspecialchars($tenantLink, ENT_QUOTES, 'UTF-8') . '" class="btn btn-xs btn-light border p-1 py-0 shadow-sm ms-2" title="View tenant"><i class="bi bi-arrow-right-short fs-5 text-primary"></i></a>'
        : '';

    return '<div class="d-flex justify-content-between align-items-center gap-2"><div style="min-width:0;flex:1;">' . $body . '</div>' . $link . '</div>';
}

$firstName = trim((string)($me['name'] ?? ''));
$firstName = $firstName !== '' ? explode(' ', $firstName)[0] : 'Operator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Dashboard | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #f8f9fb; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .stat-card { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; }
    .stat-card .value { font-size: 1.75rem; font-weight: 600; }
    .btn-xs { padding: .1rem .3rem; font-size: .75rem; }
    .pulse-icon { animation: saPulse 2s infinite; }
    @keyframes saPulse { 0%,100% { opacity:1; } 50% { opacity:.55; } }
    .card { border-radius: 10px; }
</style>
</head>
<body>

<?php renderSuperadminHeader('dashboard', $me); ?>

<div class="container-fluid p-3">

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($error, '') ?></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-0 fw-bold">Welcome back, <?= safe_output($firstName, 'Operator') ?></h4>
            <div class="text-muted small">Platform overview across <?= (int)($stats['total'] ?? 0) ?> registered compan<?= ((int)($stats['total'] ?? 0)) === 1 ? 'y' : 'ies' ?>.</div>
        </div>
    </div>

    <!-- System requires attention -->
    <?php if ($attentionTotal > 0): ?>
    <div class="card border-0 shadow-sm overflow-hidden mb-4" style="background:linear-gradient(135deg,#fff9e6 0%,#fff 100%);border-left:5px solid #ffc107 !important;">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center">
                    <div class="bg-warning-subtle text-warning p-2 rounded-circle me-3 shadow-sm">
                        <i class="bi bi-bell-fill fs-4 pulse-icon"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold text-dark">System requires your attention</h6>
                        <p class="mb-0 text-muted small"><strong><?= (int)$attentionTotal ?></strong> item<?= $attentionTotal === 1 ? '' : 's' ?> across <?= count($attention) ?> categor<?= count($attention) === 1 ? 'y' : 'ies' ?>.</p>
                    </div>
                </div>
                <button class="btn btn-warning btn-sm fw-bold px-3 shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#saAttentionDetail">
                    <i class="bi bi-eye me-1"></i> View Details
                </button>
            </div>

            <div class="collapse mt-3" id="saAttentionDetail">
                <hr class="my-3 opacity-10">
                <div class="accordion" id="saAttentionAccordion">
                    <?php foreach ($attention as $key => $group): ?>
                    <div class="accordion-item border border-light-subtle mb-2 rounded-3 shadow-sm overflow-hidden">
                        <div class="d-flex align-items-center gap-2 bg-white p-2 ps-3 flex-wrap">
                            <div class="bg-<?= $group['color'] ?>-subtle text-<?= $group['color'] ?> p-2 rounded-3" style="width:38px;height:38px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="bi <?= $group['icon'] ?> fs-5"></i>
                            </div>
                            <h6 class="mb-0 fw-bold small text-uppercase" style="letter-spacing:.5px;"><?= safe_output($group['title'], '') ?></h6>
                            <span class="badge bg-<?= $group['color'] ?> rounded-pill"><?= count($group['items']) ?></span>
                            <div class="ms-auto">
                                <button class="btn btn-sm btn-outline-<?= $group['color'] ?> collapsed d-flex align-items-center gap-1" type="button" data-bs-toggle="collapse" data-bs-target="#saGrp-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-list-ul"></i><span class="d-none d-sm-inline">View here</span>
                                </button>
                            </div>
                        </div>
                        <div id="saGrp-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" class="accordion-collapse collapse" data-bs-parent="#saAttentionAccordion">
                            <div class="p-2" style="max-height:280px;overflow-y:auto;">
                                <?php foreach ($group['items'] as $it): ?>
                                <div class="p-2 mb-2 rounded-2 border-bottom border-light-subtle">
                                    <?= saAttentionItemHtml($key, $it) ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#eafaf0 0%,#fff 100%);border-left:5px solid #198754 !important;">
        <div class="card-body p-3 d-flex align-items-center">
            <div class="bg-success-subtle text-success p-2 rounded-circle me-3 shadow-sm"><i class="bi bi-check-circle-fill fs-4"></i></div>
            <div>
                <h6 class="mb-0 fw-bold text-dark">All clear</h6>
                <p class="mb-0 text-muted small">No failing migrations, stuck trials, provisioning issues, or cleanup debris right now.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick actions -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light"><h6 class="mb-0"><i class="bi bi-lightning-charge text-primary me-1"></i> Quick Actions</h6></div>
        <div class="card-body">
            <div class="row row-cols-2 row-cols-lg-3 row-cols-xl-6 g-3">
                <div class="col">
                    <a href="<?= saUrl('tenants/new') ?>" class="btn btn-outline-primary w-100 h-100 py-3">
                        <i class="bi bi-plus-circle display-6"></i>
                        <div class="mt-2 fw-semibold">New Company</div>
                    </a>
                </div>
                <div class="col">
                    <a href="<?= saUrl('tenants') ?>" class="btn btn-outline-info w-100 h-100 py-3">
                        <i class="bi bi-building display-6"></i>
                        <div class="mt-2 fw-semibold">All Tenants</div>
                    </a>
                </div>
                <div class="col">
                    <a href="<?= saUrl('features') ?>" class="btn btn-outline-secondary w-100 h-100 py-3">
                        <i class="bi bi-grid display-6"></i>
                        <div class="mt-2 fw-semibold">Modules</div>
                    </a>
                </div>
                <div class="col">
                    <a href="<?= saUrl('plans') ?>" class="btn btn-outline-warning w-100 h-100 py-3">
                        <i class="bi bi-box-seam display-6"></i>
                        <div class="mt-2 fw-semibold">Plans</div>
                    </a>
                </div>
                <div class="col">
                    <a href="<?= saUrl('settings') ?>" class="btn btn-outline-success w-100 h-100 py-3">
                        <i class="bi bi-gear-wide-connected display-6"></i>
                        <div class="mt-2 fw-semibold">Platform Settings</div>
                    </a>
                </div>
                <div class="col">
                    <a href="<?= saUrl('profile') ?>" class="btn btn-outline-dark w-100 h-100 py-3">
                        <i class="bi bi-person-gear display-6"></i>
                        <div class="mt-2 fw-semibold">My Account</div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-2 mb-4">
        <?php foreach ([
            'active'    => ['Active', 'bi-check-circle'],
            'trial'     => ['Trial', 'bi-hourglass-split'],
            'suspended' => ['Suspended', 'bi-pause-circle'],
            'deleted'   => ['Closed', 'bi-x-circle'],
        ] as $key => [$label, $icon]): ?>
        <div class="col-6 col-md-3">
            <div class="stat-card p-3">
                <div class="text-muted small"><i class="bi <?= $icon ?> text-primary me-1"></i><?= $label ?></div>
                <div class="value"><?= (int)($stats[$key] ?? 0) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts -->
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up-arrow text-primary me-2"></i>Tenant Growth</h6>
                    <span class="badge bg-primary-subtle text-primary"><?= (int)$newLast30 ?> new in last 30 days</span>
                </div>
                <div class="card-body">
                    <div style="position:relative;height:280px;">
                        <canvas id="saGrowthChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-grid text-primary me-2"></i>Module Adoption</h6>
                </div>
                <div class="card-body">
                    <?php if ($featuresSetup): ?>
                        <div class="text-center text-muted py-5"><i class="bi bi-tools fs-2 d-block mb-2"></i>Module control is not set up on this server yet.</div>
                    <?php elseif (!$featureLabels): ?>
                        <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>No modules registered.</div>
                    <?php else: ?>
                        <div style="position:relative;height:<?= max(220, count($featureLabels) * 30) ?>px;">
                            <canvas id="saFeatureChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent activity -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light">
            <h6 class="mb-0 text-uppercase" style="font-size:.75rem;letter-spacing:.05em;font-weight:700;"><i class="bi bi-clock-history"></i> Recent Platform Activity</h6>
        </div>
        <div class="card-body p-0">
            <div class="list-group list-group-flush" style="max-height:420px;overflow-y:auto;">
                <?php if ($recentLog): foreach ($recentLog as $l): [$icon, $color, $label] = saActionMeta((string)$l['action']); ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="d-flex align-items-start gap-2" style="min-width:0;">
                            <i class="bi <?= $icon ?> text-<?= $color ?> mt-1"></i>
                            <div style="min-width:0;">
                                <div class="small fw-semibold">
                                    <?= safe_output($label, '') ?>
                                    <?php if (!empty($l['subdomain'])): ?><span class="text-muted fw-normal">— <?= safe_output($l['subdomain'], '') ?></span><?php endif; ?>
                                </div>
                                <?php if (!empty($l['detail'])): ?>
                                <div class="text-muted text-truncate" style="font-size:.72rem;max-width:240px;"><?= safe_output($l['detail'], '') ?></div>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size:.68rem;"><i class="bi bi-person-circle me-1"></i><?= safe_output($l['actor_email'] ?? '', 'system') ?></div>
                            </div>
                        </div>
                        <small class="text-muted text-nowrap"><?= saTimeAgo((string)$l['created_at']) ?></small>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div class="text-center text-muted py-4"><i class="bi bi-activity fs-2 d-block mb-2"></i>No activity yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
Chart.defaults.color = '#898781';

new Chart(document.getElementById('saGrowthChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($growthLabels) ?>,
        datasets: [{
            label: 'New tenants',
            data: <?= json_encode($growthData) ?>,
            backgroundColor: '#2a78d6',
            borderRadius: 4,
            maxBarThickness: 22
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { backgroundColor: '#0b0b0b', padding: 10, cornerRadius: 6, displayColors: false }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#52514e' } },
            y: { beginAtZero: true, ticks: { precision: 0, color: '#898781' }, grid: { color: '#e1e0d9' } }
        }
    }
});

<?php if ($featureLabels): ?>
new Chart(document.getElementById('saFeatureChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($featureLabels) ?>,
        datasets: [{
            label: 'Tenants using',
            data: <?= json_encode($featureData) ?>,
            backgroundColor: '#2a78d6',
            borderRadius: 4,
            maxBarThickness: 18
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { backgroundColor: '#0b0b0b', padding: 10, cornerRadius: 6, displayColors: false }
        },
        scales: {
            x: { beginAtZero: true, ticks: { precision: 0, color: '#898781' }, grid: { color: '#e1e0d9' } },
            y: { grid: { display: false }, ticks: { color: '#52514e' } }
        }
    }
});
<?php endif; ?>
</script>
</body>
</html>
