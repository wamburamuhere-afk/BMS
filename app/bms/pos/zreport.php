<?php
// scope-audit: skip — reads pos_sales/cash_register_shifts for one shift_id the
// caller is authorised for (own shift, or any shift with canEdit('pos')); no
// project/warehouse dimension at the shift level (see shift_history.php for the
// same reasoning), consistent with print_receipt.php's own skip marker.
/**
 * Z-Report — End-of-shift reconciliation (Phase 9, pos_upgrade_plan.md §7)
 * Printable summary of one closed (or still-active) cash-register shift:
 * cash reconciliation, sales by tender, refunds, voids, and GL posting health.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/pos_shift_reporting.php';

if (!isAuthenticated()) { die('Unauthorized'); }
if (!canView('pos'))    { die('Permission denied'); }

global $pdo;

$shift_id = isset($_GET['shift_id']) ? (int)$_GET['shift_id'] : 0;
if ($shift_id <= 0) { die('Invalid shift ID'); }

$stmt = $pdo->prepare("
    SELECT sh.*, u.username AS cashier_name, r.register_name, r.register_code
      FROM cash_register_shifts sh
      LEFT JOIN users u ON sh.user_id = u.user_id
      LEFT JOIN pos_registers r ON sh.register_id = r.register_id
     WHERE sh.shift_id = ?
");
$stmt->execute([$shift_id]);
$shift = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$shift) { die('Shift not found'); }

// A cashier may only view their own shift's Z-report; anyone with edit rights
// on POS (supervisor/admin) may view any shift — cash reconciliation is
// sensitive, not something every POS user should be able to browse for others.
if ((int)$shift['user_id'] !== (int)$_SESSION['user_id'] && !canEdit('pos')) {
    die('Access denied: you may only view your own shift reports.');
}

logActivity($pdo, $_SESSION['user_id'], 'Viewed Z-Report', "Viewed Z-Report for shift #{$shift['shift_code']}");

// Live totals — recomputed on demand for an ACTIVE shift (so a supervisor can
// check an in-progress till), and simply reflect the persisted values once the
// shift is closed (close_shift.php already wrote them via the same function).
$totals = $shift['status'] === 'active'
    ? posShiftTenderTotals($pdo, $shift_id)
    : [
        'total_sales' => (float)$shift['total_sales'], 'total_cash_sales' => (float)$shift['total_cash_sales'],
        'total_card_sales' => (float)$shift['total_card_sales'], 'total_mobile_sales' => (float)$shift['total_mobile_sales'],
        'total_credit_sales' => (float)$shift['total_credit_sales'], 'total_refunds' => (float)$shift['total_refunds'],
    ];
$other_tender = max(0.0, round($totals['total_sales'] - $totals['total_cash_sales'] - $totals['total_card_sales'] - $totals['total_mobile_sales'] - $totals['total_credit_sales'], 2));

$extras        = posShiftReportExtras($pdo, $shift_id);
$voids         = ['cnt' => $extras['void_count'], 'amt' => $extras['void_amount']];
$returnCount   = $extras['return_count'];
$unpostedCount = $extras['unposted_count'];

// Transaction listing for the shift (receipt-level detail).
$txStmt = $pdo->prepare("SELECT sale_id, receipt_number, sale_date, payment_method, grand_total, sale_status, is_return_sale
                            FROM pos_sales WHERE shift_id = ? ORDER BY sale_date");
$txStmt->execute([$shift_id]);
$transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);

$company_name = getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM');
$currency     = getSetting('currency', 'TZS');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Z-Report — <?= htmlspecialchars($shift['shift_code']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; max-width: 780px; margin: 0 auto; padding: 20px; font-size: 13px; color: #222; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .sub { color: #666; margin-bottom: 16px; }
        .section { border: 1px solid #ddd; border-radius: 6px; padding: 12px 16px; margin-bottom: 14px; }
        .section h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #555; margin: 0 0 10px; }
        .row { display: flex; justify-content: space-between; padding: 3px 0; }
        .row.total { font-weight: bold; border-top: 1px solid #ccc; margin-top: 6px; padding-top: 6px; }
        .diff-ok { color: #1a7f37; }
        .diff-bad { color: #c0392b; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { text-align: left; padding: 5px 6px; border-bottom: 1px solid #eee; }
        th { background: #f6f6f6; }
        .text-end { text-align: right; }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; }
        .badge-voided { background: #fde2e2; color: #a02020; }
        .badge-return { background: #fff3cd; color: #856404; }
        .badge-completed { background: #e6f4ea; color: #1a7f37; }
        .warn-box { background: #fff3cd; border: 1px solid #ffe08a; color: #6b4e00; padding: 8px 12px; border-radius: 6px; margin-bottom: 14px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:right; margin-bottom: 10px;">
        <button onclick="window.print()">Print</button>
        <button onclick="window.close()">Close</button>
    </div>

    <h1><?= htmlspecialchars($company_name) ?> — Z-Report</h1>
    <div class="sub">
        Shift <?= htmlspecialchars($shift['shift_code']) ?> ·
        Register: <?= htmlspecialchars($shift['register_name'] ?: 'N/A') ?> (<?= htmlspecialchars($shift['register_code'] ?: '—') ?>) ·
        Cashier: <?= htmlspecialchars($shift['cashier_name'] ?: 'N/A') ?><br>
        Opened: <?= date('d/m/Y H:i', strtotime($shift['start_time'])) ?>
        <?php if ($shift['end_time']): ?> · Closed: <?= date('d/m/Y H:i', strtotime($shift['end_time'])) ?><?php endif; ?>
        · Status: <strong><?= htmlspecialchars(ucfirst($shift['status'])) ?></strong>
    </div>

    <?php if ($unpostedCount > 0): ?>
    <div class="warn-box">
        <strong>⚠ Accounting warning:</strong> <?= $unpostedCount ?> sale(s) in this shift did not post to the
        General Ledger (best-effort posting — check the chart-of-accounts configuration). These sales are still
        valid and included in the totals below; they are simply not yet reflected on the Trial Balance/Balance Sheet.
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>Cash Reconciliation</h2>
        <div class="row"><span>Starting Cash</span><span><?= $currency ?> <?= number_format((float)$shift['starting_cash'], 2) ?></span></div>
        <div class="row"><span>Cash In (manual)</span><span><?= $currency ?> <?= number_format((float)$shift['cash_in'], 2) ?></span></div>
        <div class="row"><span>Cash Out (manual)</span><span><?= $currency ?> <?= number_format((float)$shift['cash_out'], 2) ?></span></div>
        <div class="row"><span>Cash Sales</span><span><?= $currency ?> <?= number_format($totals['total_cash_sales'], 2) ?></span></div>
        <div class="row"><span>Cash Refunds</span><span>-<?= $currency ?> <?= number_format($totals['total_refunds'], 2) ?></span></div>
        <div class="row total"><span>Expected Cash</span><span><?= $currency ?> <?= number_format((float)$shift['expected_cash'], 2) ?></span></div>
        <?php if ($shift['status'] !== 'active'): ?>
        <div class="row"><span>Actual Counted Cash</span><span><?= $currency ?> <?= number_format((float)$shift['ending_cash'], 2) ?></span></div>
        <?php $diff = (float)$shift['cash_difference']; ?>
        <div class="row total"><span>Difference</span><span class="<?= abs($diff) < 0.01 ? 'diff-ok' : 'diff-bad' ?>"><?= $currency ?> <?= number_format($diff, 2) ?></span></div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Sales by Tender</h2>
        <div class="row"><span>Cash</span><span><?= $currency ?> <?= number_format($totals['total_cash_sales'], 2) ?></span></div>
        <div class="row"><span>Card</span><span><?= $currency ?> <?= number_format($totals['total_card_sales'], 2) ?></span></div>
        <div class="row"><span>Mobile Money</span><span><?= $currency ?> <?= number_format($totals['total_mobile_sales'], 2) ?></span></div>
        <div class="row"><span>Credit (on account)</span><span><?= $currency ?> <?= number_format($totals['total_credit_sales'], 2) ?></span></div>
        <?php if ($other_tender > 0.009): ?>
        <div class="row"><span>Other (bank transfer / voucher / loyalty)</span><span><?= $currency ?> <?= number_format($other_tender, 2) ?></span></div>
        <?php endif; ?>
        <div class="row total"><span>Gross Sales</span><span><?= $currency ?> <?= number_format($totals['total_sales'], 2) ?></span></div>
        <div class="row"><span>Refunds (<?= $returnCount ?>)</span><span>-<?= $currency ?> <?= number_format($totals['total_refunds'], 2) ?></span></div>
        <div class="row"><span>Voided Sales (<?= (int)$voids['cnt'] ?>, excluded above)</span><span><?= $currency ?> <?= number_format((float)$voids['amt'], 2) ?></span></div>
        <div class="row total"><span>Net Sales</span><span><?= $currency ?> <?= number_format($totals['total_sales'] - $totals['total_refunds'], 2) ?></span></div>
    </div>

    <div class="section">
        <h2>Transactions (<?= count($transactions) ?>)</h2>
        <table>
            <thead><tr><th>Receipt #</th><th>Time</th><th>Type</th><th>Method</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['receipt_number']) ?></td>
                    <td><?= date('H:i', strtotime($t['sale_date'])) ?></td>
                    <td><?= $t['is_return_sale'] ? 'Return' : 'Sale' ?></td>
                    <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $t['payment_method']))) ?></td>
                    <td class="text-end"><?= number_format((float)$t['grand_total'], 2) ?></td>
                    <td><span class="badge badge-<?= $t['sale_status'] === 'voided' ? 'voided' : ($t['is_return_sale'] ? 'return' : 'completed') ?>"><?= htmlspecialchars(ucfirst($t['sale_status'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$transactions): ?>
                <tr><td colspan="6" style="text-align:center; color:#888;">No transactions in this shift</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($shift['notes'])): ?>
    <div class="section"><h2>Notes</h2><div><?= nl2br(htmlspecialchars($shift['notes'])) ?></div></div>
    <?php endif; ?>
</body>
</html>
