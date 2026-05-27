<?php
// scope-audit: skip — cross-project trends analysis report; project-scope filtering deferred to Phase G-2
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

// Use existing permission mapping
autoEnforcePermission('trends_analysis');

// Monthly trends for last 12 months
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("-$i months"));
}

try {
    $placeholders = implode(',', array_fill(0, 12, '?'));
    
    // Sales trend - Using grand_total for accurate revenue
    $sql_sales = "SELECT DATE_FORMAT(order_date,'%Y-%m') as month, SUM(grand_total) as total
                  FROM sales_orders 
                  WHERE DATE_FORMAT(order_date,'%Y-%m') IN ($placeholders) AND status != 'cancelled'
                  GROUP BY month";
    $stmt = $pdo->prepare($sql_sales); 
    $stmt->execute($months);
    $sales_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Expense trend
    $sql_exp = "SELECT DATE_FORMAT(expense_date,'%Y-%m') as month, SUM(amount) as total
                FROM expenses 
                WHERE DATE_FORMAT(expense_date,'%Y-%m') IN ($placeholders) AND status != 'rejected'
                GROUP BY month";
    $stmt = $pdo->prepare($sql_exp); 
    $stmt->execute($months);
    $exp_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $trend_data = [];
    foreach ($months as $m) {
        $sales = floatval($sales_raw[$m] ?? 0);
        $exp   = floatval($exp_raw[$m] ?? 0);
        $trend_data[] = [
            'month_key' => $m,
            'month_label' => date('M Y', strtotime($m . '-01')),
            'sales' => $sales, 
            'expenses' => $exp, 
            'profit' => $sales - $exp
        ];
    }
    
    // Calculate Summary Statistics before they are used in headers
    $tot_s = array_sum(array_column($trend_data, 'sales'));
    $tot_e = array_sum(array_column($trend_data, 'expenses'));
    $tot_p = $tot_s - $tot_e;
    $avg_m = count($trend_data) > 0 ? $tot_s / count($trend_data) : 0;

} catch (Exception $e) { $error = $e->getMessage(); $trend_data = []; $tot_s = $tot_e = $tot_p = $avg_m = 0; }
?>
<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <div class="mt-2 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">HISTORICAL TRENDS ANALYSIS</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Comparative performance analysis of revenue, expenses, and profitability over the last 12 months.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Annual Sales</p>
                <h4 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($tot_s) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Annual Expenses</p>
                <h4 style="color: #e74c3c; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($tot_e) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Annual Net Profit</p>
                <h4 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($tot_p) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Monthly Average</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($avg_m) ?></h4>
            </div>
        </div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-primary"><i class="bi bi-graph-up-arrow"></i> Trends Analysis</h2>
                            <p class="mb-0 text-muted">Comparative performance analysis for the last 12 months</p>
                        </div>
                        <button class="btn btn-outline-primary fw-bold" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> Print Analysis
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Summary Statistics -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3 text-center">
                    <p class="small text-uppercase text-muted fw-bold mb-1">Annual Sales</p>
                    <h4 class="fw-bold text-dark mb-0"><?= format_currency($tot_s) ?></h4>
                    <span class="small text-success fw-bold">Revenue In</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3 text-center">
                    <p class="small text-uppercase text-muted fw-bold mb-1">Annual Expenses</p>
                    <h4 class="fw-bold text-dark mb-0"><?= format_currency($tot_e) ?></h4>
                    <span class="small text-danger fw-bold">Revenue Out</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3 text-center">
                    <p class="small text-uppercase text-muted fw-bold mb-1">Annual Net Profit</p>
                    <h4 class="fw-bold text-dark mb-0"><?= format_currency($tot_p) ?></h4>
                    <span class="small text-primary fw-bold">Operational Margin</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3 text-center">
                    <p class="small text-uppercase text-muted fw-bold mb-1">Monthly Average</p>
                    <h4 class="fw-bold text-dark mb-0"><?= format_currency($avg_m) ?></h4>
                    <span class="small text-warning fw-bold">Rolling Average</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Trend Table -->
    <div class="card border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-calendar-check me-2 text-primary"></i>12-Month Performance Log</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 text-muted small text-uppercase" style="width:45px;">S/NO</th>
                            <th class="ps-2">Reporting Month</th>
                            <th class="text-end">Sales Revenue</th>
                            <th class="text-end">Operating Expenses</th>
                            <th class="text-end pe-4">Net Profit/Loss</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($trend_data)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No historical data available.</td></tr>
                        <?php else: $sno = 1; foreach(array_reverse($trend_data) as $t): ?>
                            <tr>
                                <td class="ps-3 text-center text-muted fw-bold small"><?= $sno++ ?></td>
                                <td class="ps-2 fw-bold text-dark"><?= htmlspecialchars((string)($t['month_label'] ?? '')) ?></td>
                                <td class="text-end text-success fw-bold"><?= format_currency($t['sales']) ?></td>
                                <td class="text-end text-danger"><?= format_currency($t['expenses']) ?></td>
                                <td class="text-end pe-4">
                                    <span class="badge <?= $t['profit'] >= 0 ? 'bg-success' : 'bg-danger' ?> px-3 py-2" style="font-size: 0.9rem;">
                                        <?= format_currency($t['profit']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Consolidated Totals moved here to avoid repetition on every print page -->
                        <tr class="bg-white fw-bold border-top" style="border-top: 2px solid #dee2e6 !important; color: #333;">
                            <td colspan="2" class="ps-4 py-3">Consolidated Totals</td>
                            <td class="text-end py-3" style="color: #2ecc71;"><?= format_currency($tot_s) ?></td>
                            <td class="text-end py-3" style="color: #e74c3c;"><?= format_currency($tot_e) ?></td>
                            <td class="text-end pe-4 py-3" style="color: #0d6efd;"><?= format_currency($tot_p) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    if(typeof logReportAction==='function') {
        logReportAction('Viewed Trends Analysis', 'Accessed 12-month performance trends report');
    }
});
</script>

<style>
    .card { transition: all 0.3s ease; }
    .table-hover tbody tr:hover { background-color: rgba(52, 152, 219, 0.05); }
    @media print {
        .d-print-none, .btn, .navbar, .sidebar { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .table-dark { background-color: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; }
        .badge { border: 1px solid #ddd !important; color: #000 !important; background: transparent !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
