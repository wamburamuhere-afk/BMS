<?php
// scope-audit: skip — one shift the user is authorised for.
ob_start();
$page_title = 'Shift Report';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_shifts');
includeHeader();

$id = intval($_GET['id'] ?? 0);
if (!$id) { echo '<div class="alert alert-danger m-4">' . t('Invalid shift ID.') . '</div>'; includeFooter(); exit; }

$shift = $pdo->prepare("
    SELECT s.*,
           t.till_number, a.agent_name, a.agent_code, a.region, a.district,
           n.network_name, n.network_code, n.color_hex,
           u.full_name AS teller_name, uc.full_name AS closed_by_name
    FROM mm_shifts s
    JOIN mm_tills t    ON t.till_id   = s.till_id
    JOIN mm_agents a   ON a.agent_id  = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    LEFT JOIN users u  ON u.user_id   = s.teller_user_id
    LEFT JOIN users uc ON uc.user_id  = s.closed_by
    WHERE s.shift_id = ?
");
$shift->execute([$id]);
$shift = $shift->fetch(PDO::FETCH_ASSOC);

if (!$shift) { echo '<div class="alert alert-warning m-4">' . t('Shift not found.') . '</div>'; includeFooter(); exit; }

// Transaction breakdown by type
$txnTypes = $pdo->prepare("
    SELECT txn_type,
           COUNT(*)                    AS txn_count,
           SUM(principal_amount)       AS total_amount,
           SUM(commission_earned)      AS total_commission,
           SUM(cash_effect)            AS total_cash,
           SUM(float_effect)           AS total_float
    FROM mm_transactions
    WHERE shift_id=? AND status='posted'
    GROUP BY txn_type
    ORDER BY txn_type
");
$txnTypes->execute([$id]);
$byType = $txnTypes->fetchAll(PDO::FETCH_ASSOC);

// Void list
$voids = $pdo->prepare("SELECT txn_code, txn_type, principal_amount, void_reason, voided_at FROM mm_transactions WHERE shift_id=? AND status='void' ORDER BY voided_at");
$voids->execute([$id]);
$voids = $voids->fetchAll(PDO::FETCH_ASSOC);

$txnLabels = [
    'cash_in'=>'Cash In','cash_out'=>'Cash Out','send'=>'Send Money','bill_pay'=>'Bill Payment',
    'airtime'=>'Airtime','bank_to_wallet'=>'Bank→Wallet','wallet_to_bank'=>'Wallet→Bank','international'=>'International'
];

$totalCount      = array_sum(array_column($byType, 'txn_count'));
$totalVolume     = array_sum(array_column($byType, 'total_amount'));
$totalCommission = array_sum(array_column($byType, 'total_commission'));

$page_title = 'Shift Report: ' . $shift['shift_code'];

logActivity($pdo, $_SESSION['user_id'], 'View MM Shift Report', 'Shift: ' . $shift['shift_code']);
?>

<div class="container mt-3 mb-5" style="max-width:820px" id="printArea">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-start mb-3 no-print">
        <div class="d-flex align-items-center gap-2">
            <a href="<?= getUrl('mm_shifts') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
            <h4 class="mb-0 fw-bold"><?= safe_output($shift['shift_code']) ?></h4>
            <span class="badge <?= $shift['status']==='open'?'bg-success':($shift['status']==='forced_close'?'bg-danger':'bg-secondary') ?>">
                <?= ucfirst(str_replace('_', ' ', safe_output($shift['status']))) ?>
            </span>
        </div>
        <button class="btn btn-sm btn-outline-primary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i><?= t('Print') ?>
        </button>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <!-- Print header -->
            <div class="text-center mb-3 print-only" style="display:none">
                <h5 class="mb-0"><?= t('TELLER SHIFT REPORT (Z-REPORT)') ?></h5>
                <div class="small"><?= date('d M Y H:i') ?></div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted small"><?= t('Shift Code') ?></td><td class="fw-bold"><code><?= safe_output($shift['shift_code']) ?></code></td></tr>
                        <tr><td class="text-muted small"><?= t('Network') ?></td><td><span class="d-inline-block me-1" style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($shift['color_hex'] ?: '#999') ?>"></span><?= safe_output($shift['network_name']) ?></td></tr>
                        <tr><td class="text-muted small"><?= t('Agent Outlet') ?></td><td><?= safe_output($shift['agent_name']) ?></td></tr>
                        <tr><td class="text-muted small"><?= t('Till No.') ?></td><td><code><?= safe_output($shift['till_number']) ?></code></td></tr>
                        <tr><td class="text-muted small"><?= t('Location') ?></td><td><?= safe_output(implode(', ', array_filter([$shift['region'], $shift['district']])) ?: '—') ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted small"><?= t('Teller') ?></td><td><?= safe_output($shift['teller_name'] ?: '—') ?></td></tr>
                        <tr><td class="text-muted small"><?= t('Opened') ?></td><td><?= date('d M Y H:i', strtotime($shift['opened_at'])) ?></td></tr>
                        <tr><td class="text-muted small"><?= t('Closed') ?></td><td><?= $shift['closed_at'] ? date('d M Y H:i', strtotime($shift['closed_at'])) : '<span class="badge bg-success">'.t('Still Open').'</span>' ?></td></tr>
                        <?php if ($shift['closed_by_name']): ?>
                        <tr><td class="text-muted small"><?= t('Closed By') ?></td><td><?= safe_output($shift['closed_by_name']) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Cash & Float Summary -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h6 class="fw-bold text-primary mb-3"><?= t('Cash & Float Reconciliation') ?></h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th></th><th class="text-end"><?= t('Cash (TZS)') ?></th><th class="text-end"><?= t('Float (TZS)') ?></th></tr>
                    </thead>
                    <tbody>
                        <tr><td class="text-muted small"><?= t('Opening Balance') ?></td><td class="text-end"><?= number_format((float)$shift['opening_cash']) ?></td><td class="text-end"><?= number_format((float)$shift['opening_float']) ?></td></tr>
                        <tr><td class="text-muted small"><?= t('Expected (after transactions)') ?></td><td class="text-end fw-semibold"><?= $shift['expected_cash'] !== null ? number_format((float)$shift['expected_cash']) : '—' ?></td><td class="text-end fw-semibold"><?= $shift['expected_float'] !== null ? number_format((float)$shift['expected_float']) : '—' ?></td></tr>
                        <tr><td class="text-muted small"><?= t('Counted at Close') ?></td><td class="text-end"><?= $shift['closing_cash'] !== null ? number_format((float)$shift['closing_cash']) : '—' ?></td><td class="text-end"><?= $shift['closing_float'] !== null ? number_format((float)$shift['closing_float']) : '—' ?></td></tr>
                        <tr class="table-warning">
                            <td class="fw-bold"><?= t('Variance') ?></td>
                            <td class="text-end fw-bold <?= ($shift['cash_variance'] ?? 0) < 0 ? 'text-danger' : (($shift['cash_variance'] ?? 0) > 0 ? 'text-success' : '') ?>">
                                <?= $shift['cash_variance'] !== null ? (($shift['cash_variance'] >= 0 ? '+' : '').number_format((float)$shift['cash_variance'])) : '—' ?>
                            </td>
                            <td class="text-end fw-bold <?= ($shift['float_variance'] ?? 0) < 0 ? 'text-danger' : (($shift['float_variance'] ?? 0) > 0 ? 'text-success' : '') ?>">
                                <?= $shift['float_variance'] !== null ? (($shift['float_variance'] >= 0 ? '+' : '').number_format((float)$shift['float_variance'])) : '—' ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php if ($shift['close_notes']): ?>
            <div class="mt-2 small text-muted"><?= t('Notes:') ?> <?= safe_output($shift['close_notes']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Transaction Breakdown -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h6 class="fw-bold text-primary mb-3"><?= t('Transaction Breakdown') ?> (<?= $totalCount ?> <?= t('transactions') ?>)</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?= t('Type') ?></th>
                            <th class="text-end"><?= t('Count') ?></th>
                            <th class="text-end"><?= t('Volume (TZS)') ?></th>
                            <th class="text-end"><?= t('Commission (TZS)') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byType as $row): ?>
                        <tr>
                            <td><?= t($txnLabels[$row['txn_type']] ?? $row['txn_type']) ?></td>
                            <td class="text-end"><?= (int)$row['txn_count'] ?></td>
                            <td class="text-end"><?= number_format((float)$row['total_amount']) ?></td>
                            <td class="text-end <?= $row['total_commission'] > 0 ? 'text-success' : 'text-muted' ?>"><?= $row['total_commission'] > 0 ? number_format((float)$row['total_commission']) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($byType)): ?>
                        <tr><td colspan="4" class="text-center text-muted"><?= t('No transactions in this shift.') ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <th><?= t('Total') ?></th>
                            <th class="text-end"><?= $totalCount ?></th>
                            <th class="text-end"><?= number_format($totalVolume) ?></th>
                            <th class="text-end text-success"><?= number_format($totalCommission) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Void List -->
    <?php if (!empty($voids)): ?>
    <div class="card border-warning border mb-3">
        <div class="card-body">
            <h6 class="fw-bold text-warning mb-3"><i class="bi bi-exclamation-triangle me-1"></i><?= t('Voided Transactions') ?></h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th><?= t('Code') ?></th><th><?= t('Type') ?></th><th class="text-end"><?= t('Amount') ?></th><th><?= t('Reason') ?></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($voids as $v): ?>
                        <tr>
                            <td><code><?= safe_output($v['txn_code']) ?></code></td>
                            <td><?= t($txnLabels[$v['txn_type']] ?? $v['txn_type']) ?></td>
                            <td class="text-end"><?= number_format((float)$v['principal_amount']) ?></td>
                            <td class="small text-muted"><?= safe_output($v['void_reason']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
@media print {
    .no-print { display:none!important; }
    .print-only { display:block!important; }
    body { font-size:12px; }
    .card { box-shadow:none!important; border:1px solid #dee2e6!important; }
}
</style>

<?php includeFooter(); ?>
