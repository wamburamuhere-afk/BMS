<?php
// File: app/bms/stock/stock_transfers.php
require_once __DIR__ . '/../../../roots.php';
require_once HELPERS_FILE;

// Enforce permission BEFORE any output
autoEnforcePermission('warehouses');

$user_id = $_SESSION['user_id'];
$can_transfer = isAdmin() || canEdit('warehouses');


// Create table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_transfers` (
        `transfer_id` int NOT NULL AUTO_INCREMENT,
        `transfer_number` varchar(50) NOT NULL,
        `from_warehouse_id` int NOT NULL,
        `to_warehouse_id` int NOT NULL,
        `transfer_date` date NOT NULL,
        `status` enum('pending','completed','cancelled') DEFAULT 'pending',
        `notes` text,
        `created_by` int NOT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        `completed_at` datetime DEFAULT NULL,
        PRIMARY KEY (`transfer_id`),
        UNIQUE KEY `transfer_number` (`transfer_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_transfer_items` (
        `item_id` int NOT NULL AUTO_INCREMENT,
        `transfer_id` int NOT NULL,
        `product_id` int NOT NULL,
        `quantity` decimal(15,3) NOT NULL,
        `received_quantity` decimal(15,3) DEFAULT '0.000',
        PRIMARY KEY (`item_id`),
        KEY `transfer_id` (`transfer_id`),
        CONSTRAINT `fk_transfer_items_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`transfer_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    // Table creation failed, might exist already or no permission
}

// Generate Transfer Number
function generateTransferNumber($pdo) {
    $date = date('Ymd');
    $prefix = "TRF-" . $date . "-";
    $query = "SELECT transfer_number FROM stock_transfers WHERE transfer_number LIKE '$prefix%' ORDER BY transfer_id DESC LIMIT 1";
    $last = $pdo->query($query)->fetchColumn();
    $num = $last ? intval(substr($last, -4)) + 1 : 1;
    return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_transfer) {
    if (isset($_POST['create_transfer'])) {
        $from_wh = intval($_POST['from_warehouse_id']);
        $to_wh = intval($_POST['to_warehouse_id']);
        $date = $_POST['transfer_date'];
        $notes = trim($_POST['notes']);
        $products = $_POST['products']; // Array of [id => qty]
        
        if ($from_wh === $to_wh) {
            $_SESSION['error'] = "Source and destination warehouses must be different.";
        } else {
            try {
                $pdo->beginTransaction();
                
                $trf_num = generateTransferNumber($pdo);
                $stmt = $pdo->prepare("INSERT INTO stock_transfers (transfer_number, from_warehouse_id, to_warehouse_id, transfer_date, status, notes, created_by) VALUES (?, ?, ?, ?, 'completed', ?, ?)");
                $stmt->execute([$trf_num, $from_wh, $to_wh, $date, $notes, $user_id]);
                $transfer_id = $pdo->lastInsertId();
                
                foreach ($products as $p_id => $qty) {
                    if ($qty <= 0) continue;
                    
                    // 1. Check if source has enough stock
                    $stmt = $pdo->prepare("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
                    $stmt->execute([$p_id, $from_wh]);
                    $current_qty = $stmt->fetchColumn() ?: 0;
                    
                    if ($current_qty < $qty) {
                        throw new Exception("Insufficient stock for product ID $p_id in source warehouse.");
                    }
                    
                    // 2. Insert transfer item
                    $stmt = $pdo->prepare("INSERT INTO stock_transfer_items (transfer_id, product_id, quantity, received_quantity) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$transfer_id, $p_id, $qty, $qty]);
                    
                    // 3. Update source warehouse stock
                    $stmt = $pdo->prepare("UPDATE product_stocks SET stock_quantity = stock_quantity - ? WHERE product_id = ? AND warehouse_id = ?");
                    $stmt->execute([$qty, $p_id, $from_wh]);
                    
                    // 4. Update destination warehouse stock
                    $stmt = $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock_quantity = stock_quantity + VALUES(stock_quantity)");
                    $stmt->execute([$p_id, $to_wh, $qty]);
                }
                
                $pdo->commit();
                logActivity($pdo, $_SESSION['user_id'], 'Completed Stock Transfer', "User completed stock transfer #$trf_num from Warehouse ID $from_wh to $to_wh");
                $_SESSION['success'] = "Stock transfer $trf_num completed successfully!";
            } catch (Exception $e) {
                $pdo->rollBack();
                logActivity($pdo, $_SESSION['user_id'], 'Failed Stock Transfer', "User failed to complete stock transfer from $from_wh to $to_wh: " . $e->getMessage());
                $_SESSION['error'] = "Failed to transfer stock: " . $e->getMessage();
            }
        }
        $redirect_url = getUrl('stock_transfers');
        if($project_id > 0) $redirect_url .= "?project_id=$project_id";
        header("Location: " . $redirect_url);
        exit();
    }
}

// Include HTML header AFTER all POST/redirect logic
includeHeader();

// Filters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$wh_filter = $_GET['warehouse_id'] ?? '0';
$project_id = $_GET['project_id'] ?? '0';

$where_clauses = [];
$params = [];

if ($start_date) {
    $where_clauses[] = "t.transfer_date >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $where_clauses[] = "t.transfer_date <= ?";
    $params[] = $end_date;
}
if ($wh_filter > 0) {
    $where_clauses[] = "(t.from_warehouse_id = ? OR t.to_warehouse_id = ?)";
    $params[] = $wh_filter;
    $params[] = $wh_filter;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Fetch transfers
$query = "
    SELECT t.*, fw.warehouse_name as from_wh_name, tw.warehouse_name as to_wh_name, u.username,
           (SELECT COUNT(*) FROM stock_transfer_items WHERE transfer_id = t.transfer_id) as item_count,
           (SELECT SUM(quantity) FROM stock_transfer_items WHERE transfer_id = t.transfer_id) as total_qty
    FROM stock_transfers t
    JOIN warehouses fw ON t.from_warehouse_id = fw.warehouse_id
    JOIN warehouses tw ON t.to_warehouse_id = tw.warehouse_id
    JOIN users u ON t.created_by = u.user_id
    $where_sql
    ORDER BY t.created_at DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Warehouses for dropdown
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

// Products for transfer (only products available in warehouses)
$available_products = $pdo->query("
    SELECT DISTINCT p.product_id, p.product_name, p.sku, ps.warehouse_id, ps.stock_quantity
    FROM products p
    JOIN product_stocks ps ON p.product_id = ps.product_id
    WHERE ps.stock_quantity > 0 AND p.is_service = 0
    ORDER BY p.product_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Stats for Transfers
$stats = [
    'total' => count($transfers),
    'completed' => 0,
    'pending' => 0,
    'products' => $pdo->query("SELECT COUNT(DISTINCT product_id) FROM stock_transfer_items")->fetchColumn()
];
foreach ($transfers as $tr) {
    if ($tr['status'] == 'completed') $stats['completed']++;
    if ($tr['status'] == 'pending') $stats['pending']++;
}

?>

<style>
    .custom-stat-card {
        background-color: #d1e7dd !important;
        border-color: #badbcc !important;
        transition: transform 0.2s;
        border-radius: 12px;
    }
    .custom-stat-card:hover { transform: translateY(-3px); }
    .custom-stat-card h3, 
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
    .breadcrumb-item + .breadcrumb-item::before {
        content: "\F138";
        font-family: "bootstrap-icons";
        font-size: 0.8rem;
        color: #adb5bd;
    }
    @media print {
        .stat-card, .btn, .d-print-none, .card-header, .breadcrumb, .dataTables_filter, .dataTables_info, .dataTables_paginate, .dt-buttons {
            display: none !important;
        }
        .card { border: none !important; shadow: none !important; }
        .table-responsive { overflow: visible !important; }
        .table th, .table td { border: 1px solid #dee2e6 !important; }
    }
    /* Optimize Selection Table for Mobile */
    #selectionTable { font-size: 0.9rem; }
    @media (max-width: 576px) {
        #selectionTable { font-size: 0.75rem; border: none !important; }
        #selectionTable thead th { border-bottom: 2px solid #eee !important; border-top: none !important; border-left: none !important; border-right: none !important; }
        #selectionTable td, #selectionTable th { border: none !important; padding: 8px 2px !important; }
        .qty-input-mobile { width: 65px !important; padding: 2px 4px !important; font-size: 0.75rem !important; }
        .product-name-mobile { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
        
        /* Column Widths for Mobile */
        #selectionTable th:nth-child(1) { width: 30px !important; } /* S/NO */
        #selectionTable th:nth-child(2) { width: auto !important; } /* Product Details (Flexible & Largest) */
        #selectionTable th:nth-child(3) { width: 50px !important; text-align: center; } /* Available */
        #selectionTable th:nth-child(4) { width: 75px !important; text-align: center; } /* Quantity */
    }
</style>

<div class="container-fluid mt-4">
    <!-- Print Header (Template for DataTables print) -->
    <div class="print-header d-none">
        <div class="text-center mb-4">
            <?php 
            $c_name = getSetting('company_name', 'BMS');
            $c_logo = getSetting('company_logo', '');
            ?>
            <?php if(!empty($c_logo)): ?>
                <div class="mb-3">
                    <img src="<?= getUrl($c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                </div>
            <?php endif; ?>
            <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= safe_output($c_name) ?></h1>
            <h2 style="font-weight: 700; color: #000; text-transform: uppercase; margin: 10px 0; font-size: 18pt;">STOCK TRANSFER REPORT</h2>
            <div style="font-size: 12pt; color: #555;">Generated At: <?= date('d M Y, h:i A') ?></div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="fw-bold mb-0 fs-5 fs-md-2"><i class="bi bi-truck text-primary"></i> Stock Transfers</h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <?php if($project_id > 0): ?>
                                <li class="breadcrumb-item"><a href="<?= getUrl('project_view') . '?id=' . $project_id . '#procurements' ?>" class="text-decoration-none text-muted" style="font-size: 0.7rem;">Project Details</a></li>
                            <?php else: ?>
                                <li class="breadcrumb-item"><a href="<?= getUrl('warehouses') ?>" class="text-decoration-none text-muted" style="font-size: 0.7rem;">Stock Management</a></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active fw-medium" style="font-size: 0.7rem;">Stock Transfers</li>
                        </ol>
                    </nav>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if($project_id > 0): ?>
                        <a href="<?= getUrl('project_view') . '?id=' . $project_id . '#procurements' ?>" class="btn btn-sm btn-outline-secondary px-3 shadow-sm d-flex align-items-center" style="height: 38px;">
                            <i class="bi bi-arrow-left me-2"></i> <span class="d-none d-md-inline">Back to Project</span>
                        </a>
                    <?php endif; ?>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-primary dropdown-toggle px-3 shadow-sm d-flex align-items-center" type="button" data-bs-toggle="dropdown" style="height: 38px;">
                            <i class="bi bi-gear-fill me-md-2"></i> <span class="d-none d-md-inline">Manage Transfers</span><span class="d-inline d-md-none">Actions</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 180px;">
                            <?php if ($can_transfer): ?>
                            <li>
                                <a class="dropdown-item py-2" href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#newTransferModal" onclick="logReportAction('Viewed Create Transfer Modal', 'User opened the modal to create a new stock transfer')">
                                    <i class="bi bi-plus-circle-fill text-primary me-2"></i> New Transfer
                                </a>
                            </li>
                            <?php endif; ?>
                            <li>
                                <a class="dropdown-item py-2" href="<?= getUrl('warehouses') ?>">
                                    <i class="bi bi-house-door-fill text-secondary me-2"></i> Warehouses
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Bar / Stats Cards -->
    <div class="row g-2 g-md-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-2 p-md-3 text-center text-md-start">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <div>
                            <h3 class="fw-bold mb-0 fs-5 fs-md-3"><?= number_format($stats['total']) ?></h3>
                            <p class="small mb-0 opacity-75 text-uppercase" style="font-size: 0.65rem;">Total</p>
                        </div>
                        <i class="bi bi-arrow-left-right opacity-50 fs-4 fs-md-2 d-none d-md-block"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-2 p-md-3 text-center text-md-start">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <div>
                            <h3 class="fw-bold mb-0 fs-5 fs-md-3"><?= number_format($stats['completed']) ?></h3>
                            <p class="small mb-0 opacity-75 text-uppercase" style="font-size: 0.65rem;">Completed</p>
                        </div>
                        <i class="bi bi-check-all opacity-50 fs-4 fs-md-2 d-none d-md-block"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-2 p-md-3 text-center text-md-start">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <div>
                            <h3 class="fw-bold mb-0 fs-5 fs-md-3"><?= number_format($stats['products']) ?></h3>
                            <p class="small mb-0 opacity-75 text-uppercase" style="font-size: 0.65rem;">Products Moved</p>
                        </div>
                        <i class="bi bi-box-seam opacity-50 fs-4 fs-md-2 d-none d-md-block"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card border-0 shadow-sm mb-4 bg-light">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label for="start_date" class="form-label small fw-bold text-muted mb-1" style="font-size: 0.7rem;">Start Date</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-calendar-event text-muted"></i></span>
                        <input type="date" name="start_date" id="start_date" class="form-control border-start-0 ps-0" value="<?= $start_date ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <label for="end_date" class="form-label small fw-bold text-muted mb-1" style="font-size: 0.7rem;">End Date</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-calendar-check text-muted"></i></span>
                        <input type="date" name="end_date" id="end_date" class="form-control border-start-0 ps-0" value="<?= $end_date ?>">
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <label for="wh_filter" class="form-label small fw-bold text-muted mb-1" style="font-size: 0.7rem;">Warehouse</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-house-door text-muted"></i></span>
                        <select name="warehouse_id" id="wh_filter" class="form-select border-start-0 ps-0">
                            <option value="0">All Warehouses</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['warehouse_id'] ?>" <?= $wh_filter == $wh['warehouse_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($wh['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-12 col-md-auto d-flex gap-2 ms-auto">
                    <button type="submit" class="btn btn-sm btn-primary flex-grow-1 px-4 shadow-sm" style="height: 38px;" onclick="logReportAction('Filtered Stock Transfers', 'User applied search filters to stock transfers history')">
                        <i class="bi bi-funnel me-2"></i> Filter
                    </button>
                    <?php if ($start_date || $end_date || $wh_filter > 0): ?>
                    <a href="<?= getUrl('stock_transfers') ?>" class="btn btn-sm btn-outline-secondary px-3 shadow-sm d-flex align-items-center justify-content-center" style="height: 38px;">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <script>
            $(document).ready(function() {
                Swal.fire({
                    title: 'Success!',
                    text: '<?= $_SESSION['success'] ?>',
                    icon: 'success',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'OK'
                });
            });
        </script>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <script>
            $(document).ready(function() {
                Swal.fire({
                    title: 'Error!',
                    text: '<?= $_SESSION['error'] ?>',
                    icon: 'error',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'OK'
                });
            });
        </script>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- History Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="d-flex align-items-center flex-wrap gap-2">
                <!-- Left Aligned Group: Buttons and Page Length (Web Only) -->
                <div class="d-none d-md-flex align-items-center gap-3">
                    <div id="tableButtons" class="d-flex gap-2">
                        <!-- DataTables buttons will be styled here -->
                    </div>
                    
                    <div class="d-flex align-items-center bg-white px-3 py-1 border rounded-3 shadow-sm">
                        <label for="pageLengthSelect" class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</label>
                        <select id="pageLengthSelect" class="form-select form-select-sm border-0 fw-bold p-0" style="width: 50px; box-shadow: none; background: transparent;" onchange="$('#transfersTable').DataTable().page.len(this.value).draw();">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>

                <!-- Title (Centered/Right on Web, Left on Mobile) -->
                <h5 class="mb-0 fw-bold ms-md-auto d-flex align-items-center order-first order-md-0">
                    Transfer History <span class="badge bg-primary fs-small rounded-pill ms-2 d-none d-md-inline-block"><?= count($transfers) ?> records</span>
                </h5>

                <!-- Mobile Action Dropdown (Right Aligned on Mobile) -->
                <div class="ms-auto d-md-none shadow-sm">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="height: 34px;">
                            <i class="bi bi-lightning-charge me-1"></i> Action
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 180px;">
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="$('.buttons-print').click()"><i class="bi bi-printer text-primary me-2"></i> Print History</a></li>
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="$('.buttons-excel').click()"><i class="bi bi-file-earmark-excel text-success me-2"></i> Export Excel</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="transfersTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3 text-center" style="width: 50px;">S/NO</th>
                            <th>Transfer</th>
                            <th>Date</th>
                            <th class="d-none d-md-table-cell">From</th>
                            <th>Target</th>
                            <th class="text-center d-none d-md-table-cell">Items</th>
                            <th class="text-center">Qty</th>
                            <th class="d-none d-md-table-cell">Status</th>
                            <th class="d-none d-lg-table-cell">Created By</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sn = 1; foreach ($transfers as $t): ?>
                        <tr>
                            <td class="ps-3 text-center text-muted small fw-bold"><?= $sn++ ?></td>
                            <td><code class="custom-code fw-bold" style="font-size: 0.75rem;"><?= htmlspecialchars($t['transfer_number'] ?? '') ?></code></td>
                            <td style="font-size: 0.8rem;"><?= date('d/m/y', strtotime($t['transfer_date'])) ?></td>
                            <td class="d-none d-md-table-cell"><span class="badge bg-light text-dark fw-normal"><?= htmlspecialchars($t['from_wh_name'] ?? '') ?></span></td>
                            <td><span class="badge bg-light text-primary fw-normal"><?= htmlspecialchars($t['to_wh_name'] ?? '') ?></span></td>
                            <td class="text-center d-none d-md-table-cell"><span class="badge bg-secondary rounded-pill"><?= $t['item_count'] ?></span></td>
                            <td class="text-center fw-bold text-dark" style="font-size: 0.85rem;"><?= number_format($t['total_qty'], 1) ?></td>
                            <td class="d-none d-md-table-cell"><span class="badge bg-success small">Done</span></td>
                            <td class="d-none d-lg-table-cell small"><?= htmlspecialchars($t['username'] ?? '') ?></td>
                            <td class="text-end pe-3">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="bi bi-gear"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li>
                                            <a class="dropdown-item py-2" href="javascript:void(0)" onclick="viewTransferItems(<?= $t['transfer_id'] ?>, '<?= $t['transfer_number'] ?>')">
                                                <i class="bi bi-eye-fill text-primary me-2"></i> View Items
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item py-2" href="<?= getUrl('print_transfer.php?id=' . $t['transfer_id']) ?>" target="_blank" onclick="logReportAction('Printed Transfer Note', 'User printed transfer note for #<?= $t['transfer_number'] ?>')">
                                                <i class="bi bi-printer-fill text-secondary me-2"></i> Print Note
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- View Items Modal -->
<div class="modal fade" id="viewItemsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold" id="viewItemsTitle">Transfer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewItemsContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- New Transfer Modal -->
<div class="modal fade" id="newTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content" id="transferForm">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">Create New Stock Transfer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-6 mb-3">
                        <label for="from_wh" class="form-label small fw-bold text-muted">Source *</label>
                        <select name="from_warehouse_id" id="from_wh" class="form-select" required onchange="filterAvailableProducts()">
                            <option value="">Select Source</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['warehouse_id'] ?>"><?= htmlspecialchars($wh['warehouse_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 mb-3">
                        <label for="to_wh" class="form-label small fw-bold text-muted">Destination *</label>
                        <select name="to_warehouse_id" id="to_wh" class="form-select" required>
                            <option value="">Select Destination</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['warehouse_id'] ?>"><?= htmlspecialchars($wh['warehouse_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 mb-3">
                        <label for="transfer_date" class="form-label small fw-bold text-muted">Transfer Date *</label>
                        <input type="date" name="transfer_date" id="transfer_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="card bg-light border-0 mb-3">
                    <div class="card-body">
                        <h6 class="card-title">Select Products to Transfer</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle" id="selectionTable">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center" style="width: 50px;">S/NO</th>
                                        <th>Product Details</th>
                                        <th class="text-center" style="width: 100px;">Available</th>
                                        <th class="text-center" style="width: 120px;">Quantity</th>
                                    </tr>
                                </thead>
                                <tbody id="productSelectionBody">
                                    <tr><td colspan="4" class="text-center text-muted py-3">Select source warehouse first</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="mb-0">
                    <label for="transfer_notes" class="form-label small fw-bold text-muted">Notes (Optional)</label>
                    <textarea name="notes" id="transfer_notes" class="form-control" rows="2" placeholder="Reason for transfer..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="create_transfer" class="btn btn-primary">Complete Transfer</button>
            </div>
        </form>
    </div>
</div>

<script>
const productsData = <?= json_encode($available_products) ?>;

function filterAvailableProducts() {
    const fromWh = $('#from_wh').val();
    const body = $('#productSelectionBody');
    body.empty();
    
    if (!fromWh) {
        body.append('<tr><td colspan="3" class="text-center text-muted">Select source warehouse first</td></tr>');
        return;
    }
    
    const filtered = productsData.filter(p => p.warehouse_id == fromWh);
    
    if (filtered.length === 0) {
        body.append('<tr><td colspan="3" class="text-center text-danger">No products available in this warehouse</td></tr>');
        return;
    }
    
    filtered.forEach((p, index) => {
        body.append(`
            <tr>
                <td class="text-center text-muted fw-bold">${index + 1}</td>
                <td>
                    <span class="fw-bold text-dark product-name-mobile">${p.product_name}</span>
                    <small class="text-muted d-block" style="font-size: 0.65rem;">SKU: ${p.sku || '-'}</small>
                </td>
                <td class="text-center fw-bold text-info">${p.stock_quantity}</td>
                <td>
                    <input type="number" name="products[${p.product_id}]" class="form-control form-control-sm qty-input-mobile" 
                           min="0" max="${p.stock_quantity}" step="0.001" value="0">
                </td>
            </tr>
        `);
    });
}

function viewTransferItems(id, number) {
    logReportAction('Viewed Transfer Details', 'User viewed items for transfer #' + number);
    $('#viewItemsTitle').text('Transfer Details: ' + number);
    $('#viewItemsContent').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    $('#viewItemsModal').modal('show');

    $.ajax({
        url: '<?= getUrl('ajax_get_transfer_items.php') ?>',
        type: 'GET',
        data: { id: id },
        success: function(response) {
            $('#viewItemsContent').html(response);
        },
        error: function() {
            $('#viewItemsContent').html('<div class="alert alert-danger">Error loading items</div>');
        }
    });
}

$(document).ready(function() {
    logReportAction('Viewed Stock Transfers', 'User viewed the stock transfers history page');

    $('#transfersTable').DataTable({
        pageLength: 20,
        order: [[2, 'desc']], // Ordered by Date (index 2)
        dom: 'rtip', 
        buttons: [
            {
                extend: 'print',
                className: 'btn btn-sm btn-outline-secondary px-3 bg-white shadow-sm',
                text: '<i class="bi bi-printer me-2"></i>Print History',
                title: '',
                customize: function ( win ) {
                    $(win.document.body).css('background', 'white');
                    $(win.document.body).prepend(
                        $('.print-header').html()
                    );
                    $(win.document.body).find('table')
                        .addClass('compact')
                        .css('font-size', '10pt')
                        .css('margin-top', '20px');
                },
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 8] }
            },
            {
                extend: 'excel',
                className: 'btn btn-sm btn-outline-secondary px-3 ms-2 bg-white shadow-sm',
                text: '<i class="bi bi-file-earmark-excel me-2"></i>Export Excel',
                title: 'Stock Transfer History',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 8] }
            }
        ]
    }).buttons().container().appendTo('#tableButtons');

    // Check if warehouse_id is in URL to pre-select and open modal
    const urlParams = new URLSearchParams(window.location.search);
    const fromWhId = urlParams.get('from_warehouse_id');
    const toWhId = urlParams.get('to_warehouse_id');
    const productId = urlParams.get('product_id');
    const quantity = urlParams.get('quantity');

    if (fromWhId) {
        $('#from_wh').val(fromWhId);
        filterAvailableProducts(); // Populate product list based on warehouse
        
        if (toWhId) {
            $('select[name="to_warehouse_id"]').val(toWhId);
        }

        if (productId) {
            // Find the input for this specific product
            const input = $(`input[name="products[${productId}]"]`);
            if (input.length > 0) {
                // Set quantity
                input.val(quantity || 1);
                
                // Highlight the row to make it obvious
                input.closest('tr').addClass('table-warning border-warning');
                
                // Ensure it's visible (though modal show handles this mostly, scrolling helps if list is long)
                setTimeout(() => {
                    input[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    input.focus();
                }, 500);
            }
        }
        $('#newTransferModal').modal('show');
    }
});
</script>

<?php
includeFooter();
ob_end_flush();
?>
