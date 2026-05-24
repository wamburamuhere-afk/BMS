<?php
// File: reps/balance_sheet.php
// Phase 5c — partial; normally included by app/bms/invoice/reports.php
// (which already gates 'reports'), but a direct hit on this URL must
// also be denied. roots.php and the permission helpers are idempotent.
require_once __DIR__ . '/../../../../roots.php';
if (!canView('reports')) {
    http_response_code(403);
    die("Access Denied");
}

$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');

try {
    global $pdo;
    
    // 1. Fetch all balance sheet accounts and calculate balances up to the specified date
    // We sum up the journal entries to get the real balance at that point in time.
    $sql = "
        SELECT 
            a.account_id,
            a.account_name,
            a.account_code,
            a.account_type,
            COALESCE(SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) as total_credit
        FROM accounts a
        LEFT JOIN journal_entry_items jei ON a.account_id = jei.account_id
        LEFT JOIN journal_entries je ON jei.entry_id = je.entry_id AND je.entry_date <= ? AND je.status = 'posted'
        WHERE a.account_type IN ('asset', 'liability', 'equity')
        AND a.status = 'active'
        GROUP BY a.account_id, a.account_name, a.account_code, a.account_type
        ORDER BY a.account_type, a.account_code
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$as_of_date]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Separate into categories
    $assets = [];
    $liabilities = [];
    $equity = [];
    
    foreach ($accounts as $acc) {
        $balance = 0;
        if ($acc['account_type'] === 'asset') {
            $balance = $acc['total_debit'] - $acc['total_credit'];
            if ($balance != 0 || $acc['total_debit'] != 0 || $acc['total_credit'] != 0) {
                $assets[] = ['name' => $acc['account_name'], 'code' => $acc['account_code'], 'balance' => $balance];
            }
        } elseif ($acc['account_type'] === 'liability') {
            $balance = $acc['total_credit'] - $acc['total_debit'];
            if ($balance != 0) {
                $liabilities[] = ['name' => $acc['account_name'], 'code' => $acc['account_code'], 'balance' => $balance];
            }
        } elseif ($acc['account_type'] === 'equity') {
            $balance = $acc['total_credit'] - $acc['total_debit'];
            // We'll add Net Income later
            $equity[] = ['name' => $acc['account_name'], 'code' => $acc['account_code'], 'balance' => $balance];
        }
    }
    
    // 3. Calculate Retained Earnings (Net Income to date)
    // Sum(Income) - Sum(Expense)
    $net_income_sql = "
        SELECT 
            SUM(CASE WHEN a.account_type = 'income' THEN (jei.amount * (CASE WHEN jei.type = 'credit' THEN 1 ELSE -1 END)) ELSE 0 END) -
            SUM(CASE WHEN a.account_type IN ('expense', 'cost_of_sales') THEN (jei.amount * (CASE WHEN jei.type = 'debit' THEN 1 ELSE -1 END)) ELSE 0 END) as net_income
        FROM accounts a
        JOIN journal_entry_items jei ON a.account_id = jei.account_id
        JOIN journal_entries je ON jei.entry_id = je.entry_id
        WHERE je.entry_date <= ? AND je.status = 'posted'
    ";
    $stmt = $pdo->prepare($net_income_sql);
    $stmt->execute([$as_of_date]);
    $net_income = (float)$stmt->fetchColumn();
    
    // Add Net Income to Equity
    $equity[] = ['name' => 'Net Income (Year to Date)', 'code' => '-', 'balance' => $net_income];

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!-- Print-only Header -->
<div class="d-none d-print-block text-center mb-4">
    <?php 
    $c_name = getSetting('company_name', 'BMS');
    $c_logo = getSetting('company_logo', '');
    $c_email = getSetting('company_email', '');
    $c_web = getSetting('company_website', '');
    $c_tin = getSetting('company_tin', '');
    $c_vrn = getSetting('company_vrn', '');
    ?>
    <?php if(!empty($c_logo)): ?>
        <div class="mb-3">
            <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
        </div>
    <?php endif; ?>
    <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= safe_output($c_name) ?></h1>
    
    <p class="text-dark mb-1 small text-uppercase">
        <?php 
        $web_email = [];
        if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
        if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
        if (!empty($web_email)) echo implode(" | ", $web_email);
        ?>
    </p>

    <p class="text-dark mb-1 small text-uppercase">
        <?php 
        $tin_vrn = [];
        if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
        if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
        if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
        ?>
    </p>

    <div class="mt-3">
        <h3 class="fw-bold text-success text-uppercase" style="color: #198754 !important;">BALANCE SHEET REPORT</h3>
        <h6 class="text-muted">As of Date: <?= date('d M Y', strtotime($as_of_date)) ?></h6>
        <div class="mt-2" style="border-top: 2px solid #198754; width: 100px; margin: 0 auto;"></div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <h5 class="mb-0 fw-bold text-success"><i class="bi bi-journal-text me-2"></i> Balance Sheet</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>
    <div class="card-body border-bottom bg-light d-print-none">
        <form method="GET" action="<?= getUrl('reports') ?>" class="row g-3 align-items-end">
            <input type="hidden" name="report" value="balance_sheet">
            <div class="col-md-8">
                <label class="form-label small fw-bold">As of Date</label>
                <input type="date" class="form-control form-control-sm" name="as_of_date" value="<?= $as_of_date ?>">
            </div>
            <div class="col-md-4 d-grid">
                <button type="submit" class="btn btn-success btn-sm text-white">
                    <i class="bi bi-filter"></i> Generate Report
                </button>
            </div>
        </form>
    </div>

    <style>
    @media print {
        body { background: white !important; }
        .container, .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table { width: 100% !important; border: 1px solid #dee2e6 !important; }
        .table th { background-color: #f8f9fa !important; color: black !important; }
        .text-success { color: #198754 !important; }
        .text-danger { color: #dc3545 !important; }
        .text-primary { color: #0d6efd !important; }
        .card-footer { border: none !important; margin-top: 20px !important; }
        .alert { border: 1px solid #ddd !important; background: transparent !important; color: black !important; }
    }
    </style>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-uppercase small fw-bold text-muted">
                    <tr>
                        <th width="70%" class="ps-4">Account Descripion</th>
                        <th width="30%" class="text-end pe-4">Balance (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- ASSETS -->
                    <tr class="table-info fw-bold"><td colspan="2" class="ps-4">ASSETS</td></tr>
                    <?php 
                    $total_assets = 0;
                    if(empty($assets)): ?>
                        <tr><td class="ps-5 text-muted small">No asset accounts found</td><td class="text-end pe-4">-</td></tr>
                    <?php else: ?>
                        <?php foreach($assets as $acc): ?>
                        <tr>
                            <td class="ps-5">
                                <span class="text-muted small me-2"><?= $acc['code'] ?></span>
                                <?= htmlspecialchars($acc['name']) ?>
                            </td>
                            <td class="text-end pe-4"><?= format_currency($acc['balance']) ?></td>
                        </tr>
                        <?php $total_assets += $acc['balance']; endforeach; ?>
                    <?php endif; ?>
                    <tr class="fw-bold bg-light"><td class="ps-4">TOTAL ASSETS</td><td class="text-end pe-4 text-primary"><?= format_currency($total_assets) ?></td></tr>

                    <!-- LIABILITIES -->
                    <tr class="table-warning fw-bold"><td colspan="2" class="ps-4">LIABILITIES</td></tr>
                    <?php 
                    $total_liab = 0;
                    if(empty($liabilities)): ?>
                        <tr><td class="ps-5 text-muted small">No liability accounts found</td><td class="text-end pe-4">-</td></tr>
                    <?php else: ?>
                        <?php foreach($liabilities as $acc): ?>
                        <tr>
                            <td class="ps-5">
                                <span class="text-muted small me-2"><?= $acc['code'] ?></span>
                                <?= htmlspecialchars($acc['name']) ?>
                            </td>
                            <td class="text-end pe-4"><?= format_currency($acc['balance']) ?></td>
                        </tr>
                        <?php $total_liab += $acc['balance']; endforeach; ?>
                    <?php endif; ?>
                    <tr class="fw-bold bg-light"><td class="ps-4">TOTAL LIABILITIES</td><td class="text-end pe-4 text-danger"><?= format_currency($total_liab) ?></td></tr>

                    <!-- EQUITY -->
                    <tr class="table-success fw-bold"><td colspan="2" class="ps-4">EQUITY</td></tr>
                    <?php 
                    $total_equity = 0;
                    foreach($equity as $acc): ?>
                    <tr>
                        <td class="ps-5">
                            <span class="text-muted small me-2"><?= $acc['code'] ?></span>
                            <?= htmlspecialchars($acc['name']) ?>
                        </td>
                        <td class="text-end pe-4"><?= format_currency($acc['balance']) ?></td>
                    </tr>
                    <?php $total_equity += $acc['balance']; endforeach; ?>
                    <tr class="fw-bold bg-light"><td class="ps-4">TOTAL EQUITY</td><td class="text-end pe-4 text-success"><?= format_currency($total_equity) ?></td></tr>

                    <!-- FOOTER CHECK -->
                    <tr class="custom-stat-card-green fw-bold fs-5 border-top-2">
                        <td class="ps-4">TOTAL LIABILITIES & EQUITY</td>
                        <td class="text-end pe-4"><?= format_currency($total_liab + $total_equity) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white py-3">
        <?php if (abs($total_assets - ($total_liab + $total_equity)) < 0.01): ?>
            <div class="alert alert-success d-flex align-items-center mb-0 border-0">
                <i class="bi bi-check-circle-fill me-2"></i>
                <div>Your balance sheet is perfectly balanced.</div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger d-flex align-items-center mb-0 border-0">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div>Balance Sheet is Out of Balance by <?= format_currency(abs($total_assets - ($total_liab + $total_equity))) ?>. Please check your journal entries.</div>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
$(document).ready(function() {
    logReportAction('Viewed Balance Sheet', 'User viewed the balance sheet report as of date <?= $as_of_date ?>');
    
    $('.card-body form').on('submit', function() {
        logReportAction('Generated Balance Sheet', 'User generated balance sheet report');
    });
});
</script>
