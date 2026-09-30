<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_dashboard');

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$prevMonthStart = date('Y-m-01', strtotime('-1 month'));
$prevMonthEnd   = date('Y-m-t', strtotime('-1 month'));

// --- Today's KPIs ---
$todayStats = $pdo->prepare("
    SELECT COUNT(*) AS txn_count,
           COALESCE(SUM(principal_amount),0) AS volume,
           COALESCE(SUM(commission_earned),0) AS commission
    FROM mm_transactions
    WHERE txn_date=? AND status='posted'
");
$todayStats->execute([$today]);
$today_kpi = $todayStats->fetch(PDO::FETCH_ASSOC);

// --- Month KPIs ---
$monthStats = $pdo->prepare("
    SELECT COUNT(*) AS txn_count,
           COALESCE(SUM(principal_amount),0) AS volume,
           COALESCE(SUM(commission_earned),0) AS commission
    FROM mm_transactions
    WHERE txn_date BETWEEN ? AND ? AND status='posted'
");
$monthStats->execute([$monthStart, $monthEnd]);
$month_kpi = $monthStats->fetch(PDO::FETCH_ASSOC);

// Previous month commission for trend badge
$monthStats->execute([$prevMonthStart, $prevMonthEnd]);
$prev_month_kpi = $monthStats->fetch(PDO::FETCH_ASSOC);
$comm_trend = $prev_month_kpi['commission'] > 0
    ? round((($month_kpi['commission'] - $prev_month_kpi['commission']) / $prev_month_kpi['commission']) * 100, 1)
    : null;

// --- Active tills / agents / shifts ---
$tillCount  = (int)$pdo->query("SELECT COUNT(*) FROM mm_tills WHERE status='active'")->fetchColumn();
$agentCount = (int)$pdo->query("SELECT COUNT(*) FROM mm_agents WHERE status='active'")->fetchColumn();
$openShifts = (int)$pdo->query("SELECT COUNT(*) FROM mm_shifts WHERE status='open'")->fetchColumn();

// All of the current user's open shifts (no LIMIT 1)
if (isAdmin()) {
    $myActiveShifts = $pdo->query("
        SELECT s.shift_id, s.shift_code, t.till_id, t.till_number, a.agent_name
        FROM mm_shifts s
        JOIN mm_tills t ON t.till_id = s.till_id
        JOIN mm_agents a ON a.agent_id = t.agent_id
        WHERE s.status = 'open'
        ORDER BY s.opened_at
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $myShiftStmt = $pdo->prepare("
        SELECT s.shift_id, s.shift_code, t.till_id, t.till_number, a.agent_name
        FROM mm_shifts s
        JOIN mm_tills t ON t.till_id = s.till_id
        JOIN mm_agents a ON a.agent_id = t.agent_id
        WHERE s.teller_user_id = ? AND s.status = 'open'
        ORDER BY s.opened_at
    ");
    $myShiftStmt->execute([$_SESSION['user_id']]);
    $myActiveShifts = $myShiftStmt->fetchAll(PDO::FETCH_ASSOC);
}
$myActiveShift = $myActiveShifts[0] ?? null; // kept for the active-shift chip

// Can this user still open more shifts? (assigned tills with no current open shift)
if (isAdmin()) {
    $canOpenMore = (bool)$pdo->query("
        SELECT COUNT(*) FROM mm_tills
        WHERE status = 'active'
          AND till_id NOT IN (SELECT till_id FROM mm_shifts WHERE status = 'open')
    ")->fetchColumn();
} else {
    $canOpenMoreStmt = $pdo->prepare("
        SELECT COUNT(*) FROM mm_tills t
        JOIN mm_user_agent_grants g ON g.agent_id = t.agent_id
            AND (g.till_id IS NULL OR g.till_id = t.till_id)
        WHERE t.status = 'active' AND g.user_id = ? AND g.can_open_shift = 1
          AND t.till_id NOT IN (SELECT till_id FROM mm_shifts WHERE status = 'open')
    ");
    $canOpenMoreStmt->execute([$_SESSION['user_id']]);
    $canOpenMore = (bool)$canOpenMoreStmt->fetchColumn();
}

// --- Daily volume last 14 days (chart) ---
$dailyVol = $pdo->prepare("
    SELECT txn_date, COALESCE(SUM(principal_amount),0) AS vol, COUNT(*) AS cnt
    FROM mm_transactions
    WHERE txn_date BETWEEN DATE_SUB(?, INTERVAL 13 DAY) AND ? AND status='posted'
    GROUP BY txn_date ORDER BY txn_date
");
$dailyVol->execute([$today, $today]);
$dailyData = $dailyVol->fetchAll(PDO::FETCH_ASSOC);

// --- Volume by txn type this month ---
$typeVol = $pdo->prepare("
    SELECT txn_type, COALESCE(SUM(principal_amount),0) AS vol, COUNT(*) AS cnt
    FROM mm_transactions
    WHERE txn_date BETWEEN ? AND ? AND status='posted'
    GROUP BY txn_type ORDER BY vol DESC
");
$typeVol->execute([$monthStart, $monthEnd]);
$typeData = $typeVol->fetchAll(PDO::FETCH_ASSOC);

// --- Top 5 agents by volume this month ---
$topAgents = $pdo->prepare("
    SELECT a.agent_name, COALESCE(SUM(t.principal_amount),0) AS vol, COUNT(*) AS cnt
    FROM mm_transactions t
    JOIN mm_agents a ON a.agent_id = t.agent_id
    WHERE t.txn_date BETWEEN ? AND ? AND t.status='posted'
    GROUP BY a.agent_id ORDER BY vol DESC LIMIT 5
");
$topAgents->execute([$monthStart, $monthEnd]);
$topAgentsData = $topAgents->fetchAll(PDO::FETCH_ASSOC);

// --- Volume by network this month ---
$networkVol = $pdo->prepare("
    SELECT n.network_name, n.color_hex, COALESCE(SUM(t.principal_amount),0) AS vol
    FROM mm_transactions t
    JOIN mm_networks n ON n.network_id = t.network_id
    WHERE t.txn_date BETWEEN ? AND ? AND t.status='posted'
    GROUP BY n.network_id ORDER BY vol DESC
");
$networkVol->execute([$monthStart, $monthEnd]);
$networkData = $networkVol->fetchAll(PDO::FETCH_ASSOC);

// Build chart data JSON
$chartDates = []; $chartVols = [];
foreach ($dailyData as $d) { $chartDates[] = $d['txn_date']; $chartVols[] = (float)$d['vol']; }

$typeLabels = []; $typeVols = [];
foreach ($typeData as $d) { $typeLabels[] = ucwords(str_replace('_',' ',$d['txn_type'])); $typeVols[] = (float)$d['vol']; }

$networkLabels = []; $networkVols = []; $networkColors = [];
foreach ($networkData as $d) { $networkLabels[] = $d['network_name']; $networkVols[] = (float)$d['vol']; $networkColors[] = $d['color_hex'] ?: '#6c757d'; }

includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View MM Dashboard', 'Viewed Mobile Money Dashboard');

function mmTrendBadge($pct): string {
    if ($pct === null) return '';
    $cls  = $pct >= 0 ? 'success' : 'danger';
    $icon = $pct >= 0 ? 'bi-arrow-up' : 'bi-arrow-down';
    return "<span class='badge bg-{$cls} ms-1'><i class='bi {$icon}'></i> " . abs($pct) . "%</span>";
}
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <i class="bi bi-speedometer2 text-primary fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Mobile Money Dashboard') ?></h4>
        <span class="text-muted small ms-1"><?= t('Today:') ?> <?= $today ?></span>
        <?php foreach ($myActiveShifts as $sh): ?>
        <a href="<?= getUrl('mm_shifts') ?>" class="badge bg-success text-decoration-none ms-1"
           title="<?= t('Active Shift') ?>: <?= safe_output($sh['shift_code']) ?> · <?= safe_output($sh['agent_name'].' / '.$sh['till_number']) ?>">
            <i class="bi bi-play-circle-fill me-1"></i><?= safe_output($sh['till_number']) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Quick Actions — dashboard.php style: card with bg-light header, flex-fill buttons -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-link-45deg"></i> <?= t('Quick Actions') ?></h6>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-3">
                        <?php if (canCreate('mm_transactions')): ?>
                        <div class="flex-fill" style="min-width: 130px;">
                            <a href="<?= getUrl('mm_transactions') ?>" class="btn btn-outline-primary w-100 h-100 py-3">
                                <i class="bi bi-arrow-left-right display-6"></i>
                                <div class="mt-2"><?= t('New Transaction') ?></div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if (canView('mm_shifts')): ?>
                        <?php if ($canOpenMore): ?>
                        <div class="flex-fill" style="min-width: 130px;">
                            <a href="<?= getUrl('mm_shifts') ?>?action=open" class="btn btn-outline-primary w-100 h-100 py-3">
                                <i class="bi bi-play-circle display-6"></i>
                                <div class="mt-2"><?= t('Open Shift') ?></div>
                            </a>
                        </div>
                        <?php elseif (empty($myActiveShifts)): ?>
                        <div class="flex-fill" style="min-width: 130px;">
                            <div class="btn btn-outline-secondary w-100 h-100 py-3 disabled opacity-50">
                                <i class="bi bi-play-circle display-6"></i>
                                <div class="mt-2"><?= t('Open Shift') ?></div>
                                <div class="small mt-1 opacity-75"><?= t('No tills assigned') ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($myActiveShifts)): ?>
                        <div class="flex-fill" style="min-width: 130px;">
                            <a href="<?= getUrl('mm_shifts') ?>" class="btn btn-outline-danger w-100 h-100 py-3">
                                <i class="bi bi-stop-circle display-6"></i>
                                <div class="mt-2"><?= t('Close Shift') ?></div>
                                <div class="small mt-1 opacity-75"><?= count($myActiveShifts) ?> <?= t('open') ?></div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if (canCreate('mm_float')): ?>
                        <div class="flex-fill" style="min-width: 130px;">
                            <a href="<?= getUrl('mm_float') ?>" class="btn btn-outline-warning w-100 h-100 py-3">
                                <i class="bi bi-currency-exchange display-6"></i>
                                <div class="mt-2"><?= t('Float Top-up') ?></div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if (isAdmin()): ?>
                        <div class="flex-fill" style="min-width: 130px;">
                            <a href="<?= getUrl('mm_agents') ?>" class="btn btn-outline-secondary w-100 h-100 py-3">
                                <i class="bi bi-shop-window display-6"></i>
                                <div class="mt-2"><?= t('Agents') ?></div>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI Cards — flex-fill so all 5 cards share the full row width -->
    <div class="d-flex flex-wrap gap-3 mb-4">

        <!-- 1. Today's Transactions — blue -->
        <a class="flex-fill text-decoration-none" style="min-width: 200px;"
           href="<?= getUrl('mm_transactions') ?>">
            <div class="card bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= number_format((int)$today_kpi['txn_count']) ?></h4>
                            <p class="mb-0"><?= t("Today's Transactions") ?></p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-arrow-left-right" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small><i class="bi bi-cash-stack"></i>
                            <?= number_format((float)$today_kpi['volume']) ?> TZS <?= t('volume') ?>
                        </small>
                    </div>
                </div>
            </div>
        </a>

        <!-- 2. Month Transactions — cyan -->
        <a class="flex-fill text-decoration-none" style="min-width: 200px;"
           href="<?= getUrl('mm_transactions') ?>">
            <div class="card bg-info text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= number_format((int)$month_kpi['txn_count']) ?></h4>
                            <p class="mb-0"><?= t('Month Transactions') ?></p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calendar-month" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small><i class="bi bi-cash-stack"></i>
                            <?= number_format((float)$month_kpi['volume']) ?> TZS <?= t('volume') ?>
                        </small>
                    </div>
                </div>
            </div>
        </a>

        <!-- 3. Today's Commission — green -->
        <a class="flex-fill text-decoration-none" style="min-width: 200px;"
           href="<?= getUrl('mm_transactions') ?>">
            <div class="card bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= number_format((float)$today_kpi['commission']) ?></h4>
                            <p class="mb-0"><?= t("Today's Commission") ?></p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-coin" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small><i class="bi bi-currency-dollar"></i> TZS</small>
                    </div>
                </div>
            </div>
        </a>

        <!-- 4. Month Commission — yellow (with trend) -->
        <a class="flex-fill text-decoration-none" style="min-width: 200px;"
           href="<?= getUrl('mm_transactions') ?>">
            <div class="card bg-warning text-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">
                                <?= number_format((float)$month_kpi['commission']) ?>
                                <?= mmTrendBadge($comm_trend) ?>
                            </h4>
                            <p class="mb-0"><?= t('Month Commission') ?></p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-graph-up-arrow" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small><i class="bi bi-currency-dollar"></i> TZS</small>
                    </div>
                </div>
            </div>
        </a>

        <!-- 5. Open Shifts / Active Tills — dark -->
        <a class="flex-fill text-decoration-none" style="min-width: 200px;"
           href="<?= getUrl('mm_shifts') ?>">
            <div class="card bg-dark text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $openShifts ?></h4>
                            <p class="mb-0"><?= t('Open Shifts') ?></p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-toggles" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small>
                            <i class="bi bi-display"></i> <?= $tillCount ?> <?= t('tills') ?>,
                            <i class="bi bi-person-badge"></i> <?= $agentCount ?> <?= t('agents') ?>
                        </small>
                    </div>
                </div>
            </div>
        </a>

    </div>

    <!-- Charts row -->
    <div class="row g-3 mb-4">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold bg-transparent"><?= t('Daily Volume — Last 14 Days (TZS)') ?></div>
                <div class="card-body" style="height:260px">
                    <canvas id="dailyChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold bg-transparent"><?= t('Volume by Network — This Month') ?></div>
                <div class="card-body" style="height:260px">
                    <canvas id="networkChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom row: type breakdown + top agents -->
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold bg-transparent"><?= t('Volume by Type — This Month') ?></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th><?= t('Type') ?></th><th class="text-end"><?= t('Volume') ?></th><th class="text-end"><?= t('Count') ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($typeData as $row): ?>
                            <tr>
                                <td><?= safe_output(ucwords(str_replace('_',' ',$row['txn_type']))) ?></td>
                                <td class="text-end"><?= number_format((float)$row['vol']) ?></td>
                                <td class="text-end"><?= number_format((int)$row['cnt']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($typeData)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-2"><?= t('No data yet') ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold bg-transparent"><?= t('Top 5 Agents — This Month') ?></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>#</th><th><?= t('Agent') ?></th><th class="text-end"><?= t('Volume') ?></th><th class="text-end"><?= t('Txns') ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($topAgentsData as $i => $row): ?>
                            <tr>
                                <td><?= $i+1 ?></td>
                                <td><?= safe_output($row['agent_name']) ?></td>
                                <td class="text-end"><?= number_format((float)$row['vol']) ?></td>
                                <td class="text-end"><?= number_format((int)$row['cnt']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($topAgentsData)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-2"><?= t('No data yet') ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const dailyCtx = document.getElementById('dailyChart').getContext('2d');
    new Chart(dailyCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartDates) ?>,
            datasets: [{ label: '<?= t('Volume (TZS)') ?>', data: <?= json_encode($chartVols) ?>, backgroundColor: 'rgba(13,110,253,0.6)', borderColor: 'rgba(13,110,253,1)', borderWidth: 1 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } } }
    });

    const netCtx = document.getElementById('networkChart').getContext('2d');
    new Chart(netCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($networkLabels) ?>,
            datasets: [{ data: <?= json_encode($networkVols) ?>, backgroundColor: <?= json_encode($networkColors) ?> }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
})();
</script>
<?php includeFooter(); ?>
