<?php
// File: sales_orders.php
// scope-audit: skip — Phase G complete; main query uses scopeFilterSqlNullable('project','so'); customer filter dropdown scoped below
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';
require_once ROOT_DIR . '/core/workflow.php';

// Check user permissions dynamically
$can_view_sales_orders = canView('sales_orders');
$can_create_sales_orders = canCreate('sales_orders');
$can_edit_sales_orders = canEdit('sales_orders');
$can_delete_sales_orders = canDelete('sales_orders');
$can_approve_sales_orders = $can_edit_sales_orders; // Mapping approval to edit permission

// Three-approval workflow capabilities (mirrored to JS below)
$so_can_review  = canReview('sales_orders');
$so_can_approve = canApprove('sales_orders');
$so_is_admin    = isAdmin();

if (!$can_view_sales_orders) {
    header("Location: unauthorized");
    exit();
}

logActivity($pdo, $_SESSION['user_id'], 'View sales orders', 'User viewed the sales orders management list');

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$customer_filter = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$salesperson_filter = isset($_GET['salesperson']) ? intval($_GET['salesperson']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$payment_filter = isset($_GET['payment_status']) ? $_GET['payment_status'] : '';

// Fetch specific customer details if filtered
$filtered_customer = null;
if ($customer_filter > 0) {
    $c_stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
    $c_stmt->execute([$customer_filter]);
    $filtered_customer = $c_stmt->fetch(PDO::FETCH_ASSOC);
    $filtered_customer_name = $filtered_customer['customer_name'] ?? '';
}

// Build query with filters
$query = "
    SELECT 
        so.*,
        c.customer_name,
        c.company_name,
        c.phone as customer_phone,
        c.email as customer_email,
        u1.username as created_by_name,
        u2.username as salesperson_name,
        u3.username as updated_by_name,
        COUNT(soi.order_item_id) as total_items,
        SUM(soi.quantity * soi.unit_price) as subtotal,
        SUM(soi.quantity * soi.unit_price * soi.tax_rate / 100) as tax_amount,
        SUM(soi.quantity * soi.unit_price * (1 + soi.tax_rate / 100)) as grand_total,
        SUM(soi.quantity_delivered) as total_delivered,
        SUM(soi.quantity_invoiced) as total_invoiced,
        COUNT(DISTINCT i.invoice_id) as invoice_count,
        COALESCE(SUM(p.amount), 0) as total_paid,
        CASE
            WHEN so.status = 'cancelled' THEN 'cancelled'
            WHEN so.status = 'completed' THEN 'completed'
            WHEN so.status = 'delivered' THEN 'delivered'
            WHEN so.total_delivered > 0 AND so.total_delivered < so.total_ordered THEN 'partially_delivered'
            WHEN so.status = 'approved' THEN 'approved'
            WHEN so.status = 'reviewed' THEN 'reviewed'
            ELSE 'pending'
        END as display_status
    FROM sales_orders so
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN users u1 ON so.created_by = u1.user_id
    LEFT JOIN users u2 ON so.salesperson_id = u2.user_id
    LEFT JOIN users u3 ON so.updated_by = u3.user_id
    LEFT JOIN sales_order_items soi ON so.sales_order_id  = soi.order_id
    LEFT JOIN invoices i ON so.sales_order_id  = i.order_id AND i.status != 'cancelled'
    LEFT JOIN payments p ON i.invoice_id = p.invoice_id AND p.status = 'completed'
    WHERE so.is_quote = 0
";
$query .= scopeFilterSqlNullable('project', 'so');

$params = [];

// Apply filters
if (!empty($status_filter)) {
    $query .= " AND so.status = ?";
    $params[] = $status_filter;
}

if ($customer_filter > 0) {
    $query .= " AND so.customer_id = ?";
    $params[] = $customer_filter;
}

if ($salesperson_filter > 0) {
    $query .= " AND so.salesperson_id = ?";
    $params[] = $salesperson_filter;
}

if (!empty($date_from)) {
    $query .= " AND so.order_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND so.order_date <= ?";
    $params[] = $date_to;
}

$query .= " GROUP BY so.sales_order_id ORDER BY so.order_date DESC, so.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get data for filter dropdowns
$_so_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
if (isAdmin()) {
    $customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_so_assigned)) {
    $_so_ph = implode(',', array_fill(0, count($_so_assigned), '?'));
    $_so_cstmt = $pdo->prepare("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_so_ph)) ORDER BY customer_name");
    $_so_cstmt->execute($_so_assigned);
    $customers = $_so_cstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' AND project_id IS NULL ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
}
$salespeople = $pdo->query("SELECT user_id, username, CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE is_active = '1' AND role IN ('Admin', 'Manager', 'Sales') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Check projects setting
$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

// Calculate statistics
$total_orders = count($orders);
$total_value = array_sum(array_column($orders, 'grand_total'));

// Group by status for statistics
$status_counts = [
    'pending' => 0,
    'reviewed' => 0,
    'approved' => 0,
    'processing' => 0,
    'partially_delivered' => 0,
    'delivered' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($orders as $order) {
    $status = $order['display_status'] ?? $order['status'];
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    }
}

// Helper functions removed, now in helpers.php
?>
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

.bg-success-soft { background-color: rgba(25, 135, 84, 0.1) !important; }
.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 6px;
    border-radius: 6px;
    font-weight: bold;
}

.table thead th {
    background-color: #f8fafc !important;
    border-bottom: 2px solid #e2e8f0;
    padding: 1.25rem 1rem;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
}

.dropdown-menu { 
    padding: 0.5rem;
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-radius: 15px;
}
.dropdown-item {
    border-radius: 8px;
    padding: 0.6rem 1rem;
    margin-bottom: 2px;
}
.dropdown-item i { margin-right: 12px; width: 18px; text-align: center; }

/* Status Badge Themes */
.status-completed, .status-delivered { color: #157347 !important; background-color: #d1e7dd !important; }
.status-approved { color: #087990 !important; background-color: #cff4fc !important; }
.status-primary { color: #084298 !important; background-color: #cfe2ff !important; }
.status-warning { color: #997404 !important; background-color: #fff3cd !important; }
.status-danger { color: #842029 !important; background-color: #f8d7da !important; }
.status-secondary { color: #41464b !important; background-color: #e2e3e5 !important; }

@media print {
    body { background: white !important; padding: 0 !important; padding-top: 0 !important; margin: 0 !important; }
    .sales-orders-dashboard { background: white !important; padding: 20px !important; }
    
    /* Force Stats Cards to stay on one row in print */
    .row { display: flex !important; flex-wrap: nowrap !important; gap: 10px !important; }
    .col-md-3 { flex: 1 !important; width: 25% !important; margin-bottom: 0 !important; }
    .custom-stat-card { 
        padding: 10px !important;
        box-shadow: none !important; 
        border: 1px solid #d1e7dd !important;
        background-color: #f8fafc !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .custom-stat-card h4 { font-size: 14px !important; color: #000 !important; }
    .custom-stat-card small { font-size: 10px !important; color: #000 !important; }
    .stats-icon { width: 30px !important; height: 30px !important; font-size: 1.1rem !important; margin-right: 0.5rem !important; }

    .table-responsive { overflow: visible !important; }
    table { width: 100% !important; border-collapse: collapse !important; font-size: 8px !important; }
    th, td { border: 1px solid #dee2e6 !important; padding: 4px 2px !important; }
    th { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; font-size: 8px !important; }
    .d-print-none { display: none !important; }
    .dataTables_length, .dataTables_info, .dataTables_paginate, .dataTables_filter { display: none !important; }
}

#salesOrdersTable {
    font-size: 0.8rem !important;
}
#salesOrdersTable th, #salesOrdersTable td {
    padding: 10px 6px !important;
    vertical-align: middle;
}
#salesOrdersTable thead th {
    font-size: 0.7rem !important;
    white-space: nowrap;
}
.text-truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
<?php
// PHP logic continues...
?>

<div class="sales-orders-dashboard p-4 p-md-5" style="background: #ffffff; min-height: 100vh;">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-5" id="printHeader">
        <div class="pb-3 text-center">
           
            
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

            <div class="py-2 mt-2">
                <h3 class="fw-bold mb-1 text-dark text-uppercase">SALES ORDERS LIST</h3>
                <p class="text-dark mb-0 small text-uppercase">
                    <?php if (!empty($filtered_customer_name)) echo "CUSTOMER: " . strtoupper($filtered_customer_name) . " | "; ?>
                    PRINTED ON: <?= date('M d, Y h:i A') ?>
                </p>
            </div>
            <hr>
        </div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <!-- Breadcrumbs -->
                    <nav aria-label="breadcrumb" class="mb-2">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
                            <li class="breadcrumb-item active">Sales Orders</li>
                        </ol>
                    </nav>
                    <h2 class="fw-bold mb-1">
                        <i class="bi bi-cart-check text-primary me-2"></i>Sales Orders
                        <?php if (!empty($filtered_customer_name)): ?>
                            <span class="text-primary small fw-normal ms-2">| <?= safe_output($filtered_customer_name) ?></span>
                            <a href="<?= getUrl('sales_orders') ?>" class="btn btn-sm btn-outline-secondary ms-2 rounded-pill shadow-sm">
                                <i class="bi bi-x-circle"></i> View All
                            </a>
                        <?php endif; ?>
                    </h2>
                    <p class="text-muted mb-0">
                        <?php if (!empty($filtered_customer_name)): ?>
                            Viewing all orders for <strong><?= safe_output($filtered_customer_name) ?></strong>
                        <?php else: ?>
                            Manage customer sales orders and deliveries
                        <?php endif; ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_create_sales_orders): ?>
                    <a href="<?= getUrl('sales_order_create') ?>" class="btn btn-primary btn-sm shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> New Sales Order
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($filtered_customer): ?>
    <!-- Customer Info Bar -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm bg-light-subtle rounded-4 overflow-hidden">
                <div class="card-body p-0">
                    <div class="row g-0">
                        <div class="col-auto bg-primary d-flex align-items-center px-4 py-3">
                            <div class="text-white text-center">
                                <i class="bi bi-person-vcard fs-1"></i>
                                <div class="small fw-bold mt-1 text-uppercase opacity-75">Customer</div>
                            </div>
                        </div>
                        <div class="col p-4">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="text-muted small fw-bold text-uppercase d-block mb-1">Customer Code</label>
                                    <span class="fw-bold fs-5 custom-code"><?= safe_output($filtered_customer['customer_code']) ?></span>
                                </div>
                                <div class="col-md-3">
                                    <label class="text-muted small fw-bold text-uppercase d-block mb-1">Name & Company</label>
                                    <span class="fw-bold fs-6 d-block"><?= safe_output($filtered_customer['customer_name'] ?? '') ?></span>
                                    <?php if (!empty($filtered_customer['company_name'])): ?>
                                        <small class="text-muted"><?= safe_output($filtered_customer['company_name']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-2">
                                    <label class="text-muted small fw-bold text-uppercase d-block mb-1">Tax ID (TIN)</label>
                                    <span class="badge bg-info text-dark fw-bold"><?= !empty($filtered_customer['tax_id']) ? safe_output($filtered_customer['tax_id']) : 'N/A' ?></span>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small fw-bold text-uppercase d-block mb-1">Contact Info</label>
                                    <div class="d-flex flex-wrap gap-3">
                                        <?php if (!empty($filtered_customer['email'])): ?>
                                            <span><i class="bi bi-envelope text-primary me-1"></i> <?= safe_output($filtered_customer['email']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($filtered_customer['phone'])): ?>
                                            <span><i class="bi bi-telephone text-success me-1"></i> <?= safe_output($filtered_customer['phone']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($filtered_customer['mobile'])): ?>
                                            <span><i class="bi bi-phone text-info me-1"></i> <?= safe_output($filtered_customer['mobile']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-5">
        <div class="col-md-3">
            <div class="card custom-stat-card h-100 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-cart"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat-total-orders">0</h4>
                        <small class="text-uppercase small fw-bold">Total Orders</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card h-100 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat-pending-orders">0</h4>
                        <small class="text-uppercase small fw-bold">Pending Orders</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card h-100 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat-outstanding">0.00</h4>
                        <small class="text-uppercase small fw-bold">Outstanding</small>
                        <div class="text-muted" id="stat-collected-sub" style="font-size:0.7rem;line-height:1.3;"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card h-100 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-cash-stack"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat-total-value">0.00</h4>
                        <small class="text-uppercase small fw-bold">Total Sales Value</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Card -->
    <div class="card mb-4 border-0 shadow-sm d-print-none">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filters & Search</h6>
        </div>
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select class="form-select border-0 bg-light" name="status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="reviewed" <?= $status_filter == 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                        <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="processing" <?= $status_filter == 'processing' ? 'selected' : '' ?>>Processing</option>
                        <option value="partially_delivered" <?= $status_filter == 'partially_delivered' ? 'selected' : '' ?>>Partially Delivered</option>
                        <option value="delivered" <?= $status_filter == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                        <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Customer</label>
                    <select class="form-select border-0 bg-light" name="customer">
                        <option value="">All Customers</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['customer_id'] ?>" <?= $customer_filter == $customer['customer_id'] ? 'selected' : '' ?>>
                                <?= safe_output($customer['customer_name']) ?>
                                <?php if (!empty($customer['company_name'])): ?>
                                    (<?= safe_output($customer['company_name']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Salesperson</label>
                    <select class="form-select border-0 bg-light" name="salesperson">
                        <option value="">All Salespeople</option>
                        <?php foreach ($salespeople as $salesperson): ?>
                            <option value="<?= $salesperson['user_id'] ?>" <?= $salesperson_filter == $salesperson['user_id'] ? 'selected' : '' ?>>
                                <?= safe_output($salesperson['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Payment</label>
                    <select class="form-select border-0 bg-light" name="payment_status">
                        <option value="">All Payments</option>
                        <option value="unpaid" <?= $payment_filter == 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="partial" <?= $payment_filter == 'partial' ? 'selected' : '' ?>>Partial</option>
                        <option value="paid" <?= $payment_filter == 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">From</label>
                    <input type="date" class="form-control border-0 bg-light" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">To</label>
                    <input type="date" class="form-control border-0 bg-light" name="date_to" value="<?= $date_to ?>">
                </div>
                <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-filter me-1"></i> Apply Filter
                    </button>
                    <a href="<?= getUrl('sales_orders') ?>" class="btn btn-outline-primary px-4">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="mb-3 d-print-none text-start">
        <span class="badge bg-white text-dark border border-light-subtle px-3 py-2 fs-6 rounded-2 shadow-sm">
            <i class="bi bi-check-circle-fill text-success me-1"></i> Sales Order Records
        </span>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm border rounded-2 overflow-hidden">
                <button type="button" class="btn btn-outline-primary fw-medium px-3 border-0" onclick="copyTable()">
                    <i class="bi bi-clipboard me-1"></i> Copy
                </button>
                <button type="button" class="btn btn-outline-primary fw-medium px-3 border-0 border-start" onclick="exportExcel()">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export
                </button>
                <button type="button" class="btn btn-outline-primary fw-medium px-3 border-0 border-start" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" id="filter_limit" style="width: 60px; box-shadow: none; background: transparent;" onchange="loadOrders()">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Sales Orders Table Card -->
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="salesOrdersTable" style="width:100%">
                <thead class="bg-light text-uppercase small fw-bold">
                    <tr>
                        <th style="width:50px;" class="ps-4">S/NO</th>
                        <th class="ps-4">Order #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <?php if ($enable_projects): ?><th>Project</th><?php endif; ?>
                        <th class="text-center">Type</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Total Amount</th>
                        <th class="text-center">Payment</th>
                        <th class="text-center">Delivery</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4 d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data loaded via AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <!-- Report Footer Footer -->
    <div class="d-none d-print-block mt-5 pt-4 border-top">
        <div class="row">
            <div class="col-4 text-center">
                <div class="border-top mx-4 mt-4 pt-2 small text-muted">Prepared By</div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top mx-4 mt-4 pt-2 small text-muted">Management Review</div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top mx-4 mt-4 pt-2 small text-muted">Authorised Signature</div>
            </div>
        </div>
    </div>
</div> <!-- dashboard end -->




<script>
// JavaScript helper function for building URLs (must be before other scripts)
function buildUrl(path) {
    // Remove leading slash if present
    path = path.replace(/^\//, '');
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

let ordersTable;
const canEdit = <?= json_encode($can_edit_sales_orders) ?>;
const canDelete = <?= json_encode($can_delete_sales_orders) ?>;
const canApprove = <?= json_encode($can_approve_sales_orders) ?>;
const enableProjects = <?= $enable_projects ?>;

// Three-approval capability flags (mirrored from PHP)
const SO_CAN_REVIEW  = <?= $so_can_review  ? 'true' : 'false' ?>;
const SO_CAN_APPROVE = <?= $so_can_approve ? 'true' : 'false' ?>;
const SO_IS_ADMIN    = <?= $so_is_admin    ? 'true' : 'false' ?>;

$(document).ready(function() {
    // Log Activity
    logReportAction('Viewed Sales Orders List', 'User viewed the list of customer sales orders');
    
    // Load initial stats from PHP
    updateStatsFromPHP();
    
    initTable();
    
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        loadOrders();
    });
});

// Function to update stats from PHP calculated values
function updateStatsFromPHP() {
    const stats = {
        total_orders: <?= intval($total_orders) ?>,
        total_value: <?= floatval($total_value) ?>,
        total_collected: 0,
        pending_count: <?= intval($status_counts['pending'] ?? 0) ?>,
        reviewed_count: <?= intval($status_counts['reviewed'] ?? 0) ?>,
        approved_count: <?= intval($status_counts['approved'] ?? 0) ?>,
        processing_count: <?= intval($status_counts['processing'] ?? 0) ?>
    };
    updateStats(stats);
}

function initTable() {
    ordersTable = $('#salesOrdersTable').DataTable({
        processing: true,
        serverSide: true, // Enable server-side processing
        pageLength: 10, // Show 10 entries per page
        ajax: {
            url: '<?= buildUrl('api/account/get_sales_orders.php') ?>',
            data: function(d) {
                return $.extend({}, d, {
                    status: $('select[name="status"]').val(),
                    customer: $('select[name="customer"]').val(),
                    salesperson: $('select[name="salesperson"]').val(),
                    payment_status: $('select[name="payment_status"]').val(),
                    date_from: $('input[name="date_from"]').val(),
                    date_to: $('input[name="date_to"]').val(),
                    length: $('#filter_limit').val() || 10
                });
            },
            dataSrc: function(json) {
                if (json.success) {
                    // Update stats when data is loaded
                    if (json.stats) {
                        updateStats(json.stats);
                    }
                    return json.data;
                }
                return [];
            },
            error: function(xhr, error, thrown) {
                console.error('DataTables AJAX error:', error);
                console.error('Response:', xhr.responseText);
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '40px',
                className: 'ps-4 text-center text-muted small fw-bold',
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            {
                data: 'order_number',
                className: 'ps-4',
                width: '110px',
                render: function(data, type, row) {
                    let html = `<span class="custom-code small">${data}</span>`;
                    if (row.reference) {
                        html += `<br><small class="text-muted d-block text-truncate" style="max-width:105px;" title="${row.reference}">Ref: ${row.reference}</small>`;
                    }
                    if (row.invoice_count > 0) {
                        html += `<br><a href="invoices?order_id=${row.sales_order_id}" class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 text-decoration-none mt-1" style="font-size:0.6rem;"><i class="bi bi-receipt me-1"></i>${row.invoice_count} inv</a>`;
                    }
                    return html;
                }
            },
            {
                data: 'order_date',
                width: '110px',
                render: function(data, type, row) {
                    let html = `<span class="small">${data || ''}</span>`;
                    if (row.delivery_date) {
                        const today = new Date(); today.setHours(0,0,0,0);
                        const due = new Date(row.delivery_date + 'T00:00:00');
                        const isOverdue = due < today && !['delivered','completed','cancelled'].includes(row.status);
                        html += `<br><small class="${isOverdue ? 'text-danger fw-semibold' : 'text-muted'}" style="font-size:0.65rem;">`;
                        html += `Due: ${row.delivery_date}`;
                        if (isOverdue) html += ` <span class="badge bg-danger py-0 px-1" style="font-size:0.55rem;line-height:1.4;">LATE</span>`;
                        html += `</small>`;
                    }
                    return html;
                }
            },
            { 
                data: 'customer_name',
                render: function(data, type, row) {
                    return `<div class="text-truncate" style="max-width: 150px;" title="${data}"><strong>${data}</strong></div>${row.company_name ? `<div class="text-truncate text-muted small" style="max-width: 150px;" title="${row.company_name}">${row.company_name}</div>` : ''}`;
                }
            },
            <?php if ($enable_projects): ?>
            {
                data: 'project_name',
                defaultContent: '-',
                render: function(data) {
                    return data ? `<span class="badge bg-light text-dark border small p-1">${data}</span>` : '-';
                }
            },
            <?php endif; ?>
            {
                data: 'order_type',
                className: 'text-center',
                width: '100px',
                render: function(data) {
                    const isService = data === 'Non-Inventory';
                    return `<span class="badge ${isService ? 'bg-info bg-opacity-10 text-info border border-info border-opacity-25' : 'bg-success bg-opacity-10 text-success border border-success border-opacity-25'}" style="font-size:0.65rem;">${data || 'Inventory'}</span>`;
                }
            },
            {
                data: 'total_items',
                className: 'text-center',
                width: '60px',
                render: function(data) {
                    return `<span class="badge bg-secondary rounded-pill small">${data}</span>`;
                }
            },
            {
                data: 'grand_total',
                className: 'text-end fw-bold',
                width: '120px',
                render: function(data, type, row) {
                    return `<span class="small">${formatCurrency(data, row.currency)}</span>`;
                }
            },
            {
                data: null,
                className: 'text-center',
                orderable: false,
                width: '85px',
                render: function(data, type, row) {
                    const paid  = parseFloat(row.total_paid)  || 0;
                    const total = parseFloat(row.grand_total) || 0;
                    const active = ['approved','processing','partially_delivered','delivered','completed'];
                    if (!active.includes(row.display_status) || total <= 0) {
                        return '<span class="text-muted small">—</span>';
                    }
                    const pct = paid / total;
                    if (pct >= 0.999) return '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 py-1" style="font-size:0.6rem;">PAID</span>';
                    if (pct > 0.001)  return '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 py-1" style="font-size:0.6rem;">PARTIAL</span>';
                    return '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 py-1" style="font-size:0.6rem;">UNPAID</span>';
                }
            },
            {
                data: null,
                className: 'text-center',
                orderable: false,
                width: '100px',
                render: function(data, type, row) {
                    const delivered = parseFloat(row.total_delivered) || 0;
                    const ordered   = parseFloat(row.total_ordered)   || 0;
                    if (ordered <= 0 || ['pending','reviewed','cancelled'].includes(row.status)) {
                        return '<span class="text-muted small">—</span>';
                    }
                    const pct      = Math.min(100, Math.round((delivered / ordered) * 100));
                    const barColor = pct >= 100 ? 'bg-success' : pct > 0 ? 'bg-warning' : 'bg-secondary';
                    return `<div style="min-width:75px;max-width:95px;margin:0 auto;">
                        <div class="progress mb-1" style="height:5px;border-radius:3px;">
                            <div class="progress-bar ${barColor}" style="width:${pct}%"></div>
                        </div>
                        <div class="text-muted" style="font-size:0.65rem;">${delivered}/${ordered}</div>
                    </div>`;
                }
            },
            {
                data: 'display_status',
                className: 'text-center',
                width: '110px',
                render: function(data) {
                    const badgeClass = getStatusBadgeClass(data);
                    return `<span class="badge rounded-pill ${badgeClass} bg-opacity-10 py-1 px-2" style="font-size: 0.65rem; min-width: 80px; color: currentcolor !important;">${data.toUpperCase().replace('_', ' ')}</span>`;
                }
            },
            {
                data: null,
                className: 'text-end pe-4 d-print-none',
                orderable: false,
                render: function(data, type, row) {
                    const isPending      = (row.status === 'pending');
                    const isReviewed     = (row.status === 'reviewed');
                    const isApproved     = (row.status === 'approved');
                    const inWorkflow     = (isPending || isReviewed);
                    const canEditNow     = canEdit && (!isApproved || SO_IS_ADMIN);
                    const canDeleteNow   = canDelete && (isPending || SO_IS_ADMIN);

                    let actions = `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                <li><a class="dropdown-item py-2" href="sales_order_view?id=${row.sales_order_id}"><i class="bi bi-eye text-primary me-2"></i> View Details</a></li>
                    `;

                    // Three-approval workflow actions — both Review and Approve are shown
                    // in parallel whenever the order is still in the workflow chain.
                    // Review is active only when status is pending; Approve becomes
                    // active only after the order has been reviewed.
                    if (inWorkflow && SO_CAN_REVIEW) {
                        if (isPending) {
                            actions += `<li><a class="dropdown-item py-2 text-primary fw-bold" href="javascript:void(0)" onclick="reviewSalesOrder(${row.sales_order_id}, '${row.order_number}')"><i class="bi bi-check2 me-2"></i> Mark Reviewed</a></li>`;
                        } else {
                            actions += `<li><a class="dropdown-item py-2 text-muted disabled" href="javascript:void(0)" title="Already reviewed" tabindex="-1" aria-disabled="true"><i class="bi bi-check2 me-2"></i> Mark Reviewed</a></li>`;
                        }
                    }
                    if (inWorkflow && SO_CAN_APPROVE) {
                        if (isReviewed) {
                            actions += `<li><a class="dropdown-item py-2 text-success fw-bold" href="javascript:void(0)" onclick="approveSalesOrder(${row.sales_order_id}, '${row.order_number}')"><i class="bi bi-check-circle me-2"></i> Approve Order</a></li>`;
                        } else {
                            actions += `<li><a class="dropdown-item py-2 text-muted disabled" href="javascript:void(0)" title="Must be reviewed before approval" tabindex="-1" aria-disabled="true"><i class="bi bi-check-circle me-2"></i> Approve Order</a></li>`;
                        }
                    }

                    if (canEditNow && ['pending', 'reviewed', 'approved', 'processing'].includes(row.status)) {
                        actions += `<li><a class="dropdown-item py-2" href="sales_order_edit?id=${row.sales_order_id}"><i class="bi bi-pencil text-info me-2"></i> Edit Order</a></li>`;
                    }

                    // Conversion: only an approved SO can produce an invoice
                    if (canEdit && (isApproved || row.status === 'processing' || row.status === 'partially_delivered')) {
                        actions += `<li><a class="dropdown-item py-2" href="invoice_create?id=${row.sales_order_id}"><i class="bi bi-receipt text-success me-2"></i> Create Invoice</a></li>`;
                    }

                    if (canEditNow && ['pending', 'reviewed', 'approved', 'processing'].includes(row.status)) {
                        actions += `<li><a class="dropdown-item py-2 text-warning" href="javascript:void(0)" onclick="updateOrderStatus(${row.sales_order_id}, 'cancelled')"><i class="bi bi-x-octagon me-2"></i> Cancel Order</a></li>`;
                    }

                    if (canDeleteNow) {
                        actions += `<li><hr class="dropdown-divider opacity-50"></li>`;
                        actions += `<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="confirmDeleteOrder(${row.sales_order_id})"><i class="bi bi-trash me-2"></i> Delete Order</a></li>`;
                    }

                    actions += `</ul></div>`;
                    return actions;
                }
            }
        ],
        order: [[1, 'desc']],
        pageLength: 10,
        language: {
            processing: '<div class="spinner-border text-primary" role="status"><span></span></div>'
        },
        searching: false,
        dom: 'rtip' // Remove default search box and length menu from DOM
    });
}

function loadOrders() {
    ordersTable.ajax.reload();
}

function updateStats(stats) {
    if (!stats) {
        console.warn('No stats object provided to updateStats');
        return;
    }
    $('#stat-total-orders').text(stats.total_orders || 0);
    $('#stat-pending-orders').text(stats.pending_count || 0);

    const collected    = parseFloat(stats.total_collected) || 0;
    const totalVal     = parseFloat(stats.total_value)     || 0;
    const outstanding  = Math.max(0, totalVal - collected);
    $('#stat-outstanding').text(formatCurrency(outstanding));
    $('#stat-collected-sub').text('Collected: ' + formatCurrency(collected));

    $('#stat-total-value').text(formatCurrency(totalVal));
}

function getStatusBadgeClass(status) {
    switch(status) {
        case 'completed': return 'status-completed';
        case 'delivered': return 'status-delivered';
        case 'approved': return 'status-approved';
        case 'reviewed': return 'status-primary';
        case 'processing': return 'status-primary';
        case 'pending': return 'status-warning';
        case 'partially_delivered': return 'status-warning';
        case 'cancelled': return 'status-danger';
        default: return 'status-secondary';
    }
}

function changeOrderStatus(id, currentStatus) {
    const statuses = {
        'pending': 'Pending',
        'reviewed': 'Reviewed',
        'approved': 'Approved',
        'processing': 'Processing',
        'partially_delivered': 'Partially Delivered',
        'delivered': 'Delivered',
        'completed': 'Completed',
        'cancelled': 'Cancelled'
    };

    let options = '';
    for (let key in statuses) {
        options += `<option value="${key}" ${key === currentStatus ? 'selected' : ''}>${statuses[key]}</option>`;
    }

    Swal.fire({
        title: 'Change Order Status',
        html: `
            <div class="text-start mb-3">
                <label class="form-label small fw-bold">Select New Status:</label>
                <select id="swal-order-status" class="form-select">
                    ${options}
                </select>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Update Status',
        preConfirm: () => {
            return document.getElementById('swal-order-status').value;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            updateOrderStatus(id, result.value);
        }
    });
}

function reviewSalesOrder(id, orderNumber) {
    Swal.fire({
        title: 'Mark as Reviewed?',
        text: `Sales Order ${orderNumber} will move to "Reviewed" and become approvable.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, mark reviewed',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.ajax({
            url: '<?= buildUrl('api/account/review_sales_order.php') ?>',
            type: 'POST',
            data: { sales_order_id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    logReportAction('Reviewed Sales Order', 'User marked sales order ' + orderNumber + ' as reviewed');
                    Swal.fire({ icon: 'success', title: 'Reviewed!', text: response.message, timer: 1800, showConfirmButton: false });
                    ordersTable.ajax.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to mark reviewed' });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Communication error. Please try again.' }); }
        });
    });
}

function approveSalesOrder(id, orderNumber) {
    Swal.fire({
        title: 'Approve Sales Order?',
        text: `Are you sure you want to approve ${orderNumber}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, approve it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.ajax({
            url: '<?= buildUrl('api/account/approve_sales_order.php') ?>',
            type: 'POST',
            data: { sales_order_id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    logReportAction('Approved Sales Order', 'User approved sales order ' + orderNumber);
                    Swal.fire({ icon: 'success', title: 'Approved!', text: response.message, timer: 2000, showConfirmButton: false });
                    ordersTable.ajax.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to approve order' });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Communication error. Please try again.' }); }
        });
    });
}

function updateOrderStatus(orderId, status) {
    Swal.fire({
        title: 'Update Status?',
        text: `Are you sure you want to change order status to ${status}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Update'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= buildUrl('api/account/update_sales_order_status.php') ?>',
                type: 'POST',
                data: { order_id: orderId, status: status },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: response.message,
                            confirmButtonColor: '#28a745',
                            confirmButtonText: 'OK',
                            timer: 3000
                        });
                        loadOrders();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function() {
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

function confirmDeleteOrder(orderId) {
    Swal.fire({
        title: 'Delete Order?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= buildUrl('api/account/delete_sales_order.php') ?>',
                type: 'POST',
                data: { order_id: orderId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'Order has been deleted.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        loadOrders();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function() {
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

// formatCurrency function moved to top (already defined above)

// toast function removed - using Swal.fire directly now

function exportOrders() {
    const params = $.param({
        status: $('select[name="status"]').val(),
        customer: $('select[name="customer"]').val(),
        salesperson: $('select[name="salesperson"]').val(),
        payment_status: $('select[name="payment_status"]').val(),
        date_from: $('input[name="date_from"]').val(),
        date_to: $('input[name="date_to"]').val()
    });
    logReportAction('Exported Sales Orders', 'User exported sales orders list to CSV');
    window.location.href = buildUrl('api/account/export_sales_orders.php?' + params);
}

function copyTable() {
    const table = document.getElementById('salesOrdersTable');
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    logReportAction('Copied Sales Orders Table', 'User copied sales orders table to clipboard');
    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table data copied to clipboard', timer: 1000, showConfirmButton: false });
}

function exportExcel() {
    const table = document.getElementById('salesOrdersTable');
    const rows = Array.from(table.querySelectorAll('tr'));
    const csvContent = rows.map(row => {
        const cols = Array.from(row.querySelectorAll('th, td')).slice(0, -1); // Exclude actions
        return cols.map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
    }).join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.setAttribute('download', 'SalesOrders.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    logReportAction('Exported Sales Orders', 'User exported sales orders list to Excel/CSV');
}
</script>

<style>
/* Table refined */
.table {
    border-collapse: separate;
    border-spacing: 0 8px;
}
.table tbody tr {
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    border-radius: 12px;
}
.table tbody td {
    border: none;
    padding: 1.25rem 1rem;
}
.table tbody td:first-child { border-radius: 12px 0 0 12px; }
.table tbody td:last-child { border-radius: 0 12px 12px 0; }

.badge {
    padding: 0.5em 1em;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.5px;
    border-radius: 8px;
}

.dropdown-menu { 
    padding: 0.5rem;
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-radius: 15px;
}
.dropdown-item {
    border-radius: 8px;
    padding: 0.6rem 1rem;
    margin-bottom: 2px;
}
.dropdown-item i { margin-right: 12px; width: 18px; text-align: center; }
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>