<?php
// File: app/bms/stock/inventory_valuation.php
// scope-audit: skip — global inventory valuation report; product/stock scope deferred to Phase G-2
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';

// Get user role for permissions (extracted from header.php logic)
$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id > 0) {
    $role_stmt = $pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
    $role_stmt->execute([$user_id]);
    $role_data = $role_stmt->fetch();
    $user_role = $role_data['role_name'] ?? 'user';
} else {
    header("Location: login.php");
    exit();
}

require_once HEADER_FILE;

// Check user permissions
requireViewPermission('products');

// Get filter parameters
$warehouse_id = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;
$category_id = isset($_GET['category']) ? intval($_GET['category']) : 0;
$valuation_method = isset($_GET['method']) ? $_GET['method'] : 'average_cost';
$as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');

// Get warehouses for filter — shared helper, also respects the user's direct
// warehouse grant (Phase 6, pos_upgrade_plan.md).
$warehouses = warehousesForSelect($pdo);

if ($warehouse_id > 0 && !userCan('warehouse', $warehouse_id)) {
    $warehouse_id = 0; // ignore an out-of-scope filter rather than error on a report page
}

// Get categories for filter
$categories_query = "SELECT category_id, category_name FROM categories WHERE status = 'active' AND type = 'product' ORDER BY category_name";
$categories = $pdo->query($categories_query)->fetchAll(PDO::FETCH_ASSOC);

// Build inventory valuation query
$query = "
    SELECT 
        p.product_id,
        p.product_name,
        p.sku,
        p.cost_price,
        p.selling_price,
        c.category_name,
        b.brand_name,
        COALESCE(SUM(ps.stock_quantity), 0) as total_quantity,
        COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) as available_quantity,
        COALESCE(SUM(ps.reserved_quantity), 0) as reserved_quantity,
        p.cost_price * COALESCE(SUM(ps.stock_quantity), 0) as total_cost_value,
        p.selling_price * COALESCE(SUM(ps.stock_quantity), 0) as total_selling_value,
        (p.selling_price - p.cost_price) * COALESCE(SUM(ps.stock_quantity), 0) as potential_profit
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    LEFT JOIN brands b ON p.brand_id = b.brand_id
    LEFT JOIN product_stocks ps ON p.product_id = ps.product_id
    WHERE p.status = 'active'
";

$params = [];

if ($warehouse_id > 0) {
    $query .= " AND ps.warehouse_id = ?";
    $params[] = $warehouse_id;
} else {
    // Phase 6 (pos_upgrade_plan.md): default scope when no specific
    // warehouse was chosen.
    $query .= scopeFilterSqlNullable('warehouse', 'ps');
}

if ($category_id > 0) {
    $query .= " AND p.category_id = ?";
    $params[] = $category_id;
}

$query .= " GROUP BY p.product_id, p.product_name, p.sku, p.cost_price, p.selling_price, c.category_name, b.brand_name";
$query .= " HAVING total_quantity > 0";
$query .= " ORDER BY total_cost_value DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary statistics
$total_items = count($inventory_items);
$total_cost_value = array_sum(array_column($inventory_items, 'total_cost_value'));
$total_selling_value = array_sum(array_column($inventory_items, 'total_selling_value'));
$total_potential_profit = array_sum(array_column($inventory_items, 'potential_profit'));
$total_quantity = array_sum(array_column($inventory_items, 'total_quantity'));
?>

<!-- Print Header (only visible when printing) -->
<div class="d-none d-print-block">
    <div style="text-align:center; padding: 10px 0; border-bottom: 3px solid #0d6efd; margin-bottom: 15px;">

       

        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">
            Inventory Valuation Report
        </h2>

        <p style="color: #000; margin: 0; font-size: 10pt;">
            Report Date: <?= date('d M Y, H:i') ?>

            <?php if ($warehouse_id > 0): ?>
                | Warehouse: <?php 
                    $wh = array_filter($warehouses, function($w) use ($warehouse_id) { 
                        return $w['warehouse_id'] == $warehouse_id; 
                    });
                    echo htmlspecialchars(reset($wh)['warehouse_name'] ?? 'All');
                ?>
            <?php endif; ?>
        </p>

    </div>
</div>

<!-- Print Summary Cards -->
<div class="summary-print d-none d-print-flex">
    <div class="summary-item">
        <div class="summary-label">Total Items</div>
        <div class="summary-value"><?= number_format($total_items) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-label">Total Quantity</div>
        <div class="summary-value"><?= format_number($total_quantity, 0) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-label">Cost Value</div>
        <div class="summary-value"><?= format_currency($total_cost_value) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-label">Selling Value</div>
        <div class="summary-value"><?= format_currency($total_selling_value) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-label">Potential Profit</div>
        <div class="summary-value"><?= format_currency($total_potential_profit) ?></div>
    </div>
</div>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('products') ?>">Inventory</a></li>
            <li class="breadcrumb-item active">Inventory Valuation</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="bi bi-calculator-fill text-primary"></i> Inventory Valuation</h2>
                    <p class="text-muted mb-0">Total value and stock assessment of your inventory</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4 d-print-none">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= number_format($total_items) ?></h4>
                            <p class="mb-0">Total Items</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_number($total_quantity, 0) ?></h4>
                            <p class="mb-0">Total Quantity</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-boxes" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($total_cost_value) ?></h4>
                            <p class="mb-0">Cost Value</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-currency-dollar" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($total_selling_value) ?></h4>
                            <p class="mb-0">Selling Value</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-graph-up-arrow" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 d-print-none">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Parameters</h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Warehouse</label>
                    <select class="form-select" name="warehouse">
                        <option value="0">All Warehouses</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                            <option value="<?= $warehouse['warehouse_id'] ?>" <?= $warehouse_id == $warehouse['warehouse_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($warehouse['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select class="form-select" name="category">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['category_id'] ?>" <?= $category_id == $category['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Valuation Method</label>
                    <select class="form-select" name="method">
                        <option value="average_cost" <?= $valuation_method == 'average_cost' ? 'selected' : '' ?>>Average Cost</option>
                        <option value="fifo" <?= $valuation_method == 'fifo' ? 'selected' : '' ?>>FIFO</option>
                        <option value="lifo" <?= $valuation_method == 'lifo' ? 'selected' : '' ?>>LIFO</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">As of Date</label>
                    <input type="date" class="form-control" name="as_of_date" value="<?= $as_of_date ?>">
                </div>

                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-search"></i> Apply Filters
                    </button>
                    <a href="inventory_valuation.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; color: #444;">
                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportToExcel()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="logReportAction('Printed Inventory Valuation', 'User generated a printed inventory valuation report'); window.print()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
        </div>
        <div>
            <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill">
                <i class="bi bi-check-circle-fill me-1"></i> <?= number_format($total_items) ?> items
            </span>
        </div>
    </div>

    <!-- Inventory Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom">
            <h5 class="mb-0"><i class="bi bi-table"></i> Inventory Details</h5>
        </div>
        <div class="card-body">
            <?php if (count($inventory_items) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="inventoryTable">
                        <thead class="table-light">
                            <tr>
                                <th>S/NO</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Category</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Cost Price</th>
                                <th class="text-end">Selling Price</th>
                                <th class="text-end">Cost Value</th>
                                <th class="text-end">Selling Value</th>
                                <th class="text-end">Potential Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory_items as $index => $item): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                    <?php if (!empty($item['brand_name'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($item['brand_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($item['sku'] ?? '') ?></code></td>
                                <td><?= htmlspecialchars($item['category_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= format_number($item['total_quantity'], 0) ?></td>
                                <td class="text-end"><?= format_currency($item['cost_price']) ?></td>
                                <td class="text-end"><?= format_currency($item['selling_price']) ?></td>
                                <td class="text-end fw-bold text-danger"><?= format_currency($item['total_cost_value']) ?></td>
                                <td class="text-end fw-bold text-success"><?= format_currency($item['total_selling_value']) ?></td>
                                <td class="text-end fw-bold text-primary"><?= format_currency($item['potential_profit']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">TOTAL:</td>
                                <td class="text-end"><?= format_number($total_quantity, 0) ?></td>
                                <td colspan="2"></td>
                                <td class="text-end text-danger"><?= format_currency($total_cost_value) ?></td>
                                <td class="text-end text-success"><?= format_currency($total_selling_value) ?></td>
                                <td class="text-end text-primary"><?= format_currency($total_potential_profit) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 4rem; color: #ccc;"></i>
                    <h4 class="mt-3">No Inventory Data</h4>
                    <p class="text-muted">No products found with the selected filters.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyTable() {
    logReportAction('Copied Inventory Valuation Table', 'User copied the inventory valuation data to clipboard');
    let table = document.getElementById('inventoryTable');
    let range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    try {
        document.execCommand('copy');
        Swal.fire({ icon: 'success', title: 'Copied!', text: 'Inventory table data copied to clipboard', timer: 1500, showConfirmButton: false });
    } catch (err) {
        console.error('Unable to copy', err);
    }
    window.getSelection().removeAllRanges();
}

function exportToExcel() {
    logReportAction('Exported Inventory Valuation', 'User exported inventory valuation to CSV/Excel');
    // Simple export to CSV
    let csv = 'Product,SKU,Category,Quantity,Cost Price,Selling Price,Cost Value,Selling Value,Potential Profit\n';
    
    <?php foreach ($inventory_items as $item): 
        $name     = addslashes(str_replace(["\r","\n",'"'], ['','','""'], $item['product_name']));
        $sku      = addslashes(str_replace(["\r","\n",'"'], ['','','""'], $item['sku'] ?? ''));
        $cat      = addslashes(str_replace(["\r","\n",'"'], ['','','""'], $item['category_name'] ?? 'N/A'));
    ?>
    csv += `"<?= $name ?>","<?= $sku ?>","<?= $cat ?>",<?= (float)$item['total_quantity'] ?>,<?= (float)$item['cost_price'] ?>,<?= (float)$item['selling_price'] ?>,<?= (float)$item['total_cost_value'] ?>,<?= (float)$item['total_selling_value'] ?>,<?= (float)$item['potential_profit'] ?>\n`;
    <?php endforeach; ?>
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', 'inventory_valuation_<?= date('Y-m-d') ?>.csv');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// Initialize DataTable
$(document).ready(function() {
    logReportAction('Viewed Inventory Valuation', 'User viewed the inventory valuation report');
    
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('warehouse') || urlParams.has('category')) {
        logReportAction('Filtered Inventory Valuation', 'User applied filters to inventory valuation');
    }

    $('#inventoryTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[7, 'desc']], // Sort by cost value
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        language: {
            lengthMenu: "Show _MENU_ entries",
            search: "Search:",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "Showing 0 to 0 of 0 entries",
            infoFiltered: "(filtered from _MAX_ total entries)",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        responsive: true,
        autoWidth: false
    });
});
</script>

<style>
@media print {
    /* Hide unnecessary elements */
    .btn, 
    .card-header, 
    nav, 
    .navbar,
    footer,
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate,
    .dt-buttons,
    .no-print {
        display: none !important;
    }
    
    /* Remove card styling */
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    
    .card-body {
        padding: 0 !important;
    }
    
    /* Clean table styling */
    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    
    table thead {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    table th,
    table td {
        border: 1px solid #dee2e6 !important;
        padding: 8px !important;
        font-size: 11px !important;
    }
    
    table tfoot {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        font-weight: bold !important;
    }
    
    /* Print header */
    .print-header {
        display: block !important;
        text-align: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #000;
    }
    
    .print-header h1 {
        font-size: 24px;
        margin: 0;
        font-weight: bold;
    }
    
    .print-header h2 {
        font-size: 18px;
        margin: 10px 0;
        color: #666;
    }
    
    .print-header .print-date {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
    }
    
    /* Page setup */
    @page {
        margin: 0.8cm;
        size: A4 landscape;
    }
    
    body {
        margin: 0;
        padding: 0;
        background: white !important;
        font-size: 10pt !important;
    }
    
    .container-fluid {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    /* Summary cards for print style */
    .summary-print {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        gap: 10px !important;
        margin-bottom: 20px !important;
        width: 100% !important;
    }
    
    .summary-print .summary-item {
        flex: 1 !important;
        text-align: center !important;
        padding: 10px 5px !important;
        background-color: #d1e7dd !important;
        border: 1px solid #badbcc !important;
        border-radius: 10px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .summary-print .summary-label {
        font-size: 8pt !important;
        color: #0f5132 !important;
        text-transform: uppercase !important;
        font-weight: 700 !important;
        margin-bottom: 2px !important;
    }
    
    .summary-print .summary-value {
        font-size: 11pt !important;
        font-weight: 800 !important;
        color: #000 !important;
    }
    
    /* Hide screen-only summary cards */
    .row.mb-4 {
        display: none !important;
    }
}

/* Screen-only elements */
.print-header {
    display: none;
}

.summary-print {
    display: none;
}

/* Custom stat cards styling */
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}

.custom-stat-card:hover {
    transform: translateY(-3px);
}

.custom-stat-card h4,
.custom-stat-card small {
    color: #0f5132 !important;
    font-weight: 600;
}

.bg-success-soft {
    background-color: rgba(25, 135, 84, 0.1) !important;
}
</style>

<?php
includeFooter();
ob_end_flush();
?>
