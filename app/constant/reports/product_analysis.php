<?php
// scope-audit: skip — cross-module product analysis report; project-scope filtering deferred to Phase G-2
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

// Use existing permission mapping
autoEnforcePermission('product_analysis');

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-12-31');

try {
    $sql = "SELECT p.product_code, p.product_name, c.category_name as category,
                   COUNT(soi.order_item_id) as times_sold, SUM(soi.quantity) as qty_sold,
                   SUM(soi.quantity * soi.unit_price) as revenue,
                   AVG(soi.unit_price) as avg_price
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN sales_order_items soi ON p.product_id = soi.product_id
            LEFT JOIN sales_orders so ON soi.order_id = so.sales_order_id
                AND so.order_date BETWEEN ? AND ? AND so.status != 'cancelled'
            GROUP BY p.product_id, p.product_code, p.product_name, c.category_name
            HAVING qty_sold > 0
            ORDER BY revenue DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute([$start_date, $end_date]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_revenue = array_sum(array_column($products, 'revenue'));
} catch (Exception $e) { $error = $e->getMessage(); $products = []; $total_revenue = 0; }
?>
<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
        ?>
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3 text-center">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= safe_output($c_name) ?></h1>
        
        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">PRODUCT PERFORMANCE REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Detailed analysis of products sold, category performance, and revenue contribution.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Skus Sold</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= count($products) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Items Volume</p>
                <h4 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 14pt;"><?= number_format(array_sum(array_column($products,'qty_sold'))) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Sales</p>
                <h4 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_revenue) ?></h4>
            </div>
        </div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-primary"><i class="bi bi-box-seam"></i> Product Performance</h2>
                            <p class="mb-0 text-muted">Analysis of products sold, categories and revenue contribution</p>
                        </div>
                        <div class="d-flex gap-2">
                             <button class="btn btn-outline-primary shadow-sm" onclick="window.print()">
                                <i class="bi bi-printer me-1"></i> Print Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Skus Sold</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= count($products) ?></h4>
                    <span class="small text-primary fw-bold">Active Catalog</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Items Volume</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= number_format(array_sum(array_column($products,'qty_sold'))) ?></h4>
                    <span class="small text-success fw-bold">Units Out</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Sales</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_revenue) ?></h4>
                    <span class="small text-warning fw-bold">Gross Turnover</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-radius: 12px;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase">Analysis From</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase">Analysis To</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark w-100 py-2 fw-bold shadow-sm">
                        <i class="bi bi-funnel me-1"></i> Generate Analytics
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Ranking Table -->
    <div class="card border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">Product Sales Rankings</h5>
            <div class="d-print-none">
                <span class="badge bg-light text-dark border"><?= count($products) ?> items found</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 text-muted small text-uppercase">Rank</th>
                            <th class="text-muted small text-uppercase">Product Details</th>
                            <th class="text-muted small text-uppercase">Category</th>
                            <th class="text-end text-muted small text-uppercase">Order Count</th>
                            <th class="text-end text-muted small text-uppercase">Units Sold</th>
                            <th class="text-end text-muted small text-uppercase">Avg Price</th>
                            <th class="text-end pe-4 text-muted small text-uppercase">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($products)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="bi bi-search fs-1 d-block mb-3 opacity-25"></i>
                                        No sales data available for the selected period.
                                    </div>
                                </td>
                            </tr>
                        <?php else: foreach($products as $i=>$p): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="badge <?= $i < 3 ? 'bg-primary' : 'bg-light text-dark' ?> rounded-circle p-2" style="width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;">
                                        <?= $i+1 ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars((string)($p['product_name'] ?? '')) ?></div>
                                    <div class="text-muted small font-monospace"><?= htmlspecialchars((string)($p['product_code'] ?? '')) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-muted border"><?= htmlspecialchars((string)($p['category'] ?? 'General')) ?></span>
                                </td>
                                <td class="text-end fw-semibold"><?= number_format($p['times_sold']) ?></td>
                                <td class="text-end"><?= number_format($p['qty_sold']) ?></td>
                                <td class="text-end text-muted"><?= format_currency($p['avg_price']) ?></td>
                                <td class="text-end pe-4">
                                    <span class="h6 fw-bold text-primary mb-0"><?= format_currency($p['revenue']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="6" class="ps-4 py-3 text-uppercase">Total Period Revenue</td>
                            <td class="text-end pe-4 py-3 h5 fw-bold text-primary"><?= format_currency($total_revenue) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    if(typeof logReportAction==='function') {
        logReportAction('Viewed Product Analysis', 'Analyzed performance for period <?= $start_date ?> to <?= $end_date ?>');
    }
});
</script>

<style>
    .bg-gradient-primary { background: linear-gradient(45deg, #4e73df 0%, #224abe 100%) !important; }
    .bg-gradient-success { background: linear-gradient(45deg, #1cc88a 0%, #13855c 100%) !important; }
    .bg-gradient-warning { background: linear-gradient(45deg, #f6c23e 0%, #dda20a 100%) !important; }
    
    .table thead th { border-top: none; }
    .card { border-radius: 12px; }
    
    @media print {
        .navbar, .sidebar, .d-print-none, .btn, .filter-card { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .badge { border: 1px solid #ddd !important; color: #000 !important; background: transparent !important; }
        .table-dark { background-color: #f8f9fa !important; color: #000 !important; }
    }
</style>

<?php includeFooter(); ob_end_flush(); ?>
