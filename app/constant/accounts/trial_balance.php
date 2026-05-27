<?php
/**
 * Trial Balance Report
 * Ensures Debits equal Credits
 * Premium UI/UX Design
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('financial_reports');
}

$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');

try {
    $sql = "
        SELECT 
            a.account_id,
            a.account_code,
            a.account_name,
            at.type_name as account_type,
            SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END) as total_debit_trans,
            SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END) as total_credit_trans
        FROM accounts a
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        LEFT JOIN journal_entry_items jei ON a.account_id = jei.account_id
        LEFT JOIN journal_entries je ON jei.entry_id = je.entry_id
        WHERE (je.entry_date <= ? OR je.entry_date IS NULL)
        AND (je.status = 'posted' OR je.status IS NULL)
        AND a.status = 'active'
        GROUP BY a.account_id, a.account_name, a.account_code, at.type_name
        ORDER BY a.account_code ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$as_of_date]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $trial_balance_data = [];
    $total_debits  = 0;
    $total_credits = 0;

    foreach ($accounts as $acc) {
        $debit_sum  = floatval($acc['total_debit_trans']);
        $credit_sum = floatval($acc['total_credit_trans']);
        $net = $debit_sum - $credit_sum;

        if (abs($net) < 0.001) continue;

        $row = [
            'code'   => $acc['account_code'],
            'name'   => $acc['account_name'],
            'type'   => $acc['account_type'],
            'debit'  => 0,
            'credit' => 0
        ];

        if ($net > 0) {
            $row['debit'] = $net;
            $total_debits += $net;
        } else {
            $row['credit'] = abs($net);
            $total_credits += abs($net);
        }

        $trial_balance_data[] = $row;
    }

    $is_balanced = (abs($total_debits - $total_credits) < 0.01);
    $difference  = $total_debits - $total_credits;

} catch (Exception $e) {
    $error_message = $e->getMessage();
}
?>

<style>
@media print {
    .d-print-none, .btn, .breadcrumb, .navbar, .sidebar, .filter-card, .sticky-top { display: none !important; }
    .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
    .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
    .card-header { background-color: #f8f9fa !important; border-bottom: 2px solid #333 !important; padding: 10px 15px !important; -webkit-print-color-adjust: exact; }
    body { background: white !important; font-size: 12px !important; }
    .table thead th { background-color: #333 !important; color: white !important; padding: 10px !important; -webkit-print-color-adjust: exact; }
    .print-header { display: block !important; text-align: center; margin-bottom: 12px; padding-bottom: 8px; }
}
/* Canonical I/E Print margin — see i_e_print.md §1 */
@page { margin: 10mm 8mm 16mm 8mm; }
</style>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <div class="mt-2 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 3px 0; font-size: 16pt; letter-spacing: 2px;">TRIAL BALANCE REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Verification report ensuring all debits and credits are accurately balanced across accounts.</p>
            <p style="color: #444; margin: 3px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">As of: <?= date('d M Y', strtotime($as_of_date)) ?></p>
            <p style="color: #444; margin: 3px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 8px; margin-bottom: 10px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-2">
        <div class="row g-2">
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 6px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Debits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_debits) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 6px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Credits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_credits) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 6px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Status</p>
                    <h4 style="color: <?= $is_balanced ? '#2ecc71' : '#e74c3c' ?>; font-weight: 800; margin: 0; font-size: 14pt;"><?= $is_balanced ? 'BALANCED' : 'UNBALANCED' ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h1 class="h3 mb-0 text-primary fw-bold" style="text-transform:uppercase;"><i class="bi bi-calculator me-2"></i>TRIAL BALANCE</h1>
            <p class="text-muted mb-0 font-monospace small">Verification of financial position as of <?= date('F j, Y', strtotime($as_of_date)) ?></p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4" onclick="window.print()" style="border-radius:8px;font-weight:700;">
                <i class="bi bi-printer me-2"></i> PRINT REPORT
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 d-print-none sticky-top" style="z-index:1020;top:10px;">
        <div class="card-body py-3">
            <form method="GET" class="row g-3 align-items-center">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-uppercase text-muted mb-1">As Of Date</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-calendar3"></i></span>
                        <input type="date" name="as_of_date" class="form-control" value="<?= $as_of_date ?>">
                    </div>
                </div>
                <div class="col-md-2 mt-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-filter me-1"></i> Update Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Total Debits</div>
                    <h3 class="fw-bold text-dark mb-0"><?= format_currency($total_debits) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Total Credits</div>
                    <h3 class="fw-bold text-dark mb-0"><?= format_currency($total_credits) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 <?= $is_balanced ? 'border-success' : 'border-danger' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small text-uppercase fw-bold mb-1">Status</div>
                            <h3 class="fw-bold <?= $is_balanced ? 'text-success' : 'text-danger' ?> mb-0">
                                <?= $is_balanced ? 'Balanced' : 'Unbalanced' ?>
                            </h3>
                        </div>
                        <?php if (!$is_balanced): ?>
                            <small class="text-danger fw-bold">Diff: <?= format_currency(abs($difference)) ?></small>
                        <?php else: ?>
                            <i class="bi bi-check-circle-fill text-success fs-1 opacity-25"></i>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Table -->
    <div class="card border-0 shadow-lg" id="report-content">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-uppercase ls-1">Account Balances</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th class="ps-4 py-3" style="width:15%">Code</th>
                            <th class="py-3" style="width:45%">Account Name</th>
                            <th class="text-end py-3" style="width:20%">Debit</th>
                            <th class="text-end pe-4 py-3" style="width:20%">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($error_message)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-danger"><?= htmlspecialchars($error_message) ?></td></tr>
                        <?php elseif (empty($trial_balance_data)): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">No records found for this date.</td></tr>
                        <?php else: ?>
                            <?php foreach ($trial_balance_data as $row): ?>
                            <tr>
                                <td class="ps-4 fw-mono text-muted"><?= htmlspecialchars($row['code']) ?></td>
                                <td class="fw-semibold">
                                    <?= htmlspecialchars($row['name']) ?>
                                    <span class="badge bg-light text-secondary border ms-2 small fw-normal"><?= htmlspecialchars($row['type']) ?></span>
                                </td>
                                <td class="text-end font-monospace"><?= $row['debit']  > 0 ? format_currency($row['debit'])  : '-' ?></td>
                                <td class="text-end pe-4 font-monospace"><?= $row['credit'] > 0 ? format_currency($row['credit']) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="bg-light fw-bold">
                        <tr class="border-top border-2 border-dark">
                            <td colspan="2" class="ps-4 py-3 text-uppercase">Total</td>
                            <td class="text-end py-3 text-primary"><?= format_currency($total_debits) ?></td>
                            <td class="text-end pe-4 py-3 text-primary"><?= format_currency($total_credits) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="text-center mt-4 text-muted small d-print-none">
        <p>Report generated on <?= date('Y-m-d H:i:s') ?> | <?= htmlspecialchars($_SESSION['username'] ?? 'System') ?></p>
    </div>
</div>

<style>
.fw-mono { font-family: 'Courier New', monospace; }
.ls-1 { letter-spacing: 1px; }
.card { border-radius: 10px; }
.shadow-lg { box-shadow: 0 10px 25px rgba(0,0,0,0.05) !important; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php
includeFooter();
ob_end_flush();
?>
