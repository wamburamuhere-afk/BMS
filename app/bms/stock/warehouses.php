<?php
// File: app/bms/stock/warehouses.php
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('warehouses');

// Initialize user ID and permissions early for POST handling
$user_id = $_SESSION['user_id'] ?? null;
$can_add_warehouses = isAdmin() || canCreate('warehouses');
$can_edit_warehouses = isAdmin() || canEdit('warehouses');
$can_delete_warehouses = isAdmin() || canDelete('warehouses');
$can_manage_warehouse_settings = isAdmin() || canEdit('warehouses');

// Handle form submissions BEFORE includeHeader() to allow redirects
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid form submission";
        header("Location: warehouses.php");
        exit();
    }

    // Add new warehouse
    if (isset($_POST['add_warehouse']) && $can_add_warehouses) {
        $warehouse_name = trim($_POST['warehouse_name']);
        $warehouse_code = trim($_POST['warehouse_code']);
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $postal_code = trim($_POST['postal_code'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $manager_name = trim($_POST['manager_name'] ?? '');
        $manager_phone = trim($_POST['manager_phone'] ?? '');
        $capacity = ($_POST['capacity'] ?? null) ?: null;
        $status = $_POST['status'] ?? 'active';
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        $project_id = ($_POST['project_id'] ?? null) ?: null;
        $notes = trim($_POST['notes'] ?? '');

        // Validate input
        $errors = [];
        if (empty($warehouse_name)) {
            $errors[] = "Warehouse name is required";
        }
        if (empty($warehouse_code)) {
            $errors[] = "Warehouse code is required";
        } else {
            // Check if warehouse code already exists
            $check_stmt = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_code = ?");
            $check_stmt->execute([$warehouse_code]);
            if ($check_stmt->fetch()) {
                $errors[] = "Warehouse code '{$warehouse_code}' already exists. Please use a different code.";
            }
        }

        if (empty($errors)) {
            try {
                // If setting as primary, update all others to not primary
                if ($is_primary) {
                    $pdo->query("UPDATE warehouses SET is_primary = 0 WHERE is_primary = 1");
                }

                // Combine address fields into single address
                $full_address = trim($address);
                if (!empty($city)) $full_address .= ($full_address ? ', ' : '') . $city;
                if (!empty($state)) $full_address .= ($full_address ? ', ' : '') . $state;
                if (!empty($country)) $full_address .= ($full_address ? ', ' : '') . $country;
                if (!empty($postal_code)) $full_address .= ' ' . $postal_code;
                
                // Combine location info
                $location_info = trim($city);
                if (!empty($country)) $location_info .= ($location_info ? ', ' : '') . $country;

                $query = "INSERT INTO warehouses (
                    warehouse_name, warehouse_code, location, address, 
                    city, state, country, postal_code,
                    contact_person, phone, email, 
                    capacity, status, is_primary, project_id, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    $warehouse_name, $warehouse_code, $location_info, $address,
                    $city, $state, $country, $postal_code,
                    $manager_name, $phone, $email,
                    $capacity, $status, $is_primary, $project_id, $notes, $user_id
                ]);

                $warehouse_id = $pdo->lastInsertId();
                
                // Create default location for the warehouse
                $query = "INSERT INTO locations (
                    warehouse_id, location_name, location_code, location_type, 
                    capacity, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    $warehouse_id,
                    "Main Storage Area",
                    "MAIN",
                    "storage",
                    $capacity,
                    "active",
                    $user_id
                ]);

                logActivity($pdo, $user_id, 'Create warehouse', "User created a new warehouse: $warehouse_name ($warehouse_code)");
                $_SESSION['success'] = "Warehouse added successfully!";
                header("Location: warehouses.php");
                exit();
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
                header("Location: warehouses.php");
                exit();
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
            header("Location: warehouses.php");
            exit();
        }
    }

    // Update warehouse
    if (isset($_POST['update_warehouse']) && $can_edit_warehouses) {
        $warehouse_id = intval($_POST['warehouse_id']);
        $warehouse_name = trim($_POST['warehouse_name']);
        $warehouse_code = trim($_POST['warehouse_code']);
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $postal_code = trim($_POST['postal_code'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $manager_name = trim($_POST['manager_name'] ?? '');
        $manager_phone = trim($_POST['manager_phone'] ?? '');
        $capacity = ($_POST['capacity'] ?? null) ?: null;
        $status = $_POST['status'] ?? 'active';
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        $project_id = ($_POST['project_id'] ?? null) ?: null;
        $notes = trim($_POST['notes'] ?? '');

        // Validate input
        $errors = [];
        if (empty($warehouse_name)) {
            $errors[] = "Warehouse name is required";
        }
        if (empty($warehouse_code)) {
            $errors[] = "Warehouse code is required";
        } else {
            // Check if warehouse code already exists (excluding current warehouse)
            $check_stmt = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_code = ? AND warehouse_id != ?");
            $check_stmt->execute([$warehouse_code, $warehouse_id]);
            if ($check_stmt->fetch()) {
                $errors[] = "Warehouse code '{$warehouse_code}' already exists. Please use a different code.";
            }
        }

        if (empty($errors)) {
            try {
                // If setting as primary, update all others to not primary
                if ($is_primary) {
                    $stmt_primary = $pdo->prepare("UPDATE warehouses SET is_primary = 0 WHERE is_primary = 1 AND warehouse_id != ?");
                    $stmt_primary->execute([$warehouse_id]);
                }

                // For now, use the provided address as both full address and location info
                $full_address = $address;
                $location_info = $address; // Or parse it if needed

                $query = "UPDATE warehouses SET
                    warehouse_name = ?, warehouse_code = ?, location = ?, address = ?, 
                    city = ?, state = ?, country = ?, postal_code = ?,
                    contact_person = ?, phone = ?, email = ?, 
                    capacity = ?, status = ?, is_primary = ?, project_id = ?, notes = ?, updated_by = ?
                    WHERE warehouse_id = ?";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    $warehouse_name, $warehouse_code, $location_info, $address,
                    $city, $state, $country, $postal_code,
                    $manager_name, $phone, $email,
                    $capacity, $status, $is_primary, $project_id, $notes, $user_id, $warehouse_id
                ]);

                logActivity($pdo, $user_id, 'Edit warehouse', "User edited warehouse: $warehouse_name ($warehouse_code)");
                $_SESSION['success'] = "Warehouse updated successfully!";
                header("Location: warehouses.php");
                exit();
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
                header("Location: warehouses.php");
                exit();
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
            header("Location: warehouses.php");
            exit();
        }
    }

    // Delete warehouse
    if (isset($_POST['delete_warehouse']) && $can_delete_warehouses) {
        $warehouse_id = intval($_POST['warehouse_id']);

        try {
            // Cascade delete related data, then soft-delete the warehouse
            $pdo->prepare("DELETE FROM product_stocks WHERE warehouse_id = ?")->execute([$warehouse_id]);
            $pdo->prepare("DELETE FROM stock_movements WHERE warehouse_id = ?")->execute([$warehouse_id]);
            $pdo->prepare("DELETE FROM locations WHERE warehouse_id = ?")->execute([$warehouse_id]);
            $pdo->prepare("UPDATE warehouses SET status = 'deleted', updated_by = ?, updated_at = NOW() WHERE warehouse_id = ?")
                ->execute([$user_id, $warehouse_id]);

            logActivity($pdo, $user_id, 'Deleted Warehouse', "User deleted warehouse ID: $warehouse_id");
            $_SESSION['success'] = "Warehouse deleted successfully!";
            header("Location: warehouses.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
            header("Location: warehouses.php");
            exit();
        }
    }

    // Toggle warehouse status
    if (isset($_POST['toggle_status']) && $can_edit_warehouses) {
        $warehouse_id = intval($_POST['warehouse_id']);
        $new_status = $_POST['new_status'];
        
        try {
            $query = "UPDATE warehouses SET status = ?, updated_by = ?, updated_at = NOW() WHERE warehouse_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$new_status, $user_id, $warehouse_id]);

            logActivity($pdo, $user_id, 'Updated Warehouse Status', "User changed warehouse ID $warehouse_id status to $new_status");
            $_SESSION['success'] = "Warehouse status updated!";
            header("Location: warehouses.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
            header("Location: warehouses.php");
            exit();
        }
    }
}

// Include the header
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View warehouses', 'User viewed the warehouses management list');

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Calculate Next Warehouse Code
$stmt_max = $pdo->query("SELECT MAX(warehouse_id) FROM warehouses");
$max_id = $stmt_max->fetchColumn() ?: 0;
$next_warehouse_code = 'WH-' . str_pad($max_id + 1, 3, '0', STR_PAD_LEFT);

// Fetch projects for selection — admins see all; non-admins see only their assigned projects
if (isAdmin()) {
    $active_projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $assigned = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    if (empty($assigned)) {
        $active_projects = [];
    } else {
        $ph = implode(',', array_fill(0, count($assigned), '?'));
        $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($ph) ORDER BY project_name ASC");
        $pstmt->execute($assigned);
        $active_projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}


// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(warehouse_name LIKE ? OR warehouse_code LIKE ? OR location LIKE ? OR address LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

// Exclude deleted warehouses
$where_conditions[] = "status != 'deleted'";

$scope_sql = scopeFilterSqlNullable('project');
$where_conditions[] = '1=1'; // ensure WHERE is always built so scope can append
$where_clause = 'WHERE ' . implode(' AND ', $where_conditions) . $scope_sql;

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM warehouses $where_clause";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_count / $limit);

// Get warehouses with pagination
$query = "
    SELECT 
        w.*,
        u.username as created_by_name,
        u2.username as updated_by_name,
        (SELECT COUNT(*) FROM locations WHERE warehouse_id = w.warehouse_id AND status = 'active') as location_count,
        (SELECT SUM(product_stocks.stock_quantity) FROM product_stocks WHERE warehouse_id = w.warehouse_id) as total_stock,
        (SELECT COUNT(DISTINCT product_id) FROM product_stocks WHERE warehouse_id = w.warehouse_id) as product_count,
        (SELECT SUM(ps.stock_quantity * cost_price) FROM product_stocks ps
         JOIN products p ON ps.product_id = p.product_id
         WHERE ps.warehouse_id = w.warehouse_id) as stock_value,
        (SELECT COUNT(DISTINCT ps2.product_id)
         FROM product_stocks ps2
         JOIN products p2 ON ps2.product_id = p2.product_id
         WHERE ps2.warehouse_id = w.warehouse_id
           AND p2.reorder_level > 0
           AND ps2.stock_quantity > 0
           AND ps2.stock_quantity < p2.reorder_level) as low_stock_count,
        (SELECT COUNT(DISTINCT ps2.product_id)
         FROM product_stocks ps2
         WHERE ps2.warehouse_id = w.warehouse_id
           AND ps2.stock_quantity <= 0) as zero_stock_count,
        (SELECT COUNT(DISTINCT ps2.product_id)
         FROM product_stocks ps2
         JOIN products p2 ON ps2.product_id = p2.product_id
         WHERE ps2.warehouse_id = w.warehouse_id
           AND p2.max_stock_level > 0
           AND ps2.stock_quantity > p2.max_stock_level) as overstock_count,
        (SELECT COALESCE(SUM(CASE WHEN sm.movement_type IN ('purchase_in','adjustment_in','transfer_in','return_in','production_in','found') THEN sm.quantity ELSE 0 END), 0)
         FROM stock_movements sm
         WHERE sm.warehouse_id = w.warehouse_id
           AND sm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as inbound_30d,
        (SELECT COALESCE(SUM(CASE WHEN sm.movement_type IN ('sale_out','adjustment_out','transfer_out','return_out','production_out','damaged','expired','theft','issue_out') THEN sm.quantity ELSE 0 END), 0)
         FROM stock_movements sm
         WHERE sm.warehouse_id = w.warehouse_id
           AND sm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as outbound_30d,
        (SELECT COALESCE(SUM(CASE WHEN sm.movement_type IN ('purchase_in','adjustment_in','transfer_in','return_in','production_in','found') THEN sm.total_cost ELSE 0 END), 0)
         FROM stock_movements sm
         WHERE sm.warehouse_id = w.warehouse_id
           AND sm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as value_in_30d,
        (SELECT COALESCE(SUM(CASE WHEN sm.movement_type IN ('sale_out','adjustment_out','transfer_out','return_out','production_out','damaged','expired','theft','issue_out') THEN sm.total_cost ELSE 0 END), 0)
         FROM stock_movements sm
         WHERE sm.warehouse_id = w.warehouse_id
           AND sm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as value_out_30d
    FROM warehouses w
    LEFT JOIN users u ON w.created_by = u.user_id
    LEFT JOIN users u2 ON w.updated_by = u2.user_id
    $where_clause
    ORDER BY w.is_primary DESC, w.warehouse_name
    LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics with filters
$stats_query = "
    SELECT 
        COUNT(*) as total_warehouses,
        COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active_warehouses,
        COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END), 0) as inactive_warehouses,
        COALESCE(SUM(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END), 0) as primary_warehouses,
        COALESCE((
            SELECT COUNT(*) 
            FROM locations l 
            WHERE l.warehouse_id IN (SELECT warehouse_id FROM warehouses $where_clause)
            AND l.status = 'active'
        ), 0) as total_locations
    FROM warehouses 
    $where_clause
";

$stats_stmt = $pdo->prepare($stats_query);
// Since $where_clause is used twice in the query, we need to provide the parameters twice
$stats_params = array_merge($params, $params);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// format_currency removed, now in helpers.php

// Helper functions removed, now in helpers.php
function get_primary_badge($is_primary) {
    if ($is_primary) {
        return '<span class="badge bg-primary"><i class="bi bi-star-fill"></i> Primary</span>';
    }
    return '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouses  Management</title>
    
   
    <style>
        .warehouse-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .warehouse-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .primary-warehouse {
            border-left: 4px solid #0d6efd;
        }
        .warehouse-stats {
            font-size: 0.9rem;
        }
        .capacity-bar {
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        .capacity-fill {
            height: 100%;
            border-radius: 4px;
        }
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .active-dot { background-color: #198754; }
        .inactive-dot { background-color: #6c757d; }
        .maintenance-dot { background-color: #ffc107; }

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

.table thead th {
    background-color: #f8f9fa !important;
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
    
    /* Hide page header, breadcrumbs, filters, actions, etc */
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
    
    /* Hide only action bar badges, not table badges */
    .d-flex .badge {
        display: none !important;
    }
    
    /* Reset badges and custom code to plain text for print */
    .table .badge, 
    .table .custom-code {
        display: inline !important;
        background: transparent !important;
        color: #000 !important;
        border: none !important;
        padding: 0 !important;
        font-size: inherit !important;
        font-family: inherit !important;
        border-radius: 0 !important;
    }
    
    /* Hide the page title and description */
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
    
    /* Hide action column */
    .table th:last-child,
    .table td:last-child {
        display: none !important;
    }
    
    /* Ensure proper page breaks */
    tr {
        page-break-inside: avoid;
    }
}
</style>
</style>
</head>
<body>
    
    <div class="container-fluid mt-4">
        <!-- Print Only Header -->
        <div class="d-none d-print-block">
    <div style="text-align:center; padding: 20px 0; border-bottom: 3px solid #0d6efd; margin-bottom: 20px;">

       

        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">
            Warehouse Management Report
        </h2>

        <p style="color: #000; margin: 0; font-size: 10pt;">
            Report Date: <?= date('d M Y, H:i') ?>
        </p>

    </div>
</div>

        <!-- Print Summary Cards -->
        <div class="d-none d-print-block mb-4">
            <div class="row g-3">
                <div class="col-3">
                    <div style="border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; text-align: center;">
                        <p style="color: #666; font-size: 9pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Total Warehouses</p>
                        <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 16pt;"><?= $stats['total_warehouses'] ?></h3>
                    </div>
                </div>
                <div class="col-3">
                    <div style="border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; text-align: center;">
                        <p style="color: #666; font-size: 9pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Active Warehouses</p>
                        <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 16pt;"><?= $stats['active_warehouses'] ?></h3>
                    </div>
                </div>
                <div class="col-3">
                    <div style="border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; text-align: center;">
                        <p style="color: #666; font-size: 9pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Total Locations</p>
                        <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 16pt;"><?= $stats['total_locations'] ?></h3>
                    </div>
                </div>
                <div class="col-3">
                    <div style="border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; text-align: center;">
                        <p style="color: #666; font-size: 9pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Primary Warehouses</p>
                        <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 16pt;"><?= $stats['primary_warehouses'] ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Breadcrumbs -->
        <nav aria-label="breadcrumb" class="mb-3 d-print-none">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= getUrl('products') ?>">Inventory</a></li>
                <li class="breadcrumb-item active">Warehouses</li>
            </ol>
        </nav>

        <!-- Page Header -->
        <div class="row mb-4 d-print-none">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="fw-bold text-dark mb-1"><i class="bi bi-house-door-fill text-primary"></i> Warehouse Management</h2>
                        <p class="text-muted mb-0">Manage warehouses, locations and stock distribution</p>
                    </div>
                    <div>
                        <?php if ($can_add_warehouses): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWarehouseModal">
                            <i class="bi bi-plus-circle"></i> Add New Warehouse
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4 d-print-none">
            <div class="col-md-3 mb-3">
                <div class="card custom-stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?= $stats['total_warehouses'] ?></h4>
                                <p class="mb-0">Total Warehouses</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-house-door" style="font-size: 2rem;"></i>
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
                                <h4 class="mb-0"><?= $stats['active_warehouses'] ?></h4>
                                <p class="mb-0">Active Warehouses</p>
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
                                <h4 class="mb-0"><?= $stats['total_locations'] ?></h4>
                                <p class="mb-0">Total Locations</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-map" style="font-size: 2rem;"></i>
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
                                <h4 class="mb-0"><?= $stats['primary_warehouses'] ?></h4>
                                <p class="mb-0">Primary Warehouses</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-star-fill" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle"></i> <?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle"></i> <?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Warehouses Grid View -->
        
        <!-- Filter Section -->
        <div class="card border-0 shadow-sm mb-4 d-print-none">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Parameters</h6>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Search Warehouse</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                            <input type="text" name="search" class="form-control border-start-0" 
                                   placeholder="Search by name, code, or location..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Filter by Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active Warehouses</option>
                            <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive Warehouses</option>
                            <option value="maintenance" <?= $status_filter == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end justify-content-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-filter"></i> Apply Filters
                        </button>
                        <a href="warehouses.php" class="btn btn-outline-secondary">
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
                    <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="logReportAction('Printed Warehouse List', 'User generated a printed list of warehouses'); window.print()" style="background: #fff; color: #444;">
                        <i class="bi bi-printer text-primary me-1"></i> Print
                    </button>
                    <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                    <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportWarehouses()" style="background: #fff; color: #444;">
                        <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Export
                    </button>
                </div>
                
                <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                    <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                    <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#warehousesTable').DataTable().page.len(this.value).draw();">
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
                    <i class="bi bi-check-circle-fill me-1"></i> <?= $total_count ?> warehouses
                </span>
            </div>
        </div>

        <!-- Warehouses Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-table"></i> Warehouses List</h5>
                    <span class="badge bg-light text-dark border">
                        Showing <?= count($warehouses) ?> of <?= $total_count ?> results
                    </span>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($warehouses) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="warehousesTable">
                            <thead class="table-light">
                                <tr>
                                    <th>S/NO</th>
                                    <th>Warehouse Code</th>
                                    <th>Warehouse Name</th>
                                    <th>Location</th>
                                    <th>Contact</th>
                                    <th class="text-center">Locations</th>
                                    <th class="text-center">Products</th>
                                    <th class="text-end">Stock Value</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($warehouses as $index => $warehouse): ?>
                                <tr>
                                    <td><?= $offset + $index + 1 ?></td>
                                    <td>
                                        <code class="custom-code"><?= htmlspecialchars($warehouse['warehouse_code'] ?? '') ?></code>
                                        <?php if ($warehouse['is_primary']): ?>
                                            <span class="badge bg-primary ms-1"><i class="bi bi-star-fill"></i> Primary</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($warehouse['warehouse_name'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($warehouse['location'])): ?>
                                            <i class="bi bi-geo-alt text-primary"></i> <?= htmlspecialchars($warehouse['location'] ?? '') ?>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">No location</span>
                                        <?php endif; ?>
                                        <?php if (!empty($warehouse['address'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars(substr($warehouse['address'] ?? '', 0, 50)) ?><?= strlen($warehouse['address'] ?? '') > 50 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($warehouse['contact_person'] ?? 'N/A') ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($warehouse['phone'] ?? '-') ?></small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?= $warehouse['location_count'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?= $warehouse['product_count'] ?></span>
                                        <?php if (($warehouse['low_stock_count'] ?? 0) > 0): ?>
                                            <br><span class="badge bg-warning text-dark mt-1" title="Below reorder level"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= $warehouse['low_stock_count'] ?> low</span>
                                        <?php endif; ?>
                                        <?php if (($warehouse['zero_stock_count'] ?? 0) > 0): ?>
                                            <br><span class="badge bg-danger mt-1" title="Out of stock"><i class="bi bi-x-circle-fill me-1"></i><?= $warehouse['zero_stock_count'] ?> out</span>
                                        <?php endif; ?>
                                        <?php if (($warehouse['overstock_count'] ?? 0) > 0): ?>
                                            <br><span class="badge bg-info mt-1" title="Overstocked"><i class="bi bi-arrow-up-circle-fill me-1"></i><?= $warehouse['overstock_count'] ?> over</span>
                                        <?php endif; ?>
                                        <?php
                                        $in30  = (float)($warehouse['inbound_30d']  ?? 0);
                                        $out30 = (float)($warehouse['outbound_30d'] ?? 0);
                                        if ($in30 > 0 || $out30 > 0):
                                        ?>
                                        <div class="mt-1 text-nowrap" style="font-size:0.68rem;line-height:1.3;">
                                            <span class="text-success" title="Units received in last 30 days"><i class="bi bi-box-arrow-in-down"></i> <?= number_format($in30, 0) ?></span>
                                            &nbsp;&middot;&nbsp;
                                            <span class="text-danger" title="Units dispatched in last 30 days"><i class="bi bi-box-arrow-up"></i> <?= number_format($out30, 0) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-bold text-primary"><?= format_currency($warehouse['stock_value'] ?? 0) ?></span>
                                        <?php
                                        $sv_now  = (float)($warehouse['stock_value']  ?? 0);
                                        $v_in    = (float)($warehouse['value_in_30d']  ?? 0);
                                        $v_out   = (float)($warehouse['value_out_30d'] ?? 0);
                                        $sv_past = $sv_now - ($v_in - $v_out);
                                        if ($sv_past > 0 && ($v_in > 0 || $v_out > 0)):
                                            $trend   = ($sv_now - $sv_past) / $sv_past * 100;
                                            $t_color = $trend >= 0 ? 'success' : 'danger';
                                            $t_icon  = $trend >= 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
                                            $t_sign  = $trend >= 0 ? '+' : '';
                                        ?>
                                        <span class="ms-1 small text-<?= $t_color ?>" title="vs 30 days ago"><i class="bi <?= $t_icon ?>"></i><?= $t_sign . number_format($trend, 1) ?>%</span>
                                        <?php endif; ?>
                                        <?php
                                        $wh_cap = (float)($warehouse['capacity'] ?? 0);
                                        $wh_qty = (float)($warehouse['total_stock'] ?? 0);
                                        if ($wh_cap > 0):
                                            $wh_pct = min(100, (int)round($wh_qty / $wh_cap * 100));
                                            $wh_bar = $wh_pct >= 90 ? 'danger' : ($wh_pct >= 70 ? 'warning' : 'success');
                                        ?>
                                        <div class="mt-1">
                                            <div class="d-flex justify-content-between" style="font-size:0.68rem;color:#888;line-height:1.2;">
                                                <span><?= number_format($wh_qty, 0) ?> / <?= number_format($wh_cap, 0) ?></span>
                                                <span class="fw-semibold text-<?= $wh_bar ?>"><?= $wh_pct ?>%</span>
                                            </div>
                                            <div class="capacity-bar mt-1">
                                                <div class="capacity-fill bg-<?= $wh_bar ?>" style="width:<?= $wh_pct ?>%;"></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= get_status_badge($warehouse['status'] ?? '') ?>">
                                            <?= ucfirst($warehouse['status'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="#" onclick="viewWarehouse(<?= $warehouse['warehouse_id'] ?>)">
                                                        <i class="bi bi-eye text-primary"></i> View Details
                                                    </a>
                                                </li>
                                                <?php if ($can_edit_warehouses): ?>
                                                <li>
                                                    <a class="dropdown-item" href="#" 
                                                       data-bs-toggle="modal" 
                                                       data-bs-target="#editWarehouseModal"
                                                       onclick="loadWarehouseData(<?= $warehouse['warehouse_id'] ?>)">
                                                        <i class="bi bi-pencil text-warning"></i> Edit
                                                    </a>
                                                </li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item" href="#" onclick="manageLocations(<?= $warehouse['warehouse_id'] ?>)">
                                                        <i class="bi bi-map text-info"></i> Manage Locations
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="#" onclick="transferStock(<?= $warehouse['warehouse_id'] ?>)">
                                                        <i class="bi bi-truck text-success"></i> Transfer Stock
                                                    </a>
                                                </li>
                                                <?php if ($can_delete_warehouses): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#" onclick="deleteWarehouse(<?= $warehouse['warehouse_id'] ?>)">
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
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 4rem; color: #ccc;"></i>
                        <h4 class="mt-3">No Warehouses Found</h4>
                        <p class="text-muted">Start by adding your first warehouse.</p>
                        <?php if ($can_add_warehouses): ?>
                        <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#addWarehouseModal">
                            <i class="bi bi-plus-circle"></i> Add New Warehouse
                        </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Warehouse Modal -->
    <div class="modal fade" id="addWarehouseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="add_warehouse" value="1">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New Warehouse</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="warehouse_name" class="form-label">Warehouse Name *</label>
                                <input type="text" class="form-control" id="warehouse_name" name="warehouse_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="warehouse_code" class="form-label">Warehouse Code <span class="text-muted">(Auto-generated)</span></label>
                                <input type="text" class="form-control bg-light" id="warehouse_code" name="warehouse_code" value="<?= $next_warehouse_code ?>" readonly required>
                                <small class="text-muted">Unique code automatically assigned by system</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="project_id" class="form-label">Project (Optional)</label>
                                <select class="form-select" id="project_id" name="project_id">
                                    <option value="">-- No Specific Project --</option>
                                    <?php foreach ($active_projects as $project): ?>
                                        <option value="<?= $project['project_id'] ?>"><?= htmlspecialchars($project['project_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Link this warehouse to a specific project</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="address" class="form-label">Address</label>
                                <input type="text" class="form-control" id="address" name="address">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="city" class="form-label">City</label>
                                <input type="text" class="form-control" id="city" name="city">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="country" class="form-label">Country</label>
                                <input type="text" class="form-control" id="country" name="country" value="Tanzania">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="state" class="form-label">Region</label>
                                <input type="text" class="form-control" id="state" name="state">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="postal_code" class="form-label">Postal Code</label>
                                <input type="text" class="form-control" id="postal_code" name="postal_code">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="manager_name" class="form-label">Manager Name</label>
                                <input type="text" class="form-control" id="manager_name" name="manager_name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="manager_phone" class="form-label">Manager Phone</label>
                                <input type="tel" class="form-control" id="manager_phone" name="manager_phone">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="capacity" class="form-label">Capacity (units)</label>
                                <input type="number" class="form-control" id="capacity" name="capacity" min="0" step="1">
                                <small class="text-muted">Maximum storage capacity</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" selected>Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="is_primary" name="is_primary">
                                    <label class="form-check-label" for="is_primary">
                                        Set as Primary Warehouse
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Warehouse</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Warehouse Modal -->
    <div class="modal fade" id="editWarehouseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_warehouse" value="1">
                    <input type="hidden" id="edit_warehouse_id" name="warehouse_id">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Warehouse</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div id="editFormContent">
                            <!-- Content loaded via JavaScript -->
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Warehouse</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Filters Modal -->
    <div class="modal fade" id="filtersModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="GET" action="">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-funnel"></i> Filters</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Search by name, code, or city">
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value="maintenance" <?= $status_filter == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <a href="warehouses.php" class="btn btn-outline-secondary">Clear All</a>
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // View warehouse details
    function viewWarehouse(warehouseId) {
        window.location.href = '<?= getUrl("warehouse_view") ?>?id=' + warehouseId;
    }

    function loadWarehouseData(warehouseId) {
        $.ajax({
            url: 'ajax_get_warehouse.php',
            type: 'GET',
            data: { id: warehouseId },
            success: function(response) {
                $('#edit_warehouse_id').val(warehouseId);
                $('#editFormContent').html(response);
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Load Error',
                    text: 'Error loading warehouse data. Please try again.'
                });
            }
        });
    }

    // Manage locations
    function manageLocations(warehouseId) {
        window.location.href = '<?= getUrl("locations") ?>?warehouse_id=' + warehouseId;
    }

    // Transfer stock
    function transferStock(warehouseId) {
        window.location.href = '<?= getUrl("stock_transfers") ?>?from_warehouse_id=' + warehouseId;
    }

    function deleteWarehouse(warehouseId) {
        // Step 1 — fetch counts to build an informative warning
        $.post('ajax_delete_warehouse.php', {
            warehouse_id: warehouseId,
            csrf_token: '<?= $_SESSION['csrf_token'] ?>'
        }, function(res) {
            if (!res.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                return;
            }

            // Build warning lines
            let lines = [];
            if (res.product_count > 0) {
                lines.push(`• ${res.product_count} product(s) with ${parseFloat(res.total_qty).toLocaleString()} units of stock`);
            }
            if (res.location_count > 0) {
                lines.push(`• ${res.location_count} storage location(s)`);
            }
            const detail = lines.length
                ? 'The following will also be permanently removed:\n\n' + lines.join('\n') + '\n\n'
                : '';

            // Step 2 — confirm with full details
            Swal.fire({
                title: 'Delete Warehouse?',
                text: detail + 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#aaa',
                confirmButtonText: 'Yes, delete everything!'
            }).then((result) => {
                if (!result.isConfirmed) return;

                // Step 3 — confirmed, call with confirmed=1
                $.ajax({
                    url: 'ajax_delete_warehouse.php',
                    type: 'POST',
                    data: {
                        warehouse_id: warehouseId,
                        confirmed: 1,
                        csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                    },
                    success: function(response) {
                        const res2 = (typeof response === 'string') ? JSON.parse(response) : response;
                        if (res2.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: 'Warehouse has been deleted successfully.',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                logReportAction('Deleted Warehouse', 'User deleted warehouse ID: ' + warehouseId);
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Delete Failed',
                                text: res2.message
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Server Error',
                            text: 'Error deleting warehouse. Please try again.'
                        });
                    }
                });
            });
        }, 'json');
    }

    function toggleWarehouseStatus(warehouseId, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        const action = newStatus === 'active' ? 'activate' : 'deactivate';
        
        Swal.fire({
            title: 'Confirm Action',
            text: `Are you sure you want to ${action} this warehouse?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#aaa',
            confirmButtonText: `Yes, ${action} it!`
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax_toggle_warehouse_status.php',
                    type: 'POST',
                    data: { 
                        warehouse_id: warehouseId,
                        new_status: newStatus,
                        csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                    },
                    success: function(response) {
                        const result = JSON.parse(response);
                        if (result.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Status Updated',
                                text: `Warehouse ${action}d successfully!`,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                logReportAction('Updated Warehouse Status', 'User changed warehouse ID ' + warehouseId + ' status to ' + newStatus);
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Update Failed',
                                text: result.message
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Server Error',
                            text: 'Error updating warehouse status. Please try again.'
                        });
                    }
                });
            }
        });
    }

    function setPrimaryWarehouse(warehouseId) {
        Swal.fire({
            title: 'Set as Primary?',
            text: 'Are you sure you want to set this warehouse as primary? All other warehouses will be set as non-primary.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#aaa',
            confirmButtonText: 'Yes, set as primary'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax_set_primary_warehouse.php',
                    type: 'POST',
                    data: { 
                        warehouse_id: warehouseId,
                        csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                    },
                    success: function(response) {
                        const result = JSON.parse(response);
                        if (result.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Primary Updated',
                                text: 'Primary warehouse updated successfully!',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                logReportAction('Set Primary Warehouse', 'User set warehouse ID ' + warehouseId + ' as primary');
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Update Failed',
                                text: result.message
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Server Error',
                            text: 'Error updating primary warehouse. Please try again.'
                        });
                    }
                });
            }
        });
    }

    // Export warehouses to CSV
    function exportWarehouses() {
        logReportAction('Exported Warehouse List', 'User exported warehouse records to CSV');
        const table = document.getElementById('warehousesTable');
        let csv = 'Code,Name,Location,Contact,Locations,Products,Stock Value,Status\n';
        
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const cols = row.querySelectorAll('td');
            if (cols.length > 0) {
                const code = cols[1].textContent.trim().replace(/\s+/g, ' ');
                const name = cols[2].textContent.trim();
                const location = cols[3].textContent.trim().replace(/\n/g, ' ');
                const contact = cols[4].textContent.trim().replace(/\n/g, ' ');
                const locations = cols[5].textContent.trim();
                const products = cols[6].textContent.trim();
                const stockValue = cols[7].textContent.trim();
                const status = cols[8].textContent.trim();
                
                csv += `"${code}","${name}","${location}","${contact}","${locations}","${products}","${stockValue}","${status}"\n`;
            }
        });
        
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'warehouses_' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
        window.URL.revokeObjectURL(url);
    }

    // Initialize DataTable
    $(document).ready(function() {
        $('#warehousesTable').DataTable({
            pageLength: 25,
            lengthChange: false, // Disable built-in length menu (we have custom one in actions bar)
            order: [[7, 'desc']], // Sort by Stock Value
            language: {
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
            autoWidth: false,
            columnDefs: [
                { orderable: false, targets: [9] } // Disable sorting on Actions column
            ]
        });

        logReportAction('Viewed Warehouses Page', 'User viewed the warehouse management page');
        
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('search') && urlParams.get('search').trim() !== '') {
            logReportAction('Searched Warehouses', 'User searched warehouses for: ' + urlParams.get('search'));
        }
    });

    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
    </script>
</body>
<?php
includeFooter();
ob_end_flush();
?>