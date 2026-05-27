<?php
// File: app/bms/stock/warehouse_view.php
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('warehouses');
logActivity($pdo, $_SESSION['user_id'], 'VIEW', '[Warehouse View] Page viewed');

// Include the header
includeHeader();


// Get warehouse ID
$warehouse_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

if ($warehouse_id <= 0) {
    $_SESSION['error'] = "Invalid warehouse ID";
    header("Location: warehouses.php");
    exit();
}
assertScopeForRecordHtml('warehouses', 'warehouse_id', $warehouse_id);

// Fetch warehouse details
$query = "
    SELECT w.*, 
           u_c.username as creator_name,
           u_u.username as updater_name
    FROM warehouses w
    LEFT JOIN users u_c ON w.created_by = u_c.user_id
    LEFT JOIN users u_u ON w.updated_by = u_u.user_id
    WHERE w.warehouse_id = ? AND w.status != 'deleted'
";
$stmt = $pdo->prepare($query);
$stmt->execute([$warehouse_id]);
$warehouse = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$warehouse) {
    $_SESSION['error'] = "Warehouse not found";
    header("Location: warehouses.php");
    exit();
}

// Fetch locations for this warehouse
$query = "SELECT * FROM locations WHERE warehouse_id = ? AND status != 'deleted' ORDER BY location_name";
$stmt = $pdo->prepare($query);
$stmt->execute([$warehouse_id]);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch current stock in this warehouse
$query = "
    SELECT ps.*, p.product_name, p.sku, p.product_code, p.cost_price, p.selling_price, 
           c.category_name, b.brand_name, l.location_name
    FROM product_stocks ps
    JOIN products p ON ps.product_id = p.product_id
    LEFT JOIN categories c ON p.category_id = c.category_id
    LEFT JOIN brands b ON p.brand_id = b.brand_id
    LEFT JOIN locations l ON ps.location_id = l.location_id
    WHERE ps.warehouse_id = ?
    ORDER BY p.product_name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$warehouse_id]);
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_items = count($stocks);
$total_quantity = array_sum(array_column($stocks, 'stock_quantity'));
$total_value_cost = 0;
foreach ($stocks as $s) {
    $total_value_cost += $s['stock_quantity'] * $s['cost_price'];
}

?>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4">

        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">WAREHOUSE INVENTORY REPORT</h2>
        <h5 class="text-muted"><?= htmlspecialchars($warehouse['warehouse_name']) ?> (<?= htmlspecialchars($warehouse['warehouse_code']) ?>)</h5>
        <p class="small text-muted">Generated on: <?= date('F d, Y H:i') ?></p>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Breadcrumb & Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <?php if($project_id > 0): ?>
                        <li class="breadcrumb-item"><a href="<?= getUrl('project_view') . '?id=' . $project_id . '#procurements' ?>">Project Details</a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item"><a href="warehouses.php">Warehouses</a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($warehouse['warehouse_name']) ?></li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-house-door text-success"></i> <?= htmlspecialchars($warehouse['warehouse_name'] ?? '') ?></h2>
                    <p class="text-muted mb-0">Code: <span class="badge bg-light text-dark"><?= htmlspecialchars($warehouse['warehouse_code'] ?? '') ?></span></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= $project_id > 0 ? getUrl('project_view') . '?id=' . $project_id . '#procurements' : getUrl('warehouses') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> <?= $project_id > 0 ? 'Back to Project' : 'Back to List' ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-5 g-4">
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100 p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-geo-alt"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= count($locations) ?></h4>
                        <small class="text-uppercase small fw-bold">Active Locations</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100 p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-box"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($total_items) ?></h4>
                        <small class="text-uppercase small fw-bold">Total Stock Item</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100 p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-layers"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($total_quantity, 0) ?></h4>
                        <small class="text-uppercase small fw-bold">Total Quantity</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100 p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-cash-stack"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= format_currency($total_value_cost) ?></h4>
                        <small class="text-uppercase small fw-bold">Stock Value (Cost)</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Sidebar / Details -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Warehouse Information</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">Status</span>
                            <?= get_status_badge($warehouse['status']) ?>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">Primary</span>
                            <span><?= $warehouse['is_primary'] ? '<span class="text-primary fw-bold">Yes</span>' : 'No' ?></span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">Capacity</span>
                            <span><?= $warehouse['capacity'] ? number_format($warehouse['capacity'], 0) . ' units' : 'N/A' ?></span>
                        </li>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block mb-1">Location</span>
                            <strong><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($warehouse['location'] ?: 'Not specified') ?></strong>
                        </li>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block mb-1">Full Address</span>
                            <span><?= nl2br(htmlspecialchars($warehouse['address'] ?: 'No address provided')) ?></span>
                        </li>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block mb-1">Contact Person</span>
                            <strong><?= htmlspecialchars($warehouse['contact_person'] ?: 'N/A') ?></strong>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">Phone</span>
                            <span><?= htmlspecialchars($warehouse['phone'] ?: 'N/A') ?></span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">Email</span>
                            <span><?= htmlspecialchars($warehouse['email'] ?: 'N/A') ?></span>
                        </li>
                    </ul>
                </div>
                <div class="card-footer bg-light">
                    <small class="text-muted">Created by: <?= htmlspecialchars($warehouse['creator_name'] ?: 'System') ?> (<?= date('M d, Y', strtotime($warehouse['created_at'])) ?>)</small>
                </div>
            </div>

            <!-- Locations List -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Storage Locations</h5>
                    <button class="btn btn-sm btn-link" onclick="manageLocations(<?= $warehouse_id ?>)">Manage</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Location Name</th>
                                    <th>Code</th>
                                    <th class="text-center">Active</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($locations)): ?>
                                    <tr><td colspan="3" class="text-center py-3">No locations defined</td></tr>
                                <?php else: ?>
                                    <?php foreach ($locations as $loc): ?>
                                        <tr>
                                            <td class="ps-3"><?= htmlspecialchars($loc['location_name'] ?? '') ?></td>
                                            <td><code class="text-dark"><?= htmlspecialchars($loc['location_code'] ?? '') ?></code></td>
                                            <td class="text-center">
                                                <span class="status-dot <?= ($loc['status'] ?? '') == 'active' ? 'active-dot' : 'inactive-dot' ?>"></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content (Stock List) -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 d-print-none">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-box-seam text-success"></i> Current Inventory</h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-success" onclick="transferStock(<?= $warehouse_id ?>)">
                            <i class="bi bi-truck"></i> Transfer Stock
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Actions Row -->
                    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                        <div class="d-flex align-items-center gap-3">
                            <div class="btn-group dropdown shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; color: #444;">
                                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                                </button>
                                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportTable()" style="background: #fff; color: #444;">
                                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                                </button>
                                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="window.print()" style="background: #fff; color: #444;">
                                    <i class="bi bi-printer text-primary me-1"></i> Print
                                </button>
                            </div>

                            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                                <select class="form-select form-select-sm border-0 fw-bold p-0" id="tableLength" style="width: 60px; box-shadow: none; background: transparent;">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="-1">All</option>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <div class="input-group input-group-sm shadow-sm" style="width: 250px;">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" id="customSearch" class="form-control border-start-0" placeholder="Search inventory...">
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="warehouseStockTable">
                            <thead class="table-light">
                                <tr>
                                    <th>S/NO</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Location</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-end">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stocks as $index => $s): ?>
                                <?php $s_value = $s['stock_quantity'] * $s['cost_price']; ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($s['product_name'] ?? '') ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($s['category_name'] ?? 'N/A') ?> | <?= htmlspecialchars($s['brand_name'] ?? 'N/A') ?></small>
                                    </td>
                                    <td><code class="custom-code text-dark"><?= htmlspecialchars(($s['sku'] ?: $s['product_code']) ?? '') ?></code></td>
                                    <td>
                                        <span class="badge bg-light text-dark"><i class="bi bi-geo"></i> <?= htmlspecialchars(($s['location_name'] ?: 'Default') ?? '') ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bold"><?= format_number($s['stock_quantity'], 0) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <?= format_currency($s_value) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="4" class="text-end">Total:</td>
                                    <td class="text-center"><?= number_format($total_quantity, 0) ?></td>
                                    <td class="text-end"><?= format_currency($total_value_cost) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card small,
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}

.stats-icon {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-right: 1.25rem;
    background: rgba(15, 81, 50, 0.1);
    color: #0f5132 !important;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.active-dot { background-color: #198754; }
.inactive-dot { background-color: #dc3545; }
.custom-code {
    background: #f8f9fa;
    padding: 2px 5px;
    border-radius: 4px;
}

@page { margin: 10mm 8mm 16mm 8mm; }
@media print {
    .d-print-none, .breadcrumb, .btn, .btn-group, .col-lg-4, .card-header,
    .dataTables_info, .dataTables_paginate, .dataTables_length, .dataTables_filter,
    footer {
        display: none !important;
    }
    .col-lg-8 { 
        width: 100% !important; 
        flex: 0 0 100% !important; 
        max-width: 100% !important; 
    }
    .card { 
        box-shadow: none !important; 
        border: 1px solid #dee2e6 !important; 
    }
    body { background: white !important; }
    
    /* Stats cards in a single row for print */
    .row.mb-5.g-4 {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        gap: 10px !important;
    }
    .row.mb-5.g-4 .col-md-3 {
        width: 25% !important;
        flex: 0 0 25% !important;
        max-width: 25% !important;
    }
    .custom-stat-card {
        padding: 5px !important;
        background-color: #d1e7dd !important; 
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        min-height: 80px !important;
    }
    .stats-icon {
        font-size: 1rem !important;
        margin-right: 0.5rem !important;
    }
    .custom-stat-card h4 {
        font-size: 1.2rem !important;
    }
    .custom-stat-card small {
        font-size: 0.65rem !important;
    }
}
</style>

<script>
$(document).ready(function() {
    const table = $('#warehouseStockTable').DataTable({
        pageLength: 25,
        order: [[0, 'asc']],
        dom: 'rtip',
        language: {
            search: "Filter Stock:"
        }
    });

    // Custom Search
    $('#customSearch').on('keyup', function() {
        table.search(this.value).draw();
    });

    // Custom Length
    $('#tableLength').on('change', function() {
        table.page.len(this.value).draw();
    });
});

function copyTable() {
    const table = document.getElementById('warehouseStockTable');
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table data copied to clipboard', timer: 1000, showConfirmButton: false });
}

function exportTable() {
    const table = document.getElementById('warehouseStockTable');
    let csv = [];
    const rows = table.querySelectorAll("tr");
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length; j++) row.push('"' + cols[j].innerText.trim() + '"');
        csv.push(row.join(","));
    }
    const csv_string = csv.join("\n");
    const link = document.createElement("a");
    link.style.display = 'none';
    link.setAttribute('target', '_blank');
    link.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv_string));
    link.setAttribute('download', 'Warehouse_Inventory.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function manageLocations(id) {
    location.href = 'locations?warehouse_id=' + id + '<?= $project_id > 0 ? "&project_id=$project_id" : "" ?>';
}

function transferStock(id) {
    location.href = 'stock_transfers?warehouse_id=' + id + '<?= $project_id > 0 ? "&project_id=$project_id" : "" ?>';
}
</script>

<?php
includeFooter();
ob_end_flush();
?>
