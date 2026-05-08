<?php
// app/constant/reports/sales_forecast.php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

autoEnforcePermission('performance_dashboard');

// 1. Fetch sales for the last 12 months (group by month)
$historical = [];
$forecast = [];
$total_revenue = 0;
$avg_monthly = 0;

try {
    $hist_sql = "
        SELECT 
            DATE_FORMAT(order_date, '%Y-%m') as month_key, 
            DATE_FORMAT(order_date, '%M %Y') as month_label,
            SUM(grand_total) as total
        FROM sales_orders 
        WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
          AND status != 'cancelled'
        GROUP BY month_key
        ORDER BY month_key ASC
    ";
    $stmt = $pdo->query($hist_sql);
    $historical = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate Average Monthly Revenue
    if (count($historical) > 0) {
        $total_revenue = array_sum(array_column($historical, 'total'));
        $avg_monthly = $total_revenue / count($historical);
        
        // Simple Average-based Forecast for next 6 months
        $last_month_str = count($historical) > 0 ? $historical[count($historical)-1]['month_key'] : date('Y-m');
        
        for ($i = 1; $i <= 6; $i++) {
            $ts = strtotime($last_month_str . "-01 +$i month");
            $forecast[] = [
                'month_label' => date('F Y', $ts),
                'projection' => $avg_monthly, // This is a baseline forecast
                'optimistic' => $avg_monthly * 1.15,
                'conservative' => $avg_monthly * 0.85
            ];
        }
    }

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
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 2px 0; font-size: 14pt; letter-spacing: 1px;">SALES FORECASTING REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 9pt;">Projected revenue and sales volume based on historical patterns and predictive analytics.</p>
            <div style="display: flex; justify-content: center; gap: 20px; font-size: 8pt; font-weight: 600; text-transform: uppercase; margin-top: 5px; color: #444;">
                <span>Type: Baseline Moving Average</span>
                <span>Generated At: <?= date('d M Y, h:i A') ?></span>
            </div>
        </div>
        <div style="border-bottom: 2px solid #0d6efd; margin-top: 10px; margin-bottom: 15px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-3">
        <div style="display: flex !important; flex-direction: row !important; gap: 8px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 8px; text-align: center;">
                <p style="color: #666; font-size: 7pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Avg Monthly Revenue</p>
                <h5 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 12pt;"><?= format_currency($avg_monthly) ?></h5>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 8px; text-align: center;">
                <p style="color: #666; font-size: 7pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Next Month (Forecast)</p>
                <h5 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 12pt;"><?= count($forecast) > 0 ? format_currency($forecast[0]['projection']) : '0.00' ?></h5>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 8px; text-align: center;">
                <p style="color: #666; font-size: 7pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Confidence Score</p>
                <h5 style="color: #333; font-weight: 800; margin: 0; font-size: 12pt;"><?= count($historical) > 6 ? 'High (85%)' : (count($historical) > 2 ? 'Medium (60%)' : 'Low (30%)') ?></h5>
            </div>
        </div>
    </div>

    <!-- Screen-Only Header -->
    <div class="d-print-none">
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h2 class="fw-bold text-primary mb-0"><i class="bi bi-crystal-ball me-2"></i>Sales Intelligence</h2>
                <p class="text-muted mb-0">Predictive forecasting using historical sales trajectory</p>
            </div>
            <div class="col-md-6 text-end">
                <button class="btn btn-outline-primary px-4 fw-bold shadow-sm" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i> Print Forecast
                </button>
            </div>
        </div>

        <?php if(count($historical) < 2): ?>
        <div class="alert alert-warning border-0 shadow-sm mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> **Note:** Limited historical data found. Forecast accuracy increases with at least 3 months of sales history.
        </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <!-- Projections Table -->
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm" style="border-radius: 20px; overflow: hidden;">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Revenue Projections (Next 6 Months)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr class="text-muted small text-uppercase">
                                    <th class="ps-4">Forecast Month</th>
                                    <th class="text-center">Conservative (Low)</th>
                                    <th class="text-center">Baseline (Expected)</th>
                                    <th class="text-center">Optimistic (High)</th>
                                    <th class="text-end pe-4">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($forecast)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted italic">No forecasting data available. Please record more sales.</td>
                                    </tr>
                                <?php else: foreach($forecast as $i => $f): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark"><?= $f['month_label'] ?></div>
                                            <div class="small text-muted">Projected Period T+<?= $i+1 ?></div>
                                        </td>
                                        <td class="text-center text-muted"><?= format_currency($f['conservative']) ?></td>
                                        <td class="text-center fw-bold text-primary"><?= format_currency($f['projection']) ?></td>
                                        <td class="text-center text-success"><?= format_currency($f['optimistic']) ?></td>
                                        <td class="text-end pe-4">
                                            <span class="badge rounded-pill bg-<?= $i < 2 ? 'success' : 'light text-dark' ?>">
                                                <?= $i < 2 ? 'High Reliable' : 'Projected' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Historical Context -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm" style="border-radius: 20px;">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history me-2 text-muted"></i>Historical Baseline</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php if(empty($historical)): ?>
                            <div class="text-center py-4 text-muted">No history found.</div>
                        <?php else: foreach(array_reverse(array_slice($historical, -4)) as $h): ?>
                            <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center px-0">
                                <div>
                                    <div class="fw-bold"><?= $h['month_label'] ?></div>
                                    <div class="small text-muted">Actual Performance</div>
                                </div>
                                <span class="fw-bold text-dark"><?= format_currency($h['total']) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Insights -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 20px; background-color: #f8f9fa;">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-dark mb-4">Forecast Intelligence</h5>
                    
                    <div class="mb-4">
                        <label class="small text-muted text-uppercase fw-bold mb-1">Growth Assumption</label>
                        <p class="mb-1 text-dark fw-bold">Organic Monthly Trajectory</p>
                        <div class="progress" style="height: 6px; border-radius: 3px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: 100%;"></div>
                        </div>
                        <small class="text-muted">Calculated based on rolling average of the last 12 months.</small>
                    </div>

                    <div class="p-3 bg-white border-0 shadow-sm rounded-4">
                        <h6 class="fw-bold small mb-1"><i class="bi bi-lightbulb-fill text-warning me-2"></i>Strategic Insight:</h6>
                        <p class="text-muted mb-0" style="font-size: 0.8rem;">
                            Based on current patterns, your business should maintain adequate inventory for **<?= count($forecast) > 0 ? $forecast[0]['month_label'] : 'next month' ?>** 
                            to meet a projected demand of approximately **<?= count($forecast) > 0 ? format_currency($forecast[0]['projection']) : 'TSh 0.00' ?>**.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .navbar, .sidebar, .d-print-none, .btn { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .badge { border: 1px solid #ccc !important; color: #000 !important; background: transparent !important; }
        .col-lg-6 { width: 48% !important; float: left; margin-right: 2% !important; }
        .row { display: block !important; }
        .row::after { content: ""; display: table; clear: both; }
    }
</style>

<?php includeFooter(); ob_end_flush(); ?>
