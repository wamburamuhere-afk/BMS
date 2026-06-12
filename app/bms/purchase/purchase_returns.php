<?php
// File: purchase_returns.php
// scope-audit: skip — stat counts only in PHP; list data loaded via AJAX from api/get_purchase_returns.php which is scoped (Phase G); stat queries deferred to Phase G-2
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('purchase_returns');

includeHeader();

// Get permissions for JS
$can_create = canCreate('purchase_returns') ? 'true' : 'false';
$can_delete = hasPermission('delete_purchase_returns') ? 'true' : 'false';
$can_approve = hasPermission('approve_purchase_returns') ? 'true' : 'false';

// Get suppliers and warehouses for filter dropdowns — scoped by project for non-admins
$_pr_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
if (isAdmin()) {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_pr_assigned)) {
    $_pr_ph = implode(',', array_fill(0, count($_pr_assigned), '?'));
    $_pr_ss = $pdo->prepare("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_pr_ph)) ORDER BY supplier_name");
    $_pr_ss->execute($_pr_assigned);
    $suppliers = $_pr_ss->fetchAll(PDO::FETCH_ASSOC);
    $_pr_ws = $pdo->prepare("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_pr_ph)) ORDER BY warehouse_name");
    $_pr_ws->execute($_pr_assigned);
    $warehouses = $_pr_ws->fetchAll(PDO::FETCH_ASSOC);
} else {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' AND project_id IS NULL ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' AND project_id IS NULL ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch initial stats for first paint and print
$stats_counts = $pdo->query("
    SELECT 
        status,
        COUNT(*) as count 
    FROM purchase_returns 
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$total_returns = $pdo->query("SELECT COUNT(*) FROM purchase_returns")->fetchColumn();

$total_value = $pdo->query("
    SELECT SUM(pri.quantity * pri.unit_price) 
    FROM purchase_return_items pri
    JOIN purchase_returns pr ON pri.purchase_return_id = pr.purchase_return_id
    WHERE pr.status != 'cancelled'
")->fetchColumn();

$initial_stats = [
    'total_returns' => $total_returns ?: 0,
    'pending' => $stats_counts['pending'] ?? 0,
    'approved' => $stats_counts['approved'] ?? 0,
    'completed' => $stats_counts['completed'] ?? 0,
    'rejected' => $stats_counts['rejected'] ?? 0,
    'total_value' => floatval($total_value ?: 0)
];
?>

<style>
@media (max-width: 767px) {
    .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
}

.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 0;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}
.bg-success-soft {
    background-color: rgba(25, 135, 84, 0.1) !important;
}

/* Print styles */
.print-header {
    display: none;
}

@media print {
    .print-header {
        display: block !important;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #000;
    }
    
    .d-print-none {
        display: none !important;
    }
    
    .breadcrumb,
    .btn,
    button,
    .card-header,
    .pagination,
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate,
    .dropdown,
    .input-group,
    .form-select,
    .alert,
    .paging_simple_numbers {
        display: none !important;
    }
    
    .d-flex .badge {
        display: none !important;
    }
    
    .table .badge {
        display: inline-block !important;
        background: transparent !important;
        color: #000 !important;
        border: none !important;
        padding: 0 !important;
        font-size: inherit !important;
        font-weight: normal !important;
        border-radius: 0 !important;
    }
    
    .row.mb-4:first-of-type {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
        page-break-inside: auto !important;
    }
    
    .card-body {
        padding: 0 !important;
    }
    
    .table {
        font-size: 11px;
        width: 100%;
    }
    
    .table thead th {
        background-color: #f8f9fa !important;
        border: 1px solid #000 !important;
        padding: 8px 4px !important;
        font-weight: bold;
    }
    
    .table td {
        border: 1px solid #ddd !important;
        padding: 6px 4px !important;
    }
    
    .table th:last-child,
    .table td:last-child {
        display: none !important;
    }
    
    tr {
        page-break-inside: avoid;
    }
}

/* Floating product search */
.product-search-results {
    position: absolute;
    background: white;
    z-index: 10000;
    max-height: 400px;
    overflow-y: auto;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
}

.product-search-results table thead th {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    z-index: 10;
}

.product-search-results tr {
    cursor: pointer;
    transition: all 0.2s;
}

.product-search-results tr:hover {
    background-color: #e9ecef !important;
}
</style>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
      
        
        <p class="text-dark mb-1 small text-uppercase text-center">
            <?php 
            $web_email = [];
            if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
            if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
            if (!empty($web_email)) echo implode(" | ", $web_email);
            ?>
        </p>

        <p class="text-dark mb-1 small text-uppercase text-center">
            <?php 
            $tin_vrn = [];
            if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
            if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
            if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
            ?>
        </p>

        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">PURCHASE RETURNS REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div class="row g-2">
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Returns</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $initial_stats['total_returns'] ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Pending</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $initial_stats['pending'] ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Approved</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $initial_stats['approved'] ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Completed</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $initial_stats['completed'] ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Rejected</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $initial_stats['rejected'] ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Value</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 11pt;"><?= format_currency($initial_stats['total_value']) ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('purchases') ?>">Purchases</a></li>
            <li class="breadcrumb-item active">Purchase Returns</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="bi bi-arrow-return-left text-primary"></i> Purchase Returns</h2>
                    <p class="text-muted mb-0">Professional management of supplier returns</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if (canCreate('purchase_returns')): ?>
                    <button type="button" class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addReturnModal">
                        <i class="bi bi-plus-circle me-1"></i> New Return
                    </button>
                    <?php endif; ?>
                    <a href="<?= getUrl('reports') ?>?report=purchase_returns" class="btn btn-outline-info px-4 shadow-sm">
                        <i class="bi bi-graph-up me-1"></i> Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 d-print-none" id="stats-container">
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0"><?= $initial_stats['total_returns'] ?></h4><p class="mb-0 small">Total Returns</p></div>
                        <div><i class="bi bi-box-arrow-left fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0"><?= $initial_stats['pending'] ?></h4><p class="mb-0 small">Pending</p></div>
                        <div><i class="bi bi-clock fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0"><?= $initial_stats['approved'] ?></h4><p class="mb-0 small">Approved</p></div>
                        <div><i class="bi bi-check-circle fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0"><?= $initial_stats['completed'] ?></h4><p class="mb-0 small">Completed</p></div>
                        <div><i class="bi bi-check2-all fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0"><?= $initial_stats['rejected'] ?></h4><p class="mb-0 small">Rejected</p></div>
                        <div><i class="bi bi-x-circle fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h5 class="mb-0 fw-bold" style="font-size: 1rem;"><?= format_currency($initial_stats['total_value']) ?></h5><p class="mb-0 small">Total Value</p></div>
                        <div><i class="bi bi-cash fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4 d-print-none border-0 shadow-sm">
        <div class="card-header bg-light border-bottom">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel"></i> Filters & Parameters</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Status</label>
                    <select class="form-select" id="filter_status">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="completed">Completed</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Supplier</label>
                    <select class="form-select select2-static" id="filter_supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= $supplier['supplier_id'] ?>">
                                <?= safe_output($supplier['supplier_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Date From</label>
                    <input type="date" class="form-control" id="filter_date_from">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Date To</label>
                    <input type="date" class="form-control" id="filter_date_to">
                </div>
                <div class="col-12 d-flex justify-content-end">
                    <button type="button" class="btn btn-primary px-4 me-2" onclick="refreshTable()">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="resetFilters()">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printList()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportReturns()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Export
                </button>
            </div>

            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="dataTable.page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span id="returns-count-badge" class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill">
                <i class="bi bi-check-circle-fill me-1"></i> Purchase Returns
            </span>
            <div class="d-none d-md-flex align-items-center gap-1 ms-2">
                <span class="small fw-bold text-muted"><i class="bi bi-display"></i> View:</span>
                <div class="btn-group btn-group-sm shadow-sm">
                    <button type="button" class="btn btn-outline-primary" id="prTableViewBtn" onclick="togglePRView('table')" title="Table View"><i class="bi bi-table"></i></button>
                    <button type="button" class="btn btn-outline-primary" id="prCardViewBtn" onclick="togglePRView('card')" title="Card View"><i class="bi bi-grid-3x3-gap"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Returns Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-print-none">
            <h5 class="mb-0 fw-bold">Purchase Returns List</h5>
        </div>
        <div class="card-body">
            <div id="prTableView">
                <div class="table-responsive">
                    <table id="returnsTable" class="table table-striped table-hover w-100">
                        <thead>
                            <tr>
                                <th style="width:50px;">S/NO</th>
                                <th>Return #</th>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>GRN Number</th>
                                <th>Items</th>
                                <th>Total Value</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div id="prCardView" style="display:none;">
                <div class="row g-3" id="prCardGrid"></div>
            </div>
        </div>
    </div>
</div>

<!-- Add Return Modal -->
<?php if (canCreate('purchase_returns')): ?>
<div class="modal fade" id="addReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Create Purchase Return</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addReturnForm">
                <div class="modal-body">
                    <div id="add-return-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="warehouse_id" class="form-label">Warehouse <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" id="warehouse_id" name="warehouse_id" required onchange="loadWarehouseSuppliers(this.value, 'supplier_id')">
                                <option value="">Select Warehouse</option>
                                <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['warehouse_id'] ?>">
                                    <?= safe_output($wh['warehouse_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select" id="supplier_id" name="supplier_id" required onchange="loadWarehouseSupplierGRNs($('#warehouse_id').val(), this.value, 'receipt_id'); loadInvoicesForReturn(this.value, 'supplier_invoice_id')">
                                <option value="">Select Supplier First</option>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="supplier_invoice_id" class="form-label">
                                Supplier Invoice <span class="text-muted small">(optional — enables qty guard)</span>
                            </label>
                            <select class="form-select" id="supplier_invoice_id" name="supplier_invoice_id" onchange="loadInvoiceItemsForReturn(this.value, 'returnItemsBody')">
                                <option value="">Select Invoice</option>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="receipt_id" class="form-label">Select GRN <small class="text-muted">(optional)</small></label>
                            <select class="form-select" id="receipt_id" name="receipt_id" onchange="loadGRNItems(this.value, 'returnItemsBody')">
                                <option value="">Select GRN</option>
                            </select>
                        </div>

                        
                        <div class="col-md-6 mb-3">
                            <label for="return_date" class="form-label">Return Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="return_date" name="return_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="reason" class="form-label">Reason <span class="text-danger">*</span></label>
                            <select class="form-select" id="reason" name="reason" required>
                                <option value="">Select Reason</option>
                                <option value="damaged">Damaged Goods</option>
                                <option value="wrong_item">Wrong Item Received</option>
                                <option value="quality_issue">Quality Issue</option>
                                <option value="over_supply">Over Supply</option>
                                <option value="expired">Expired Goods</option>
                                <option value="wrong_spec">Wrong Specifications</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label for="reason_details" class="form-label">Reason Details</label>
                            <textarea class="form-control" id="reason_details" name="reason_details" rows="2" placeholder="Provide detailed explanation"></textarea>
                        </div>
                        
                        <!-- Items Section -->
                        <div class="col-12 mb-3">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-list-check"></i> Return Items</h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="table-responsive">
                                        <table class="table table-sm" id="returnItemsTable">
                                            <thead>
                                                <tr>
                                                    <th style="width:35px;">S/NO</th>
                                                    <th width="25%">Product/Item *</th>
                                                    <th width="9%">SKU</th>
                                                    <th width="9%" class="inv-qty-col d-none text-center text-info" title="Qty on invoice">Inv Qty</th>
                                                    <th width="9%" class="inv-qty-col d-none text-center text-warning" title="Max you can return">Max Return</th>
                                                    <th width="9%">Qty *</th>
                                                    <th width="7%">Unit</th>
                                                    <th width="11%">Unit Price</th>
                                                    <th width="9%">VAT</th>
                                                    <th width="9%">Total</th>
                                                    <th width="4%"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="returnItemsBody"></tbody>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addReturnItem()">
                                        <i class="bi bi-plus-circle"></i> Add Item
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="d-flex justify-content-end">
                                <div style="min-width:280px;">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted small">Subtotal:</span>
                                        <span class="fw-bold small" id="add-subtotal">0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted small">VAT (18%):</span>
                                        <span class="fw-bold small" id="add-vat-total">0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between border-top pt-1">
                                        <span class="fw-bold">Grand Total:</span>
                                        <span class="fw-bold text-primary" id="add-grand-total">0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                        </div>

                        <div class="col-12 mb-3">
                            <label for="add_attachment" class="form-label">Attachment <small class="text-muted">(PDF, JPG, PNG — max 10MB)</small></label>
                            <input type="file" class="form-control" id="add_attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Return</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Floating Product Search Results -->
<div id="productSearchResults" class="product-search-results shadow-lg border d-print-none">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="bg-light sticky-top">
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Stock</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody id="productsSearchBody">
                <!-- Products will be loaded here -->
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Edit Return Modal -->
<div class="modal fade" id="editReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Purchase Return</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editReturnForm">
                <div class="modal-body">
                    <div id="edit-return-message" class="mb-3"></div>
                    <input type="hidden" id="edit_return_id" name="return_id">
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="edit_warehouse_id" class="form-label">Warehouse <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" id="edit_warehouse_id" name="warehouse_id" required onchange="loadWarehouseSuppliers(this.value, 'edit_supplier_id')">
                                <option value="">Select Warehouse</option>
                                <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['warehouse_id'] ?>">
                                    <?= safe_output($wh['warehouse_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="edit_supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_supplier_id" name="supplier_id" required onchange="loadWarehouseSupplierGRNs($('#edit_warehouse_id').val(), this.value, 'edit_receipt_id')">
                                <option value="">Select Supplier First</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label for="edit_receipt_id" class="form-label">Select GRN <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_receipt_id" name="receipt_id" required onchange="loadGRNItems(this.value, 'editReturnItemsBody', 'edit')">
                                <option value="">Select GRN</option>
                            </select>
                        </div>

                        
                        <div class="col-md-6 mb-3">
                            <label for="edit_return_date" class="form-label">Return Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_return_date" name="return_date" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="edit_reason" class="form-label">Reason <span class="text-danger"></span></label>
                            <select class="form-select" id="edit_reason" name="reason" required>
                                <option value="">Select Reason</option>
                                <option value="damaged">Damaged Goods</option>
                                <option value="wrong_item">Wrong Item Received</option>
                                <option value="quality_issue">Quality Issue</option>
                                <option value="over_supply">Over Supply</option>
                                <option value="expired">Expired Goods</option>
                                <option value="wrong_spec">Wrong Specifications</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label for="edit_reason_details" class="form-label">Reason Details</label>
                            <textarea class="form-control" id="edit_reason_details" name="reason_details" rows="2" placeholder="Provide detailed explanation"></textarea>
                        </div>
                        
                        <!-- Items Section -->
                        <div class="col-12 mb-3">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-list-check"></i> Return Items</h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="table-responsive">
                                        <table class="table table-sm" id="editReturnItemsTable">
                                            <thead>
                                                <tr>
                                                    <th style="width: 50px;">S/NO</th>
                                                    <th width="28%">Product/Item</th>
                                                    <th width="12%">SKU/Barcode</th>
                                                    <th width="10%">Quantity</th>
                                                    <th width="8%">Unit</th>
                                                    <th width="13%">Unit Price</th>
                                                    <th width="11%">VAT</th>
                                                    <th width="13%">Total</th>
                                                    <th width="5%"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="editReturnItemsBody"></tbody>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addReturnItem('edit')">
                                        <i class="bi bi-plus-circle"></i> Add Item
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label for="edit_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="edit_notes" name="notes" rows="2"></textarea>
                        </div>

                        <div class="col-12 mb-3">
                            <label for="edit_attachment" class="form-label">Attachment <small class="text-muted">(PDF, JPG, PNG — max 10MB)</small></label>
                            <div id="edit_current_attachment" class="mb-2" style="display:none;">
                                <small class="text-muted">Current: </small>
                                <a id="edit_attachment_link" href="#" target="_blank" class="small text-primary"><i class="bi bi-paperclip"></i> <span id="edit_attachment_name"></span></a>
                            </div>
                            <input type="file" class="form-control" id="edit_attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="text-muted">Leave blank to keep existing attachment.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let returnItemCount = 0;
let dataTable;
let productsCache = [];
let currentItemIndex = null;

// Helper function to format date
function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateForInput(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toISOString().split('T')[0];
}

function togglePRView(viewType) {
    const isMobile = window.innerWidth <= 767;
    if (isMobile) viewType = 'card';
    if (viewType === 'card') {
        document.getElementById('prTableView').style.display = 'none';
        document.getElementById('prCardView').style.display = '';
        document.getElementById('prCardViewBtn').classList.add('active');
        document.getElementById('prTableViewBtn').classList.remove('active');
    } else {
        document.getElementById('prTableView').style.display = '';
        document.getElementById('prCardView').style.display = 'none';
        document.getElementById('prTableViewBtn').classList.add('active');
        document.getElementById('prCardViewBtn').classList.remove('active');
    }
    if (!isMobile) localStorage.setItem('prView', viewType);
}

function extractReturnId(actionsHtml) {
    if (!actionsHtml) return null;
    const m = actionsHtml.match(/deleteReturn\((\d+)\)|editReturn\((\d+)\)|viewReturn\((\d+)\)/);
    return m ? parseInt(m[1] || m[2] || m[3]) : null;
}

function renderPRCards(data) {
    const grid = document.getElementById('prCardGrid');
    grid.innerHTML = '';
    if (!data || data.length === 0) {
        grid.innerHTML = '<div class="col-12 text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i> No records found</div>';
        return;
    }
    const statusMap = { pending:'warning', approved:'primary', completed:'success', rejected:'danger', cancelled:'secondary' };
    data.each(function(row) {
        const badge = statusMap[row.status] || 'secondary';
        const rid = extractReturnId(row.actions);
        grid.innerHTML += `
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="card h-100 border-0 shadow-sm rounded-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2 px-3">
                        <code class="small">${row.return_number || ''}</code>
                        <span class="badge bg-${badge}" style="font-size:0.65rem;">${(row.status||'').toUpperCase()}</span>
                    </div>
                    <div class="card-body py-2 px-3">
                        <div class="small text-muted mb-1">Supplier: <strong class="text-dark">${row.supplier_name || ''}</strong></div>
                        <div class="small text-muted mb-1">Date: <span class="text-dark">${row.return_date || ''}</span></div>
                        <div class="small text-muted mb-1">GRN: <span class="text-dark">${row.receipt_number || 'N/A'}</span></div>
                        <div class="small text-muted mb-1">Items: <span class="text-dark">${row.total_items || 0}</span></div>
                        <div class="small text-muted">Value: <strong class="text-dark">${row.total_amount || ''}</strong></div>
                    </div>
                    <div class="card-footer bg-white" style="padding:6px 8px;">
                        <div style="display:flex;flex-wrap:nowrap;gap:4px;">
                            <button onclick="viewReturn(${rid})" class="btn btn-outline-primary" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="View"><i class="bi bi-eye"></i></button>
                            <button onclick="editReturn(${rid})" class="btn btn-outline-secondary" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="Edit"><i class="bi bi-pencil"></i></button>
                            <button onclick="deleteReturn(${rid})" class="btn btn-outline-danger" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="Delete"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                </div>
            </div>`;
    });
}

$(document).ready(function() {
    logReportAction('Viewed Purchase Returns List', 'User viewed the purchase returns management list');

    // Select2 on filter supplier
    $('#filter_supplier').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'All Suppliers' });

    // Select2 on warehouse selects in modals
    $('#addReturnModal').on('shown.bs.modal', function() {
        if (!$('#warehouse_id').hasClass('select2-hidden-accessible'))
            $('#warehouse_id').select2({ theme: 'bootstrap-5', dropdownParent: $('#addReturnModal'), width: '100%', allowClear: true, placeholder: 'Select Warehouse' });
    });
    $('#editReturnModal').on('shown.bs.modal', function() {
        if (!$('#edit_warehouse_id').hasClass('select2-hidden-accessible'))
            $('#edit_warehouse_id').select2({ theme: 'bootstrap-5', dropdownParent: $('#editReturnModal'), width: '100%', allowClear: true, placeholder: 'Select Warehouse' });
    });

    // Initial view
    const savedPRView = window.innerWidth <= 767 ? 'card' : (localStorage.getItem('prView') || 'table');
    togglePRView(savedPRView);
    window.addEventListener('resize', function() { if (window.innerWidth <= 767) togglePRView('card'); });

    loadStats();
    initializeDataTable();
    loadProductsCache();
    
    // Hide search results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.item-name, #productSearchResults').length) {
            $('#productSearchResults').hide();
        }
    });

    // Handle ESC key to hide search results
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#productSearchResults').hide();
        }
    });
    
    // Add return form submission
    $('#addReturnForm').on('submit', function(e) {
        e.preventDefault();
        createPurchaseReturn();
    });
    
    // Edit return form submission
    $('#editReturnForm').on('submit', function(e) {
        e.preventDefault();
        updatePurchaseReturn();
    });
    
    // Modal resets
    $('#addReturnModal').on('hidden.bs.modal', function() {
        $('#addReturnForm')[0].reset();
        $('#add-return-message').html('');
        $('#returnItemsBody').empty();
        returnItemCount = 0;
        addReturnItem(); // Add one initial empty row
    });
    
    // Initial item row
    if ($('#returnItemsBody').length) {
        addReturnItem();
    }
});

function initializeDataTable() {
    dataTable = $('#returnsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= getUrl('api/get_purchase_returns.php') ?>',
            type: 'GET',
            data: function(d) {
                d.status = $('#filter_status').val();
                d.supplier_id = $('#filter_supplier').val();
                d.date_from = $('#filter_date_from').val();
                d.date_to = $('#filter_date_to').val();
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '50px',
                className: 'text-muted small fw-bold',
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            { data: 'return_number' },
            { data: 'return_date' },
            { data: 'supplier_name' },
            { data: 'receipt_number' },
            { data: 'total_items' },
            { data: 'total_amount' },
            { data: 'reason' },
            { data: 'status' },
            { data: 'actions', orderable: false, searchable: false }
        ],
        order: [[1, 'desc']],
        pageLength: 25,
        lengthChange: false,
        dom: 'rtip',
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search returns..."
        },
        drawCallback: function(settings) {
            const api = this.api();
            const count = api.rows({search:'applied'}).count();
            $('#returns-count-badge').html(`<i class="bi bi-check-circle-fill me-1"></i> ${count} records found`);
            renderPRCards(api.rows({page:'current'}).data());
        }
    });
}

function refreshTable() {
    dataTable.ajax.reload();
    loadStats();
}

function resetFilters() {
    $('#filter_status').val('');
    $('#filter_supplier').val('');
    $('#filter_date_from').val('');
    $('#filter_date_to').val('');
    refreshTable();
}

function loadStats() {
    $.ajax({
        url: '<?= getUrl('api/get_purchase_return_stats.php') ?>',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderStats(response.data);
            }
        }
    });
}

function renderStats(data) {
    const html = `
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0">${data.total_returns}</h4><p class="mb-0 small">Total Returns</p></div>
                        <div><i class="bi bi-box-arrow-left fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0">${data.pending}</h4><p class="mb-0 small">Pending</p></div>
                        <div><i class="bi bi-clock fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0">${data.approved}</h4><p class="mb-0 small">Approved</p></div>
                        <div><i class="bi bi-check-circle fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0">${data.completed}</h4><p class="mb-0 small">Completed</p></div>
                        <div><i class="bi bi-check2-all fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h4 class="mb-0">${data.rejected}</h4><p class="mb-0 small">Rejected</p></div>
                        <div><i class="bi bi-x-circle fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><h5 class="mb-0 fw-bold" style="font-size: 1rem;">${(data.total_value).toLocaleString('en-US', {style: 'currency', currency: 'TZS'})}</h5><p class="mb-0 small">Total Value</p></div>
                        <div><i class="bi bi-cash fs-2"></i></div>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('#stats-container').html(html);
}

function loadProductsCache() {
    $.getJSON('<?= getUrl('api/account/get_products.php') ?>', { active_only: true }, function(response) {
        if (response.success) {
            productsCache = response.data;
        }
    });
}

function updateSerialNumbers(container) {
    $(container + ' tr').each(function(index) {
        $(this).find('.row-sn').text(index + 1);
    });
}

function addReturnItem(type = 'add', data = null) {
    const i = returnItemCount++;
    const container = type === 'edit' ? '#editReturnItemsBody' : '#returnItemsBody';
    const qty       = data ? data.quantity : 1;
    const price     = data ? data.unit_price : 0;
    const total     = (qty * price).toFixed(2);
    const taxRate   = data ? (parseFloat(data.tax_rate || 0) === 18 ? 18 : 0) : 0;

    const origInvItemId  = (data && data.original_invoice_item_id) ? data.original_invoice_item_id : '';
    const origQty        = (data && data.original_qty != null)     ? parseFloat(data.original_qty) : '';
    const maxRet         = (data && data.max_returnable != null)   ? parseFloat(data.max_returnable) : '';

    // Inv Qty / Max Return cells — hidden unless invoice is linked
    const invQtyCell = `<td class="inv-qty-col text-center ${origQty !== '' ? '' : 'd-none'}">
        <span class="badge bg-info text-dark">${origQty !== '' ? origQty : ''}</span>
    </td>`;
    const maxRetCell = `<td class="inv-qty-col text-center ${maxRet !== '' ? '' : 'd-none'}">
        <span class="badge ${maxRet === 0 ? 'bg-danger' : 'bg-warning text-dark'}">${maxRet !== '' ? maxRet : ''}</span>
    </td>`;

    const maxAttr    = maxRet !== '' ? `max="${maxRet}"` : '';
    const maxWarn    = (maxRet !== '' && maxRet === 0) ? ' border-danger' : '';

    const html = `
        <tr id="item-row-${type}-${i}">
            <td class="row-sn text-center fw-bold text-muted">${$(container + ' tr').length + 1}</td>
            <td>
                <input type="text" class="form-control form-control-sm item-name" name="items[${i}][name]"
                       placeholder="Product name" required readonly
                       value="${data ? data.product_name : ''}">
                <input type="hidden" class="item-product-id"         name="items[${i}][product_id]"              value="${data ? data.product_id || '' : ''}">
                <input type="hidden" class="item-orig-inv-item-id"   name="items[${i}][original_invoice_item_id]" value="${origInvItemId}">
                <input type="hidden" class="item-max-returnable"     value="${maxRet}">
            </td>
            <td><input type="text" class="form-control form-control-sm item-sku" readonly value="${data ? data.sku || '' : ''}"></td>
            ${invQtyCell}
            ${maxRetCell}
            <td><input type="number" class="form-control form-control-sm item-quantity${maxWarn}" name="items[${i}][quantity]"
                       value="${qty}" min="0.001" step="0.001" ${maxAttr} required
                       oninput="validateReturnQty(this); calculateRowTotal('${type}', ${i})"></td>
            <td><input type="text" class="form-control form-control-sm item-unit" readonly value="${data ? data.unit || '' : ''}"></td>
            <td><input type="number" class="form-control form-control-sm item-price" name="items[${i}][unit_price]" value="${price}" min="0" step="0.01" required oninput="calculateRowTotal('${type}', ${i})"></td>
            <td>
                <select class="form-select form-select-sm item-tax" name="items[${i}][tax_rate]" onchange="calculateRowTotal('${type}', ${i})">
                    <option value="0" ${taxRate === 0 ? 'selected' : ''}>0%</option>
                    <option value="18" ${taxRate === 18 ? 'selected' : ''}>18%</option>
                </select>
            </td>
            <td><input type="text" class="form-control form-control-sm item-total" readonly value="${total}"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItemRow('${type}', ${i})"><i class="bi bi-trash"></i></button></td>
        </tr>
    `;
    $(container).append(html);
    updateSerialNumbers(container);
}

function removeItemRow(type, index) {
    const container = type === 'edit' ? '#editReturnItemsBody' : '#returnItemsBody';
    $(`#item-row-${type}-${index}`).remove();
    updateSerialNumbers(container);
}

function calculateRowTotal(type, index) {
    const row   = $(`#item-row-${type}-${index}`);
    const qty   = parseFloat(row.find('.item-quantity').val()) || 0;
    const price = parseFloat(row.find('.item-price').val()) || 0;
    const rate  = parseFloat(row.find('.item-tax').val()) || 0;
    const base  = qty * price;
    const total = (base + base * (rate / 100)).toFixed(2);
    row.find('.item-total').val(total);
}


function openProductSearch(index, term) {
    currentItemIndex = index;
    const input = $(`#item-row-${index} .item-name`);
    const offset = input.offset();
    
    // Position the results container
    $('#productSearchResults').css({
        top: offset.top + input.outerHeight() + 2,
        left: offset.left,
        width: Math.max(input.outerWidth() * 1.5, 400),
        display: 'block'
    });
    
    searchProducts(term);
}

function searchProducts(term = '') {
    const tbody = $('#productsSearchBody');
    tbody.empty();
    
    const searchTerm = term.toLowerCase().trim();
    let results = productsCache;
    
    if (searchTerm.length > 0) {
        results = productsCache.filter(p =>
            (p.product_name && p.product_name.toLowerCase().includes(searchTerm)) ||
            (p.sku && p.sku.toLowerCase().includes(searchTerm)) ||
            (p.barcode && p.barcode.toLowerCase().includes(searchTerm))
        );
    }
    
    if (results.length === 0) {
        tbody.append(`<tr><td colspan="4" class="text-center text-danger p-3">No products found</td></tr>`);
        return;
    }
    
    results.slice(0, 50).forEach(product => {
        tbody.append(`
            <tr onclick="selectProduct(${product.product_id})">
                <td>
                    <strong>${product.product_name}</strong><br>
                    <small class="text-muted">${product.sku || 'No SKU'}</small>
                </td>
                <td>${product.sku || 'N/A'}</td>
                
                <td>${product.current_stock || 0}</td>
                <td>${product.cost_price || 0}</td>
            </tr>
        `);
    });
}

function selectProduct(productId) {
    const product = productsCache.find(p => p.product_id == productId);
    if (product) {
        const row = $(`#item-row-${currentItemIndex}`);
        row.find('.item-name').val(product.product_name);
        row.find('.item-product-id').val(product.product_id);
        row.find('.item-price').val(product.cost_price || 0);
        
        $('#productSearchResults').hide();
        row.find('.item-quantity').focus();
    }
}

function loadWarehouseSuppliers(warehouseId, targetId) {
    const $el = $(`#${targetId}`);
    if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
    if (!warehouseId) {
        $el.html('<option value="">Select Supplier First</option>');
        return;
    }
    $.ajax({
        url: '<?= getUrl('api/get_warehouse_suppliers.php') ?>',
        type: 'GET',
        data: { warehouse_id: warehouseId },
        dataType: 'json',
        success: function(response) {
            let html = '<option value="">Select Supplier</option>';
            if (response.success && response.data) {
                response.data.forEach(supplier => {
                    html += `<option value="${supplier.supplier_id}">${supplier.supplier_name}</option>`;
                });
            }
            $el.html(html);
            const $modal = $el.closest('.modal');
            $el.select2({ theme: 'bootstrap-5', dropdownParent: $modal.length ? $modal : null, width: '100%', allowClear: true, placeholder: 'Select Supplier' });
        }
    });
}

function loadWarehouseSupplierGRNs(warehouseId, supplierId, targetId) {
    const $el = $(`#${targetId}`);
    if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
    if (!warehouseId || !supplierId) {
        $el.html('<option value="">Select GRN</option>');
        return;
    }
    $.ajax({
        url: '<?= getUrl('api/get_warehouse_supplier_grns.php') ?>',
        type: 'GET',
        data: { warehouse_id: warehouseId, supplier_id: supplierId },
        dataType: 'json',
        success: function(response) {
            let html = '<option value="">Select GRN</option>';
            if (response.success && response.data) {
                response.data.forEach(grn => {
                    html += `<option value="${grn.receipt_id}">${grn.receipt_number} (${formatDate(grn.receipt_date)})</option>`;
                });
            }
            $el.html(html);
            const $modal = $el.closest('.modal');
            $el.select2({ theme: 'bootstrap-5', dropdownParent: $modal.length ? $modal : null, width: '100%', allowClear: true, placeholder: 'Select GRN' });
        }
    });
}

function loadGRNItems(receiptId, tableBodyId, type = 'add') {
    if (!receiptId) return;
    $.ajax({
        url: '<?= getUrl('api/get_grn_items.php') ?>',
        type: 'GET',
        data: { receipt_id: receiptId },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                $(`#${tableBodyId}`).empty();
                // We keep returnItemCount growing but we start fresh
                response.data.forEach(item => {
                    const mappedItem = {
                        product_id: item.product_id,
                        product_name: item.product_name,
                        sku: item.sku,
                        unit: item.unit,
                        quantity: item.qty,
                        unit_price: item.unit_price
                    };
                    addReturnItem(type, mappedItem);
                });
            }
        }
    });
}


function loadInvoicesForReturn(supplierId, targetId) {
    const sel = document.getElementById(targetId);
    if (!sel) return;
    sel.innerHTML = '<option value="">Select Invoice</option>';
    if (!supplierId) return;
    $.getJSON('<?= getUrl('api/get_invoices_for_return.php') ?>', { supplier_id: supplierId }, function(res) {
        if (res.success && res.data && res.data.length) {
            res.data.forEach(inv => {
                const label = inv.invoice_ref + ' — ' + inv.date_raised + ' (TZS ' + parseFloat(inv.amount).toLocaleString() + ')';
                sel.innerHTML += `<option value="${inv.id}">${label}</option>`;
            });
        }
    });
}

function loadInvoiceItemsForReturn(invoiceId, bodyId) {
    if (!invoiceId) {
        $('.inv-qty-col').addClass('d-none');
        return;
    }
    $.getJSON('<?= getUrl('api/get_invoice_items_for_return.php') ?>', { invoice_id: invoiceId }, function(res) {
        if (res.success && res.data && res.data.length) {
            $(`#${bodyId}`).empty();
            $('.inv-qty-col').removeClass('d-none');
            res.data.forEach(item => {
                const mappedItem = {
                    product_id: item.product_id,
                    product_name: item.product_name,
                    sku: item.sku,
                    unit: item.unit,
                    quantity: Math.min(1, item.max_returnable),
                    unit_price: item.unit_price,
                    tax_rate: item.tax_rate,
                    original_invoice_item_id: item.item_id,
                    original_qty: item.original_qty,
                    max_returnable: item.max_returnable
                };
                addReturnItem('add', mappedItem);
            });
        } else {
            Swal.fire({ icon: 'info', title: 'No items', text: 'No returnable items found on this invoice.' });
        }
    });
}

function validateReturnQty(input) {
    const max = parseFloat($(input).attr('max'));
    if (!isNaN(max)) {
        const val = parseFloat($(input).val()) || 0;
        if (val > max) {
            $(input).addClass('border-danger');
            $(input).val(max);
        } else {
            $(input).removeClass('border-danger');
        }
    }
}

function createPurchaseReturn() {
    // Client-side guard: no qty may exceed max_returnable
    let overError = '';
    $('#returnItemsBody tr').each(function() {
        const max = parseFloat($(this).find('.item-max-returnable').val());
        if (!isNaN(max) && max !== '') {
            const qty = parseFloat($(this).find('.item-quantity').val()) || 0;
            const name = $(this).find('.item-name').val() || 'item';
            if (qty > max) {
                overError = `"${name}": return qty ${qty} exceeds max returnable ${max}.`;
                return false; // break
            }
        }
    });
    if (overError) {
        Swal.fire({ icon: 'error', title: 'Qty exceeded', text: overError });
        return;
    }

    const formData = new FormData(document.getElementById('addReturnForm'));
    $.ajax({
        url: '<?= getUrl('api/create_purchase_return.php') ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                logReportAction('Created Purchase Return', 'User created a new purchase return');
                $('#addReturnModal').modal('hide');
                Swal.fire('Success', response.message, 'success');
                refreshTable();
            } else {
                $('#add-return-message').html('<div class="alert alert-danger">' + response.message + '</div>');
            }
        },
        error: function() {
            $('#add-return-message').html('<div class="alert alert-danger">Server error. Please try again.</div>');
        }
    });
}

function editReturn(id) {
    logReportAction('Initiated Purchase Return Edit', 'User clicked edit for purchase return #' + id);
    $.get('<?= getUrl('api/get_purchase_return.php') ?>', { id: id }, function(response) {
        if (response.success) {
            const data = response.data;
            $('#edit_return_id').val(data.purchase_return_id);
            $('#edit_warehouse_id').val(data.warehouse_id);
            $('#edit_return_date').val(data.return_date);
            $('#edit_reason').val(data.reason);
            $('#edit_reason_details').val(data.reason_details);
            $('#edit_notes').val(data.notes);
            
            // Clear and Load Items
            $('#editReturnItemsBody').empty();
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    addReturnItem('edit', item);
                });
            } else {
                addReturnItem('edit');
            }
            
            // Load Suppliers for this warehouse
            loadWarehouseSuppliers(data.warehouse_id, 'edit_supplier_id');
            // Set Supplier after short delay
            setTimeout(() => {
                $('#edit_supplier_id').val(data.supplier_id);
                
                // Load GRNs for this supplier and warehouse
                loadWarehouseSupplierGRNs(data.warehouse_id, data.supplier_id, 'edit_receipt_id');
                setTimeout(() => {
                    $('#edit_receipt_id').val(data.receipt_id);
                }, 500);
            }, 500);

            // Show current attachment if exists
            if (data.attachment) {
                const fname = data.attachment.split('/').pop();
                $('#edit_attachment_name').text(fname);
                $('#edit_attachment_link').attr('href', '<?= getUrl('') ?>' + data.attachment);
                $('#edit_current_attachment').show();
            } else {
                $('#edit_current_attachment').hide();
            }

            $('#editReturnModal').modal('show');
        } else {
            Swal.fire('Error', response.message, 'error');
        }
    }, 'json');
}

function updatePurchaseReturn() {
    const formData = new FormData(document.getElementById('editReturnForm'));
    $.ajax({
        url: '<?= getUrl('api/update_purchase_return.php') ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                logReportAction('Updated Purchase Return', 'User updated purchase return');
                $('#editReturnModal').modal('hide');
                Swal.fire('Success', response.message, 'success');
                refreshTable();
            } else {
                $('#edit-return-message').html('<div class="alert alert-danger">' + response.message + '</div>');
            }
        },
        error: function() {
            $('#edit-return-message').html('<div class="alert alert-danger">Server error. Please try again.</div>');
        }
    });
}

function updateReturnStatus(id, status) {
    Swal.fire({
        title: 'Confirm Update',
        text: `Are you sure you want to mark this return as ${status}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, update it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('<?= getUrl('api/update_purchase_return_status.php') ?>', { return_id: id, status: status }, function(response) {
                if (response.success) {
                    logReportAction('Updated Purchase Return Status', 'User updated purchase return #' + id + ' status to ' + status);
                    Swal.fire('Updated!', response.message, 'success');
                    refreshTable();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            }, 'json');
        }
    });
}

// ── Three-approval workflow actions (returns three-approval slice) ──
// These go through the canonical endpoints so the workflow_signatures
// rows + reviewed_by/approved_by stamps are captured (the legacy
// updateReturnStatus() above now rejects 'reviewed'/'approved' transitions).
function sendForReviewPR(id) {
    Swal.fire({
        title: 'Send for Review?',
        text: 'This will mark the return as reviewed and capture your e-signature.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, send for review',
        confirmButtonColor: '#ffc107'
    }).then((result) => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading() });
        $.post('<?= getUrl('api/account/review_purchase_return.php') ?>',
            { return_id: id },
            function(response) {
                if (response.success) {
                    logReportAction('Reviewed Purchase Return', 'User reviewed purchase return #' + id);
                    Swal.fire('Reviewed', response.message, 'success');
                    refreshTable();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            }, 'json'
        ).fail(function(xhr) {
            var msg = 'Server error';
            try { var r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch (e) {}
            Swal.fire('Error', msg, 'error');
        });
    });
}

function approvePR(id) {
    Swal.fire({
        title: 'Approve Purchase Return?',
        text: 'This will deduct stock from the warehouse and capture your e-signature.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, approve',
        confirmButtonColor: '#198754'
    }).then((result) => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading() });
        $.post('<?= getUrl('api/account/approve_purchase_return.php') ?>',
            { return_id: id },
            function(response) {
                if (response.success) {
                    logReportAction('Approved Purchase Return', 'User approved purchase return #' + id);
                    Swal.fire('Approved', response.message, 'success');
                    refreshTable();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            }, 'json'
        ).fail(function(xhr) {
            var msg = 'Server error';
            try { var r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch (e) {}
            Swal.fire('Error', msg, 'error');
        });
    });
}

function deleteReturn(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('<?= getUrl('api/delete_purchase_return.php') ?>', { return_id: id }, function(response) {
                if (response.success) {
                    logReportAction('Deleted Purchase Return', 'User deleted purchase return #' + id);
                    Swal.fire('Deleted!', response.message, 'success');
                    refreshTable();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            }, 'json');
        }
    });
}
function viewReturn(id) {
    logReportAction('Viewed Purchase Return Details Link', 'User clicked to view details for purchase return #' + id);
    // Redirect to view page
    window.location.href = '<?= getUrl('purchase_return_view') ?>?id=' + id;
}

function printList() {
    logReportAction('Printed Purchase Returns List', 'User generated a printed list of purchase returns');
    window.print();
}

function exportReturns() {
    logReportAction('Exported Purchase Returns', 'User exported the purchase returns list to Excel/CSV');
    const params = new URLSearchParams({
        status: $('#filter_status').val(),
        supplier: $('#filter_supplier').val(),
        from: $('#filter_date_from').val(),
        to: $('#filter_date_to').val()
    });
    
    window.location.href = '../../../api/export_purchase_returns.php?' + params.toString();
}
</script>

<?php includeFooter(); ?>