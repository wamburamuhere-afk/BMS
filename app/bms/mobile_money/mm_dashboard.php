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
           COALESCE(SUM(commission_earned),0) AS commission,
           COALESCE(SUM(CASE WHEN status='void' THEN 1 ELSE 0 END),0) AS void_count
    FROM mm_transactions
    WHERE txn_date=? AND status IN ('posted','void')
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

// Previous month for trend
$monthStats->execute([$prevMonthStart, $prevMonthEnd]);
$prev_month_kpi = $monthStats->fetch(PDO::FETCH_ASSOC);

$vol_trend  = $prev_month_kpi['volume']     > 0 ? round((($month_kpi['volume'] - $prev_month_kpi['volume']) / $prev_month_kpi['volume']) * 100, 1) : null;
$comm_trend = $prev_month_kpi['commission'] > 0 ? round((($month_kpi['commission'] - $prev_month_kpi['commission']) / $prev_month_kpi['commission']) * 100, 1) : null;

// --- Active tills / agents ---
$tillCount  = (int)$pdo->query("SELECT COUNT(*) FROM mm_tills WHERE status='active'")->fetchColumn();
$agentCount = (int)$pdo->query("SELECT COUNT(*) FROM mm_agents WHERE status='active'")->fetchColumn();
$openShifts = (int)$pdo->query("SELECT COUNT(*) FROM mm_shifts WHERE status='open'")->fetchColumn();

// --- Daily volume last 14 days (for chart) ---
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

// --- Open recons ---
$openRecons = (int)$pdo->query("SELECT COUNT(*) FROM mm_reconciliations WHERE status='open'")->fetchColumn();

// Build chart data JSON
$chartDates  = [];
$chartVols   = [];
foreach ($dailyData as $d) { $chartDates[] = $d['txn_date']; $chartVols[] = (float)$d['vol']; }

$typeLabels = []; $typeVols = [];
foreach ($typeData as $d) { $typeLabels[] = ucwords(str_replace('_',' ',$d['txn_type'])); $typeVols[] = (float)$d['vol']; }

$networkLabels = []; $networkVols = []; $networkColors = [];
foreach ($networkData as $d) { $networkLabels[] = $d['network_name']; $networkVols[] = (float)$d['vol']; $networkColors[] = $d['color_hex'] ?: '#6c757d'; }

includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View MM Dashboard', 'Viewed Mobile Money Dashboard');

function trendBadge($pct): string {
    if ($pct === null) return '';
    $cls  = $pct >= 0 ? 'success' : 'danger';
    $icon = $pct >= 0 ? 'bi-arrow-up' : 'bi-arrow-down';
    return "<span class='badge bg-{$cls} ms-1'><i class='bi {$icon}'></i> " . abs($pct) . "%</span>";
}
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-speedometer2 text-primary fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Mobile Money Dashboard') ?></h4>
        <span class="text-muted small ms-2"><?= t('Today:') ?> <?= $today ?></span>
    </div>

    <!-- KPI row 1 — Today -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted mb-1"><?= t("Today's Transactions") ?></div>
                <div class="fs-3 fw-bold text-primary"><?= number_format((int)$today_kpi['txn_count']) ?></div>
                <div class="small text-muted"><?= t('Volume:') ?> <strong><?= number_format((float)$today_kpi['volume']) ?></strong> TZS</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted mb-1"><?= t("Today's Commission") ?></div>
                <div class="fs-3 fw-bold text-success"><?= number_format((float)$today_kpi['commission']) ?></div>
                <div class="small text-muted">TZS</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted mb-1"><?= t('Open Shifts') ?></div>
                <div class="fs-3 fw-bold text-<?= $openShifts > 0 ? 'warning' : 'secondary' ?>"><?= $openShifts ?></div>
                <div class="small text-muted"><?= $tillCount ?> <?= t('active tills') ?>, <?= $agentCount ?> <?= t('agents') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted mb-1"><?= t('Open Reconciliations') ?></div>
                <div class="fs-3 fw-bold text-<?= $openRecons > 0 ? 'danger' : 'secondary' ?>"><?= $openRecons ?></div>
                <div class="small text-muted"><?= $today_kpi['void_count'] > 0 ? $today_kpi['void_count'] . ' ' . t('voided today') : t('No voids today') ?></div>
            </div>
        </div>
    </div>

    <!-- KPI row 2 — This month -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted mb-1"><?= t('Month Transactions') ?></div>
                <div class="fs-4 fw-bold text-primary"><?= number_format((int)$month_kpi['txn_count']) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted mb-1"><?= t('Month Volume (TZS)') ?></div>
                <div class="fs-4 fw-bold text-info"><?= number_format((float)$month_kpi['volume']) ?> <?= trendBadge($vol_trend) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted mb-1"><?= t('Month Commission (TZS)') ?></div>
                <div class="fs-4 fw-bold text-success"><?= number_format((float)$month_kpi['commission']) ?> <?= trendBadge($comm_trend) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted mb-1"><?= t('Prev Month Volume (TZS)') ?></div>
                <div class="fs-4 fw-bold text-secondary"><?= number_format((float)$prev_month_kpi['volume']) ?></div>
            </div>
        </div>
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
    // Daily volume bar chart
    const dailyCtx = document.getElementById('dailyChart').getContext('2d');
    new Chart(dailyCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartDates) ?>,
            datasets: [{ label: '<?= t('Volume (TZS)') ?>', data: <?= json_encode($chartVols) ?>, backgroundColor: 'rgba(13,110,253,0.6)', borderColor: 'rgba(13,110,253,1)', borderWidth: 1 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } } }
    });

    // Network donut chart
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
