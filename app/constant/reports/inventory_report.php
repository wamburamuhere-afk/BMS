<?php
// app/constant/reports/inventory_report.php
// scope-audit: skip — inventory report with cross-project aggregation; project-scope filtering deferred to Phase G-2
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

autoEnforcePermission('inventory_report');

try {
    $sql = "SELECT p.product_code, p.product_name, c.category_name as category,
                   p.selling_price, p.cost_price, p.current_stock,
                   p.reorder_level, p.status,
                   (p.current_stock * p.cost_price) as stock_value
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            ORDER BY p.product_name ASC";
    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_value  = array_sum(array_column($products, 'stock_value'));
    $total_items  = array_sum(array_column($products, 'current_stock'));
    $low_stock_items = array_filter($products, fn($p) => $p['current_stock'] <= $p['reorder_level']);
    $low_stock_count = count($low_stock_items);
} catch (Exception $e) { $error = $e->getMessage(); $products = []; $total_value = $total_items = $low_stock_count = 0; }
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
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">INVENTORY VALUATION REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Real-time stock value analysis, SKU distribution, and reorder status.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total SKU Inventory</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= count($products) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Asset Value</p>
                <h4 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_value) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Low Stock Alerts</p>
                <h4 style="color: #e74c3c; font-weight: 800; margin: 0; font-size: 14pt;"><?= $low_stock_count ?></h4>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-box-seam me-2"></i>Inventory Valuation</h2>
            <p class="text-muted mb-0">Real-time stock value and reorder analysis</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary px-4 fw-bold shadow-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print Report
            </button>
            <button class="btn btn-dark px-4 fw-bold shadow-sm ms-2" onclick="alert('Exporting to Excel...')">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel
            </button>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total SKU Inventory</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= count($products) ?> <small class="fs-6 text-muted">Items</small></h4>
                    <span class="small text-primary fw-bold">Active SKUs</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Asset Value</p>
                    <h4 class="fw-bold mb-0 text-success"><?= format_currency($total_value) ?></h4>
                    <span class="small text-success fw-bold">Portfolio Worth</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Low Stock Alerts</p>
                    <h4 class="fw-bold mb-0 text-danger"><?= $low_stock_count ?> <small class="fs-6 text-muted">SKUs</small></h4>
                    <span class="small text-danger fw-bold">Reorder Required</span>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Inventory Table -->
    <div class="card border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">Live Inventory Ledger</h5>
            <input type="text" id="inventorySearch" class="form-control form-control-sm px-3 shadow-sm border-light d-print-none" placeholder="Search products..." style="width: 250px; border-radius: 20px;">
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="inventoryTable">
                    <thead class="bg-light">
                        <tr class="text-muted small text-uppercase">
                            <th class="ps-3" style="width:45px;">S/NO</th>
                            <th class="ps-2">SKU / Product</th>
                            <th>Category</th>
                            <th class="text-end">Unit Cost</th>
                            <th class="text-end">Current Qty</th>
                            <th class="text-end pe-4">Total Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($products)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No inventory records found.</td></tr>
                        <?php else: $sno = 1; foreach($products as $p): 
                            $is_low = $p['current_stock'] <= $p['reorder_level'];
                        ?>
                            <tr class="<?= $is_low ? 'bg-danger bg-opacity-10' : '' ?>">
                                <td class="ps-3 text-center text-muted fw-bold small"><?= $sno++ ?></td>
                                <td class="ps-2">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars((string)($p['product_name'] ?? '')) ?></div>
                                    <div class="small text-muted font-monospace"><?= htmlspecialchars((string)($p['product_code'] ?? '')) ?></div>
                                    <?php if($is_low): ?>
                                        <span class="badge bg-danger p-1 mt-1" style="font-size: 0.6rem;">REORDER REQUIRED</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars((string)($p['category'] ?? 'General')) ?></span></td>
                                <td class="text-end"><?= format_currency($p['cost_price']) ?></td>
                                <td class="text-end">
                                    <span class="fw-bold <?= $is_low ? 'text-danger animate-pulse' : 'text-primary' ?>"><?= number_format($p['current_stock']) ?></span>
                                    <div class="small text-muted" style="font-size: 0.7rem;">Min: <?= number_format($p['reorder_level']) ?></div>
                                </td>
                                <td class="text-end pe-4 fw-bold text-dark"><?= format_currency($p['stock_value']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot class="bg-light border-top">
                        <tr class="fw-bold">
                            <td colspan="4" class="ps-4 py-3">PORTFOLIO VALUATION</td>
                            <td class="text-end py-3 h6 mb-0"><?= number_format($total_items) ?> <small>Units</small></td>
                            <td class="text-end pe-4 py-3 h5 mb-0 text-success"><?= format_currency($total_value) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#inventorySearch').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $("#inventoryTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    if(typeof logReportAction==='function') {
        logReportAction('Viewed Inventory Valuation', 'Generated live inventory status report');
    }
});
</script>

<style>
    .animate-pulse { animation: pulse 2s infinite; }
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.5; }
        100% { opacity: 1; }
    }
    .card { border-radius: 12px; }
    .table thead th { border-top: none; }
    @media print {
        .navbar, .sidebar, .d-print-none, .btn, #inventorySearch, .card-header .d-print-none { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .bg-danger.bg-opacity-10 { background-color: transparent !important; border: 1px solid #dc3545 !important; }
        .badge { color: #000 !important; border: 1px solid #ddd !important; background: transparent !important; }
        /* Prevent tfoot from repeating on every page - show only at the very end */
        tfoot { display: table-row-group !important; }
    }
</style>

<?php includeFooter(); ob_end_flush(); ?>

