<?php
// app/constant/reports/performance_dashboard.php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

autoEnforcePermission('performance_dashboard');

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-12-31');

try {
    // 1. Total Revenue (Sales)
    $rev_sql = "SELECT COALESCE(SUM(grand_total), 0) as revenue FROM sales_orders WHERE order_date BETWEEN ? AND ? AND status != 'cancelled'";
    $stmt = $pdo->prepare($rev_sql);
    $stmt->execute([$start_date, $end_date]);
    $revenue = floatval($stmt->fetchColumn());

    // 2. Direct Costs (Purchases as COGS approximation)
    $pur_sql = "SELECT COALESCE(SUM(grand_total), 0) as purchases FROM purchase_orders WHERE order_date BETWEEN ? AND ? AND status NOT IN ('rejected', 'cancelled')";
    $stmt = $pdo->prepare($pur_sql);
    $stmt->execute([$start_date, $end_date]);
    $purchases = floatval($stmt->fetchColumn());

    // 3. Operating Expenses
    $exp_sql = "SELECT COALESCE(SUM(amount), 0) as expenses FROM expenses WHERE expense_date BETWEEN ? AND ? AND status NOT IN ('rejected', 'cancelled')";
    $stmt = $pdo->prepare($exp_sql);
    $stmt->execute([$start_date, $end_date]);
    $expenses_total = floatval($stmt->fetchColumn());

    $gross_profit  = $revenue - $purchases;
    $net_profit    = $gross_profit - $expenses_total;
    $profit_margin = $revenue > 0 ? ($net_profit / $revenue) * 100 : 0;
    $expense_ratio = $revenue > 0 ? ($expenses_total / $revenue) * 100 : 0;

    // 4. Monthly Trend Data
    $monthly_sql = "
        SELECT 
            DATE_FORMAT(order_date, '%Y-%m') as month_key, 
            DATE_FORMAT(order_date, '%b %Y') as month_label,
            SUM(grand_total) as total
        FROM sales_orders 
        WHERE order_date BETWEEN ? AND ? AND status != 'cancelled'
        GROUP BY month_key 
        ORDER BY month_key ASC
    ";
    $stmt = $pdo->prepare($monthly_sql);
    $stmt->execute([$start_date, $end_date]);
    $monthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
        ?>
        <?php if(!empty($c_logo)): ?>
            <div class="mb-2 text-center">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 60px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 20pt;" class="text-center"><?= safe_output($c_name) ?></h1>
        
        <div class="mt-2 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 2px 0; font-size: 14pt; letter-spacing: 1px;">BUSINESS PERFORMANCE DASHBOARD</h2>
            <p style="color: #6c757d; margin: 0; font-size: 9pt;">Executive summary of financial health and operational efficiency.</p>
            <div style="display: flex; justify-content: center; gap: 20px; font-size: 8pt; font-weight: 600; text-transform: uppercase; margin-top: 5px; color: #444;">
                <span>Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></span>
                <span>Generated At: <?= date('d M Y, h:i A') ?></span>
            </div>
        </div>
        <div style="border-bottom: 2px solid #0d6efd; margin-top: 10px; margin-bottom: 15px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-3">
        <div style="display: flex !important; flex-direction: row !important; gap: 8px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 8px; text-align: center;">
                <p style="color: #666; font-size: 7pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Revenue</p>
                <h5 style="color: #333; font-weight: 800; margin: 0; font-size: 12pt;"><?= format_currency($revenue) ?></h5>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 8px; text-align: center;">
                <p style="color: #666; font-size: 7pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Operating Spend</p>
                <h5 style="color: #e74c3c; font-weight: 800; margin: 0; font-size: 12pt;"><?= format_currency($expenses_total) ?></h5>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 8px; text-align: center;">
                <p style="color: #666; font-size: 7pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Net Earnings</p>
                <h5 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 12pt;"><?= format_currency($net_profit) ?></h5>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 8px; text-align: center;">
                <p style="color: #666; font-size: 7pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Profitability %</p>
                <h5 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 12pt;"><?= number_format($profit_margin, 1) ?>%</h5>
            </div>
        </div>
    </div>

    <!-- Unified Screen-Only Section -->
    <div class="d-print-none">
        <!-- Header -->
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h2 class="fw-bold text-primary mb-0"><i class="bi bi-speedometer2 me-2 text-primary"></i>Business Performance</h2>
                <p class="text-muted mb-0">High-level executive dashboard and financial health metrics</p>
            </div>
            <div class="col-md-6 text-end">
                <button class="btn btn-outline-primary px-4 fw-bold shadow-sm" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i> Print Dashboard
                </button>
                <button class="btn btn-dark px-4 fw-bold shadow-sm ms-2" onclick="alert('Exporting Data...')">
                    <i class="bi bi-file-earmark-pdf me-2"></i> Export Report
                </button>
            </div>
        </div>

        <!-- Health Metrics Grid -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                    <div class="card-body p-3">
                        <p class="text-muted small text-uppercase fw-bold mb-1">Total Revenue</p>
                        <h4 class="fw-bold mb-0 text-dark"><?= format_currency($revenue) ?></h4>
                        <span class="small text-success fw-bold">Income Stream</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                    <div class="card-body p-3">
                        <p class="text-muted small text-uppercase fw-bold mb-1">Operating Spend</p>
                        <h4 class="fw-bold mb-0 text-dark"><?= format_currency($expenses_total) ?></h4>
                        <span class="small text-danger fw-bold">Expenses</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                    <div class="card-body p-3">
                        <p class="text-muted small text-uppercase fw-bold mb-1">Net Earnings</p>
                        <h4 class="fw-bold mb-0 text-dark"><?= format_currency($net_profit) ?></h4>
                        <span class="small text-primary fw-bold">Efficiency</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                    <div class="card-body p-3">
                        <p class="text-muted small text-uppercase fw-bold mb-1">Profitability %</p>
                        <h4 class="fw-bold mb-0 text-dark"><?= number_format($profit_margin, 1) ?>%</h4>
                        <span class="small text-warning fw-bold">Margin</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Period Filter -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
            <div class="card-body p-4">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Analysis Start</label>
                        <input type="date" name="start_date" class="form-control rounded-3 border-light shadow-sm" value="<?= $start_date ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Analysis End</label>
                        <input type="date" name="date_to" class="form-control rounded-3 border-light shadow-sm" value="<?= $end_date ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm rounded-3">
                            <i class="bi bi-arrow-repeat me-1"></i> Refresh Intelligence
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Revenue Trend Visualizing Monthly Breakdown -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 20px;">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Revenue Trend</h5>
                </div>
                <div class="card-body">
                    <?php if(empty($monthly)): ?>
                        <div class="text-center py-5 text-muted">No monthly data captured for this range.</div>
                    <?php else: 
                        $max_total = max(array_column($monthly, 'total')) ?: 1;
                        foreach($monthly as $m): 
                            $pct = ($m['total'] / $max_total) * 100;
                    ?>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="fw-bold text-muted small"><?= $m['month_label'] ?></span>
                                <span class="fw-bold text-dark small"><?= format_currency($m['total']) ?></span>
                            </div>
                            <div class="progress" style="height: 12px; border-radius: 6px; background-color: #f8f9fa;">
                                <div class="progress-bar bg-primary shadow-sm" role="progressbar" 
                                     style="width: <?= $pct ?>%; border-radius: 6px; transition: width 1s ease-in-out;" 
                                     aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Profitability Ratio Analysis -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 20px;">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pie-chart-fill me-2 text-danger"></i>Health Breakdown</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4 text-center">
                        <p class="text-muted small fw-bold text-uppercase mb-2">Expense-to-Revenue Ratio</p>
                        <h2 class="fw-bold <?= $expense_ratio < 40 ? 'text-success' : ($expense_ratio < 70 ? 'text-warning' : 'text-danger') ?>">
                            <?= number_format($expense_ratio, 1) ?>%
                        </h2>
                    </div>

                    <div class="list-group list-group-flush mt-4">
                        <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <div class="d-flex align-items-center">
                                <div class="bg-success rounded-circle me-2" style="width: 12px; height: 12px;"></div>
                                <span class="text-muted small fw-bold">Gross Profit</span>
                            </div>
                            <span class="fw-bold text-dark"><?= format_currency($gross_profit) ?></span>
                        </div>
                        <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <div class="d-flex align-items-center">
                                <div class="bg-danger rounded-circle me-2" style="width: 12px; height: 12px;"></div>
                                <span class="text-muted small fw-bold">Operating Cost</span>
                            </div>
                            <span class="fw-bold text-dark"><?= format_currency($expenses_total) ?></span>
                        </div>
                        <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary rounded-circle me-2" style="width: 12px; height: 12px;"></div>
                                <span class="text-muted small fw-bold">Net Margin</span>
                            </div>
                            <span class="fw-bold text-dark"><?= format_currency($net_profit) ?></span>
                        </div>
                    </div>

                    <div class="mt-4 p-3 bg-light rounded-4">
                        <h6 class="fw-bold small mb-1">Executive Advice:</h6>
                        <p class="text-muted mb-0" style="font-size: 0.8rem;">
                            <?php 
                            if($profit_margin > 20) echo "Your profitability is strong. Consider reinvesting into expansion.";
                            elseif($profit_margin > 5) echo "Margins are stable, but optimization of operating costs could increase yield.";
                            else echo "Low margins detected. Review product pricing and direct procurement costs.";
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    if(typeof logReportAction==='function') {
        logReportAction('Viewed Executive Performance', 'Dashboard analysis for period <?= $start_date ?> to <?= $end_date ?>');
    }
});
</script>

<style>
    .card { transition: all 0.3s cubic-bezier(.25,.8,.25,1); }
    .card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
    .progress-bar { transition: width 1.5s ease-in-out !important; }
    @media print {
        .navbar, .sidebar, .d-print-none, .btn { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .bg-opacity-10 { background-color: transparent !important; }
        .badge { border: 1px solid #ddd !important; color: #000 !important; background: transparent !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .col-lg-6 { width: 50% !important; float: left; padding: 5px; }
        .row { display: block !important; }
        .row::after { content: ""; display: table; clear: both; }
    }
</style>

<?php includeFooter(); ob_end_flush(); ?>
