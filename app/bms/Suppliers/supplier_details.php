<?php
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('suppliers');

// Include the header
includeHeader();

// Permission flags
$can_view_suppliers = isAdmin() || canView('suppliers');


// Get supplier ID
$supplier_id = $_GET['id'] ?? '';
if (empty($supplier_id)) {
    header("Location: suppliers.php?error=Supplier ID required");
    exit();
}

// Get supplier details
$stmt = $pdo->prepare("
    SELECT s.*,
           sc.category_name,
           u1.username as created_by_name,
           u2.username as updated_by_name
    FROM suppliers s
    LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
    LEFT JOIN users u1 ON s.created_by = u1.user_id
    LEFT JOIN users u2 ON s.updated_by = u2.user_id
    WHERE s.supplier_id = ? AND s.status != 'deleted'
");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    echo "<div class='alert alert-danger'>Supplier not found</div>";
    include("footer.php");
    exit();
}

// Get purchase orders
$orders_stmt = $pdo->prepare("
    SELECT po.*, u.username as created_by_name
    FROM purchase_orders po
    LEFT JOIN users u ON po.created_by = u.user_id
    WHERE po.supplier_id = ?
    ORDER BY po.order_date DESC
    LIMIT 20
");
$orders_stmt->execute([$supplier_id]);
$purchase_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment history
$payments_stmt = $pdo->prepare("
    SELECT sp.*, u.username as created_by_name
    FROM supplier_payments sp
    LEFT JOIN users u ON sp.created_by = u.user_id
    WHERE sp.supplier_id = ?
    ORDER BY sp.payment_date DESC
    LIMIT 20
");
$payments_stmt->execute([$supplier_id]);
$payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get projects this supplier is involved in (via purchase orders)
$supplier_projects = [];
$proj_ids_seen = [];
foreach ($purchase_orders as $po) {
    if (!empty($po['project_id']) && !in_array($po['project_id'], $proj_ids_seen)) {
        $proj_ids_seen[] = $po['project_id'];
    }
}
if (!empty($proj_ids_seen)) {
    $proj_placeholders = implode(',', array_fill(0, count($proj_ids_seen), '?'));
    $proj_stmt = $pdo->prepare("
        SELECT p.project_id, p.project_name, p.status, p.contract_sum,
               COUNT(po.purchase_order_id) as po_count,
               SUM(po.total_amount) as total_supplied
        FROM projects p
        LEFT JOIN purchase_orders po ON po.project_id = p.project_id AND po.supplier_id = ?
        WHERE p.project_id IN ($proj_placeholders)
        GROUP BY p.project_id, p.project_name, p.status, p.contract_sum
        ORDER BY p.project_name
    ");
    $proj_stmt->execute(array_merge([$supplier_id], $proj_ids_seen));
    $supplier_projects = $proj_stmt->fetchAll(PDO::FETCH_ASSOC);
}
$total_supplier_projects = count($supplier_projects);

// Calculate statistics
$total_orders = count($purchase_orders);
$total_spent = array_sum(array_column($purchase_orders, 'total_amount'));
$pending_orders = array_filter($purchase_orders, function($order) {
    return in_array($order['status'], ['pending', 'ordered']);
});
$pending_amount = array_sum(array_column($pending_orders, 'total_amount'));

// Helper functions removed, now in helpers.php
global $company_name, $company_logo;
?>

<div class="container-fluid mt-2 mt-md-4 px-2 px-md-4 mb-5">
    <!-- Print-only Header -->
    <div class="d-none d-print-block text-center mb-4">
       
        <h4 class="fw-bold text-dark text-uppercase">SUPPLIER INFORMATION REPORT</h4>
        <h5 class="text-muted"><?= htmlspecialchars($supplier['supplier_name']) ?> (<?= htmlspecialchars($supplier['supplier_code']) ?>)</h5>
        <div class="mt-2" style="border-top: 2px solid #0d6efd; width: 150px; margin: 0 auto;"></div>
    </div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('suppliers') ?>">Suppliers</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($supplier['supplier_name']) ?></li>
        </ol>
    </nav>

    <!-- Supplier Header -->
    <div class="row mb-3 mb-md-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-start flex-nowrap gap-2">
                <div>
                    <h2 class="mb-0 fs-4 fs-md-2 fw-bold"><i class="bi bi-truck"></i> Supplier View</h2>
                    <p class="text-muted mb-0 small mt-1 header-desc">
                        Detailed information about <?= htmlspecialchars($supplier['supplier_name']) ?> 
                        <?php if (!empty($supplier['company_name'])): ?>
                        • Company: <?= htmlspecialchars($supplier['company_name']) ?>
                        <?php endif; ?>
                        • Code: <code><?= htmlspecialchars($supplier['supplier_code']) ?></code>
                    </p>
                </div>
                <!-- Desktop Actions (Hidden on mobile) -->
                <div class="d-none d-sm-flex gap-2 ms-auto pt-2 flex-shrink-0">
                    <a href="<?= getUrl('suppliers') ?>" class="btn btn-secondary btn-sm px-2 shadow-sm" title="Back to Suppliers">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                    <button onclick="printDetails()" class="btn btn-info btn-sm px-2 text-white shadow-sm" title="Print Details">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <?php if (isAdmin() || canEdit('suppliers')): ?>
                    <button class="btn btn-primary btn-sm px-2 shadow-sm" onclick="editSupplier(<?= $supplier['supplier_id'] ?>)" title="Edit Supplier">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Mobile Actions (Dropdown) -->
                <div class="d-flex d-sm-none ms-auto pt-1 flex-shrink-0">
                    <div class="dropdown">
                        <button class="btn btn-primary btn-sm dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill me-1"></i> Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <li>
                                <a class="dropdown-item py-2" href="<?= getUrl('suppliers') ?>">
                                    <i class="bi bi-arrow-left text-secondary"></i> Back to Suppliers
                                </a>
                            </li>
                            <li>
                                <button class="dropdown-item py-2" onclick="printDetails()">
                                    <i class="bi bi-printer text-info"></i> Print Details
                                </button>
                            </li>
                            <?php if (isAdmin() || canEdit('suppliers')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button class="dropdown-item py-2" onclick="editSupplier(<?= $supplier['supplier_id'] ?>)">
                                    <i class="bi bi-pencil text-primary"></i> Edit Supplier
                                </button>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <hr class="d-md-none mt-2 mb-0 opacity-25">
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 g-3">
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 auto-resize"><?= $total_orders ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold" style="font-size: 0.75rem;">Total Orders</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 auto-resize text-nowrap"><?= format_currency($total_spent) ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold" style="font-size: 0.75rem;">Total Spent</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 auto-resize"><?= count($pending_orders) ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold" style="font-size: 0.75rem;">Pending Orders</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 auto-resize text-nowrap"><?= format_currency($pending_amount) ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold" style="font-size: 0.75rem;">Pending Amount</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Supplier Info Cards -->
    <div class="row mb-4">
        <!-- Basic Info Card -->
        <div class="col-md-4 mb-3">
            <?php if (!empty($supplier['logo_path']) && file_exists($supplier['logo_path'])): ?>
            <div class="card mb-3 shadow-sm border-0 overflow-hidden">
                <style>
                    .logo-box {
                        height: 140px;
                        background: #fff;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        padding: 15px;
                        border: 1px dashed #e9ecef;
                    }
                    .logo-img {
                        max-width: 100%;
                        max-height: 100%;
                        object-fit: contain;
                        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.05));
                    }
                </style>
                <div class="card-header bg-white border-bottom-0 pt-3 pb-1">
                    <h6 class="mb-0 fw-bold text-primary small text-uppercase" style="letter-spacing: 0.5px;">
                        <i class="bi bi-image me-1"></i> Supplier Logo
                    </h6>
                </div>
                <div class="card-body p-3">
                    <div class="logo-box rounded-3">
                        <img src="<?= getUrl($supplier['logo_path']) ?>" 
                             class="logo-img" 
                             alt="Supplier Logo">
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-info-circle"></i> Basic Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td>
                                <span class="badge bg-<?= get_status_badge($supplier['status']) ?>">
                                    <?= ucfirst($supplier['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php if (!empty($supplier['company_name'])): ?>
                        <tr>
                            <td><strong>Company Name:</strong></td>
                            <td><?= htmlspecialchars($supplier['company_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['acronym'])): ?>
                        <tr>
                            <td><strong>Acronym:</strong></td>
                            <td><?= htmlspecialchars($supplier['acronym']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['supplier_type'])): ?>
                        <tr>
                            <td><strong>Supplier Type:</strong></td>
                            <td><?= htmlspecialchars($supplier['supplier_type']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['year'])): ?>
                        <tr>
                            <td><strong>Year:</strong></td>
                            <td><?= htmlspecialchars($supplier['year']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['category_name'])): ?>
                        <tr>
                            <td><strong>Category:</strong></td>
                            <td><?= htmlspecialchars($supplier['category_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['contact_person'])): ?>
                        <tr>
                            <td><strong>Contact Person:</strong></td>
                            <td><?= htmlspecialchars($supplier['contact_person']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['contact_title'])): ?>
                        <tr>
                            <td><strong>Contact Title:</strong></td>
                            <td><?= htmlspecialchars($supplier['contact_title']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['tax_id'])): ?>
                        <tr>
                            <td><strong>Tax ID:</strong></td>
                            <td><code><?= htmlspecialchars($supplier['tax_id']) ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['vat_number'])): ?>
                        <tr>
                            <td><strong>VAT Number:</strong></td>
                            <td><?= htmlspecialchars($supplier['vat_number']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['payment_terms'])): ?>
                        <tr>
                            <td><strong>Payment Terms:</strong></td>
                            <td><?= ucfirst(str_replace('_', ' ', $supplier['payment_terms'])) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Credit Limit:</strong></td>
                            <td><span class="text-primary fw-bold"><?= format_currency($supplier['credit_limit'] ?? 0) ?></span></td>
                        </tr>
                        <?php if (!empty($supplier['currency'])): ?>
                        <tr>
                            <td><strong>Currency:</strong></td>
                            <td><?= htmlspecialchars($supplier['currency']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Created:</strong></td>
                            <td>
                                <?= htmlspecialchars($supplier['created_by_name']) ?>
                                <br>
                                <small class="text-muted"><?= format_date($supplier['created_at']) ?></small>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Contact Info Card -->
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-telephone"></i> Contact Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <?php if (!empty($supplier['company_email'])): ?>
                        <tr>
                            <td><strong>Company Email:</strong></td>
                            <td>
                                <a href="mailto:<?= htmlspecialchars($supplier['company_email']) ?>">
                                    <?= htmlspecialchars($supplier['company_email']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['email'])): ?>
                        <tr>
                            <td><strong>Contact Email:</strong></td>
                            <td>
                                <a href="mailto:<?= htmlspecialchars($supplier['email']) ?>">
                                    <?= htmlspecialchars($supplier['email']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['phone'])): ?>
                        <tr>
                            <td><strong>Phone:</strong></td>
                            <td>
                                <a href="tel:<?= htmlspecialchars($supplier['phone']) ?>">
                                    <?= htmlspecialchars($supplier['phone']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['mobile'])): ?>
                        <tr>
                            <td><strong>Mobile:</strong></td>
                            <td>
                                <a href="tel:<?= htmlspecialchars($supplier['mobile']) ?>">
                                    <?= htmlspecialchars($supplier['mobile']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['fax'])): ?>
                        <tr>
                            <td><strong>Fax:</strong></td>
                            <td><?= htmlspecialchars($supplier['fax']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['website'])): ?>
                        <tr>
                            <td><strong>Website:</strong></td>
                            <td>
                                <a href="<?= htmlspecialchars($supplier['website']) ?>" target="_blank">
                                    <?= htmlspecialchars($supplier['website']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Address Card -->
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-geo-alt"></i> Address Information</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($supplier['address'])): ?>
                    <p class="mb-2"><strong>Address:</strong><br>
                    <?= nl2br(htmlspecialchars($supplier['address'])) ?></p>
                    <?php endif; ?>
                    
                    <table class="table table-sm mb-0">
                        <?php if (!empty($supplier['country'])): ?>
                        <tr>
                            <td style="width: 40%;"><strong>Country:</strong></td>
                            <td><?= htmlspecialchars($supplier['country']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['state'])): ?>
                        <tr>
                            <td><strong>Region:</strong></td>
                            <td><?= htmlspecialchars($supplier['state']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['council'])): ?>
                        <tr>
                            <td><strong>Council:</strong></td>
                            <td><?= htmlspecialchars($supplier['council']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['ward'])): ?>
                        <tr>
                            <td><strong>Ward:</strong></td>
                            <td><?= htmlspecialchars($supplier['ward']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['city'])): ?>
                        <tr>
                            <td><strong>City:</strong></td>
                            <td><?= htmlspecialchars($supplier['city']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['postal_code'])): ?>
                        <tr>
                            <td><strong>Zip Code:</strong></td>
                            <td><?= htmlspecialchars($supplier['postal_code']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['postal_address'])): ?>
                        <tr>
                            <td><strong>Postal Address:</strong></td>
                            <td><?= htmlspecialchars($supplier['postal_address']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial Information -->
    <?php if (!empty($supplier['bank_name']) || !empty($supplier['bank_account'])): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-bank"></i> Bank Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php if (!empty($supplier['bank_name'])): ?>
                        <div class="col-6 col-md-4 mb-2 mb-md-0">
                            <p class="mb-0 text-muted small fw-bold">Bank Name</p>
                            <p class="mb-0"><?= htmlspecialchars($supplier['bank_name']) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($supplier['bank_account'])): ?>
                        <div class="col-6 col-md-4 mb-2 mb-md-0">
                            <p class="mb-0 text-muted small fw-bold">Account Number</p>
                            <p class="mb-0"><code><?= htmlspecialchars($supplier['bank_account']) ?></code></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($supplier['bank_address'])): ?>
                        <div class="col-12 col-md-4">
                            <p class="mb-0 text-muted small fw-bold">Bank Address</p>
                            <p class="mb-0"><?= nl2br(htmlspecialchars($supplier['bank_address'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Description -->
    <?php if (!empty($supplier['description'])): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-chat-text"></i> Description</h6>
                </div>
                <div class="card-body">
                    <?= nl2br(htmlspecialchars($supplier['description'])) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Projects Involved -->
    <div class="row mt-2 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex align-items-center">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-diagram-3 text-primary me-2"></i> Projects Involved <span class="badge bg-primary ms-1"><?= $total_supplier_projects ?></span></h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered mb-0" id="supplierProjectsTable">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width:50px">S/No</th>
                                    <th>Project Name</th>
                                    <th>Contract Sum</th>
                                    <th>POs Count</th>
                                    <th>Total Supplied</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($supplier_projects as $i => $proj): ?>
                                <tr>
                                    <td class="text-muted"><?= $i + 1 ?></td>
                                    <td>
                                        <a href="<?= getUrl('project_view') ?>?id=<?= $proj['project_id'] ?>" class="fw-bold text-decoration-none">
                                            <?= htmlspecialchars($proj['project_name']) ?>
                                        </a>
                                    </td>
                                    <td><?= format_currency($proj['contract_sum'] ?? 0) ?></td>
                                    <td><span class="badge bg-secondary"><?= $proj['po_count'] ?></span></td>
                                    <td><?= format_currency($proj['total_supplied'] ?? 0) ?></td>
                                    <td><span class="badge bg-<?= get_status_badge($proj['status']) ?>"><?= ucfirst($proj['status']) ?></span></td>
                                    <td class="text-end">
                                        <a href="<?= getUrl('project_view') ?>?id=<?= $proj['project_id'] ?>" class="btn btn-sm btn-outline-info shadow-sm px-2">
                                            <i class="bi bi-eye me-1"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Purchase Orders -->
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-cart"></i> Recent Purchase Orders</h6>
                        <a href="<?= getUrl('purchase_orders') ?>?supplier=<?= $supplier_id ?>" class="btn btn-outline-primary btn-sm shadow-sm">
                            View All
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="supplierPOTable">
                            <thead class="bg-light">
                                <tr>
                                    <th width="50">S/NO</th>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sn = 1;
                                foreach ($purchase_orders as $order):
                                ?>
                                <tr>
                                    <td><?= $sn++ ?></td>
                                    <td>
                                        <a href="<?= getUrl('purchase_order_details') ?>?id=<?= $order['purchase_order_id'] ?>" class="fw-bold">
                                            <?= htmlspecialchars($order['order_number']) ?>
                                        </a>
                                    </td>
                                    <td><?= format_date($order['order_date']) ?></td>
                                    <td><?= format_currency($order['total_amount']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= get_status_badge($order['status']) ?>">
                                            <?= ucfirst($order['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear-fill me-1"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                                <li>
                                                    <a class="dropdown-item py-2 rounded" href="<?= getUrl('purchase_order_details') ?>?id=<?= $order['purchase_order_id'] ?>">
                                                        <i class="bi bi-eye text-info me-2"></i> View
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item py-2 rounded text-danger" href="#" onclick="deletePO(<?= $order['purchase_order_id'] ?>, '<?= htmlspecialchars(addslashes($order['order_number'])) ?>'); return false;">
                                                        <i class="bi bi-trash text-danger me-2"></i> Delete
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

        <!-- Payment History -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-cash"></i> Recent Payments</h6>
                        <a href="<?= getUrl('suppliers/payments') ?>?id=<?= $supplier_id ?>" class="btn btn-outline-primary btn-sm shadow-sm">
                            View All
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="supplierPaymentsTable">
                            <thead class="bg-light">
                                <tr>
                                    <th width="50">S/NO</th>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Amount</th>
                                    <th>Currency</th>
                                    <th>Method</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $psn = 1;
                                foreach ($payments as $payment):
                                ?>
                                <tr>
                                    <td><?= $psn++ ?></td>
                                    <td><?= format_date($payment['payment_date']) ?></td>
                                    <td><?= htmlspecialchars($payment['reference_number']) ?></td>
                                    <td><?= format_currency($payment['amount']) ?></td>
                                    <td><?= htmlspecialchars($payment['currency'] ?? 'TZS') ?></td>
                                    <td><?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?></td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear-fill me-1"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                                <li>
                                                    <a class="dropdown-item py-2 rounded" href="<?= getUrl('suppliers/payments') ?>?id=<?= $supplier_id ?>&payment=<?= $payment['payment_id'] ?>">
                                                        <i class="bi bi-eye text-info me-2"></i> View
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item py-2 rounded text-danger" href="#" onclick="deletePayment(<?= $payment['payment_id'] ?>, '<?= htmlspecialchars(addslashes($payment['reference_number'] ?? '')) ?>'); return false;">
                                                        <i class="bi bi-trash text-danger me-2"></i> Delete
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
    </div>
</div>

<script>
$(document).ready(function() {

    // Handle printing
    window.printDetails = function() {
        window.print();
    };

    // Handle edit click
    window.logEditClick = function() {
        return true;
    };

    $('#supplierProjectsTable').DataTable({
        pageLength: 10,
        responsive: false,
        order: [[0, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, -1] }],
        language: { emptyTable: 'No projects found for this supplier.', zeroRecords: 'No matching projects found.' }
    });

    $('#supplierPOTable').DataTable({
        pageLength: 10,
        responsive: false,
        order: [[0, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, -1] }],
        language: { emptyTable: 'No purchase orders found.', zeroRecords: 'No matching purchase orders found.' }
    });

    $('#supplierPaymentsTable').DataTable({
        pageLength: 10,
        responsive: false,
        order: [[0, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, -1] }],
        language: { emptyTable: 'No payment history found.', zeroRecords: 'No matching payments found.' }
    });
});

function editSupplier(supplierId) {
    logEditClick();
    window.location.href = '<?= getUrl('suppliers') ?>?edit=' + supplierId;
}

function deletePO(poId, poNumber) {
    Swal.fire({
        title: 'Delete Purchase Order?',
        text: 'Delete PO "' + poNumber + '"? This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (r.isConfirmed) {
            $.post(APP_URL + '/api/delete_purchase_order.php', { id: poId }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1500, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function deletePayment(paymentId, voucherNo) {
    Swal.fire({
        title: 'Delete Payment?',
        text: 'Delete payment "' + (voucherNo || '#' + paymentId) + '"? This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (r.isConfirmed) {
            $.post(APP_URL + '/api/delete_supplier_payment.php', { payment_id: paymentId }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1500, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function resizeTextToFit() {
    const elements = document.querySelectorAll('.custom-stat-card h4.auto-resize');
    elements.forEach(el => {
        let size = 1.5; // Starting size
        el.style.fontSize = size + 'rem';
        const container = el.closest('.card-body');
        if (container) {
            const containerWidth = container.clientWidth - 20;
            while (el.scrollWidth > containerWidth && size > 0.6) {
                size -= 0.05;
                el.style.fontSize = size + 'rem';
            }
        }
    });
}
window.addEventListener('load', resizeTextToFit);
window.addEventListener('resize', resizeTextToFit);
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}

.badge {
    font-size: 0.75em;
}

.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border: 1px solid #badbcc !important;
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
}

.custom-stat-card {
    background-color: #d1e7dd !important;
    border: none !important;
    border-radius: 12px;
    height: 100%;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: transform 0.2s;
}
.custom-stat-card:hover {
    transform: translateY(-5px);
}
.custom-stat-card h4 {
    color: #0f5132 !important;
    font-weight: 800 !important;
    font-size: 1.5rem;
}
.custom-stat-card p {
    color: #157347 !important;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.custom-code {
    color: #084298 !important;
    background-color: #e7f1ff !important;
    padding: 2px 4px;
    border-radius: 4px;
}

@media (max-width: 576px) {
    .header-desc {
        font-size: 0.7rem !important;
        line-height: 1.3 !important;
        margin-top: 2px !important;
    }
}

.table thead th {
    background-color: #f8f9fa !important;
}

@media print {
    /* ===== PAGE SETUP ===== */
    @page {
        size: A4 portrait;
        margin: 10mm 10mm 10mm 10mm;
    }

    /* ===== HIDE NON-PRINTABLE ELEMENTS ===== */
    .btn, .breadcrumb, .alert, .navbar, footer,
    .d-print-none, nav, .card-header .btn,
    .dropdown, .sidebar, .d-flex.gap-2 {
        display: none !important;
    }

    /* ===== BASE ===== */
    html, body {
        background: white !important;
        font-family: 'Inter', Arial, sans-serif !important;
        font-size: 9pt !important;
        color: #111 !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }

    /* ===== STRIP ALL CONTAINERS ===== */
    .container-fluid, .container, .px-4, .mt-4 {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    /* ===== ROWS: full width, NO page-break-inside on outer row ===== */
    .row {
        display: flex !important;
        flex-wrap: wrap !important;
        width: 100% !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        box-sizing: border-box !important;
    }

    /* ===== 3-COLUMN INFO CARDS ROW (col-md-4 each = ~33%) ===== */
    .row > .col-md-4,
    .row > .col-md-4.mb-3 {
        flex: 0 0 33.333% !important;
        max-width: 33.333% !important;
        padding: 0 4pt !important;
        box-sizing: border-box !important;
    }

    /* ===== FULL-WIDTH COL (col-12, col-md-12) ===== */
    .row > .col-12,
    .row > .col-md-12 {
        flex: 0 0 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }

    /* ===== 4 STAT CARDS IN ONE ROW (col-md-3 each = 25%) ===== */
    .row > .col-md-3,
    .row > .col-6.col-md-3 {
        flex: 0 0 25% !important;
        max-width: 25% !important;
        padding: 0 3pt !important;
        box-sizing: border-box !important;
    }

    /* ===== 2-COLUMN TABLES ROW (col-md-6 each = 50%) ===== */
    .row > .col-md-6 {
        flex: 0 0 50% !important;
        max-width: 50% !important;
        padding: 0 4pt !important;
        box-sizing: border-box !important;
    }

    /* ===== INNER COLS INSIDE CARDS ===== */
    .card .col-md-4 {
        flex: 0 0 33.333% !important;
        max-width: 33.333% !important;
        padding: 0 3pt !important;
        box-sizing: border-box !important;
    }

    /* ===== mb-4/mb-3 ===== */
    .mb-4, .mb-3 { margin-bottom: 5pt !important; }

    /* ===== CARDS ===== */
    .card {
        border: none !important;
        box-shadow: none !important;
        margin-bottom: 6pt !important;
        border-radius: 0 !important;
        overflow: visible !important;
        width: 100% !important;
        page-break-inside: avoid;
    }

    /* ===== STAT CARDS ===== */
    .custom-stat-card {
        background-color: #d1e7dd !important;
        border: 0.5pt solid #badbcc !important;
        border-radius: 4pt !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .custom-stat-card .card-body {
        padding: 8pt 4pt !important;
        text-align: center !important;
    }
    .custom-stat-card h4 {
        font-size: 12pt !important;
        font-weight: 800 !important;
        color: #0f5132 !important;
        margin-bottom: 2pt !important;
    }
    .custom-stat-card p {
        font-size: 7pt !important;
        color: #157347 !important;
        font-weight: 600 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
        margin: 0 !important;
    }

    /* ===== CARD HEADERS: blue underline ===== */
    .card-header {
        background: none !important;
        border: none !important;
        border-bottom: 1.5pt solid #0d6efd !important;
        padding: 2pt 0 !important;
        margin-bottom: 4pt !important;
    }
    .card-header h6 {
        font-size: 8pt !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        color: #0d6efd !important;
        margin: 0 !important;
    }

    /* ===== CARD BODY ===== */
    .card-body {
        padding: 4pt 0 0 0 !important;
    }

    /* ===== TABLES ===== */
    .table, table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 8.5pt !important;
    }
    .table td, .table th {
        padding: 3pt 4pt !important;
        border-bottom: 0.5pt solid #ddd !important;
        word-break: break-word !important;
    }

    /* ===== BADGES ===== */
    .badge {
        border: 0.75pt solid #888 !important;
        background: transparent !important;
        color: #111 !important;
        font-weight: 700 !important;
        font-size: 7pt !important;
        padding: 1pt 4pt !important;
    }

    /* ===== TEXT COLORS ===== */
    .text-muted  { color: #666 !important; }
    .text-primary { color: #0d6efd !important; }
    .text-success { color: #198754 !important; }
    .text-danger  { color: #dc3545 !important; }
    .text-dark    { color: #111 !important; }

    /* ===== PRINT HEADER ===== */
    .d-none.d-print-block {
        display: block !important;
    }
}
</style>

<?php
includeFooter();
ob_end_flush();
?>