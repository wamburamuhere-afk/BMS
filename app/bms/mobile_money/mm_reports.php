<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_reports');

$can_export = canExport('mm_reports');

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

// Shared filter params
$fromDate  = trim($_GET['from']    ?? $monthStart);
$toDate    = trim($_GET['to']      ?? $today);
$networkId = intval($_GET['network_id'] ?? 0);
$agentId   = intval($_GET['agent_id']   ?? 0);
$reportTab = trim($_GET['report']  ?? 'txn_summary');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = $monthStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = $today;

// Build WHERE fragments
$txnWhere  = "WHERE t.txn_date BETWEEN ? AND ? AND t.status='posted'";
$txnParams = [$fromDate, $toDate];
if ($networkId) { $txnWhere .= " AND t.network_id=?"; $txnParams[] = $networkId; }
if ($agentId)   { $txnWhere .= " AND t.agent_id=?";   $txnParams[] = $agentId; }

// --- Report data ---
$reportData = [];

if ($reportTab === 'txn_summary') {
    $stmt = $pdo->prepare("
        SELECT txn_type,
               COUNT(*) AS cnt,
               COALESCE(SUM(principal_amount),0) AS volume,
               COALESCE(SUM(customer_fee),0) AS fees,
               COALESCE(SUM(commission_earned),0) AS commission
        FROM mm_transactions t
        $txnWhere
        GROUP BY txn_type ORDER BY volume DESC
    ");
    $stmt->execute($txnParams);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($reportTab === 'float_position') {
    $stmt = $pdo->prepare("
        SELECT a.agent_name, t.till_number, n.network_name, n.color_hex,
               s.float_balance, s.cash_balance, s.snapshot_at
        FROM mm_tills t
        JOIN mm_agents a   ON a.agent_id   = t.agent_id
        JOIN mm_networks n ON n.network_id = t.network_id
        LEFT JOIN mm_float_snapshots s ON s.till_id = t.till_id
            AND s.snapshot_at = (SELECT MAX(s2.snapshot_at) FROM mm_float_snapshots s2 WHERE s2.till_id=t.till_id)
        WHERE t.status='active'
        " . ($networkId ? " AND t.network_id=$networkId" : "") . "
        " . ($agentId   ? " AND t.agent_id=$agentId"     : "") . "
        ORDER BY a.agent_name, t.till_number
    ");
    $stmt->execute();
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($reportTab === 'commission') {
    $stmt = $pdo->prepare("
        SELECT n.network_name, n.color_hex,
               COALESCE(SUM(t.commission_earned),0) AS earned,
               COALESCE((SELECT SUM(cr.amount_received) FROM mm_commissions_received cr
                          WHERE cr.network_id=n.network_id AND cr.status='posted'
                            AND cr.period_from >= ? AND cr.period_to <= ?),0) AS received
        FROM mm_networks n
        LEFT JOIN mm_transactions t ON t.network_id=n.network_id AND t.status='posted'
            AND t.txn_date BETWEEN ? AND ?
        " . ($networkId ? " WHERE n.network_id=$networkId" : "") . "
        GROUP BY n.network_id ORDER BY earned DESC
    ");
    $stmt->execute([$fromDate, $toDate, $fromDate, $toDate]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($reportTab === 'agent_perf') {
    $stmt = $pdo->prepare("
        SELECT a.agent_code, a.agent_name, a.region,
               COUNT(t.mm_txn_id) AS txn_count,
               COALESCE(SUM(t.principal_amount),0) AS volume,
               COALESCE(SUM(t.commission_earned),0) AS commission,
               COUNT(DISTINCT t.till_id) AS tills_used
        FROM mm_agents a
        LEFT JOIN mm_transactions t ON t.agent_id=a.agent_id AND t.status='posted'
            AND t.txn_date BETWEEN ? AND ?
        WHERE a.status='active'
        " . ($agentId ? " AND a.agent_id=$agentId" : "") . "
        GROUP BY a.agent_id ORDER BY volume DESC
    ");
    $stmt->execute([$fromDate, $toDate]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($reportTab === 'shift_summary') {
    $stmt = $pdo->prepare("
        SELECT s.shift_code, s.opened_at, s.closed_at,
               t.till_number, a.agent_name,
               u.name AS teller_name,
               s.opening_cash, s.closing_cash, s.cash_variance,
               s.opening_float, s.closing_float, s.float_variance,
               s.status
        FROM mm_shifts s
        JOIN mm_tills t    ON t.till_id   = s.till_id
        JOIN mm_agents a   ON a.agent_id  = t.agent_id
        LEFT JOIN users u  ON u.user_id   = s.teller_user_id
        WHERE DATE(s.opened_at) BETWEEN ? AND ?
        " . ($agentId ? " AND t.agent_id=$agentId" : "") . "
        ORDER BY s.opened_at DESC
    ");
    $stmt->execute([$fromDate, $toDate]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($reportTab === 'void_suspicious') {
    $stmt = $pdo->prepare("
        SELECT t.txn_code, t.txn_date, t.txn_type, t.principal_amount, t.status,
               t.suspicious_flag, t.void_reason,
               ti.till_number, a.agent_name, n.network_name, n.color_hex,
               u.name AS teller_name, v.name AS voided_by_name
        FROM mm_transactions t
        JOIN mm_tills ti    ON ti.till_id   = t.till_id
        JOIN mm_agents a    ON a.agent_id   = t.agent_id
        JOIN mm_networks n  ON n.network_id = t.network_id
        LEFT JOIN users u   ON u.user_id    = t.teller_user_id
        LEFT JOIN users v   ON v.user_id    = t.voided_by
        WHERE t.txn_date BETWEEN ? AND ?
          AND (t.status='void' OR t.suspicious_flag=1)
        " . ($agentId ? " AND t.agent_id=$agentId" : "") . "
        ORDER BY t.txn_date DESC
    ");
    $stmt->execute([$fromDate, $toDate]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($reportTab === 'network_comparison') {
    $stmt = $pdo->prepare("
        SELECT n.network_name, n.color_hex,
               COUNT(t.mm_txn_id) AS txn_count,
               COALESCE(SUM(t.principal_amount),0) AS volume,
               COALESCE(SUM(t.commission_earned),0) AS commission,
               COUNT(DISTINCT t.agent_id) AS agents
        FROM mm_networks n
        LEFT JOIN mm_transactions t ON t.network_id=n.network_id AND t.status='posted'
            AND t.txn_date BETWEEN ? AND ?
        WHERE n.status='active'
        GROUP BY n.network_id ORDER BY volume DESC
    ");
    $stmt->execute([$fromDate, $toDate]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Filter dropdowns
$networks = $pdo->query("SELECT network_id, network_name FROM mm_networks WHERE status='active' ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$agents   = $pdo->query("SELECT agent_id, agent_name FROM mm_agents WHERE status='active' ORDER BY agent_name")->fetchAll(PDO::FETCH_ASSOC);

$reportTabs = [
    'txn_summary'       => t('Transaction Summary'),
    'float_position'    => t('Float Position'),
    'commission'        => t('Commission'),
    'agent_perf'        => t('Agent Performance'),
    'shift_summary'     => t('Shift Summary'),
    'void_suspicious'   => t('Void & Suspicious'),
    'network_comparison'=> t('Network Comparison'),
];

includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View MM Reports', "Viewed MM report: $reportTab");
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-bar-chart text-warning fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Mobile Money Reports') ?></h4>
        <?php if ($can_export): ?>
        <button class="btn btn-sm btn-outline-success ms-auto" id="exportBtn">
            <i class="bi bi-file-earmark-excel me-1"></i><?= t('Export') ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="card border-0 shadow-sm mb-4 p-3">
        <input type="hidden" name="report" value="<?= safe_output($reportTab) ?>">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small"><?= t('From') ?></label>
                <input type="date" class="form-control form-control-sm" name="from" value="<?= $fromDate ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small"><?= t('To') ?></label>
                <input type="date" class="form-control form-control-sm" name="to" value="<?= $toDate ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small"><?= t('Network') ?></label>
                <select class="form-select form-select-sm" name="network_id">
                    <option value=""><?= t('All Networks') ?></option>
                    <?php foreach ($networks as $n): ?>
                    <option value="<?= $n['network_id'] ?>" <?= $networkId === (int)$n['network_id'] ? 'selected' : '' ?>><?= safe_output($n['network_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small"><?= t('Agent') ?></label>
                <select class="form-select form-select-sm" name="agent_id">
                    <option value=""><?= t('All Agents') ?></option>
                    <?php foreach ($agents as $ag): ?>
                    <option value="<?= $ag['agent_id'] ?>" <?= $agentId === (int)$ag['agent_id'] ? 'selected' : '' ?>><?= safe_output($ag['agent_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="bi bi-search me-1"></i><?= t('Run') ?></button>
                <a href="<?= getUrl('mobile_money/mm_reports') ?>" class="btn btn-sm btn-outline-secondary"><?= t('Reset') ?></a>
            </div>
        </div>
    </form>

    <!-- Report tabs -->
    <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" style="white-space:nowrap">
        <?php foreach ($reportTabs as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link <?= $reportTab === $key ? 'active' : '' ?>"
               href="<?= getUrl('mobile_money/mm_reports') ?>?report=<?= $key ?>&from=<?= $fromDate ?>&to=<?= $toDate ?><?= $networkId ? '&network_id='.$networkId : '' ?><?= $agentId ? '&agent_id='.$agentId : '' ?>">
                <?= $label ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- Report table -->
    <div class="table-responsive">
        <table id="reportTable" class="table table-hover align-middle w-100">
            <?php if ($reportTab === 'txn_summary'): ?>
            <thead class="table-dark"><tr><th><?= t('Type') ?></th><th class="text-end"><?= t('Count') ?></th><th class="text-end"><?= t('Volume (TZS)') ?></th><th class="text-end"><?= t('Fees (TZS)') ?></th><th class="text-end"><?= t('Commission (TZS)') ?></th></tr></thead>
            <tbody>
                <?php $totV=0; $totF=0; $totC=0; $totN=0;
                      foreach ($reportData as $r): $totV+=$r['volume']; $totF+=$r['fees']; $totC+=$r['commission']; $totN+=$r['cnt']; ?>
                <tr>
                    <td><?= safe_output(ucwords(str_replace('_',' ',$r['txn_type']))) ?></td>
                    <td class="text-end"><?= number_format((int)$r['cnt']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['volume']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['fees']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['commission']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-dark fw-bold"><td><?= t('Total') ?></td><td class="text-end"><?= number_format($totN) ?></td><td class="text-end"><?= number_format($totV) ?></td><td class="text-end"><?= number_format($totF) ?></td><td class="text-end"><?= number_format($totC) ?></td></tr>
            </tbody>

            <?php elseif ($reportTab === 'float_position'): ?>
            <thead class="table-dark"><tr><th><?= t('Agent') ?></th><th><?= t('Till') ?></th><th><?= t('Network') ?></th><th class="text-end"><?= t('Float Balance') ?></th><th class="text-end"><?= t('Cash Balance') ?></th><th><?= t('Snapshot At') ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r): ?>
                <tr>
                    <td><?= safe_output($r['agent_name']) ?></td>
                    <td><?= safe_output($r['till_number']) ?></td>
                    <td><span class="badge rounded-pill" style="background:<?= safe_output($r['color_hex'] ?: '#6c757d') ?>"><?= safe_output($r['network_name']) ?></span></td>
                    <td class="text-end"><?= $r['float_balance'] !== null ? number_format((float)$r['float_balance']) : '—' ?></td>
                    <td class="text-end"><?= $r['cash_balance'] !== null ? number_format((float)$r['cash_balance']) : '—' ?></td>
                    <td><?= $r['snapshot_at'] ? safe_output($r['snapshot_at']) : '<span class="text-muted">No snapshot</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <?php elseif ($reportTab === 'commission'): ?>
            <thead class="table-dark"><tr><th><?= t('Network') ?></th><th class="text-end"><?= t('Earned (TZS)') ?></th><th class="text-end"><?= t('Received (TZS)') ?></th><th class="text-end"><?= t('Outstanding (TZS)') ?></th></tr></thead>
            <tbody>
                <?php $totE=0; $totR=0;
                      foreach ($reportData as $r): $totE+=$r['earned']; $totR+=$r['received'];
                          $outstanding = max(0, (float)$r['earned'] - (float)$r['received']); ?>
                <tr>
                    <td><span class="badge rounded-pill" style="background:<?= safe_output($r['color_hex'] ?: '#6c757d') ?>"><?= safe_output($r['network_name']) ?></span></td>
                    <td class="text-end"><?= number_format((float)$r['earned']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['received']) ?></td>
                    <td class="text-end <?= $outstanding > 0 ? 'text-warning fw-bold' : '' ?>"><?= number_format($outstanding) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-dark fw-bold"><td><?= t('Total') ?></td><td class="text-end"><?= number_format($totE) ?></td><td class="text-end"><?= number_format($totR) ?></td><td class="text-end"><?= number_format(max(0,$totE-$totR)) ?></td></tr>
            </tbody>

            <?php elseif ($reportTab === 'agent_perf'): ?>
            <thead class="table-dark"><tr><th><?= t('Code') ?></th><th><?= t('Agent') ?></th><th><?= t('Region') ?></th><th class="text-end"><?= t('Txns') ?></th><th class="text-end"><?= t('Volume (TZS)') ?></th><th class="text-end"><?= t('Commission (TZS)') ?></th><th class="text-end"><?= t('Tills') ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r): ?>
                <tr>
                    <td><code><?= safe_output($r['agent_code']) ?></code></td>
                    <td><?= safe_output($r['agent_name']) ?></td>
                    <td><?= safe_output($r['region'] ?? '—') ?></td>
                    <td class="text-end"><?= number_format((int)$r['txn_count']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['volume']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['commission']) ?></td>
                    <td class="text-end"><?= $r['tills_used'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <?php elseif ($reportTab === 'shift_summary'): ?>
            <thead class="table-dark"><tr><th><?= t('Code') ?></th><th><?= t('Till') ?></th><th><?= t('Agent') ?></th><th><?= t('Teller') ?></th><th><?= t('Opened') ?></th><th><?= t('Closed') ?></th><th class="text-end"><?= t('Cash Var') ?></th><th class="text-end"><?= t('Float Var') ?></th><th><?= t('Status') ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r): ?>
                <tr>
                    <td><code><?= safe_output($r['shift_code']) ?></code></td>
                    <td><?= safe_output($r['till_number']) ?></td>
                    <td><?= safe_output($r['agent_name']) ?></td>
                    <td><?= safe_output($r['teller_name'] ?? '—') ?></td>
                    <td><?= safe_output(substr($r['opened_at'],0,16)) ?></td>
                    <td><?= $r['closed_at'] ? safe_output(substr($r['closed_at'],0,16)) : '<span class="badge bg-warning">Open</span>' ?></td>
                    <td class="text-end <?= ($r['cash_variance'] ?? 0) != 0 ? 'text-danger' : '' ?>"><?= $r['cash_variance'] !== null ? number_format((float)$r['cash_variance']) : '—' ?></td>
                    <td class="text-end <?= ($r['float_variance'] ?? 0) != 0 ? 'text-danger' : '' ?>"><?= $r['float_variance'] !== null ? number_format((float)$r['float_variance']) : '—' ?></td>
                    <td><span class="badge bg-<?= $r['status']==='closed'?'success':($r['status']==='forced_close'?'warning':'primary') ?>"><?= safe_output(ucfirst($r['status'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <?php elseif ($reportTab === 'void_suspicious'): ?>
            <thead class="table-dark"><tr><th><?= t('Code') ?></th><th><?= t('Date') ?></th><th><?= t('Type') ?></th><th><?= t('Network') ?></th><th><?= t('Agent') ?></th><th class="text-end"><?= t('Amount') ?></th><th><?= t('Status') ?></th><th><?= t('Reason') ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r): ?>
                <tr>
                    <td><code><?= safe_output($r['txn_code']) ?></code></td>
                    <td><?= safe_output($r['txn_date']) ?></td>
                    <td><?= safe_output(ucwords(str_replace('_',' ',$r['txn_type']))) ?></td>
                    <td><span class="badge rounded-pill" style="background:<?= safe_output($r['color_hex'] ?: '#6c757d') ?>"><?= safe_output($r['network_name']) ?></span></td>
                    <td><?= safe_output($r['agent_name']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['principal_amount']) ?></td>
                    <td>
                        <?php if ($r['suspicious_flag']): ?><span class="badge bg-warning me-1"><?= t('Suspicious') ?></span><?php endif; ?>
                        <?php if ($r['status']==='void'): ?><span class="badge bg-danger"><?= t('Void') ?></span><?php endif; ?>
                    </td>
                    <td><?= safe_output($r['void_reason'] ?? '—', '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <?php elseif ($reportTab === 'network_comparison'): ?>
            <thead class="table-dark"><tr><th><?= t('Network') ?></th><th class="text-end"><?= t('Transactions') ?></th><th class="text-end"><?= t('Volume (TZS)') ?></th><th class="text-end"><?= t('Commission (TZS)') ?></th><th class="text-end"><?= t('Active Agents') ?></th></tr></thead>
            <tbody>
                <?php $totV=0; $totC=0; $totN=0;
                      foreach ($reportData as $r): $totV+=$r['volume']; $totC+=$r['commission']; $totN+=$r['txn_count']; ?>
                <tr>
                    <td><span class="badge rounded-pill" style="background:<?= safe_output($r['color_hex'] ?: '#6c757d') ?>"><?= safe_output($r['network_name']) ?></span></td>
                    <td class="text-end"><?= number_format((int)$r['txn_count']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['volume']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['commission']) ?></td>
                    <td class="text-end"><?= $r['agents'] ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-dark fw-bold"><td><?= t('Total') ?></td><td class="text-end"><?= number_format($totN) ?></td><td class="text-end"><?= number_format($totV) ?></td><td class="text-end"><?= number_format($totC) ?></td><td></td></tr>
            </tbody>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#reportTable')) {
        const dt = $('#reportTable').DataTable({
            responsive: false, scrollX: true, pageLength: 50, order: [],
            dom: 'rtipB', buttons: [{ extend: 'excelHtml5', className: 'd-none', filename: 'mm-report', exportOptions: { columns: ':not(:last-child)' } }]
        });
        $('#exportBtn')?.on('click', function () { dt.button('.buttons-excel').trigger(); });
    }
});
</script>
<?php includeFooter(); ?>
