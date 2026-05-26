<?php
// File: app/bms/stock/locations.php
// scope-audit: skip — warehouse location management; locations are sub-entities of warehouses, no independent project_id
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('locations');

$user_id = $_SESSION['user_id'];
$can_add = isAdmin() || canCreate('locations');
$can_edit = isAdmin() || canEdit('locations');
$can_delete = isAdmin() || canDelete('locations');

// Fetch company settings for print
$c_logo = getSetting('company_logo', '');
$c_name = getSetting('company_name', 'BMS');


// Get filter parameters
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Process Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Location
    if (isset($_POST['add_location']) && $can_add) {
        $loc_name = trim($_POST['location_name']);
        $loc_code = trim($_POST['location_code']);
        $wh_id = intval($_POST['warehouse_id']);
        $capacity = $_POST['capacity'] ?: null;
        $loc_type = $_POST['location_type'];
        $status = $_POST['status'];
        
        if (!empty($loc_name) && $wh_id > 0) {
            try {
                $query = "INSERT INTO locations (warehouse_id, location_name, location_code, location_type, capacity, status, created_by) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$wh_id, $loc_name, $loc_code, $loc_type, $capacity, $status, $user_id]);
                logActivity($pdo, $user_id, 'Created Storage Location', "User created a new location: $loc_name in Warehouse ID: $wh_id");
                $_SESSION['success'] = "Location added successfully!";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Location name and Warehouse are required";
        }
        $redirect_params = [];
        if ($warehouse_id) $redirect_params[] = "warehouse_id=$warehouse_id";
        if ($project_id) $redirect_params[] = "project_id=$project_id";
        $redirect_url = getUrl('locations') . (!empty($redirect_params) ? "?" . implode("&", $redirect_params) : "");
        header("Location: $redirect_url");
        exit();
    }

    // Update Location
    if (isset($_POST['update_location']) && $can_edit) {
        $loc_id = intval($_POST['location_id']);
        $loc_name = trim($_POST['location_name']);
        $loc_code = trim($_POST['location_code']);
        $wh_id = intval($_POST['warehouse_id']);
        $capacity = $_POST['capacity'] ?: null;
        $loc_type = $_POST['location_type'];
        $status = $_POST['status'];

        try {
            $query = "UPDATE locations SET warehouse_id = ?, location_name = ?, location_code = ?, location_type = ?, capacity = ?, status = ?, updated_by = ?, updated_at = NOW() 
                      WHERE location_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$wh_id, $loc_name, $loc_code, $loc_type, $capacity, $status, $user_id, $loc_id]);
            logActivity($pdo, $user_id, 'Updated Storage Location', "User updated location ID: $loc_id ($loc_name)");
            $_SESSION['success'] = "Location updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        $redirect_params = [];
        if ($warehouse_id) $redirect_params[] = "warehouse_id=$warehouse_id";
        if ($project_id) $redirect_params[] = "project_id=$project_id";
        $redirect_url = getUrl('locations') . (!empty($redirect_params) ? "?" . implode("&", $redirect_params) : "");
        header("Location: $redirect_url");
        exit();
    }

    // Delete Location
    if (isset($_POST['delete_location']) && $can_delete) {
        $loc_id = intval($_POST['location_id']);
        try {
            // Check for stock
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_stocks WHERE location_id = ?");
            $stmt->execute([$loc_id]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['error'] = "Cannot delete location with existing stock.";
            } else {
                $pdo->prepare("UPDATE locations SET status = 'deleted' WHERE location_id = ?")->execute([$loc_id]);
                logActivity($pdo, $user_id, 'Deleted Storage Location', "User deleted location ID: $loc_id");
                $_SESSION['success'] = "Location deleted successfully!";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        $redirect_params = [];
        if ($warehouse_id) $redirect_params[] = "warehouse_id=$warehouse_id";
        if ($project_id) $redirect_params[] = "project_id=$project_id";
        $redirect_url = getUrl('locations') . (!empty($redirect_params) ? "?" . implode("&", $redirect_params) : "");
        header("Location: $redirect_url");
        exit();
    }
}

// Include header AFTER all POST handling so header() redirects work
includeHeader();

// Fetch Warehouses for dropdown
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

// Build query for locations
$where = ["l.status != 'deleted'"];
$params = [];

if ($warehouse_id > 0) {
    $where[] = "l.warehouse_id = ?";
    $params[] = $warehouse_id;
}
if ($status_filter !== 'all') {
    $where[] = "l.status = ?";
    $params[] = $status_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where);

$query = "
    SELECT l.*, w.warehouse_name,
           (SELECT COUNT(*) FROM product_stocks WHERE location_id = l.location_id) as item_count,
           (SELECT SUM(stock_quantity) FROM product_stocks WHERE location_id = l.location_id) as total_quantity
    FROM locations l
    JOIN warehouses w ON l.warehouse_id = w.warehouse_id
    $where_clause
    ORDER BY w.warehouse_name ASC, l.location_name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Statistics (Filter-aware)
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN l.status = 'inactive' THEN 1 ELSE 0 END) as inactive,
        SUM(CASE WHEN (SELECT COUNT(*) FROM product_stocks WHERE location_id = l.location_id) > 0 THEN 1 ELSE 0 END) as occupied
    FROM locations l
    JOIN warehouses w ON l.warehouse_id = w.warehouse_id
    $where_clause
";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

?>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 0 !important;
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

/* Mobile sticky navbar */
@media (max-width: 767px) {
    .navbar, nav.navbar { position: sticky; top: 0; z-index: 1020; }
}

/* Print styles */
@media print {
    .d-print-none {
        display: none !important;
    }
    .print-header {
        display: block !important;
        text-align: center;
        margin-bottom: 25px;
        border-bottom: 3px solid #0d6efd;
        padding-bottom: 15px;
    }
    .print-header h1 {
        color: #0d6efd !important;
        margin: 0;
        font-size: 24pt;
    }
    .print-header h2 {
        color: #495057 !important;
        font-size: 16pt;
        margin: 5px 0;
    }

    #print-stats-cards {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        width: 100% !important;
        gap: 10px !important;
        margin-bottom: 20px !important;
    }
    #print-stats-cards > div {
        flex: 1 1 25% !important;
        max-width: 25% !important;
        width: 25% !important;
        margin-bottom: 0 !important;
    }
    .custom-stat-card {
        border: 1px solid #badbcc !important;
        border-radius: 0 !important;
        background-color: #d1e7dd !important;
        padding: 6px 8px !important;
        overflow: hidden !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .custom-stat-card .card-body {
        padding: 6px 8px !important;
        overflow: hidden !important;
    }
    .custom-stat-card h4 {
        font-size: 14pt !important;
        white-space: normal !important;
        word-break: break-word !important;
    }
    .custom-stat-card p {
        font-size: 8pt !important;
        white-space: normal !important;
    }
    .custom-stat-card i {
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
    .alert {
        display: none !important;
    }
    
    .d-flex .badge {
        display: none !important;
    }
    
    .table .badge {
        display: inline-block !important;
        border: 1px solid #000;
        padding: 2px 6px;
        font-size: 10px;
    }
    
    .row.mb-4:first-of-type {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
        page-break-inside: avoid;
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
</style>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeaderSection">
        
        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Storage Locations Report</h2>
        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <?php if($project_id > 0): ?>
                <li class="breadcrumb-item"><a href="<?= getUrl('project_view') . '?id=' . $project_id . '#procurements' ?>">Project Details</a></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?= getUrl('warehouses') ?>">Inventory</a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active">Storage Locations</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="bi bi-geo-alt-fill text-primary"></i> Storage Locations</h2>
                    <p class="text-muted mb-0">Manage and track inventory storage slots</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if($project_id > 0): ?>
                        <a href="<?= getUrl('project_view') . '?id=' . $project_id . '#procurements' ?>" class="btn btn-outline-secondary px-4 shadow-sm">
                            <i class="bi bi-arrow-left me-2"></i> Back to Project
                        </a>
                    <?php endif; ?>
                    <?php if ($can_add): ?>
                    <button class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#locationModal" onclick="prepareAdd()">
                        <i class="bi bi-plus-circle me-2"></i> Add Location
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4" id="print-stats-cards">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= number_format($stats['total']) ?></h4>
                            <p class="mb-0">Total Locations</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-geo-alt" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0"><?= number_format($stats['active']) ?></h4>
                            <p class="mb-0">Active Locations</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0"><?= number_format($stats['inactive']) ?></h4>
                            <p class="mb-0">Inactive Only</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0"><?= number_format($stats['occupied']) ?></h4>
                            <p class="mb-0">With Stock</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card border-0 shadow-sm mb-4 d-print-none">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Parameters</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-center">
                <div class="col-md-4">
                    <label class="form-label">Warehouse</label>
                    <select name="warehouse_id" id="filter_warehouse" class="form-select select2-static">
                        <option value="0">All Warehouses</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['warehouse_id'] ?>" <?= $warehouse_id == $wh['warehouse_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($wh['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Filter by Status</label>
                    <select name="status" id="filter_status" class="form-select select2-static">
                        <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active Only</option>
                        <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                    </select>
                </div>
                <div class="col-md-5 d-flex align-items-end justify-content-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                    <a href="<?= getUrl('locations') . ($project_id > 0 ? '?project_id=' . $project_id : '') ?>" class="btn btn-outline-secondary">
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
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="logReportAction('Printed Locations List', 'User generated a printed list of storage locations'); window.print()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportLocations()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Export
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#locationsTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
        <div>
            <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill">
                <i class="bi bi-check-circle-fill me-1"></i> <?= $stats['total'] ?> locations
            </span>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({ icon: 'success', title: 'Success!', text: <?= json_encode($_SESSION['success']) ?>, confirmButtonColor: '#198754' });
    });
    </script>
    <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({ icon: 'error', title: 'Error', text: <?= json_encode($_SESSION['error']) ?>, confirmButtonColor: '#dc3545' });
    });
    </script>
    <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-print-none">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">Locations List</h5>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle" id="locationsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px;">S/NO</th>
                            <th>Location Name</th>
                            <th>Code</th>
                            <th>Warehouse</th>
                            <th>Type</th>
                            <th class="text-center">Products</th>
                            <th class="text-center">Sub-qty</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sn = 1;
                        foreach ($locations as $loc): ?>
                        <tr>
                            <td class="text-muted small fw-bold"><?= $sn++ ?></td>
                            <td><strong><?= htmlspecialchars($loc['location_name'] ?? '') ?></strong></td>
                            <td><code class="custom-code"><?= htmlspecialchars($loc['location_code'] ?? '') ?></code></td>
                            <td><?= htmlspecialchars($loc['warehouse_name'] ?? '') ?></td>
                            <td class="text-capitalize"><?= htmlspecialchars($loc['location_type'] ?? '') ?></td>
                            <td class="text-center"><span class="badge bg-secondary"><?= $loc['item_count'] ?></span></td>
                            <td class="text-center"><?= number_format($loc['total_quantity'] ?? 0, 0) ?></td>
                            <td class="text-center"><?= get_status_badge($loc['status'] ?? '') ?></td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="bi bi-gear"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <?php if ($can_edit): ?>
                                        <li>
                                            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#locationModal" 
                                               onclick='prepareEdit(<?= json_encode($loc) ?>)'>
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <?php if ($can_delete && $loc['item_count'] == 0): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item text-danger" href="#" onclick="deleteLocation(<?= $loc['location_id'] ?>)">
                                                <i class="bi bi-trash"></i> Delete
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View -->
            <div class="d-md-none" id="locationsCards">
                <?php foreach ($locations as $loc): ?>
                <div class="border rounded mb-2 p-2" style="font-size:0.85rem;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong><?= htmlspecialchars($loc['location_name'] ?? '') ?></strong>
                        <?= get_status_badge($loc['status'] ?? '') ?>
                    </div>
                    <div class="text-muted mb-1">
                        <code class="custom-code"><?= htmlspecialchars($loc['location_code'] ?? '') ?></code>
                        &nbsp;|&nbsp; <?= htmlspecialchars($loc['warehouse_name'] ?? '') ?>
                        &nbsp;|&nbsp; <span class="text-capitalize"><?= htmlspecialchars($loc['location_type'] ?? '') ?></span>
                    </div>
                    <div class="text-muted mb-1" style="font-size:0.78rem;">
                        <i class="bi bi-box"></i> <?= $loc['item_count'] ?> products &nbsp;|&nbsp; Qty: <?= number_format($loc['total_quantity'] ?? 0, 0) ?>
                    </div>
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding-top:0.5rem;border-top:1px solid #dee2e6;background:#fff;">
                        <?php if ($can_edit): ?>
                        <button class="btn btn-outline-primary btn-sm" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"
                            data-bs-toggle="modal" data-bs-target="#locationModal"
                            onclick='prepareEdit(<?= json_encode($loc) ?>)' title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($can_delete && $loc['item_count'] == 0): ?>
                        <button class="btn btn-outline-danger btn-sm" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"
                            onclick="deleteLocation(<?= $loc['location_id'] ?>)" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($locations)): ?>
                <div class="text-center text-muted py-4">No locations found.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="locationModal" tabindex="-1" aria-modal="true" role="dialog" aria-labelledby="modalTitle">
    <div class="modal-dialog">
        <form method="POST" id="locationForm" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">Add Location</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="location_id" id="loc_id">
                <div class="mb-3">
                    <label class="form-label">Warehouse *</label>
                    <select name="warehouse_id" id="loc_wh" class="form-select select2-static" required>
                        <option value="">Select Warehouse</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['warehouse_id'] ?>"><?= htmlspecialchars($wh['warehouse_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Location Name *</label>
                        <input type="text" name="location_name" id="loc_name" class="form-control" required placeholder="e.g. Shelf A1">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Code</label>
                        <input type="text" name="location_code" id="loc_code" class="form-control" placeholder="A1">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Type</label>
                        <select name="location_type" id="loc_type" class="form-select select2-static">
                            <option value="storage">Storage</option>
                            <option value="receiving">Receiving</option>
                            <option value="shipping">Shipping</option>
                            <option value="picking">Picking</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Capacity (Optional)</label>
                        <input type="number" name="capacity" id="loc_cap" class="form-control" placeholder="0">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" id="loc_status" class="form-select select2-static">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="add_location" id="submitBtn" class="btn btn-primary">Save Location</button>
            </div>
        </form>
    </div>
</div>

<script>
function exportLocations() {
    logReportAction('Exported Locations List', 'User exported storage locations records to CSV');
    const table = document.getElementById('locationsTable');
    let csv = 'Name,Code,Warehouse,Type,Products,Qty,Status\n';
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cols = row.querySelectorAll('td');
        if (cols.length > 0) {
            const name = cols[0].textContent.trim();
            const code = cols[1].textContent.trim();
            const warehouse = cols[2].textContent.trim();
            const type = cols[3].textContent.trim();
            const products = cols[4].textContent.trim();
            const qty = cols[5].textContent.trim();
            const status = cols[6].textContent.trim();
            csv += `"${name}","${code}","${warehouse}","${type}","${products}","${qty}","${status}"\n`;
        }
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'locations_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

$(document).ready(function() {
    $('#locationsTable').DataTable({
        pageLength: 25,
        lengthChange: false,
        order: [[2, 'asc'], [0, 'asc']]
    });

    // Select2 on filter dropdowns
    $('#filter_warehouse, #filter_status').select2({
        theme: 'bootstrap-5',
        allowClear: true,
        width: '100%'
    });

    // Select2 on modal dropdowns — re-init on each open so dropdownParent is set
    $('#locationModal').on('shown.bs.modal', function() {
        ['#loc_wh', '#loc_type', '#loc_status'].forEach(function(sel) {
            var $el = $(sel);
            if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
            $el.select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#locationModal'),
                allowClear: true,
                width: '100%'
            });
        });
    });

    logReportAction('Viewed Locations Page', 'User viewed the storage locations management page');
    
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('warehouse_id') || urlParams.has('status')) {
        logReportAction('Filtered Locations', 'User applied search filters to storage locations');
    }
});

function prepareAdd() {
    $('#modalTitle').text('Add New Location');
    $('#locationForm')[0].reset();
    $('#loc_id').val('');
    $('#submitBtn').attr('name', 'add_location').text('Save Location');
    // Set warehouse if filtered
    <?php if ($warehouse_id > 0): ?>
    $('#loc_wh').val('<?= $warehouse_id ?>');
    <?php endif; ?>
}

function prepareEdit(loc) {
    $('#modalTitle').text('Edit Location');
    $('#loc_id').val(loc.location_id);
    $('#loc_wh').val(loc.warehouse_id);
    $('#loc_name').val(loc.location_name);
    $('#loc_code').val(loc.location_code);
    $('#loc_type').val(loc.location_type);
    $('#loc_cap').val(loc.capacity);
    $('#loc_status').val(loc.status);
    $('#submitBtn').attr('name', 'update_location').text('Update Location');
}

function deleteLocation(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            // Usually we'd use AJAX here or a hidden form
            // For now, let's assume there's a delete_location endpoint or handled via POST
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="delete_location" value="1"><input type="hidden" name="location_id" value="${id}">`;
            document.body.appendChild(form);
            form.submit();
        }
    })
}
</script>

<?php
includeFooter();
ob_end_flush();
?>
