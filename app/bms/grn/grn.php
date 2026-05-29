<?php
// File: grn.php
// scope-audit: skip — Phase G complete; main query scoped via scopeFilterSqlNullable('project','po'); projects/suppliers/warehouses/PO dropdowns scoped inline below
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/workflow.php';

// Enforce permission BEFORE any output
autoEnforcePermission('grn');

// Include the header
includeHeader();

// Permission flags
$can_view_grn = isAdmin() || canView('grn');
$can_create_grn = isAdmin() || canCreate('grn');
$can_approve_grn = isAdmin() || canEdit('grn');
$can_delete_grn = isAdmin() || canDelete('grn');

// Three-approval workflow capabilities (mirrored to JS below)
$grn_can_review  = canReview('grn');
$grn_can_approve = canApprove('grn');
$grn_is_admin    = isAdmin();


// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$supplier_filter = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
$warehouse_filter = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$po_filter = isset($_GET['po']) ? intval($_GET['po']) : 0;
$project_filter = isset($_GET['project']) ? intval($_GET['project']) : 0;

// Check projects setting
$enable_projects = getSetting('enable_projects', 0);

// Scope: assigned project IDs for current user (empty = none, ignored for admin)
$_grn_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));

// Get projects for filter
$projects = [];
if ($enable_projects) {
    if (isAdmin()) {
        $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status != 'cancelled' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($_grn_assigned)) {
        $_grn_prj_ph = implode(',', array_fill(0, count($_grn_assigned), '?'));
        $_grn_prj_stmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status != 'cancelled' AND project_id IN ($_grn_prj_ph) ORDER BY project_name");
        $_grn_prj_stmt->execute($_grn_assigned);
        $projects = $_grn_prj_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Build query with filters
$query = "
    SELECT 
        pr.*,
        s.supplier_name,
        s.company_name,
        w.warehouse_name,
        po.order_number,
        u1.username as received_by_name,
        u2.username as created_by_name,
        COUNT(ri.receipt_item_id) as total_items,
        SUM(ri.quantity_received * ri.unit_price) as total_value,
        GROUP_CONCAT(DISTINCT p.product_name SEPARATOR ', ') as product_names
    FROM purchase_receipts pr
    LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
    LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
    LEFT JOIN warehouses w ON pr.warehouse_id = w.warehouse_id
    LEFT JOIN receipt_items ri ON pr.receipt_id = ri.receipt_id
    LEFT JOIN products p ON ri.product_id = p.product_id
    LEFT JOIN users u1 ON pr.received_by = u1.user_id
    LEFT JOIN users u2 ON pr.created_by = u2.user_id
    WHERE 1=1
";
$query .= scopeFilterSqlNullable('project', 'po');

$params = [];

// Apply filters
if (!empty($status_filter)) {
    $query .= " AND pr.status = ?";
    $params[] = $status_filter;
}

if ($supplier_filter > 0) {
    $query .= " AND pr.supplier_id = ?";
    $params[] = $supplier_filter;
}

if ($warehouse_filter > 0) {
    $query .= " AND pr.warehouse_id = ?";
    $params[] = $warehouse_filter;
}

if ($po_filter > 0) {
    $query .= " AND pr.purchase_order_id = ?";
    $params[] = $po_filter;
}

if (!empty($date_from)) {
    $query .= " AND pr.receipt_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND pr.receipt_date <= ?";
    $params[] = $date_to;
}

$query .= " GROUP BY pr.receipt_id ORDER BY pr.receipt_date DESC, pr.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$grns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get data for filter dropdowns — scoped by project for non-admins
if (isAdmin()) {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
    $purchase_orders = $pdo->query("SELECT purchase_order_id, order_number FROM purchase_orders WHERE status IN ('ordered', 'partially_received') ORDER BY order_date DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_grn_assigned)) {
    $_grn_ph = implode(',', array_fill(0, count($_grn_assigned), '?'));
    $_grn_sup = $pdo->prepare("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_grn_ph)) ORDER BY supplier_name");
    $_grn_sup->execute($_grn_assigned);
    $suppliers = $_grn_sup->fetchAll(PDO::FETCH_ASSOC);
    $_grn_wh = $pdo->prepare("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_grn_ph)) ORDER BY warehouse_name");
    $_grn_wh->execute($_grn_assigned);
    $warehouses = $_grn_wh->fetchAll(PDO::FETCH_ASSOC);
    $_grn_po = $pdo->prepare("SELECT purchase_order_id, order_number FROM purchase_orders WHERE status IN ('ordered', 'partially_received') AND (project_id IS NULL OR project_id IN ($_grn_ph)) ORDER BY order_date DESC LIMIT 50");
    $_grn_po->execute($_grn_assigned);
    $purchase_orders = $_grn_po->fetchAll(PDO::FETCH_ASSOC);
} else {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' AND project_id IS NULL ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' AND project_id IS NULL ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
    $purchase_orders = $pdo->query("SELECT purchase_order_id, order_number FROM purchase_orders WHERE status IN ('ordered', 'partially_received') AND project_id IS NULL ORDER BY order_date DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate statistics
$total_grns = count($grns);
$draft_grns = array_filter($grns, function($grn) {
    return $grn['status'] == 'draft';
});
$completed_grns = array_filter($grns, function($grn) {
    return $grn['status'] == 'completed';
});
$cancelled_grns = array_filter($grns, function($grn) {
    return $grn['status'] == 'cancelled';
});

// Helper functions removed, now in helpers.php
// Generate GRN number (for new GRN)
function generate_grn_number() {
    $prefix = 'GRN';
    $year = date('Y');
    $month = date('m');
    $random = mt_rand(1000, 9999);
    return $prefix . '-' . $year . $month . '-' . $random;
}
?>

<style>
    @media (max-width: 767px) {
        .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
    }

    /* Sticky Header Logic */
    .sticky-page-header {
        position: sticky;
        top: 0;
        z-index: 1020;
        background: #f8f9fa;
        padding-top: 1rem;
        padding-bottom: 1rem;
        margin-top: -1rem;
        border-bottom: 1px solid #dee2e6;
        transition: all 0.3s ease;
    }
    
    /* Responsive Table Adjustments */
    .table-responsive {
        border-radius: 8px;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 1rem;
    }
    
    /* Prevent whole page horizontal scroll */
    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }

    /* Print Logic: Force Table View, Hide Card View */
    @media print {
        #card-view-container {
            display: none !important;
        }
        #table-view-container {
            display: block !important;
        }
        
        /* Repeat Table Headers on each page */
        thead {
            display: table-header-group !important;
        }
        
        tr {
            page-break-inside: avoid !important;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            display: none !important;
        }
        .sticky-page-header {
            position: static !important;
            background: none !important;
            border: none !important;
        }
    }

    /* Fix for Table Alignment and Margins */
    .table-responsive {
        padding: 0.5rem;
        background: #fff;
        border-radius: 8px;
    }
    
    /* Screen View Table Styling */
    table.dataTable {
        width: 100% !important;
        margin: 0 !important;
        border-collapse: collapse !important;
        table-layout: fixed !important; /* Force fit to screen width */
    }
    
    #grnTable th, #grnTable td {
        text-align: center !important;
        vertical-align: middle !important;
        padding: 10px 5px !important;
        border: 1px solid #dee2e6 !important;
        overflow-wrap: break-word !important;
        word-break: break-word !important;
        white-space: normal !important; 
        /* Removed min-width to prevent horizontal scrolling */
    }

    /* Fixed widths only for Print Preview */
    @media print {
        table.dataTable {
            table-layout: fixed !important;
        }
        #grnTable th, #grnTable td {
            width: 8.33% !important;
            min-width: 0 !important; /* Allow shrinking to fit page */
            font-size: 8pt !important;
            padding: 5px 2px !important;
        }
        #grnTable th:first-child, #grnTable td:first-child {
            width: 35px !important;
        }
    }

    /* Keep content inside the box with wrapping */
    .text-wrap-cell {
        display: block;
        width: 100%;
        white-space: normal;
        word-break: break-word;
    }

    /* Small adjustment for S/NO */
    #grnTable th:first-child, #grnTable td:first-child {
        width: 50px !important;
    }
    
    /* Slightly wider for Actions if needed, but keeping them mostly equal as requested */
    #grnTable th:last-child, #grnTable td:last-child {
        width: 100px !important;
    }

    /* Mobile adjustments */
    @media (max-width: 768px) {
        .sticky-page-header h2 {
            font-size: 1.25rem;
        }
        .sticky-page-header p {
            display: none;
        }
        .btn-responsive {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .card-body {
            padding: 0.75rem;
        }
    }
</style>

<div class="container-fluid mt-4 px-3 px-md-4">
    <!-- Sticky Wrapper for Breadcrumbs and Header -->
    <div class="sticky-page-header d-print-none">
        <!-- Breadcrumbs -->
        <nav aria-label="breadcrumb" class="mb-2">
            <ol class="breadcrumb mb-0" style="font-size: 0.85rem;">
                <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Goods Received Notes</li>
            </ol>
        </nav>

        <!-- Page Header Content -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="flex-grow-1">
                <h2 class="fw-bold text-dark mb-0"><i class="bi bi-clipboard-check-fill text-primary"></i> GRNs</h2>
                <p class="text-muted mb-0 small d-none d-md-block">Professional goods receipt management</p>
            </div>
            <div class="d-flex gap-2">
                <?php if ($can_create_grn): ?>
                <a href="<?= getUrl('grn_create') ?>" class="btn btn-primary btn-sm btn-responsive px-3 shadow-sm">
                    <i class="bi bi-plus-circle me-1"></i> New GRN
                </a>
                <?php endif; ?>
                <a href="<?= getUrl('reports') ?>?report=grn_summary" class="btn btn-outline-info btn-sm btn-responsive px-3 shadow-sm">
                    <i class="bi bi-graph-up me-1"></i> Reports
                </a>
            </div>
        </div>
    </div>

    <div class="mt-4"></div> <!-- Spacer for sticky header -->

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4 d-print-none">
        <div class="col-md-3">
            <div class="card custom-stat-card h-100 overflow-hidden border-0 shadow-sm p-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1 overflow-hidden">
                            <h4 class="mb-0 fw-bold text-nowrap" id="stat-total-grns" style="font-size: 1.25rem;"><?= $total_grns ?></h4>
                            <p class="mb-0 text-uppercase small fw-bold text-truncate opacity-75">Total GRNs</p>
                        </div>
                        <div class="flex-shrink-0 ms-2">
                            <i class="bi bi-clipboard-data" style="font-size: 1.8rem; opacity: 0.8;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card h-100 overflow-hidden border-0 shadow-sm p-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1 overflow-hidden">
                            <h4 class="mb-0 fw-bold text-nowrap" id="stat-draft-grns" style="font-size: 1.25rem;"><?= count($draft_grns) ?></h4>
                            <p class="mb-0 text-uppercase small fw-bold text-truncate opacity-75">Draft Sheets</p>
                        </div>
                        <div class="flex-shrink-0 ms-2">
                            <i class="bi bi-file-earmark" style="font-size: 1.8rem; opacity: 0.8;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card h-100 overflow-hidden border-0 shadow-sm p-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1 overflow-hidden">
                            <h4 class="mb-0 fw-bold text-nowrap" id="stat-completed-grns" style="font-size: 1.25rem;"><?= count($completed_grns) ?></h4>
                            <p class="mb-0 text-uppercase small fw-bold text-truncate opacity-75">Completed</p>
                        </div>
                        <div class="flex-shrink-0 ms-2">
                            <i class="bi bi-check2-all" style="font-size: 1.8rem; opacity: 0.8;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card h-100 overflow-hidden border-0 shadow-sm p-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1 overflow-hidden">
                            <h4 class="mb-0 fw-bold" id="stat-total-value" style="font-size: 1.1rem; word-break: break-word;"><?= format_currency(array_sum(array_column($grns, 'total_value'))) ?></h4>
                            <p class="mb-0 text-uppercase small fw-bold text-truncate opacity-75">Total Value</p>
                        </div>
                        <div class="flex-shrink-0 ms-2">
                            <i class="bi bi-cash-stack" style="font-size: 1.8rem; opacity: 0.8;"></i>
                        </div>
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
            <form id="filterForm" method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All Statuses</option>
                        <option value="pending"   <?= $status_filter == 'pending'   ? 'selected' : '' ?>>Pending</option>
                        <option value="reviewed"  <?= $status_filter == 'reviewed'  ? 'selected' : '' ?>>Reviewed</option>
                        <option value="approved"  <?= $status_filter == 'approved'  ? 'selected' : '' ?>>Approved</option>
                        <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed (legacy)</option>
                        <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Supplier</label>
                    <select class="form-select select2-static" id="grn_filter_supplier" name="supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= $supplier['supplier_id'] ?>" <?= $supplier_filter == $supplier['supplier_id'] ? 'selected' : '' ?>>
                                <?= safe_output($supplier['supplier_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Warehouse</label>
                    <select class="form-select select2-static" id="grn_filter_warehouse" name="warehouse">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                            <option value="<?= $warehouse['warehouse_id'] ?>" <?= $warehouse_filter == $warehouse['warehouse_id'] ? 'selected' : '' ?>>
                                <?= safe_output($warehouse['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($enable_projects): ?>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Project</label>
                    <select class="form-select select2-static" id="grn_filter_project" name="project">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?= $project['project_id'] ?>" <?= $project_filter == $project['project_id'] ? 'selected' : '' ?>>
                                <?= safe_output($project['project_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Date From</label>
                    <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Date To</label>
                    <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                </div>
                <div class="col-md-6 d-flex align-items-end justify-content-end">
                    <button type="submit" class="btn btn-primary px-4 me-2">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                    <a href="<?= getUrl('grn') ?>" class="btn btn-outline-secondary px-4">
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
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="logReportAction('Printed GRNs List', 'User generated a printed report of the GRN list'); window.print()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportGRNs()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Export
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="grnTable.page.len(this.value).draw();">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
        <div class="d-none d-md-flex align-items-center gap-2">
            <span class="small fw-bold text-muted me-1"><i class="bi bi-display"></i> View:</span>
            <div class="btn-group shadow-sm" role="group" aria-label="View Mode Toggle">
                <input type="radio" class="btn-check" name="viewMode" id="tableViewBtn" checked onchange="toggleViewMode('table')">
                <label class="btn btn-outline-primary px-3" for="tableViewBtn" title="Table View">
                    <i class="bi bi-table"></i>
                </label>
                
                <input type="radio" class="btn-check" name="viewMode" id="cardViewBtn" onchange="toggleViewMode('card')">
                <label class="btn btn-outline-primary px-3" for="cardViewBtn" title="Card View">
                    <i class="bi bi-grid-3x3-gap"></i>
                </label>
            </div>
        </div>
    </div>

    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
       
        
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
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">GOODS RECEIVED NOTES REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-3">
        <div style="display: flex !important; flex-direction: row !important; gap: 8px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 80px; overflow: hidden;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 4px; font-weight: 600;">Total GRNs</p>
                <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt; line-height: 1.1;"><?= number_format($total_grns) ?></h3>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 80px; overflow: hidden;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 4px; font-weight: 600;">Draft Sheets</p>
                <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt; line-height: 1.1;"><?= number_format(count($draft_grns)) ?></h3>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 80px; overflow: hidden;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 4px; font-weight: 600;">Completed</p>
                <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt; line-height: 1.1;"><?= number_format(count($completed_grns)) ?></h3>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 80px; overflow: hidden;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 4px; font-weight: 600;">Total Value</p>
                <h3 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 12pt; line-height: 1.1;"><?= format_currency(array_sum(array_column($grns, 'total_value'))) ?></h3>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-print-none">
            <h5 class="mb-0 fw-bold">Goods Received Notes List</h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
                <div class="table-responsive" id="table-view-container">
                    <table class="table table-striped table-hover align-middle" id="grnTable" style="width:100%">
                        <thead class="bg-light">
                            <tr>
                                <th class="text-center" style="width:50px;">S/NO</th>
                                <th class="text-center">GRN #</th>
                                <th class="text-center">Date</th>
                                <th class="text-center">Supplier</th>
                                <th class="text-center">PO #</th>
                                <?php if ($enable_projects): ?><th class="text-center">Project</th><?php endif; ?>
                                <th class="text-center">Warehouse</th>
                                <th class="text-center">Items</th>
                                <th class="text-center">Total Value</th>
                                <th class="text-center">Received By</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Loaded via AJAX -->
                        </tbody>
                    </table>
                </div>

                <!-- Card View Container (Initially Hidden) -->
                <div id="card-view-container" class="row g-3 d-none">
                    <!-- Cards will be dynamically loaded here -->
                </div>
        </div>
    </div>
</div>




<script>
    // Define API URL dynamically or absolute
    const API_URL = "<?= getUrl('api/get_grns.php') ?>";
</script>

<!-- Include necessary scripts -->
<!--script src="https://code.jquery.com/jquery-3.6.0.min.js"></script-->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>
// JavaScript helper function for building URLs
function buildUrl(path) {
    return path;
}

// Format currency function
function formatCurrency(amount, currency = 'TZS') {
    const num = parseFloat(amount) || 0;
    return currency + ' ' + num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
}

let grnTable;
const canCreate = <?= json_encode($can_create_grn) ?>;
const canApprove = <?= json_encode($can_approve_grn) ?>;
const canDelete = <?= json_encode($can_delete_grn) ?>;

// Three-approval capability flags (mirrored from PHP)
const GRN_CAN_REVIEW  = <?= $grn_can_review  ? 'true' : 'false' ?>;
const GRN_CAN_APPROVE = <?= $grn_can_approve ? 'true' : 'false' ?>;
const GRN_IS_ADMIN    = <?= $grn_is_admin    ? 'true' : 'false' ?>;

// Define safe_output equivalent in JS
function safeOutput(str) {
    if (str === 0 || str === '0') return '0';
    if (!str) return '';
    return $('<div>').text(str).html();
}

    // View Mode Toggle Logic
    function toggleViewMode(mode) {
        const isMobile = window.innerWidth <= 767;
        if (isMobile) mode = 'card';
        if (mode === 'card') {
            $('#table-view-container').addClass('d-none');
            $('#card-view-container').removeClass('d-none');
            $('#cardViewBtn').prop('checked', true);
        } else {
            $('#table-view-container').removeClass('d-none');
            $('#card-view-container').addClass('d-none');
            $('#tableViewBtn').prop('checked', true);
        }
        if (!isMobile) localStorage.setItem('grnView', mode);
    }

    // Auto-detect screen size and set view mode
    function checkResponsiveView() {
        if (window.innerWidth <= 767) {
            toggleViewMode('card');
        } else {
            const saved = localStorage.getItem('grnView') || 'table';
            toggleViewMode(saved);
        }
    }

    function renderCards(data) {
        const container = $('#card-view-container');
        container.empty();
        
        if (data.length === 0) {
            container.html('<div class="col-12 text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i> No records found</div>');
            return;
        }
        
        data.each(function(row) {
            const statusClass = getStatusBadge(row.status);
            const cardHtml = `
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm border-0 hover-shadow transition-all" style="border-radius: 12px; border: 1px solid #eef2f6 !important;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <code class="small d-block mb-1">${safeOutput(row.receipt_number)}</code>
                                    <h6 class="fw-bold mb-0">${safeOutput(row.supplier_name)}</h6>
                                    <small class="text-muted">${safeOutput(row.company_name || '')}</small>
                                </div>
                                <span class="badge bg-${statusClass} small" style="font-size: 0.65rem;">${row.status.toUpperCase()}</span>
                            </div>
                            
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <small class="text-muted d-block small">Date</small>
                                    <span class="small fw-medium text-dark">${formatDate(row.receipt_date)}</span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block small">Total Value</small>
                                    <span class="small fw-bold text-dark">${formatCurrency(row.total_value)}</span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block small">Warehouse</small>
                                    <span class="small text-dark text-truncate d-block" title="${safeOutput(row.warehouse_name)}">${safeOutput(row.warehouse_name)}</span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block small">Items</small>
                                    <span class="small text-dark">${row.total_items} items</span>
                                </div>
                                <?php if ($enable_projects): ?>
                                <div class="col-12 mt-2">
                                    <small class="text-muted d-block small">Project</small>
                                    <span class="badge bg-info-soft text-info border border-info small p-1 text-wrap d-inline-block" style="max-width: 100%;">${safeOutput(row.project_name || 'N/A')}</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="border-top mt-2 pt-1">
                                <div class="small text-muted mb-1"><i class="bi bi-person me-1"></i> ${safeOutput(row.received_by_name)}</div>
                                <div style="display:flex; flex-wrap:nowrap; gap:4px;">
                                    <a href="<?= getUrl('grn_view') ?>?id=${row.receipt_id}" class="btn btn-outline-primary" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="<?= getUrl('grn_edit') ?>?id=${row.receipt_id}" class="btn btn-outline-secondary" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-danger" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" onclick="confirmDeleteGRN(${row.receipt_id})" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.append(cardHtml);
        });
    }

    $(document).ready(function() {
        // Select2 on DB-backed filter selects
        $('#grn_filter_supplier').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'All Suppliers' });
        $('#grn_filter_warehouse').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'All Warehouses' });
        <?php if ($enable_projects): ?>
        $('#grn_filter_project').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'All Projects' });
        <?php endif; ?>

        // Initial responsive check
        checkResponsiveView();

        // Listen for window resize
        $(window).resize(function() {
            checkResponsiveView();
        });

        // Log page view
        logReportAction('Viewed GRNs List', 'User viewed the goods received notes management list');


    // Load initial stats
    updateStatsFromPHP();

    // Initialize DataTable
    initTable();

    // Bind Filter Form
    $('#filterForm').on('submit', function(e) { // Make sure form has id="filterForm"
        e.preventDefault();
        loadGRNs();
    });
    
    // Also bind individual inputs for auto-search on change if desired, 
    // but the users usually expect "Apply Filters" button to work.
    // We can keep the button execution model.
});

function updateStatsFromPHP() {
    // Initial stats from PHP variables if needed, but we will rely on AJAX updates mostly.
    // Since we removed PHP loop, $total_grns etc might still be valid from initial page load if query was kept at top.
    // However, to be fully AJAX, let's update stats via the API response.
    // For the very first load, the top cards might be empty or show PHP values. 
    // To ensure consistency, we will let the first AJAX draw update them.
    // The PHP variables at TOP of file ($total_grns etc) calculate based on initial load.
    // If we want to keep using PHP for first paint, we can. 
    // But since we are moving to AJAX, the top query in grn.php should essentially be removed or minimized 
    // to just getting filter options (suppliers, warehouses).
}

function initTable() {
    grnTable = $('#grnTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: false, 
        autoWidth: false, 
        drawCallback: function(settings) {
            renderCards(this.api().rows({page:'current'}).data());
        },
        columnDefs: [
            { className: 'text-center', targets: '_all' }
        ],
        pageLength: 10,
        order: [[2, 'desc']], 
        ajax: {
            url: API_URL,
            data: function(d) {
                return $.extend({}, d, {
                    status: $('select[name="status"]').val(),
                    supplier: $('select[name="supplier"]').val(),
                    warehouse: $('select[name="warehouse"]').val(),
                    po: $('select[name="po"]').val(),
                    project: $('select[name="project"]').val(),
                    date_from: $('input[name="date_from"]').val(),
                    date_to: $('input[name="date_to"]').val()
                });
            },
            dataSrc: function(json) {
                if (json.success) {
                    if (json.stats) {
                        updateStats(json.stats);
                    }
                    return json.data;
                } else {
                    console.error("API Error: ", json.message);
                    Swal.fire({
                        icon: 'error',
                        title: 'Data Load Error',
                        text: 'API Error: ' + (json.message || 'Unknown error')
                    });
                    return [];
                }
            },
            error: function(xhr, error, thrown) {
                console.error("DataTables AJAX error:", error);
                console.error("Response:", xhr.responseText);
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Failed to load data. Status: ' + xhr.status + ' ' + xhr.statusText
                });
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '50px',
                className: 'ps-4 text-center text-muted small fw-bold',
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            { 
                data: 'receipt_number',
                render: function(data, type, row) {
                    let html = `<code class="small text-wrap-cell">${safeOutput(data)}</code>`;
                    if (row.notes) {
                        html += `<small class="text-muted d-block text-wrap-cell" title="${safeOutput(row.notes)}">${safeOutput(row.notes)}</small>`;
                    }
                    return html;
                }
            },
            { 
                data: 'receipt_date',
                render: function(data) { return `<span class="text-wrap-cell">${formatDate(data)}</span>`; }
            },
            { 
                data: 'supplier_name',
                render: function(data, type, row) {
                    let html = `<div class="text-wrap-cell fw-bold" title="${safeOutput(data)}">${safeOutput(data)}</div>`;
                    if (row.company_name) {
                        html += `<div class="text-wrap-cell text-muted small" title="${safeOutput(row.company_name)}">${safeOutput(row.company_name)}</div>`;
                    }
                    return html;
                }
            },
            { 
                data: 'order_number',
                width: '100px',
                render: function(data, type, row) {
                    if (data) {
                        return `<a href="<?= getUrl('purchase_order_view') ?>?id=${row.purchase_order_id}" class="text-decoration-none small">${safeOutput(data)}</a>`;
                    }
                    return '<span class="text-muted small">N/A</span>';
                }
            },
            <?php if ($enable_projects): ?>
            {
                data: 'project_name',
                render: function(data) { 
                    return data ? `<div class="text-wrap-cell"><span class="badge bg-info-soft text-info border border-info small p-1 text-wrap w-100" style="white-space: normal; word-break: break-word;">${safeOutput(data)}</span></div>` : '<span class="text-muted small">N/A</span>'; 
                }
            },
            <?php endif; ?>
            { 
                data: 'warehouse_name',
                render: function(data) { return `<div class="text-truncate small" style="max-width: 100px;" title="${safeOutput(data)}">${safeOutput(data)}</div>`; }
            },
            { 
                data: 'total_items',
                width: '80px',
                render: function(data, type, row) {
                    return `<span class="badge bg-secondary p-1">${data}</span> <small>items</small>`;
                }
            },
            { 
                data: 'total_value',
                width: '110px',
                render: function(data) {
                    return `<strong class="small">${formatCurrency(data)}</strong>`;
                }
            },
            { 
                data: 'received_by_name',
                width: '100px',
                render: function(data) { return `<div class="text-truncate small" style="max-width: 80px;" title="${safeOutput(data)}">${safeOutput(data)}</div>`; }
            },
            { 
                data: 'status',
                className: 'text-center',
                width: '90px',
                render: function(data) {
                    const badgeClass = getStatusBadge(data);
                    return `<span class="badge bg-${badgeClass} small" style="font-size: 0.7rem; padding: 4px 8px;">${data.toUpperCase()}</span>`;
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    let actions = `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="<?= getUrl('grn_view') ?>?id=${row.receipt_id}" onclick="logReportAction('Viewed GRN Details', 'User viewed details for GRN #' + row.receipt_number)">
                                        <i class="bi bi-eye"></i> View GRN
                                    </a>
                                </li>
                    `;
                    
                    const isPending  = (row.status === 'pending');
                    const isReviewed = (row.status === 'reviewed');
                    const isApproved = (row.status === 'approved');
                    const inWorkflow = (isPending || isReviewed);
                    const canEditNow = (inWorkflow || GRN_IS_ADMIN);

                    if (canEditNow) {
                        actions += `
                            <li>
                                <a class="dropdown-item text-primary" href="<?= getUrl('grn_edit') ?>?id=${row.receipt_id}" onclick="logReportAction('Initiated GRN Edit', 'User clicked edit for GRN #' + row.receipt_number)">
                                    <i class="bi bi-pencil"></i> Edit GRN
                                </a>
                            </li>
                        `;
                    }

                    // Parallel Review + Approve (one active, one disabled)
                    if (inWorkflow && GRN_CAN_REVIEW) {
                        if (isPending) {
                            actions += `<li><a class="dropdown-item text-primary fw-bold" href="#" onclick="markReviewedGRN(${row.receipt_id})"><i class="bi bi-check2"></i> Mark Reviewed</a></li>`;
                        } else {
                            actions += `<li><a class="dropdown-item text-muted disabled" href="#" tabindex="-1" aria-disabled="true" title="Already reviewed"><i class="bi bi-check2"></i> Mark Reviewed</a></li>`;
                        }
                    }
                    if (inWorkflow && GRN_CAN_APPROVE) {
                        if (isReviewed) {
                            actions += `<li><a class="dropdown-item text-success fw-bold" href="#" onclick="approveGRN(${row.receipt_id})"><i class="bi bi-check-circle"></i> Approve GRN</a></li>`;
                        } else {
                            actions += `<li><a class="dropdown-item text-muted disabled" href="#" tabindex="-1" aria-disabled="true" title="Must be reviewed before approval"><i class="bi bi-check-circle"></i> Approve GRN</a></li>`;
                        }
                    }

                    if (canEditNow) {
                        actions += `
                            <li>
                                <a class="dropdown-item text-warning" href="#" onclick="updateGRNStatus(${row.receipt_id}, 'cancelled')">
                                    <i class="bi bi-x-octagon"></i> Cancel GRN
                                </a>
                            </li>
                        `;
                    }
                    
                    actions += `
                            <li>
                                <a class="dropdown-item" href="#" onclick="printGRN(${row.receipt_id})">
                                    <i class="bi bi-printer"></i> Print GRN
                                </a>
                            </li>
                    `;
                    
                    if (canDelete && (isPending || GRN_IS_ADMIN)) {
                        actions += `<li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="#" onclick="confirmDeleteGRN(${row.receipt_id})">
                                    <i class="bi bi-trash"></i> Delete GRN
                                </a>
                            </li>
                        `;
                    }
                    
                    actions += `</ul></div>`;
                    return actions;
                }
            }
        ],
        language: {
            processing: '<div class="spinner-border text-primary" role="status"><span></span></div>',
            emptyTable: '<div class="text-center my-3"><i class="bi bi-clipboard-check display-4 text-muted"></i><p class="mt-2">No Goods Received Notes Found</p></div>',
            lengthMenu: "Show _MENU_ entries"
        },
        lengthChange: false, // Using custom selector
        dom: 'rtip' // Hide default length and filter
    });
}

function loadGRNs() {
    grnTable.ajax.reload();
}

function updateStats(stats) {
    if (!stats) return;
    
    // Update logic assuming the ID's exist in the DOM
    // The previous PHP file had generic classes, we need to make sure IDs exist or are targetable.
    // Let's add IDs to the stats cards in a separate edit or verify they exist.
    // The sales_orders.php used specific IDs like #stat-total-orders.
    // grn.php used PHP echo directly. I need to update the HTML to include IDs for these stats.
    
    // Safe update based on finding elements by text or position is risky.
    // Better to update the HTML first to add IDs.
    // I will do that in the next step. For now, I'll log the stats.
    console.log("Stats update:", stats);
    
    // Attempt to update if IDs are added (I will add them next)
    // Safe update for statistics cards
    if ($('#stat-total-grns').length) $('#stat-total-grns').text(stats.total_grns || 0);
    if ($('#stat-draft-grns').length) $('#stat-draft-grns').text(stats.draft_count || 0);
    if ($('#stat-completed-grns').length) $('#stat-completed-grns').text(stats.completed_count || 0);
    if ($('#stat-cancelled-grns').length) $('#stat-cancelled-grns').text(stats.cancelled_count || 0);
    if ($('#stat-total-value').length) $('#stat-total-value').text(formatCurrency(stats.total_value || 0));
}

function getStatusBadge(status) {
    switch (status) {
        case 'active':
        case 'approved':
        case 'completed':
        case 'success':
            return 'success';
        case 'pending':
        case 'waiting':
            return 'warning';
        case 'draft':
            return 'secondary';
        case 'cancelled':
        case 'deleted':
        case 'void':
            return 'danger';
        default:
            return 'secondary';
    }
}

function markReviewedGRN(receiptId) {
    Swal.fire({
        title: 'Mark as Reviewed?',
        text: 'GRN will move to Reviewed and become approvable.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#0d6efd', confirmButtonText: 'Yes, mark reviewed'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/review_grn.php') ?>', { receipt_id: receiptId }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Reviewed!', text: res.message, timer: 1800, showConfirmButton: false });
                grnTable.ajax.reload();
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function approveGRN(receiptId) {
    Swal.fire({
        title: 'Approve GRN?',
        text: 'Stock will be updated on approval.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#198754', confirmButtonText: 'Yes, approve'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/approve_grn.php') ?>', { receipt_id: receiptId }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Approved!', text: res.message, timer: 2000, showConfirmButton: false });
                grnTable.ajax.reload();
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function updateGRNStatus(receiptId, status) {
    const actionMap = {
        'completed': 'complete',
        'cancelled': 'cancel'
    };
    
    const action = actionMap[status] || 'update';
    const actionText = status.charAt(0).toUpperCase() + status.slice(1);
    const icon = status === 'completed' ? 'success' : 'warning';
    
    Swal.fire({
        title: `Are you sure?`,
        text: `Do you want to ${action} this GRN?`,
        icon: icon,
        showCancelButton: true,
        confirmButtonText: `Yes, ${actionText}`,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl("api/update_grn_status.php") ?>',
                type: 'POST',
                data: { 
                    receipt_id: receiptId,
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Updated GRN Status', 'User updated GRN #' + receiptId + ' status to ' + status);
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            loadGRNs(); // Reload DataTables instead of location.reload()
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred. Please try again.'
                    });
                }
            });
        }
    });
}

function confirmDeleteGRN(receiptId) {
    Swal.fire({
        title: 'Delete GRN',
        text: 'Are you sure you want to delete this GRN? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl("api/delete_grn.php") ?>',
                type: 'POST',
                data: { receipt_id: receiptId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Deleted GRN', 'User deleted GRN ID #' + receiptId);
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            loadGRNs();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred. Please try again.'
                    });
                }
            });
        }
    });
}

function printGRN(receiptId) {
    logReportAction('Printed GRN', 'User generated a printed GRN for ID #' + receiptId);
    // Open the dedicated print page in the same window to enable back button
    window.location.href = `<?= getUrl('grn_print') ?>?id=${receiptId}`;
}

function exportGRNs() {
    logReportAction('Exported GRNs list', 'User exported the GRN list to Excel/CSV');
    const params = new URLSearchParams({
        export: 'excel',
        status: $('select[name="status"]').val(),
        supplier: $('select[name="supplier"]').val(),
        warehouse: $('select[name="warehouse"]').val(),
        po: $('select[name="po"]').val(),
        date_from: $('input[name="date_from"]').val(),
        date_to: $('input[name="date_to"]').val()
    });
    
    window.location.href = '<?= getUrl("api/export_grns.php") ?>?' + params.toString();
}

// Remove the old quick search keyup logic as DataTables handles it
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
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

#grnTable {
    font-size: 0.82rem !important;
}
#grnTable th, #grnTable td {
    padding: 8px 4px !important;
    vertical-align: middle;
}
#grnTable thead th {
    font-size: 0.75rem !important;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.text-truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Print styles */
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
        color: #000 !important; /* Ensure main table text is black */
    }
    
    .table thead th {
        background-color: #f8f9fa !important;
        border: 1px solid #000 !important;
        padding: 8px 4px !important;
        font-weight: 900 !important; /* Extra bold headers */
        color: #000 !important;
        text-transform: uppercase;
    }
    
    .table td {
        border: 1px solid #000 !important; /* Darker borders for clarity */
        padding: 6px 4px !important;
        color: #000 !important;
    }

    /* Force all nested elements to be black during print */
    .table td *, .table td strong, .table td span, .table td div, .table td small, .table td code {
        color: #000 !important;
        opacity: 1 !important;
        font-weight: 500;
    }

    /* Keep important badges bold but black text */
    .badge {
        color: #000 !important;
        border: 1px solid #000 !important;
        background: transparent !important;
        font-weight: bold !important;
    }
    
    .table th:last-child,
    .table td:last-child,
    .dataTables_info,
    .dataTables_paginate,
    .paging_simple_numbers {
        display: none !important;
    }
}
</style>

<?php
// Include the footer
includeFooter();

// Flush the buffer
ob_end_flush();
?>

