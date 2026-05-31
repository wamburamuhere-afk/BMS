<?php
// app/constant/reports/inventory_report.php
// Inventory Report — 4 views in one page:
//   1. Stock Snapshot   (DEFAULT) — product × warehouse current stock (product_stocks)
//   2. Stock Movements  — every IN/OUT ledger (stock_movements)
//   3. Stock Transfers  — warehouse → warehouse (stock_transfers + items)
//   4. Stock Adjustments— manual corrections (stock_movements, ref=manual)
// Views 2–4 lazy-load on first click; the snapshot stays the default and is
// unchanged. AJAX-driven, Chart.js, DataTable, Select2.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('inventory_report');

// Static filter sources (small lists — rendered in PHP, no AJAX needed).
$projects = $pdo->query(
    "SELECT project_id, project_name FROM projects
      WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
      ORDER BY project_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$warehouses = $pdo->query(
    "SELECT warehouse_id, warehouse_name FROM warehouses
      WHERE status = 'active' ORDER BY warehouse_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$products = $pdo->query(
    "SELECT product_id, product_code, product_name FROM products
      WHERE status != 'discontinued' ORDER BY product_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query(
    "SELECT category_id, category_name FROM categories
      WHERE status = 'active' ORDER BY category_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$currency  = get_setting('currency', 'TZS');
$year_from = date('Y-01-01');
$year_to   = date('Y-12-31');

// Reusable <option> blocks for the shared Product / Warehouse pickers.
$product_options = '';
foreach ($products as $p) {
    $label = $p['product_name'] ?: '—';
    if (!empty($p['product_code'])) $label .= ' (' . $p['product_code'] . ')';
    $product_options .= '<option value="' . (int)$p['product_id'] . '">' . safe_output($label) . '</option>';
}
$warehouse_options = '';
foreach ($warehouses as $w) {
    $warehouse_options .= '<option value="' . (int)$w['warehouse_id'] . '">' . safe_output($w['warehouse_name']) . '</option>';
}
?>

<div class="container-fluid py-4">

    <!-- Print Header (title set dynamically per active view) -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 id="printTitle" style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">INVENTORY VALUATION REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">As of: <?= date('d M Y') ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <!-- Screen header -->
    <div class="row mb-3 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-box-seam me-2"></i>Inventory Report</h2>
            <p class="text-muted mb-0">Current stock, movements, transfers &amp; adjustments</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> Print
            </button>
        </div>
    </div>

    <!-- View toggle (segmented) -->
    <div class="btn-group inv-toggle shadow-sm mb-4 d-print-none flex-wrap" role="group">
        <button type="button" class="btn inv-tab active" data-view="snapshot">
            <i class="bi bi-grid-3x3-gap me-1"></i> Stock Snapshot
        </button>
        <button type="button" class="btn inv-tab" data-view="movements">
            <i class="bi bi-arrow-left-right me-1"></i> Stock Movements
        </button>
        <button type="button" class="btn inv-tab" data-view="transfers">
            <i class="bi bi-truck me-1"></i> Stock Transfers
        </button>
        <button type="button" class="btn inv-tab" data-view="adjustments">
            <i class="bi bi-sliders me-1"></i> Stock Adjustments
        </button>
    </div>

    <!-- ============================================================= -->
    <!-- VIEW 1 — STOCK SNAPSHOT (DEFAULT, unchanged behaviour)        -->
    <!-- ============================================================= -->
    <div id="view-snapshot" class="inv-view">
        <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-body p-4">
                <form id="snapForm" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Product</label>
                        <select id="s-product" class="form-select" style="width:100%">
                            <option value="">All Products</option><?= $product_options ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Warehouse</label>
                        <select id="s-warehouse" class="form-select" style="width:100%">
                            <option value="">All Warehouses</option><?= $warehouse_options ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Category</label>
                        <select id="s-category" class="form-select" style="width:100%">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int)$c['category_id'] ?>"><?= safe_output($c['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Stock Status</label>
                        <select id="s-stock" class="form-select" style="width:100%">
                            <option value="">All Stock</option>
                            <option value="in">In Stock</option>
                            <option value="low">Low Stock</option>
                            <option value="out">Out of Stock</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Project</label>
                        <select id="s-project" class="form-select" style="width:100%">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <?php
            $snap_cards = [
                ['Total SKUs', 'stat-skus'], ['Warehouses with Stock', 'stat-wh'],
                ['Total Cost Value', 'stat-cost'], ['Total Selling Value', 'stat-sell'],
            ];
            foreach ($snap_cards as $c): ?>
                <div class="col-6 col-md-3">
                    <div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                        <div class="card-body p-3 text-center">
                            <p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                            <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h4>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-5">
                <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                    <div class="card-header bg-white fw-bold border-0"><i class="bi bi-building text-primary me-2"></i>Cost Value by Warehouse</div>
                    <div class="card-body"><div style="height:230px;"><canvas id="snapChartWarehouse"></canvas></div></div>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                    <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>Stock Status</div>
                    <div class="card-body"><div style="height:230px;"><canvas id="snapChartStatus"></canvas></div></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                    <div class="card-header bg-white fw-bold border-0"><i class="bi bi-trophy text-primary me-2"></i>Top Items by Value</div>
                    <div class="card-body"><div style="height:230px;"><canvas id="snapChartTop"></canvas></div></div>
                </div>
            </div>
        </div>

        <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
            <div class="card-header bg-white border-0">
                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-boxes me-2"></i>Stock Items <small class="text-muted fw-normal">(click a row for its movements)</small></h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 w-100" id="snapTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">S/No</th>
                                <th>Code</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Warehouse</th>
                                <th class="text-end">Qty in Stock</th>
                                <th class="text-end">Cost Value</th>
                                <th class="text-end pe-3">Selling Value</th>
                                <th>pid</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- VIEW 2 — STOCK MOVEMENTS                                       -->
    <!-- ============================================================= -->
    <div id="view-movements" class="inv-view d-none">
        <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-body p-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Direction</label>
                        <div class="btn-group w-100 dir-group" data-target="m-direction" role="group">
                            <button type="button" class="btn btn-outline-primary active" data-val="">All</button>
                            <button type="button" class="btn btn-outline-success" data-val="in">IN</button>
                            <button type="button" class="btn btn-outline-danger" data-val="out">OUT</button>
                        </div>
                        <input type="hidden" id="m-direction" value="">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Type</label>
                        <select id="m-type" class="form-select" style="width:100%">
                            <option value="">All Types</option>
                            <option value="purchase_in">Purchase In</option>
                            <option value="sale_out">Sale Out</option>
                            <option value="adjustment_in">Adjustment In</option>
                            <option value="adjustment_out">Adjustment Out</option>
                            <option value="transfer_in">Transfer In</option>
                            <option value="transfer_out">Transfer Out</option>
                            <option value="issue_out">Issue Out</option>
                            <option value="correction">Correction</option>
                            <option value="damaged">Damaged</option>
                            <option value="expired">Expired</option>
                            <option value="return_in">Return In</option>
                            <option value="return_out">Return Out</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Product</label>
                        <select id="m-product" class="form-select" style="width:100%">
                            <option value="">All Products</option><?= $product_options ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Warehouse</label>
                        <select id="m-warehouse" class="form-select" style="width:100%">
                            <option value="">All Warehouses</option><?= $warehouse_options ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <div>
                            <label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                            <input type="date" id="m-from" class="form-control" value="<?= $year_from ?>">
                        </div>
                        <div>
                            <label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                            <input type="date" id="m-to" class="form-control" value="<?= $year_to ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <?php
            $mv_cards = [['Total IN','mv-in','#198754'],['Total OUT','mv-out','#dc3545'],['Net Change','mv-net','#0d6efd'],['Movements','mv-count','#0d6efd']];
            foreach ($mv_cards as $c): ?>
                <div class="col-6 col-md-3">
                    <div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                        <div class="card-body p-3 text-center">
                            <p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                            <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:<?= $c[2] ?>;">—</h4>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-8">
                <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                    <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Movement Timeline (IN vs OUT)</div>
                    <div class="card-body"><div style="height:230px;"><canvas id="mvChartTimeline"></canvas></div></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                    <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>By Movement Type</div>
                    <div class="card-body"><div style="height:230px;"><canvas id="mvChartType"></canvas></div></div>
                </div>
            </div>
        </div>

        <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
            <div class="card-header bg-white border-0">
                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-arrow-left-right me-2"></i>Movement Ledger</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 w-100" id="mvTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">S/No</th>
                                <th>Date</th>
                                <th class="text-center">Direction</th>
                                <th>Type</th>
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Value</th>
                                <th class="text-end">Balance After</th>
                                <th>Reference #</th>
                                <th class="pe-3">By</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- VIEW 3 — STOCK TRANSFERS                                       -->
    <!-- ============================================================= -->
    <div id="view-transfers" class="inv-view d-none">
        <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-body p-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">From Warehouse</label>
                        <select id="t-from" class="form-select" style="width:100%">
                            <option value="">Any Source</option><?= $warehouse_options ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">To Warehouse</label>
                        <select id="t-to" class="form-select" style="width:100%">
                            <option value="">Any Destination</option><?= $warehouse_options ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Product</label>
                        <select id="t-product" class="form-select" style="width:100%">
                            <option value="">All Products</option><?= $product_options ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Status</label>
                        <select id="t-status" class="form-select" style="width:100%">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <div>
                            <label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                            <input type="date" id="t-from-date" class="form-control" value="<?= $year_from ?>">
                        </div>
                        <div>
                            <label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                            <input type="date" id="t-to-date" class="form-control" value="<?= $year_to ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <?php
            $tf_cards = [['Total Transfers','tf-count'],['Total Qty Moved','tf-qty'],['Total Value','tf-value'],['Completed','tf-done']];
            foreach ($tf_cards as $c): ?>
                <div class="col-6 col-md-3">
                    <div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                        <div class="card-body p-3 text-center">
                            <p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                            <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h4>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-8">
                <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                    <div class="card-header bg-white fw-bold border-0"><i class="bi bi-signpost-split text-primary me-2"></i>Value by Route</div>
                    <div class="card-body"><div style="height:230px;"><canvas id="tfChartRoute"></canvas></div></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                    <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>By Status</div>
                    <div class="card-body"><div style="height:230px;"><canvas id="tfChartStatus"></canvas></div></div>
                </div>
            </div>
        </div>

        <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
            <div class="card-header bg-white border-0">
                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-truck me-2"></i>Transfer Lines</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 w-100" id="tfTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">S/No</th>
                                <th>Date</th>
                                <th>Transfer #</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Product</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Received</th>
                                <th class="text-end">Value</th>
                                <th class="pe-3 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- VIEW 4 — STOCK ADJUSTMENTS                                     -->
    <!-- ============================================================= -->
    <div id="view-adjustments" class="inv-view d-none">
        <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-body p-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Direction</label>
                        <div class="btn-group w-100 dir-group" data-target="a-direction" role="group">
                            <button type="button" class="btn btn-outline-primary active" data-val="">All</button>
                            <button type="button" class="btn btn-outline-success" data-val="in">IN</button>
                            <button type="button" class="btn btn-outline-danger" data-val="out">OUT</button>
                        </div>
                        <input type="hidden" id="a-direction" value="">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Reason</label>
                        <select id="a-reason" class="form-select" style="width:100%">
                            <option value="">All Reasons</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Product</label>
                        <select id="a-product" class="form-select" style="width:100%">
                            <option value="">All Products</option><?= $product_options ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Warehouse</label>
                        <select id="a-warehouse" class="form-select" style="width:100%">
                            <option value="">All Warehouses</option><?= $warehouse_options ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <div>
                            <label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                            <input type="date" id="a-from" class="form-control" value="<?= $year_from ?>">
                        </div>
                        <div>
                            <label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                            <input type="date" id="a-to" class="form-control" value="<?= $year_to ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <?php
            $aj_cards = [['Total Adjustments','aj-count','#0d6efd'],['Qty Added','aj-add','#198754'],['Qty Removed','aj-rem','#dc3545'],['Net','aj-net','#0d6efd']];
            foreach ($aj_cards as $c): ?>
                <div class="col-6 col-md-3">
                    <div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                        <div class="card-body p-3 text-center">
                            <p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                            <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:<?= $c[2] ?>;">—</h4>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-8">
                <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                    <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart text-primary me-2"></i>By Reason</div>
                    <div class="card-body"><div style="height:230px;"><canvas id="ajChartReason"></canvas></div></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                    <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>In vs Out</div>
                    <div class="card-body"><div style="height:230px;"><canvas id="ajChartDir"></canvas></div></div>
                </div>
            </div>
        </div>

        <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
            <div class="card-header bg-white border-0">
                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-sliders me-2"></i>Adjustment Records</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 w-100" id="ajTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">S/No</th>
                                <th>Date</th>
                                <th>Ref #</th>
                                <th>Product</th>
                                <th class="text-center">Direction</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Value</th>
                                <th>Warehouse</th>
                                <th>Reason</th>
                                <th class="pe-3">By</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    .inv-view table thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    .inv-toggle .inv-tab { background:#fff; border:1px solid #b6ccfe; color:#0d6efd; font-weight:600; padding:.5rem 1.1rem; }
    .inv-toggle .inv-tab.active { background:#0d6efd; color:#fff; border-color:#0d6efd; }
    .inv-toggle .inv-tab:not(.active):hover { background:#e7f0ff; }
    #snapTable tbody tr { cursor: pointer; }
    .badge-dir { font-size:.68rem; padding:.35em .6em; border-radius:6px; font-weight:600; }
    @media print {
        .d-print-none, .inv-toggle, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .inv-view .card[style*="border"] { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        canvas { print-color-adjust: exact; -webkit-print-color-adjust: exact; max-width: 100% !important; }
        /* Single-table render so header cells line up with their data on print
           (scrollX is off, so there is no split head/body table to misalign). */
        .table-responsive { overflow: visible !important; }
        .inv-view table { border: 1px solid #000 !important; width: 100% !important; table-layout: auto !important; font-size: 9px !important; }
        .inv-view table th, .inv-view table td { white-space: normal !important; word-break: break-word !important; padding: 4px !important; }
        .inv-view table th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        .inv-view table td { border: 1px solid #dee2e6 !important; }
        .inv-view.d-none { display: none !important; }
    }
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const BLUE = '#0d6efd';
    const blues = ['#0d6efd','#052c65','#6ea8fe','#cfe2ff','#1e3a8a','#9ec5fe','#bfdbfe','#084298'];
    const URLS = {
        snapshot:    '<?= buildUrl('api/account/get_inventory_report.php') ?>',
        movements:   '<?= buildUrl('api/account/get_stock_movements.php') ?>',
        transfers:   '<?= buildUrl('api/account/get_stock_transfers.php') ?>',
        adjustments: '<?= buildUrl('api/account/get_stock_adjustments.php') ?>'
    };
    const TITLES = {
        snapshot:'INVENTORY VALUATION REPORT', movements:'STOCK MOVEMENTS REPORT',
        transfers:'STOCK TRANSFERS REPORT', adjustments:'STOCK ADJUSTMENTS REPORT'
    };
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const num  = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
    const esc  = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const dt   = s => s ? new Date(s).toLocaleDateString() : '—';
    const cap  = s => String(s||'').replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };
    const loaded = { snapshot:false, movements:false, transfers:false, adjustments:false };

    function dirBadge(d) {
        if (d === 'IN')  return '<span class="badge-dir" style="background:#198754;color:#fff;">IN</span>';
        if (d === 'OUT') return '<span class="badge-dir" style="background:#dc3545;color:#fff;">OUT</span>';
        return '<span class="badge-dir" style="background:#adb5bd;color:#fff;">—</span>';
    }
    function statusBadge(s) {
        const k = (s||'').toLowerCase();
        const map = { completed:'#052c65', pending:'#e9ecef', cancelled:'#6c757d' };
        const fg  = k === 'pending' ? '#495057' : '#fff';
        return `<span class="badge-dir" style="background:${map[k]||'#0d6efd'};color:${fg};">${(s||'').toUpperCase()}</span>`;
    }

    // ── View switching (lazy load) ────────────────────────────────────────
    $('.inv-tab').on('click', function () {
        const view = $(this).data('view');
        $('.inv-tab').removeClass('active');
        $(this).addClass('active');
        $('.inv-view').addClass('d-none');
        $('#view-' + view).removeClass('d-none');
        $('#printTitle').text(TITLES[view]);
        if (!loaded[view]) { LOADERS[view](); loaded[view] = true; }
        else { $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust(); }
    });

    // Direction pill groups (movements + adjustments)
    $('.dir-group .btn').on('click', function () {
        const grp = $(this).closest('.dir-group');
        grp.find('.btn').removeClass('active');
        $(this).addClass('active');
        $('#' + grp.data('target')).val($(this).data('val'));
        if (grp.data('target') === 'm-direction') loadMovements();
        else loadAdjustments();
    });

    // ============================ SNAPSHOT ============================
    let snapTable, snapWH, snapStatus, snapTop;
    function initSnapshot() {
        $('#s-product, #s-warehouse, #s-category, #s-stock, #s-project').select2({ theme:'bootstrap-5', allowClear:true, width:'100%' });
        snapTable = $('#snapTable').DataTable({ responsive:false, scrollX:false, pageLength:25, order:[[6,'desc']], dom:'rtip',
            columnDefs:[{ targets:[5,6,7], className:'text-end' }, { targets:[8], visible:false }], language:{ emptyTable:'No stock items found.' } });
        $('#snapForm').on('submit', e => { e.preventDefault(); loadSnapshot(); });
        $('#s-product, #s-warehouse, #s-category, #s-stock, #s-project').on('change', loadSnapshot);
        // Drill-down: click a stock row → Movements pre-filtered to that product.
        $('#snapTable tbody').on('click', 'tr', function () {
            const d = snapTable.row(this).data(); if (!d || !d[8]) return;
            $('.inv-tab[data-view="movements"]').click();
            $('#m-product').val(d[8]).trigger('change');
        });
        loadSnapshot();
    }
    function loadSnapshot() {
        $.getJSON(URLS.snapshot, {
            product_id:$('#s-product').val()||'', warehouse_id:$('#s-warehouse').val()||'',
            category_id:$('#s-category').val()||'', stock_status:$('#s-stock').val()||'', project_id:$('#s-project').val()||''
        }).done(res => {
            if (!res || !res.success) { Swal.fire({icon:'error',title:'Error',text:(res&&res.message)||'Load failed.'}); return; }
            $('#stat-skus').text(Number(res.summary.total_skus).toLocaleString());
            $('#stat-wh').text(Number(res.summary.warehouse_count).toLocaleString());
            $('#stat-cost').text(fmt(res.summary.total_cost_value));
            $('#stat-sell').text(fmt(res.summary.total_selling_value));
            [snapWH,snapStatus,snapTop].forEach(c=>c&&c.destroy());
            snapWH = new Chart(snapChartWarehouse, { type:'bar', data:{ labels:res.charts.by_warehouse.map(r=>r.name), datasets:[{label:'Cost Value',data:res.charts.by_warehouse.map(r=>+r.total),backgroundColor:blues}] }, options:{...baseOpts,plugins:{legend:{display:false}}} });
            snapStatus = new Chart(snapChartStatus, { type:'doughnut', data:{ labels:res.charts.stock_status.map(r=>r.label), datasets:[{data:res.charts.stock_status.map(r=>+r.value),backgroundColor:['#052c65','#6ea8fe','#dc3545']}] }, options:{...baseOpts} });
            snapTop = new Chart(snapChartTop, { type:'bar', data:{ labels:res.charts.top_items.map(r=>r.name), datasets:[{label:'Cost Value',data:res.charts.top_items.map(r=>+r.total),backgroundColor:BLUE}] }, options:{...baseOpts,indexAxis:'y',plugins:{legend:{display:false}}} });
            snapTable.clear();
            res.rows.forEach((r,i)=>snapTable.row.add([ i+1, esc(r.product_code||'—'), esc(r.product_name||'—'), esc(r.category||'Uncategorised'), esc(r.warehouse_name), num(r.current_stock), fmt(r.cost_value), fmt(r.selling_value), r.product_id||'' ]));
            snapTable.draw();
        }).fail(()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'}));
    }

    // ============================ MOVEMENTS ============================
    let mvTable, mvTimeline, mvType;
    function initMovements() {
        $('#m-type, #m-product, #m-warehouse').select2({ theme:'bootstrap-5', allowClear:true, width:'100%' });
        mvTable = $('#mvTable').DataTable({ responsive:false, scrollX:false, pageLength:25, order:[], dom:'rtip',
            columnDefs:[{ targets:[6,7,8], className:'text-end' }, { targets:[2], className:'text-center' }], language:{ emptyTable:'No movements found.' } });
        $('#m-type, #m-product, #m-warehouse').on('change', loadMovements);
        $('#m-from, #m-to').on('change', loadMovements);
        loadMovements();
    }
    function loadMovements() {
        $.getJSON(URLS.movements, {
            direction:$('#m-direction').val()||'', movement_type:$('#m-type').val()||'',
            product_id:$('#m-product').val()||'', warehouse_id:$('#m-warehouse').val()||'',
            date_from:$('#m-from').val(), date_to:$('#m-to').val()
        }).done(res => {
            if (!res || !res.success) { Swal.fire({icon:'error',title:'Error',text:(res&&res.message)||'Load failed.'}); return; }
            $('#mv-in').text(num(res.summary.total_in));
            $('#mv-out').text(num(res.summary.total_out));
            $('#mv-net').text(num(res.summary.net_change));
            $('#mv-count').text(Number(res.summary.movement_count).toLocaleString());
            [mvTimeline,mvType].forEach(c=>c&&c.destroy());
            mvTimeline = new Chart(mvChartTimeline, { type:'bar', data:{ labels:res.charts.timeline.map(r=>r.label),
                datasets:[{label:'IN',data:res.charts.timeline.map(r=>+r.in_qty),backgroundColor:'#198754'},{label:'OUT',data:res.charts.timeline.map(r=>+r.out_qty),backgroundColor:'#dc3545'}] }, options:{...baseOpts} });
            mvType = new Chart(mvChartType, { type:'doughnut', data:{ labels:res.charts.by_type.map(r=>cap(r.name)), datasets:[{data:res.charts.by_type.map(r=>+r.qty),backgroundColor:blues}] }, options:{...baseOpts} });
            mvTable.clear();
            res.rows.forEach((r,i)=>mvTable.row.add([ i+1, dt(r.movement_date), dirBadge(r.direction), cap(r.movement_type), esc(r.product_name), esc(r.warehouse_name), num(r.quantity)+' '+esc(r.unit), fmt(r.value), num(r.stock_after), esc(r.reference_number), esc(r.recorded_by) ]));
            mvTable.draw();
        }).fail(()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'}));
    }

    // ============================ TRANSFERS ============================
    let tfTable, tfRoute, tfStatus;
    function initTransfers() {
        $('#t-from, #t-to, #t-product, #t-status').select2({ theme:'bootstrap-5', allowClear:true, width:'100%' });
        tfTable = $('#tfTable').DataTable({ responsive:false, scrollX:false, pageLength:25, order:[], dom:'rtip',
            columnDefs:[{ targets:[6,7,8], className:'text-end' }, { targets:[9], className:'text-center' }], language:{ emptyTable:'No transfers found.' } });
        $('#t-from, #t-to, #t-product, #t-status').on('change', loadTransfers);
        $('#t-from-date, #t-to-date').on('change', loadTransfers);
        loadTransfers();
    }
    function loadTransfers() {
        $.getJSON(URLS.transfers, {
            from_warehouse_id:$('#t-from').val()||'', to_warehouse_id:$('#t-to').val()||'',
            product_id:$('#t-product').val()||'', status:$('#t-status').val()||'',
            date_from:$('#t-from-date').val(), date_to:$('#t-to-date').val()
        }).done(res => {
            if (!res || !res.success) { Swal.fire({icon:'error',title:'Error',text:(res&&res.message)||'Load failed.'}); return; }
            $('#tf-count').text(Number(res.summary.transfer_count).toLocaleString());
            $('#tf-qty').text(num(res.summary.total_qty));
            $('#tf-value').text(fmt(res.summary.total_value));
            $('#tf-done').text(Number(res.summary.completed_count).toLocaleString());
            [tfRoute,tfStatus].forEach(c=>c&&c.destroy());
            tfRoute = new Chart(tfChartRoute, { type:'bar', data:{ labels:res.charts.by_route.map(r=>r.name), datasets:[{label:'Value',data:res.charts.by_route.map(r=>+r.total),backgroundColor:BLUE}] }, options:{...baseOpts,indexAxis:'y',plugins:{legend:{display:false}}} });
            tfStatus = new Chart(tfChartStatus, { type:'doughnut', data:{ labels:res.charts.by_status.map(r=>cap(r.name)), datasets:[{data:res.charts.by_status.map(r=>+r.count),backgroundColor:blues}] }, options:{...baseOpts} });
            tfTable.clear();
            res.rows.forEach((r,i)=>tfTable.row.add([ i+1, dt(r.transfer_date), esc(r.transfer_number), esc(r.from_warehouse), esc(r.to_warehouse), esc(r.product_name), num(r.quantity)+' '+esc(r.unit), num(r.received_quantity), fmt(r.value), statusBadge(r.status) ]));
            tfTable.draw();
        }).fail(()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'}));
    }

    // ============================ ADJUSTMENTS ============================
    let ajTable, ajReason, ajDir, ajReasonsLoaded = false;
    function initAdjustments() {
        $('#a-reason, #a-product, #a-warehouse').select2({ theme:'bootstrap-5', allowClear:true, width:'100%' });
        ajTable = $('#ajTable').DataTable({ responsive:false, scrollX:false, pageLength:25, order:[], dom:'rtip',
            columnDefs:[{ targets:[5,6], className:'text-end' }, { targets:[4], className:'text-center' }], language:{ emptyTable:'No adjustments found.' } });
        $('#a-reason, #a-product, #a-warehouse').on('change', loadAdjustments);
        $('#a-from, #a-to').on('change', loadAdjustments);
        loadAdjustments();
    }
    function loadAdjustments() {
        $.getJSON(URLS.adjustments, {
            direction:$('#a-direction').val()||'', reason:$('#a-reason').val()||'',
            product_id:$('#a-product').val()||'', warehouse_id:$('#a-warehouse').val()||'',
            date_from:$('#a-from').val(), date_to:$('#a-to').val()
        }).done(res => {
            if (!res || !res.success) { Swal.fire({icon:'error',title:'Error',text:(res&&res.message)||'Load failed.'}); return; }
            $('#aj-count').text(Number(res.summary.adj_count).toLocaleString());
            $('#aj-add').text(num(res.summary.qty_added));
            $('#aj-rem').text(num(res.summary.qty_removed));
            $('#aj-net').text(num(res.summary.net));
            if (!ajReasonsLoaded && res.reasons) {
                const sel = $('#a-reason');
                res.reasons.forEach(r => sel.append(new Option(cap(r), r)));
                ajReasonsLoaded = true;
            }
            [ajReason,ajDir].forEach(c=>c&&c.destroy());
            ajReason = new Chart(ajChartReason, { type:'bar', data:{ labels:res.charts.by_reason.map(r=>cap(r.name)), datasets:[{label:'Qty',data:res.charts.by_reason.map(r=>+r.qty),backgroundColor:BLUE}] }, options:{...baseOpts,indexAxis:'y',plugins:{legend:{display:false}}} });
            ajDir = new Chart(ajChartDir, { type:'doughnut', data:{ labels:res.charts.by_direction.map(r=>r.name), datasets:[{data:res.charts.by_direction.map(r=>+r.count),backgroundColor:['#198754','#dc3545']}] }, options:{...baseOpts} });
            ajTable.clear();
            res.rows.forEach((r,i)=>ajTable.row.add([ i+1, dt(r.movement_date), esc(r.reference_number), esc(r.product_name), dirBadge(r.direction), num(r.quantity)+' '+esc(r.unit), fmt(r.value), esc(r.warehouse_name), esc(r.reason), esc(r.recorded_by) ]));
            ajTable.draw();
        }).fail(()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'}));
    }

    const LOADERS = { snapshot:initSnapshot, movements:initMovements, transfers:initTransfers, adjustments:initAdjustments };

    // Snapshot is the default — load it now.
    initSnapshot();
    loaded.snapshot = true;
    if (typeof logReportAction === 'function') logReportAction('Viewed Inventory Report', 'Loaded inventory report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
