<?php
/**
 * app/bms/operations/asset_dashboard.php
 *
 * Asset intelligence dashboard (Asset Register & PPE Schedule, Phase 8):
 * KPIs (total NBV by category, current-FY depreciation charge, counts) and
 * alerts (maintenance overdue, fully depreciated, warranty expiring, nearing
 * end of life) — surfaced without being asked.
 */
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/asset_depreciation_service.php';
require_once __DIR__ . '/../../../core/asset_settings.php';
require_once __DIR__ . '/../../../core/asset_depreciation_run.php';

autoEnforcePermission('assets');

$settings = getAssetSettings($pdo);
$timing   = $settings['depreciation_timing'];
$today    = date('Y-m-d');
$fy       = (int)date('Y', strtotime($settings['financial_year_start']));
[$fyStart, $fyEnd] = fyBoundsForYear($settings, $fy);

// ── Pull active assets with book area + latest posted book entry ─────────────
$assets = $pdo->query("
    SELECT a.asset_id, a.asset_code, a.asset_name, a.category, a.cost, a.status,
           a.`condition`, a.location, a.warranty_expiry, a.disposal_date,
           ba.method bmethod, ba.useful_life buse, ba.rate brate,
           ba.salvage_value bsalv, ba.start_date bstart, ba.opening_accum_bf bbf,
           le.accumulated le_acc, le.closing_nbv le_nbv,
           TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS custodian_name,
           u.username AS custodian_username
      FROM assets a
      LEFT JOIN asset_depreciation_areas ba ON ba.asset_id = a.asset_id AND ba.area = 'book'
      LEFT JOIN users u ON u.user_id = a.custodian_id
      LEFT JOIN (
          SELECT de.asset_id, de.accumulated, de.closing_nbv
            FROM depreciation_entries de
            JOIN (SELECT asset_id, MAX(period_end) mpe FROM depreciation_entries WHERE area='book' GROUP BY asset_id) m
              ON m.asset_id = de.asset_id AND m.mpe = de.period_end
           WHERE de.area='book'
      ) le ON le.asset_id = a.asset_id
     WHERE a.status != 'deleted'
")->fetchAll(PDO::FETCH_ASSOC);

// ── Compute per-asset book NBV + classify ────────────────────────────────────
$nbvByCategory = [];
$totalNbv = 0.0; $activeCount = 0; $nearingEol = 0;
$alerts = ['fully_depreciated' => [], 'nearing_eol' => [], 'warranty' => [], 'maintenance' => []];

foreach ($assets as $a) {
    $cost = (float)$a['cost'];
    $disposed = in_array($a['status'], ['disposed','written_off'], true);

    if ($a['le_nbv'] !== null) {
        $nbv = (float)$a['le_nbv'];
    } elseif ($a['bmethod']) {
        $nbv = calcAreaDepreciation([
            'method'=>$a['bmethod'],'useful_life'=>$a['buse'],'rate'=>$a['brate'],
            'salvage_value'=>$a['bsalv'],'start_date'=>$a['bstart'],'opening_accum_bf'=>$a['bbf'],
        ], $cost, $today, $timing)['nbv'];
    } else {
        $nbv = $cost; // non-depreciable / unconfigured
    }

    if (!$disposed) {
        $cat = $a['category'] ?: 'Uncategorized';
        $nbvByCategory[$cat] = ($nbvByCategory[$cat] ?? 0) + $nbv;
        $totalNbv += $nbv;
        $activeCount++;

        $salvage = (float)($a['bsalv'] ?? 0);
        $pct = $cost > 0 ? ($nbv / $cost) * 100 : 100;

        // Fully depreciated: NBV at/below salvage (and it was depreciable).
        if ($a['bmethod'] && $nbv <= $salvage + 0.01) {
            $alerts['fully_depreciated'][] = $a;
        }
        // Nearing end of life: condition flag or NBV < 25%.
        if (in_array($a['condition'], ['poor','eol'], true) || ($a['bmethod'] && $pct < 25 && $nbv > $salvage + 0.01)) {
            $alerts['nearing_eol'][] = $a;
            $nearingEol++;
        }
        // Warranty expiring within 60 days (or already lapsed).
        if ($a['warranty_expiry'] && strtotime($a['warranty_expiry']) <= strtotime('+60 days')) {
            $alerts['warranty'][] = $a;
        }
    }
}

// ── Depreciation charge for the current FY (book) ────────────────────────────
$chargeStmt = $pdo->prepare("SELECT COALESCE(SUM(charge),0) FROM depreciation_entries WHERE area='book' AND period_end BETWEEN ? AND ?");
$chargeStmt->execute([$fyStart, $fyEnd]);
$currentFyCharge = (float)$chargeStmt->fetchColumn();

// ── Maintenance overdue (latest next_due_date in the past) ───────────────────
$maintStmt = $pdo->query("
    SELECT a.asset_id, a.asset_code, a.asset_name, mx.next_due
      FROM assets a
      JOIN (SELECT asset_id, MAX(next_due_date) next_due FROM asset_maintenance WHERE next_due_date IS NOT NULL GROUP BY asset_id) mx
        ON mx.asset_id = a.asset_id
     WHERE a.status NOT IN ('deleted','disposed','written_off')
       AND mx.next_due < CURDATE()
  ORDER BY mx.next_due ASC
");
$maintenanceOverdue = $maintStmt->fetchAll(PDO::FETCH_ASSOC);

arsort($nbvByCategory);
$maxCatNbv = $nbvByCategory ? max($nbvByCategory) : 0;

if (function_exists('logActivity')) {
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed Asset Dashboard', 'Opened the asset intelligence dashboard');
}

includeHeader();
function tzs0($v){ return number_format((float)$v, 0) . ' TZS'; }
$viewUrl = getUrl('asset_view');
?>

<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('assets') ?>">Assets</a></li>
            <li class="breadcrumb-item active">Intelligence Dashboard</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="fw-bold text-primary mb-0"><i class="bi bi-speedometer2 me-2"></i> Asset Intelligence</h2>
        <div>
            <a href="<?= getUrl('asset_schedule') ?>" class="btn btn-outline-primary"><i class="bi bi-table me-1"></i> PPE Schedule</a>
            <a href="<?= getUrl('asset_verify') ?>" class="btn btn-outline-secondary"><i class="bi bi-qr-code-scan me-1"></i> Verify</a>
            <a href="<?= getUrl('assets') ?>" class="btn btn-outline-secondary"><i class="bi bi-list me-1"></i> Register</a>
        </div>
    </div>

    <!-- KPI cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-primary"><?= tzs0($totalNbv) ?></div><div class="small text-muted">Total NBV (Book)</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-success"><?= tzs0($currentFyCharge) ?></div><div class="small text-muted">Dep. Charge FY <?= $fy ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-dark"><?= (int)$activeCount ?></div><div class="small text-muted">Active Assets</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-warning"><?= (int)$nearingEol ?></div><div class="small text-muted">Nearing End of Life</div></div></div>
    </div>

    <div class="row g-4">
        <!-- NBV by category -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold"><i class="bi bi-bar-chart me-1"></i> Net Book Value by Category</div>
                <div class="card-body">
                    <?php if (!$nbvByCategory): ?>
                        <div class="text-muted small">No active assets.</div>
                    <?php else: foreach ($nbvByCategory as $cat => $v): $w = $maxCatNbv > 0 ? round($v / $maxCatNbv * 100) : 0; ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small"><span><?= safe_output($cat) ?></span><span class="fw-semibold"><?= tzs0($v) ?></span></div>
                            <div class="progress" style="height:8px"><div class="progress-bar bg-primary" style="width:<?= $w ?>%"></div></div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold"><i class="bi bi-bell me-1"></i> Alerts</div>
                <div class="card-body">
                    <?php
                    $alertBlocks = [
                        ['Maintenance overdue', 'danger', 'bi-tools', $maintenanceOverdue, 'next_due'],
                        ['Warranty expiring',   'warning', 'bi-shield-exclamation', $alerts['warranty'], 'warranty_expiry'],
                        ['Nearing end of life', 'warning', 'bi-hourglass-bottom', $alerts['nearing_eol'], null],
                        ['Fully depreciated',   'secondary', 'bi-check2-circle', $alerts['fully_depreciated'], null],
                    ];
                    $anyAlert = false;
                    foreach ($alertBlocks as [$label,$color,$icon,$list,$dateKey]):
                        if (!$list) continue; $anyAlert = true; ?>
                        <div class="mb-3">
                            <div class="fw-semibold mb-1"><i class="bi <?= $icon ?> text-<?= $color ?> me-1"></i> <?= $label ?> <span class="badge bg-<?= $color ?>"><?= count($list) ?></span></div>
                            <ul class="list-group list-group-flush small">
                                <?php foreach (array_slice($list, 0, 5) as $it): ?>
                                <li class="list-group-item d-flex justify-content-between px-0 py-1">
                                    <a href="<?= $viewUrl ?>?id=<?= (int)$it['asset_id'] ?>"><?= safe_output($it['asset_code']) ?> — <?= safe_output($it['asset_name']) ?></a>
                                    <?php if ($dateKey && !empty($it[$dateKey])): ?><span class="text-muted"><?= safe_output($it[$dateKey]) ?></span><?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                                <?php if (count($list) > 5): ?><li class="list-group-item px-0 py-1 text-muted">…and <?= count($list)-5 ?> more</li><?php endif; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$anyAlert): ?>
                        <div class="text-success small"><i class="bi bi-check-circle me-1"></i> No alerts — everything looks healthy.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php includeFooter(); ob_end_flush(); ?>
