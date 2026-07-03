<?php
// scope-audit: skip — multi-project scope gate below checks supplier_projects junction table
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('suppliers');

includeHeader();

// Permission flags (canX() handles admin bypass internally)
$can_view   = canView('suppliers');
$can_create = canCreate('suppliers');
$can_edit   = canEdit('suppliers');
$can_delete = canDelete('suppliers');


// Get supplier ID
$supplier_id = $_GET['id'] ?? '';
if (empty($supplier_id)) {
    header("Location: suppliers.php?error=Supplier ID required");
    exit();
}
// Multi-project scope gate: visible if global, or primary project in scope,
// or at least one supplier_projects entry in scope
if (empty($_SESSION['scope']['is_admin'])) {
    $sp_ids = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    $ids_sql = empty($sp_ids) ? '0' : implode(',', $sp_ids);
    $gate = $pdo->prepare("
        SELECT 1 FROM suppliers s
        WHERE s.supplier_id = ?
          AND (
              (s.project_id IS NULL AND NOT EXISTS (SELECT 1 FROM supplier_projects WHERE supplier_id = s.supplier_id))
              OR s.project_id IN ($ids_sql)
              OR EXISTS (SELECT 1 FROM supplier_projects WHERE supplier_id = s.supplier_id AND project_id IN ($ids_sql))
          )
        LIMIT 1
    ");
    $gate->execute([intval($supplier_id)]);
    if (!$gate->fetchColumn()) {
        if (!headers_sent()) http_response_code(403);
        die('Access denied: this supplier is not in your project scope.');
    }
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

// Received invoices count for this supplier
$ri_stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_invoices WHERE supplier_id = ? AND status != 'deleted'");
$ri_stmt->execute([$supplier_id]);
$received_invoices_count = (int)$ri_stmt->fetchColumn();

// Fetch all projects this supplier is involved in. This is the UNION of:
//  (a) the projects assigned via the supplier_projects junction, AND
//  (b) the PRIMARY project chosen at creation (suppliers.project_id) — which is
//      not in the junction, so it used to be missing from "Projects Involved".
$proj_stmt = $pdo->prepare("
    SELECT p.project_id, p.project_name, p.status, p.contract_sum,
           sp.assigned_at,
           CONCAT(u.first_name, ' ', u.last_name) AS assigned_by_name,
           u.user_role AS assigned_by_role,
           0 AS is_primary
    FROM supplier_projects sp
    JOIN projects p ON sp.project_id = p.project_id
    LEFT JOIN users u ON sp.assigned_by = u.user_id
    WHERE sp.supplier_id = ?
    UNION
    SELECT p.project_id, p.project_name, p.status, p.contract_sum,
           s.created_at AS assigned_at,
           CONCAT(cu.first_name, ' ', cu.last_name) AS assigned_by_name,
           cu.user_role AS assigned_by_role,
           1 AS is_primary
    FROM suppliers s
    JOIN projects p ON p.project_id = s.project_id
    LEFT JOIN users cu ON cu.user_id = s.created_by
    WHERE s.supplier_id = ?
      AND s.project_id IS NOT NULL
      AND s.project_id NOT IN (SELECT project_id FROM supplier_projects WHERE supplier_id = ?)
    ORDER BY is_primary DESC, assigned_at DESC
");
$proj_stmt->execute([$supplier_id, $supplier_id, $supplier_id]);
$supplier_projects = $proj_stmt->fetchAll(PDO::FETCH_ASSOC);
$total_supplier_projects = count($supplier_projects);

// Active projects for assign modal — admins see all; non-admins see only their assigned projects
if (isAdmin()) {
    $all_projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $assigned = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    if (empty($assigned)) {
        $all_projects = [];
    } else {
        $ph = implode(',', array_fill(0, count($assigned), '?'));
        $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($ph) ORDER BY project_name");
        $pstmt->execute($assigned);
        $all_projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

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
                    <?php if (canCreate('received_invoices')): ?>
                    <button onclick="openRiModal()" class="btn btn-outline-success btn-sm px-2 shadow-sm" title="Record Bill">
                        <i class="bi bi-inbox me-1"></i> Record Invoice
                    </button>
                    <?php endif; ?>
                    <?php if ($can_edit): ?>
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
                            <?php if (canCreate('received_invoices')): ?>
                            <li>
                                <button class="dropdown-item py-2" onclick="openRiModal()">
                                    <i class="bi bi-inbox text-success"></i> Record Invoice
                                </button>
                            </li>
                            <?php endif; ?>
                            <?php if ($can_edit): ?>
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
                        <?php if (!empty($supplier['ward'])): ?>
                        <tr>
                            <td><strong>Ward:</strong></td>
                            <td><?= htmlspecialchars($supplier['ward']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['village'])): ?>
                        <tr>
                            <td><strong>Street/Village:</strong></td>
                            <td><?= htmlspecialchars($supplier['village']) ?></td>
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

    <!-- Section Tab Navigation -->
    <div class="row mb-3">
        <div class="col-12">
            <ul class="nav nav-pills flex-wrap gap-2" id="supplierSectionTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pane-payments" type="button" role="tab">
                        <i class="bi bi-cash-coin me-1"></i> Recent Payments
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-invoices" type="button" role="tab">
                        <i class="bi bi-receipt me-1"></i> Bills
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-pos" type="button" role="tab">
                        <i class="bi bi-cart me-1"></i> Recent Purchase Orders
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-projects" type="button" role="tab">
                        <i class="bi bi-diagram-3 me-1"></i> Projects Involved
                    </button>
                </li>
            </ul>
        </div>
    </div>

    <div class="tab-content" id="supplierSectionTabContent">

        <!-- Projects Involved -->
        <div class="tab-pane fade" id="pane-projects" role="tabpanel">
            <div class="row mt-2 mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex align-items-center">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-diagram-3 text-primary me-2"></i> Projects Involved <span class="badge bg-primary ms-1"><?= $total_supplier_projects ?></span></h6>
                            <?php if ($can_edit): ?>
                            <button class="btn btn-sm btn-primary shadow-sm ms-auto" onclick="openAssignProjectModal()" title="Assign to a project">
                                <i class="bi bi-plus-circle me-1"></i> Assign Project
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered mb-0" id="supplierProjectsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width:50px">S/No</th>
                                            <th>Project Name</th>
                                            <th>Contract Value</th>
                                            <th>Assigned On</th>
                                            <th>Assigned By</th>
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($supplier_projects as $i => $proj): ?>
                                        <tr>
                                            <td class="text-muted"><?= $i + 1 ?></td>
                                            <td>
                                                <a href="<?= getUrl('project_view') ?>?id=<?= $proj['project_id'] ?>&supplier_id=<?= $supplier_id ?>" class="fw-bold text-decoration-none">
                                                    <?= htmlspecialchars($proj['project_name']) ?>
                                                </a>
                                                <?php if (!empty($proj['is_primary'])): ?>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle ms-1" title="Linked when this supplier was created">Primary</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= format_currency($proj['contract_sum'] ?? 0) ?></td>
                                            <td><?= !empty($proj['assigned_at']) ? date('d M Y', strtotime($proj['assigned_at'])) : '—' ?></td>
                                            <td>
                                                <?php $aname = trim($proj['assigned_by_name'] ?? ''); $arole = ucwords($proj['assigned_by_role'] ?? ''); ?>
                                                <?= $aname ? htmlspecialchars($aname) : '—' ?>
                                                <?php if ($arole): ?><br><small class="text-muted"><?= htmlspecialchars($arole) ?></small><?php endif; ?>
                                                <?php if (!empty($proj['is_primary'])): ?><br><small class="text-muted fst-italic">at registration</small><?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-<?= get_status_badge($proj['status']) ?>"><?= ucfirst($proj['status']) ?></span></td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="bi bi-gear-fill me-1"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                                        <li>
                                                            <a class="dropdown-item py-2 rounded" href="<?= getUrl('project_view') ?>?id=<?= $proj['project_id'] ?>&supplier_id=<?= $supplier_id ?>">
                                                                <i class="bi bi-eye text-info me-2"></i> View Project
                                                            </a>
                                                        </li>
                                                        <?php if ($can_edit && empty($proj['is_primary'])): ?>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <a class="dropdown-item py-2 rounded text-danger" href="#" onclick="removeFromProject(<?= $proj['project_id'] ?>, '<?= htmlspecialchars(addslashes($proj['project_name'])) ?>'); return false;">
                                                                <i class="bi bi-x-circle text-danger me-2"></i> Remove from Project
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
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Received Invoices -->
        <div class="tab-pane fade" id="pane-invoices" role="tabpanel">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex align-items-center">
                            <h6 class="mb-0 fw-bold text-dark">
                                <i class="bi bi-inbox text-success me-2"></i>
                                Bills
                                <span class="badge bg-success ms-1" id="ri-count-badge"><?= $received_invoices_count ?></span>
                            </h6>
                            <?php if (canCreate('received_invoices')): ?>
                            <button class="btn btn-sm btn-outline-success shadow-sm ms-auto" onclick="openRiModal()">
                                <i class="bi bi-plus-circle me-1"></i> Record Invoice
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0 w-100" id="riTable" style="width:100%">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="45">S/NO</th>
                                            <th>Invoice Ref</th>
                                            <th>Date Raised</th>
                                            <th>Date Recorded</th>
                                            <th>PO Reference</th>
                                            <th class="text-end">Amount (TZS)</th>
                                            <th>Status</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Purchase Orders -->
        <div class="tab-pane fade" id="pane-pos" role="tabpanel">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-light border-bottom">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-cart"></i> Recent Purchase Orders</h6>
                                <div class="d-flex gap-2">
                                    <?php if (canCreate('purchase_orders')): ?>
                                    <a href="<?= getUrl('purchase_order_create') ?>?supplier_id=<?= $supplier_id ?>" class="btn btn-primary btn-sm shadow-sm">
                                        <i class="bi bi-plus-circle me-1"></i> Create PO
                                    </a>
                                    <?php endif; ?>
                                    <?php if (canCreate('received_invoices')): ?>
                                    <button type="button" class="btn btn-success btn-sm shadow-sm" onclick="openRiModal()">
                                        <i class="bi bi-inbox me-1"></i> Record Invoice
                                    </button>
                                    <?php endif; ?>
                                    <a href="<?= getUrl('purchase_orders') ?>?supplier=<?= $supplier_id ?>" class="btn btn-outline-primary btn-sm shadow-sm">
                                        View All
                                    </a>
                                </div>
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
            </div>
        </div>

        <!-- Payments -->
        <div class="tab-pane fade show active" id="pane-payments" role="tabpanel">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-light border-bottom">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-cash"></i> Recent Payments</h6>
                                <div class="d-flex gap-2">
                                    <a href="<?= getUrl('suppliers/payments') ?>?id=<?= $supplier_id ?>&create=1" class="btn btn-primary btn-sm shadow-sm">
                                        <i class="bi bi-plus-circle me-1"></i> Add Payment
                                    </a>
                                    <a href="<?= getUrl('suppliers/payments') ?>?id=<?= $supplier_id ?>" class="btn btn-outline-primary btn-sm shadow-sm">
                                        View All
                                    </a>
                                </div>
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

    // Fix DataTable column widths when switching tabs
    $('#supplierSectionTabs button[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
        const target = $(e.target).attr('data-bs-target');
        if (target === '#pane-invoices' && riDt) { riDt.columns.adjust().draw(); }
        else { $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust(); }
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

const supplierId = <?= (int)$supplier_id ?>;

$('#supplierAssignProjectModal').on('shown.bs.modal', function () {
    const sel = $('#supplierAssignProjectSelect');
    if (!sel.hasClass('select2-hidden-accessible')) {
        sel.select2({
            theme: 'bootstrap-5',
            dropdownParent: $('#supplierAssignProjectModal'),
            placeholder: 'Search project by name...',
            allowClear: true,
            width: '100%'
        });
    }
    sel.val(null).trigger('change');
});

function openAssignProjectModal() {
    $('#supplierAssignProjectModal').modal('show');
}

$(document).on('submit', '#supplierAssignProjectForm', function(e) {
    e.preventDefault();
    const projectId = $('#supplierAssignProjectSelect').val();
    if (!projectId) { Swal.fire('Warning', 'Please select a project.', 'warning'); return; }
    $.post(APP_URL + '/api/assign_sc_to_project.php', {
        action: 'assign', supplier_id: supplierId, project_id: projectId, entity_type: 'supplier'
    }, function(res) {
        if (res.success) {
            $('#supplierAssignProjectModal').modal('hide');
            Swal.fire({ icon: 'success', title: 'Assigned!', text: res.message, timer: 1500, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
});

function removeFromProject(projectId, projectName) {
    Swal.fire({
        title: 'Remove from Project?',
        text: 'Remove this supplier from "' + projectName + '"?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Remove'
    }).then(r => {
        if (r.isConfirmed) {
            $.post(APP_URL + '/api/assign_sc_to_project.php', {
                action: 'unassign', supplier_id: supplierId, project_id: projectId, entity_type: 'supplier'
            }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Removed!', text: res.message, timer: 1500, showConfirmButton: false })
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

@page { margin: 10mm 8mm 16mm 8mm; }
@media print {
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
#supplierSectionTabs .nav-link {
    background: #f8f9fa;
    color: #495057;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 8px 18px;
    transition: background .15s, color .15s, border-color .15s;
}
#supplierSectionTabs .nav-link:hover:not(.active) {
    background: #e7f0ff;
    color: #0d6efd;
    border-color: #b6ccfe;
}
#supplierSectionTabs .nav-link.active {
    background: #0d6efd !important;
    color: #fff !important;
    border-color: #0d6efd !important;
}
</style>

<?php if ($can_edit): ?>
<!-- Assign to Project Modal -->
<div class="modal fade" id="supplierAssignProjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:12px;">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i> Assign Supplier to Project</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="supplierAssignProjectForm">
                <div class="modal-body p-4">
                    <label class="form-label fw-bold">Select Project</label>
                    <select class="form-select select2-static" id="supplierAssignProjectSelect" required>
                        <option value="">-- Choose a project --</option>
                        <?php foreach ($all_projects as $proj): ?>
                        <option value="<?= $proj['project_id'] ?>"><?= htmlspecialchars($proj['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted mt-1">Projects already assigned will be ignored.</div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Record / Edit Invoice Modal -->
<div class="modal fade" id="riModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white" id="riModalHeader">
                <h5 class="modal-title" id="riModalTitle"><i class="bi bi-inbox me-2"></i>Record Bill</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="riForm" enctype="multipart/form-data" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="invoice_type" value="supplier">
                    <input type="hidden" name="supplier_id" value="<?= (int)$supplier_id ?>">
                    <input type="hidden" name="id" id="ri-id">
                    <div id="ri-msg" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Invoice Reference No. <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="invoice_ref" id="ri-ref" placeholder="Auto-generating..." required>
                                <button type="button" class="btn btn-outline-secondary" id="ri-btn-refresh" onclick="generateRiRef()" title="Regenerate reference"><i class="bi bi-arrow-clockwise"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">PO Reference</label>
                            <select name="po_id" id="ri-po" class="form-select select2-static">
                                <option value="">— Select PO (optional) —</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Project <small class="fw-normal text-muted">(optional)</small></label>
                            <select name="project_id" id="ri-project" class="form-select select2-static">
                                <option value="">— Select Project —</option>
                            </select>
                        </div>

                        <!-- PO Summary panel (visible when PO selected) -->
                        <div class="col-12 d-none" id="ri-po-summary-wrap">
                            <div class="border rounded p-3" style="background:#f8fafc;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-primary"><i class="bi bi-clipboard-data me-1"></i>PO Summary</span>
                                    <span class="badge" id="ri-po-sum-status">—</span>
                                </div>
                                <div class="row g-2 small">
                                    <div class="col-md-3 col-6">
                                        <div class="text-muted">PO Total</div>
                                        <div class="fw-bold" id="ri-po-sum-total">—</div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="text-muted">Previously Invoiced</div>
                                        <div class="fw-bold" id="ri-po-sum-invoiced">—</div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="text-muted">Remaining Capacity</div>
                                        <div class="fw-bold text-success" id="ri-po-sum-remaining">—</div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="text-muted">After This Invoice</div>
                                        <div class="fw-bold" id="ri-po-sum-after">—</div>
                                    </div>
                                </div>
                                <div class="mt-2 small d-none" id="ri-po-sum-warning"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date Raised <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date_raised" id="ri-raised" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date Recorded <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date_recorded" id="ri-recorded" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Amount (TZS) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" id="ri-amount" min="1" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Attachment <small class="fw-normal text-muted">(PDF/Image, max 5 MB)</small></label>
                            <input type="file" class="form-control" name="attachment" id="ri-attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <small id="ri-current-file" class="text-muted d-none"></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea class="form-control" name="notes" id="ri-notes" rows="2" placeholder="Optional..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="ri-save-btn">
                        <i class="bi bi-check-circle me-1"></i> Save Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const RI_SUPPLIER_ID  = <?= (int)$supplier_id ?>;
const RI_API_URL      = '<?= buildUrl('api/received_invoices.php') ?>';
const RI_CAN_EDIT_SD  = <?= json_encode(canEdit('received_invoices')) ?>;
const RI_CAN_DEL_SD   = <?= json_encode(canDelete('received_invoices')) ?>;
function safeOutput(s) { return s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }

let riDt = null;

let _riPoSummaryCache = null;

$(document).ready(function () {
    initRiTable();
    loadReceivedInvoices();

    $('#riModal').on('shown.bs.modal', function () {
        ['#ri-po', '#ri-project'].forEach(function (id) {
            if (!$(id).hasClass('select2-hidden-accessible')) {
                $(id).select2({ theme: 'bootstrap-5', dropdownParent: $('#riModal'), placeholder: 'Select...', allowClear: true, width: '100%' });
            }
        });
        if (!$('#ri-po option[value!=""]').length) { loadRiPOs(); }
        if (!$('#ri-project option[value!=""]').length) { loadRiProjects(); }
        const isEdit = !!$('#ri-id').val();
        $('#ri-btn-refresh').toggleClass('d-none', isEdit);
        if (!isEdit) { generateRiRef(); }
    });

    $('#riModal').on('hidden.bs.modal', function () {
        $('#riForm')[0].reset();
        $('#ri-id').val('');
        $('#ri-msg, #ri-current-file').addClass('d-none').html('');
        $('#riModalHeader').removeClass('bg-warning text-dark').addClass('bg-primary text-white');
        $('#riModalTitle').html('<i class="bi bi-inbox me-2"></i>Record Bill');
        $('#ri-save-btn').removeClass('btn-warning').addClass('btn-primary')
            .html('<i class="bi bi-check-circle me-1"></i> Save Invoice');
        $('#ri-btn-refresh').removeClass('d-none');
        ['#ri-po', '#ri-project'].forEach(function (id) {
            if ($(id).hasClass('select2-hidden-accessible')) $(id).select2('destroy');
        });
        $('#ri-po').empty().append('<option value="">— Select PO (optional) —</option>');
        $('#ri-project').empty().append('<option value="">— Select Project —</option>');
        hideRiPoSummary();
    });

    // PO selection → load summary + auto-fill project
    $('#ri-po').on('change', function () { loadRiPoSummary($(this).val()); });
    // Amount typing → recalculate "After This Invoice"
    $('#ri-amount').on('input', recalcRiPoAfter);

    $('#riForm').on('submit', function (e) {
        e.preventDefault();

        // Client-side cap guard (server enforces this too — defense in depth)
        if (_riPoSummaryCache) {
            const amt   = parseFloat($('#ri-amount').val()) || 0;
            const after = parseFloat(_riPoSummaryCache.invoiced_total) + amt;
            const total = parseFloat(_riPoSummaryCache.grand_total);
            if (after > total) {
                Swal.fire({
                    icon: 'error',
                    title: 'Exceeds PO Amount',
                    html: 'This invoice (<strong>' + formatRiTZS(amt) + '</strong>) plus previous invoices ' +
                          '(<strong>' + formatRiTZS(_riPoSummaryCache.invoiced_total) + '</strong>) totals ' +
                          '<strong>' + formatRiTZS(after) + '</strong>, which is over the PO Total of ' +
                          '<strong>' + formatRiTZS(total) + '</strong>.<br><br>' +
                          'Return the invoice to the supplier so they can issue a corrected amount.',
                    confirmButtonText: 'OK'
                });
                return;
            }
        }

        const btn = $('#ri-save-btn'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        const action = $('#ri-id').val() ? 'update' : 'create';
        $.ajax({
            url: RI_API_URL + '?action=' + action, type: 'POST',
            data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false })
                        .then(() => { bootstrap.Modal.getInstance($('#riModal')[0]).hide(); loadReceivedInvoices(); });
                } else { Swal.fire({ icon: 'error', title: 'Error', text: res.message }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });
});

// ── PO Summary live panel (mirror of received_invoices.php) ──────────────
function formatRiTZS(n) {
    n = parseFloat(n) || 0;
    return 'TZS ' + n.toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function hideRiPoSummary() {
    _riPoSummaryCache = null;
    $('#ri-po-summary-wrap').addClass('d-none');
}

function loadRiPoSummary(poId) {
    if (!poId) { hideRiPoSummary(); return; }
    const editId = $('#ri-id').val() || 0;
    $.getJSON(RI_API_URL, { action: 'po_summary', po_id: poId, exclude_id: editId }, function (res) {
        if (!res.success) { hideRiPoSummary(); return; }
        _riPoSummaryCache = res.data;
        $('#ri-po-summary-wrap').removeClass('d-none');
        $('#ri-po-sum-total').text(formatRiTZS(res.data.grand_total));
        $('#ri-po-sum-invoiced').text(formatRiTZS(res.data.invoiced_total));
        $('#ri-po-sum-remaining').text(formatRiTZS(res.data.remaining))
            .toggleClass('text-success', res.data.remaining > 0)
            .toggleClass('text-danger',  res.data.remaining <= 0);
        recalcRiPoAfter();

        // Auto-fill Project from PO (per boss: "ukichagua PO, project itokee tuu")
        if (res.data.project_id) {
            const $proj = $('#ri-project');
            if ($proj.find('option[value="' + res.data.project_id + '"]').length === 0 && res.data.project_name) {
                $proj.append(new Option(res.data.project_name, res.data.project_id, true, true));
            }
            $proj.val(res.data.project_id).trigger('change.select2');
        }
    });
}

function recalcRiPoAfter() {
    const d = _riPoSummaryCache;
    if (!d) return;
    const amt   = parseFloat($('#ri-amount').val()) || 0;
    const after = parseFloat(d.invoiced_total) + amt;
    const total = parseFloat(d.grand_total);
    $('#ri-po-sum-after').text(formatRiTZS(after));

    const $st  = $('#ri-po-sum-status');
    const $war = $('#ri-po-sum-warning');
    $war.addClass('d-none').text('');

    if (after > total) {
        const over = after - total;
        $st.removeClass().addClass('badge bg-danger').text('Exceeds PO');
        $war.removeClass('d-none')
            .html('<i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>' +
                  '<strong>This invoice exceeds the PO by ' + formatRiTZS(over) + '.</strong> ' +
                  'Return it to the supplier to issue a corrected amount.');
    } else if (after === total) {
        $st.removeClass().addClass('badge bg-success').text('Fully Billed');
    } else if (after > total * 0.9) {
        $st.removeClass().addClass('badge bg-warning text-dark').text('Near Cap');
    } else {
        $st.removeClass().addClass('badge bg-primary').text('Within Capacity');
    }
}

function initRiTable() {
    riDt = $('#riTable').DataTable({
        data: [], pageLength: 10, order: [[2, 'desc']], autoWidth: false,
        columns: [
            { data: null, orderable: false, className: 'text-muted', render: (d, t, r, m) => m.row + m.settings._iDisplayStart + 1 },
            { data: 'invoice_ref', render: v => `<span class="fw-bold">${safeOutput(v)}</span>` },
            { data: 'date_raised' },
            { data: 'date_recorded' },
            { data: 'po_number', render: v => v ? `<span class="badge bg-light text-dark border">${safeOutput(v)}</span>` : '—' },
            { data: 'amount', className: 'text-end fw-bold', render: v => new Intl.NumberFormat('en-TZ', { minimumFractionDigits: 2 }).format(v) },
            { data: 'status', render: v => {
                const m = { draft:'bg-secondary', submitted:'bg-warning text-dark', approved:'bg-success', paid:'bg-dark' };
                return `<span class="badge ${m[v]||'bg-secondary'} text-uppercase">${v}</span>`;
            }},
            { data: null, orderable: false, className: 'text-end', render: (d, t, row) => riActions(row) }
        ],
        language: { emptyTable: 'No received invoices found for this supplier.' }
    });
}

function loadReceivedInvoices() {
    $.getJSON(RI_API_URL, { action: 'list', supplier_id: RI_SUPPLIER_ID }, function (res) {
        if (!res.success) return;
        riDt.clear().rows.add(res.data).draw();
        $('#ri-count-badge').text(res.data.length);
    });
}

function riActions(row) {
    let items = `<li><a class="dropdown-item py-2 rounded" href="#" onclick="riViewDetails(${row.id});return false;"><i class="bi bi-eye text-info me-2"></i> View Details</a></li>`;
    if (RI_CAN_EDIT_SD || RI_CAN_DEL_SD) items += '<li><hr class="dropdown-divider"></li>';
    if (RI_CAN_EDIT_SD) items += `<li><a class="dropdown-item py-2 rounded" href="#" onclick="riEditRow(${row.id});return false;"><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>`;
    if (RI_CAN_DEL_SD)  items += `<li><a class="dropdown-item py-2 rounded text-danger" href="#" onclick="riDeleteRow(${row.id},'${safeOutput(row.invoice_ref)}');return false;"><i class="bi bi-trash text-danger me-2"></i> Delete</a></li>`;
    return `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
}

function riViewDetails(id) {
    $.getJSON(RI_API_URL, { action: 'get', id: id }, function (res) {
        if (!res.success) { Swal.fire('Error', 'Could not load invoice.', 'error'); return; }
        const d = res.data;
        const statusColors = { draft:'bg-secondary', submitted:'bg-warning text-dark', approved:'bg-success', paid:'bg-dark' };
        const badge = `<span class="badge ${statusColors[d.status]||'bg-secondary'} text-uppercase">${safeOutput(d.status)}</span>`;
        const attachment = d.attachment
            ? `<a href="${APP_URL}/${safeOutput(d.attachment)}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-arrow-down me-1"></i> Download Attachment</a>`
            : '<span class="text-muted fst-italic">No attachment</span>';
        Swal.fire({
            title: 'Invoice — ' + safeOutput(d.invoice_ref),
            html: `
                <div class="text-start">
                    <div class="row g-2 mb-3">
                        <div class="col-6"><p class="text-muted small mb-0">Status</p>${badge}</div>
                        <div class="col-6"><p class="text-muted small mb-0">Amount (TZS)</p><strong>${new Intl.NumberFormat('en-TZ',{minimumFractionDigits:2}).format(d.amount)}</strong></div>
                        <div class="col-6"><p class="text-muted small mb-0">Date Raised</p><strong>${safeOutput(d.date_raised)||'—'}</strong></div>
                        <div class="col-6"><p class="text-muted small mb-0">Date Recorded</p><strong>${safeOutput(d.date_recorded)||'—'}</strong></div>
                        <div class="col-6"><p class="text-muted small mb-0">PO Reference</p><strong>${safeOutput(d.po_number)||'—'}</strong></div>
                        <div class="col-6"><p class="text-muted small mb-0">Project</p><strong>${safeOutput(d.project_name)||'—'}</strong></div>
                    </div>
                    ${d.notes ? `<p class="text-muted small mb-1">Notes</p><p>${safeOutput(d.notes)}</p>` : ''}
                    <p class="text-muted small mb-1">Attachment</p>${attachment}
                </div>`,
            width: 520,
            confirmButtonText: 'Close',
            confirmButtonColor: '#6c757d'
        });
    });
}

function openRiModal() {
    new bootstrap.Modal(document.getElementById('riModal')).show();
}

function generateRiRef() {
    $.getJSON(RI_API_URL, { action: 'get_next_ref' }, function (res) {
        if (res.success) $('#ri-ref').val(res.ref);
    });
}

function loadRiProjects(selectedId) {
    $.getJSON(RI_API_URL, { action: 'get_projects', supplier_id: RI_SUPPLIER_ID, type: 'supplier' }, function (res) {
        const $sel = $('#ri-project');
        $sel.empty().append('<option value="">— Select Project —</option>');
        (res.data || []).forEach(function (item) {
            $sel.append($('<option>').val(item.id).text(item.text));
        });
        if (selectedId) $sel.val(selectedId).trigger('change.select2');
    });
}

function riEditRow(id) {
    $.getJSON(RI_API_URL, { action: 'get', id: id }, function (res) {
        if (!res.success) { Swal.fire('Error', 'Could not load invoice.', 'error'); return; }
        const d = res.data;
        $('#ri-id').val(d.id);
        $('#ri-ref').val(d.invoice_ref);
        $('#ri-raised').val(d.date_raised);
        $('#ri-recorded').val(d.date_recorded);
        $('#ri-amount').val(d.amount);
        $('#ri-notes').val(d.notes);
        if (d.attachment) { $('#ri-current-file').removeClass('d-none').text('Current: ' + d.attachment.split('/').pop()); }
        loadRiPOs(d.po_id);
        loadRiProjects(d.project_id || null);
        $('#ri-btn-refresh').addClass('d-none');
        $('#riModalHeader').removeClass('bg-primary text-white').addClass('bg-warning text-dark');
        $('#riModalTitle').html('<i class="bi bi-pencil me-2"></i>Edit Bill');
        $('#ri-save-btn').removeClass('btn-primary').addClass('btn-warning')
            .html('<i class="bi bi-check-circle me-1"></i> Update Invoice');
        new bootstrap.Modal(document.getElementById('riModal')).show();
    });
}

function riDeleteRow(id, ref) {
    Swal.fire({
        title: 'Delete Invoice?', text: 'Invoice "' + ref + '" will be deleted.',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(RI_API_URL + '?action=delete', { id: id, _csrf: CSRF_TOKEN }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => loadReceivedInvoices());
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function loadRiPOs(selectedId) {
    $.getJSON(RI_API_URL, { action: 'get_pos', supplier_id: RI_SUPPLIER_ID }, function (res) {
        const $sel = $('#ri-po');
        if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
        $sel.empty().append('<option value="">— Select PO (optional) —</option>');
        (res.data || []).forEach(item => $sel.append($('<option>').val(item.id).text(item.text)));
        $sel.select2({ theme: 'bootstrap-5', dropdownParent: $('#riModal'), placeholder: 'Select PO...', allowClear: true, width: '100%' });
        if (selectedId) $sel.val(selectedId).trigger('change.select2');
    });
}
</script>

<?php
includeFooter();
ob_end_flush();
?>