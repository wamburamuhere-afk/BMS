<?php
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output (SC shares the suppliers page key)
autoEnforcePermission('suppliers');

// Include the header
includeHeader();

// Permission flags (canX() handles admin bypass internally)
$can_view   = canView('suppliers');
$can_create = canCreate('suppliers');
$can_edit   = canEdit('suppliers');
$can_delete = canDelete('suppliers');

// Get ID
$supplier_id = $_GET['id'] ?? '';
if (empty($supplier_id)) {
    header("Location: sub_contractors?error=ID required");
    exit();
}

// Get sub-contractor details
$stmt = $pdo->prepare("
    SELECT s.*,
           sc.category_name,
           p.project_name,
           p.contract_sum as project_contract_sum,
           p.status as project_status,
           u1.username as created_by_name,
           u2.username as updated_by_name
    FROM sub_contractors s
    LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
    LEFT JOIN users u1 ON s.created_by = u1.user_id
    LEFT JOIN users u2 ON s.updated_by = u2.user_id
    LEFT JOIN projects p ON s.project_id = p.project_id
    WHERE s.supplier_id = ? AND s.status != 'deleted'
");
$stmt->execute([$supplier_id]);
$sc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sc) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Sub-Contractor not found</div></div>";
    includeFooter();
    exit();
}

// Fetch categories for edit modal dropdown
$categories = $pdo->query("SELECT * FROM supplier_categories WHERE status = 'active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

// Active projects for assign modal and edit modal
$all_projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all projects this sub-contractor is assigned to (many-to-many)
$sc_projects_stmt = $pdo->prepare("
    SELECT p.project_id, p.project_name, p.status, p.contract_sum,
           scp.assigned_at,
           CONCAT(u.first_name, ' ', u.last_name) AS assigned_by_name,
           u.user_role AS assigned_by_role
    FROM sub_contractor_projects scp
    JOIN projects p ON scp.project_id = p.project_id
    LEFT JOIN users u ON scp.assigned_by = u.user_id
    WHERE scp.supplier_id = ?
    ORDER BY scp.assigned_at DESC
");
$sc_projects_stmt->execute([$supplier_id]);
$sc_projects = $sc_projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get related purchase orders from all assigned projects
$purchase_orders = [];
if (!empty($sc_projects)) {
    $proj_ids = array_column($sc_projects, 'project_id');
    $placeholders = implode(',', array_fill(0, count($proj_ids), '?'));
    $orders_stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE project_id IN ($placeholders) ORDER BY created_at DESC LIMIT 10");
    $orders_stmt->execute($proj_ids);
    $purchase_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch recent payments for this sub-contractor
$payments_stmt = $pdo->prepare("
    SELECT sp.payment_id, sp.reference_number, sp.payment_date, sp.amount,
           sp.payment_method, sp.currency, po.order_number
    FROM supplier_payments sp
    LEFT JOIN purchase_orders po ON sp.purchase_order_id = po.purchase_order_id
    WHERE sp.supplier_id = ?
    ORDER BY sp.payment_date DESC
    LIMIT 10
");
$payments_stmt->execute([$supplier_id]);
$payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Company settings (needed for print dialog JS)
$company_name = getSetting('company_name') ?: 'BJP Technologies';
$company_logo = getSetting('company_logo') ?: '';

// Statistics
$total_projects = count($sc_projects);
$contract_value = array_sum(array_column($sc_projects, 'contract_sum'));
$milestones_count = 0;
$paid_amount = 0;

?>

<div class="container-fluid mt-2 mt-md-4 px-2 px-md-4 mb-5">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('sub_contractors') ?>">Sub-Contractors</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($sc['supplier_name']) ?></li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="row mb-3 mb-md-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-start flex-nowrap gap-2">
                <div>
                    <h2 class="mb-0 fs-4 fs-md-2 fw-bold"><i class="bi bi-person-workspace text-info"></i> Sub-Contractor View</h2>
                    <p class="text-muted mb-0 small mt-1 header-desc">
                        Detailed information about <?= htmlspecialchars($sc['supplier_name']) ?> 
                        <?php if($sc['company_name']): ?> • <?= htmlspecialchars($sc['company_name']) ?><?php endif; ?>
                        • Code: <code><?= htmlspecialchars($sc['supplier_code']) ?></code>
                    </p>
                </div>
                <!-- Desktop buttons -->
                <div class="d-none d-sm-flex gap-2 ms-auto pt-2 flex-shrink-0">
                    <a href="<?= getUrl('sub_contractors') ?>" class="btn btn-secondary btn-sm px-2 shadow-sm" title="Back to list">
                        <i class="bi bi-arrow-left text-white"></i>
                    </a>
                    <button onclick="printScDetails()" class="btn btn-info btn-sm px-2 text-white shadow-sm" title="Print details">
                        <i class="bi bi-printer"></i>
                    </button>
                    <?php if ($can_edit): ?>
                    <button onclick="editSC(<?= $sc['supplier_id'] ?>)" class="btn btn-primary btn-sm px-2 shadow-sm" title="Edit sub-contractor">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <!-- Mobile dropdown -->
                <div class="d-flex d-sm-none ms-auto pt-2">
                    <div class="dropdown">
                        <button class="btn btn-primary btn-sm dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill me-1"></i> Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <?php if ($can_edit): ?>
                            <li><button class="dropdown-item py-2" onclick="editSC(<?= $sc['supplier_id'] ?>)"><i class="bi bi-pencil me-2 text-primary"></i> Edit</button></li>
                            <?php endif; ?>
                            <li><button class="dropdown-item py-2" onclick="printScDetails()"><i class="bi bi-printer me-2 text-info"></i> Print</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2" href="<?= getUrl('sub_contractors') ?>"><i class="bi bi-arrow-left me-2"></i> Back to List</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 g-3">
        <div class="col-6 col-md-3">
            <div class="card h-100 stat-card-green">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 fw-bold"><?= $total_projects ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold">Total Projects</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 stat-card-green">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 fw-bold"><?= format_currency($contract_value) ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold">Contract Value</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 stat-card-green">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 fw-bold"><?= $milestones_count ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold">Milestones</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 stat-card-green">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 fw-bold"><?= format_currency($paid_amount) ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold">Paid Amount</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column: Basic & Bank Info -->
        <div class="col-md-4">
            <!-- Basic Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-info-circle"></i> Basic Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm border-0">
                        <tr><td class="text-muted border-0">Status:</td><td class="border-0"><span class="badge bg-<?= get_status_badge($sc['status']) ?>"><?= ucfirst($sc['status']) ?></span></td></tr>
                        <tr><td class="text-muted border-0">Type:</td><td class="border-0"><?= htmlspecialchars($sc['supplier_type'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Category:</td><td class="border-0"><?= htmlspecialchars($sc['category_name'] ?? 'General') ?></td></tr>
                        <tr><td class="text-muted border-0">Year:</td><td class="border-0"><?= htmlspecialchars($sc['year'] ?? 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Projects:</td><td class="border-0"><span class="badge bg-primary"><?= $total_projects ?></span> <?= $total_projects == 1 ? 'project' : 'projects' ?></td></tr>
                        <tr><td class="text-muted border-0">TIN:</td><td class="border-0"><?= htmlspecialchars($sc['tax_id'] ?? 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">VAT:</td><td class="border-0"><?= htmlspecialchars($sc['vat_number'] ?? 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Credit Limit:</td><td class="border-0"><?= format_currency($sc['credit_limit'] ?? 0) ?></td></tr>
                    </table>
                </div>
            </div>

            <!-- Bank Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-bank"></i> Bank Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm border-0">
                        <tr><td class="text-muted border-0">Bank Name:</td><td class="border-0"><?= htmlspecialchars($sc['bank_name'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Account:</td><td class="border-0"><code><?= htmlspecialchars($sc['bank_account'] ?: 'N/A') ?></code></td></tr>
                        <tr><td class="text-muted border-0">Currency:</td><td class="border-0"><?= htmlspecialchars($sc['currency'] ?: 'TZS') ?></td></tr>
                        <tr><td class="text-muted border-0">Payment Terms:</td><td class="border-0"><?= htmlspecialchars($sc['payment_terms'] ?: 'N/A') ?></td></tr>
                    </table>
                    <?php if($sc['bank_address']): ?>
                    <p class="mb-0 small text-muted mt-2"><strong>Bank Address:</strong><br><?= nl2br(htmlspecialchars($sc['bank_address'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Middle Column: Contact & Description -->
        <div class="col-md-4">
            <!-- Contact Details -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-person-lines-fill"></i> Contact Details</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm border-0">
                        <tr><td class="text-muted border-0">Contact Person:</td><td class="border-0"><?= htmlspecialchars($sc['contact_person'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Title:</td><td class="border-0"><?= htmlspecialchars($sc['contact_title'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Email:</td><td class="border-0"><a href="mailto:<?= htmlspecialchars($sc['email']) ?>"><?= htmlspecialchars($sc['email'] ?: 'N/A') ?></a></td></tr>
                        <tr><td class="text-muted border-0">Phone:</td><td class="border-0"><?= htmlspecialchars($sc['phone'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Mobile:</td><td class="border-0"><?= htmlspecialchars($sc['mobile'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Website:</td><td class="border-0"><a href="<?= htmlspecialchars($sc['website']) ?>" target="_blank"><?= htmlspecialchars($sc['website'] ?: 'N/A') ?></a></td></tr>
                    </table>
                </div>
            </div>

            <!-- Description -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-justify-left"></i> Description & Notes</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($sc['description'] ?: 'No additional notes provided.')) ?></p>
                </div>
            </div>
        </div>

        <!-- Right Column: Address -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-geo-alt"></i> Address Information</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <p class="mb-1 text-muted small fw-bold uppercase">Physical Address</p>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($sc['address'] ?: 'N/A')) ?></p>
                    </div>
                    <div class="mb-3">
                        <p class="mb-1 text-muted small fw-bold uppercase">Postal Address</p>
                        <p class="mb-0"><?= htmlspecialchars($sc['postal_address'] ?: 'N/A') ?></p>
                    </div>
                    <hr class="opacity-10">
                    <div class="row g-2">
                        <div class="col-6"><p class="mb-0 text-muted small"><strong>District:</strong> <?= htmlspecialchars($sc['city'] ?: 'N/A') ?></p></div>
                        <div class="col-6"><p class="mb-0 text-muted small"><strong>Region:</strong> <?= htmlspecialchars($sc['state'] ?: 'N/A') ?></p></div>
                        <div class="col-6"><p class="mb-0 text-muted small"><strong>Ward:</strong> <?= htmlspecialchars($sc['ward'] ?: 'N/A') ?></p></div>
                        <div class="col-6"><p class="mb-0 text-muted small"><strong>Zip:</strong> <?= htmlspecialchars($sc['postal_code'] ?: 'N/A') ?></p></div>
                        <div class="col-12"><p class="mb-0 text-muted small"><strong>Country:</strong> <?= htmlspecialchars($sc['country'] ?: 'Tanzania') ?></p></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Projects Involved -->
    <div class="row mt-4">
        <div class="col-12 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex align-items-center">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-diagram-3 text-primary me-2"></i> Projects Involved <span class="badge bg-primary ms-1"><?= $total_projects ?></span></h6>
                    <?php if ($can_edit): ?>
                    <button class="btn btn-sm btn-primary shadow-sm ms-auto" onclick="openAssignProjectModal()" title="Assign to a project">
                        <i class="bi bi-plus-circle me-1"></i> Assign Project
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="overflow:visible">
                        <table class="table table-hover table-bordered mb-0" id="scProjectsTable">
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
                            <tbody id="scProjectsBody">
                                <?php foreach ($sc_projects as $i => $proj): ?>
                                <tr>
                                    <td class="text-muted"><?= $i + 1 ?></td>
                                    <td>
                                        <a href="<?= getUrl('project_view') ?>?id=<?= $proj['project_id'] ?>&sc_id=<?= $supplier_id ?>" class="fw-bold text-decoration-none">
                                            <?= htmlspecialchars($proj['project_name']) ?>
                                        </a>
                                    </td>
                                    <td><?= format_currency($proj['contract_sum'] ?? 0) ?></td>
                                    <td><?= $proj['assigned_at'] ? date('d M Y', strtotime($proj['assigned_at'])) : '—' ?></td>
                                    <td>
                                        <?php
                                            $name = trim($proj['assigned_by_name'] ?? '');
                                            $role = ucwords($proj['assigned_by_role'] ?? '');
                                        ?>
                                        <?= $name ? htmlspecialchars($name) : '—' ?>
                                        <?php if ($role): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($role) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?= get_status_badge($proj['status']) ?>"><?= ucfirst($proj['status']) ?></span></td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear-fill me-1"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                                <li>
                                                    <a class="dropdown-item py-2 rounded" href="<?= getUrl('project_view') ?>?id=<?= $proj['project_id'] ?>&sc_id=<?= $supplier_id ?>">
                                                        <i class="bi bi-eye text-info me-2"></i> View Project
                                                    </a>
                                                </li>
                                                <?php if ($can_edit): ?>
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

    <!-- Related Tables -->
    <div class="row mt-4">
        <!-- Recent Purchase Orders -->
        <div class="col-12 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-cart-check"></i> Recent Purchase Orders</h6>
                        <a href="<?= getUrl('purchase_orders') ?>?supplier=<?= $supplier_id ?>" class="btn btn-outline-primary btn-sm shadow-sm">View All</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="overflow:visible">
                        <table class="table table-hover table-bordered mb-0" id="scPOTable">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width:50px">S/No</th>
                                    <th>PO Number</th>
                                    <th>Date</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($purchase_orders as $i => $po): ?>
                                <tr>
                                    <td class="text-muted"><?= $i + 1 ?></td>
                                    <td><span class="fw-bold"><?= htmlspecialchars($po['order_number']) ?></span></td>
                                    <td><?= format_date($po['order_date']) ?></td>
                                    <td><?= format_currency($po['grand_total']) ?></td>
                                    <td><span class="badge bg-<?= get_status_badge($po['status']) ?>"><?= ucfirst($po['status']) ?></span></td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear-fill me-1"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                                <li>
                                                    <a class="dropdown-item py-2 rounded" href="<?= getUrl('purchase_order_details') ?>?id=<?= $po['purchase_order_id'] ?>">
                                                        <i class="bi bi-eye text-info me-2"></i> View
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item py-2 rounded text-danger" href="#" onclick="deletePO(<?= $po['purchase_order_id'] ?>, '<?= htmlspecialchars(addslashes($po['order_number'])) ?>'); return false;">
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

        <!-- Recent Payments -->
        <div class="col-12 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-cash-stack"></i> Recent Payments</h6>
                        <a href="<?= getUrl('suppliers/payments') ?>?id=<?= $supplier_id ?>" class="btn btn-outline-primary btn-sm shadow-sm">View All</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="overflow:visible">
                        <table class="table table-hover table-bordered mb-0" id="scPaymentsTable">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width:50px">S/No</th>
                                    <th>Reference No</th>
                                    <th>Payment Date</th>
                                    <th>Amount</th>
                                    <th>Currency</th>
                                    <th>Method</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($payments as $i => $pay): ?>
                                <tr>
                                    <td class="text-muted"><?= $i + 1 ?></td>
                                    <td><span class="fw-bold"><?= htmlspecialchars($pay['reference_number'] ?? '—') ?></span></td>
                                    <td><?= $pay['payment_date'] ? date('d M Y', strtotime($pay['payment_date'])) : '—' ?></td>
                                    <td><?= format_currency($pay['amount'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($pay['currency'] ?? 'TZS') ?></td>
                                    <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $pay['payment_method'] ?? '—'))) ?></td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear-fill me-1"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                                <li>
                                                    <a class="dropdown-item py-2 rounded" href="<?= getUrl('suppliers/payments') ?>?id=<?= $supplier_id ?>&payment=<?= $pay['payment_id'] ?>">
                                                        <i class="bi bi-eye text-info me-2"></i> View
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item py-2 rounded text-danger" href="#" onclick="deletePayment(<?= $pay['payment_id'] ?>, '<?= htmlspecialchars(addslashes($pay['reference_number'] ?? '')) ?>'); return false;">
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

<style>
.stat-card-green {
    background-color: #d1e7dd;
    border: 1px solid #a3cfbb;
    border-radius: 12px;
}
.stat-card-green h4 { color: #0f5132; }
.header-desc { font-size: 0.85rem; }
.card-header { border-bottom: 1px solid rgba(0,0,0,0.05); }
.uppercase { text-transform: uppercase; letter-spacing: 0.5px; }
</style>

<!-- Edit Sub-Contractor Modal -->
<div class="modal fade" id="editSCModal" tabindex="-1" aria-labelledby="editSCModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editSCModalLabel"><i class="bi bi-pencil"></i> Edit Sub-Contractor</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editSCForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="supplier_id" id="edit_sc_id">
                <div class="modal-body">
                    <div id="edit-sc-message" class="mb-3"></div>
                    <ul class="nav nav-tabs mb-3" id="editSCTabs" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-edit-basic" type="button"><i class="bi bi-info-circle me-1"></i>Basic Info</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-edit-contact" type="button"><i class="bi bi-person-lines-fill me-1"></i>Contact Details</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-edit-address" type="button"><i class="bi bi-geo-alt me-1"></i>Address</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-edit-financial" type="button"><i class="bi bi-wallet2 me-1"></i>Financial</button></li>
                    </ul>
                    <div class="tab-content">
                        <!-- Basic Info -->
                        <div class="tab-pane fade show active" id="tab-edit-basic">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Sub-Contractor Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="edit_sc_name" name="supplier_name" required></div>
                                <div class="col-6 mb-3"><label class="form-label">Company Name</label><input type="text" class="form-control" id="edit_company_name" name="company_name"></div>
                                <div class="col-6 mb-3"><label class="form-label">Acronym</label><input type="text" class="form-control" id="edit_acronym" name="acronym"></div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Company Logo</label>
                                    <input type="file" class="form-control" id="edit_logo" name="logo" accept="image/*">
                                    <div id="current_logo_display" class="mt-2"></div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Sub-Contractor Type</label>
                                    <select class="form-select" id="edit_sc_type" name="supplier_type">
                                        <option value="">Select Type</option>
                                        <option value="Manufacturer">Manufacturer</option>
                                        <option value="Distributor">Distributor</option>
                                        <option value="Wholesaler">Wholesaler</option>
                                        <option value="Retailer">Retailer</option>
                                        <option value="Service Provider">Service Provider</option>
                                        <option value="Contractor">Contractor</option>
                                        <option value="Consultant">Consultant</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Year <span class="text-danger">*</span></label>
                                    <select class="form-select" id="edit_sc_year" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php $cy = date('Y'); for ($y = $cy; $y >= $cy - 10; $y--) echo "<option value='$y'>$y</option>"; ?>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_sc_year_other_wrap" class="mt-2" style="display:none;"><input type="text" class="form-control" id="edit_sc_year_other" name="year_other" placeholder="Enter year"></div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" id="edit_category_id" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $cat): ?><option value="<?= $cat['category_id'] ?>"><?= safe_output($cat['category_name']) ?></option><?php endforeach; ?>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_category_id_other_wrap" class="mt-2" style="display:none;"><input type="text" class="form-control" id="edit_category_id_other" name="category_other" placeholder="Enter category"></div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="edit_status" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Linked Project (Optional)</label>
                                    <select class="form-select select2-static" id="edit_project_id" name="project_id">
                                        <option value="">-- No linked project --</option>
                                        <?php foreach ($all_projects as $proj): ?>
                                        <option value="<?= $proj['project_id'] ?>"><?= htmlspecialchars($proj['project_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 mb-3"><label class="form-label">Credit Limit</label><input type="number" class="form-control" id="edit_credit_limit" name="credit_limit" step="0.01"></div>
                                <div class="col-12 mb-3"><label class="form-label">Description</label><textarea class="form-control" id="edit_description" name="description" rows="2"></textarea></div>
                            </div>
                        </div>
                        <!-- Contact Details -->
                        <div class="tab-pane fade" id="tab-edit-contact">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Contact Person</label><input type="text" class="form-control" id="edit_contact_person" name="contact_person"></div>
                                <div class="col-6 mb-3"><label class="form-label">Contact Title</label><input type="text" class="form-control" id="edit_contact_title" name="contact_title"></div>
                                <div class="col-6 mb-3"><label class="form-label">Contact Email</label><input type="email" class="form-control" id="edit_email" name="email"></div>
                                <div class="col-6 mb-3"><label class="form-label">Company Email</label><input type="email" class="form-control" id="edit_company_email" name="company_email"></div>
                                <div class="col-6 mb-3"><label class="form-label">Phone Number</label><input type="text" class="form-control" id="edit_phone" name="phone"></div>
                                <div class="col-6 mb-3"><label class="form-label">Mobile Number</label><input type="text" class="form-control" id="edit_mobile" name="mobile"></div>
                                <div class="col-6 mb-3"><label class="form-label">Fax Number</label><input type="text" class="form-control" id="edit_fax" name="fax"></div>
                                <div class="col-md-12 mb-3"><label class="form-label">Website</label><input type="url" class="form-control" id="edit_website" name="website"></div>
                            </div>
                        </div>
                        <!-- Address -->
                        <div class="tab-pane fade" id="tab-edit-address">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Country</label><input type="text" class="form-control" id="edit_country" name="country"></div>
                                <div class="col-6 mb-3"><label class="form-label">Region</label><input type="text" class="form-control" id="edit_state" name="state"></div>
                                <div class="col-6 mb-3"><label class="form-label">District</label><input type="text" class="form-control" id="edit_city" name="city"></div>
                                <div class="col-6 mb-3"><label class="form-label">Council</label><input type="text" class="form-control" id="edit_council" name="council"></div>
                                <div class="col-6 mb-3"><label class="form-label">Ward</label><input type="text" class="form-control" id="edit_ward" name="ward"></div>
                                <div class="col-6 mb-3"><label class="form-label">Zip Code</label><input type="text" class="form-control" id="edit_postal_code" name="postal_code"></div>
                                <div class="col-12 mb-3"><label class="form-label">Physical Address</label><textarea class="form-control" id="edit_address" name="address" rows="2"></textarea></div>
                                <div class="col-12 mb-3"><label class="form-label">Postal Address</label><input type="text" class="form-control" id="edit_postal_address" name="postal_address"></div>
                            </div>
                        </div>
                        <!-- Financial -->
                        <div class="tab-pane fade" id="tab-edit-financial">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Tax ID (TIN)</label><input type="text" class="form-control" id="edit_tax_id" name="tax_id"></div>
                                <div class="col-6 mb-3"><label class="form-label">VAT Number</label><input type="text" class="form-control" id="edit_vat_number" name="vat_number"></div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <select class="form-select" id="edit_payment_terms" name="payment_terms">
                                        <option value="">Select...</option>
                                        <option value="Cash">Cash</option>
                                        <option value="Net 15">Net 15</option>
                                        <option value="Net 30">Net 30</option>
                                        <option value="Net 60">Net 60</option>
                                        <option value="Due on Receipt">Due on Receipt</option>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_payment_terms_other_wrap" class="mt-2" style="display:none;"><input type="text" class="form-control" id="edit_payment_terms_other" name="payment_terms_other" placeholder="Enter terms"></div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Currency</label>
                                    <select class="form-select" id="edit_currency" name="currency">
                                        <option value="">Select...</option>
                                        <option value="TZS">TZS</option>
                                        <option value="USD">USD</option>
                                        <option value="KES">KES</option>
                                        <option value="EUR">EUR</option>
                                        <option value="GBP">GBP</option>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_currency_other_wrap" class="mt-2" style="display:none;"><input type="text" class="form-control" id="edit_currency_other" name="currency_other" placeholder="Enter currency"></div>
                                </div>
                                <div class="col-6 mb-3"><label class="form-label">Bank Name</label><input type="text" class="form-control" id="edit_bank_name" name="bank_name"></div>
                                <div class="col-6 mb-3"><label class="form-label">Bank Account</label><input type="text" class="form-control" id="edit_bank_account" name="bank_account"></div>
                                <div class="col-12 mb-3"><label class="form-label">Bank Address</label><textarea class="form-control" id="edit_bank_address" name="bank_address" rows="2"></textarea></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update Sub-Contractor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editSC(id) {
    $.getJSON(APP_URL + '/api/get_sub_contractor.php', { id: id }, function(res) {
        if (res.success) {
            const d = res.data;
            $('#edit_sc_id').val(d.supplier_id);
            $('#edit_sc_name').val(d.supplier_name);
            $('#edit_company_name').val(d.company_name);
            $('#edit_acronym').val(d.acronym);
            $('#edit_sc_type').val(d.supplier_type);
            $('#edit_sc_year').val(d.year);
            $('#edit_category_id').val(d.category_id);
            $('#edit_status').val(d.status);
            $('#edit_project_id').val(d.project_id || '').trigger('change');
            $('#edit_credit_limit').val(d.credit_limit);
            $('#edit_description').val(d.description);
            $('#edit_contact_person').val(d.contact_person);
            $('#edit_contact_title').val(d.contact_title);
            $('#edit_email').val(d.email);
            $('#edit_company_email').val(d.company_email);
            $('#edit_phone').val(d.phone);
            $('#edit_mobile').val(d.mobile);
            $('#edit_fax').val(d.fax);
            $('#edit_website').val(d.website);
            $('#edit_country').val(d.country);
            $('#edit_state').val(d.state);
            $('#edit_city').val(d.city);
            $('#edit_council').val(d.council);
            $('#edit_ward').val(d.ward);
            $('#edit_postal_code').val(d.postal_code);
            $('#edit_address').val(d.address);
            $('#edit_postal_address').val(d.postal_address);
            $('#edit_tax_id').val(d.tax_id);
            $('#edit_vat_number').val(d.vat_number);
            $('#edit_payment_terms').val(d.payment_terms);
            $('#edit_currency').val(d.currency);
            $('#edit_bank_name').val(d.bank_name);
            $('#edit_bank_account').val(d.bank_account);
            $('#edit_bank_address').val(d.bank_address);
            if (d.logo_path) {
                $('#current_logo_display').html('<img src="' + d.logo_path + '" style="max-height:50px;" class="img-thumbnail">');
            } else {
                $('#current_logo_display').empty();
            }
            $('#editSCModal').modal('show');
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    });
}

$('#editSCForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    $.ajax({
        url: APP_URL + '/api/update_sub_contractor.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                Swal.fire('Updated', res.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
});

function printScDetails() {
    <?php
    $logo_url  = !empty($company_logo) ? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($company_logo, '/')) : '';
    $printed_by = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) . ' — ' . ucwords($_SESSION['user_role'] ?? 'Staff');
    ?>
    const companyName = <?= json_encode($company_name) ?>;
    const logoHtml    = <?= json_encode(!empty($logo_url) ? '<img src="'.$logo_url.'" style="max-height:70px;width:auto;display:block;margin:0 auto 8px;">' : '') ?>;
    const printedBy   = <?= json_encode($printed_by) ?>;

    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head>
        <meta charset="UTF-8">
        <title>Sub-Contractor — <?= htmlspecialchars($sc['supplier_name']) ?></title>
        <style>
            * { box-sizing:border-box; margin:0; padding:0; }
            body { background:#fff; font-family:Arial,sans-serif; padding:28px 32px; font-size:12.5px; color:#222; }

            /* Header */
            .prt-header { text-align:center; border-bottom:2px solid #0d6efd; padding-bottom:14px; margin-bottom:18px; }
            .prt-header .co-name { font-size:20px; font-weight:800; color:#0d6efd; text-transform:uppercase; }
            .prt-header .doc-title { font-size:15px; font-weight:700; margin:4px 0 2px; }
            .prt-header .sc-name { font-size:13px; color:#555; }
            .prt-header .gen-date { font-size:10.5px; color:#999; margin-top:3px; }

            /* Stat row */
            .stat-row { width:100%; border-collapse:collapse; margin-bottom:18px; }
            .stat-row td { width:25%; text-align:center; padding:10px 6px; background:#d1e7dd; border:1px solid #a3cfbb; border-radius:8px; }
            .stat-row td h4 { color:#0f5132; font-size:16px; font-weight:800; margin-bottom:2px; }
            .stat-row td p { font-size:10px; text-transform:uppercase; color:#555; font-weight:600; }

            /* 3-column info using table layout */
            .info-row { width:100%; border-collapse:separate; border-spacing:10px 0; margin-bottom:18px; }
            .info-row td { width:33.33%; vertical-align:top; border:1px solid #e0e0e0; border-radius:8px; padding:0; }
            .info-col-head { background:#f5f7ff; border-bottom:1px solid #e0e0e0; padding:7px 12px; font-weight:700; color:#0d6efd; font-size:11.5px; border-radius:8px 8px 0 0; }
            .info-col-body { padding:6px 12px; }
            .info-col-body table { width:100%; border-collapse:collapse; }
            .info-col-body table td { padding:5px 4px; border-bottom:1px solid #f5f5f5; vertical-align:top; font-size:12px; }
            .info-col-body table td:first-child { color:#888; width:42%; white-space:nowrap; }

            /* Section title */
            .sec-title { font-size:10.5px; text-transform:uppercase; color:#888; font-weight:700;
                         border-bottom:1px solid #eee; padding-bottom:4px; margin:0 0 8px; }

            /* Tables */
            .prt-table { width:100%; border-collapse:collapse; margin-bottom:18px; font-size:12px; }
            .prt-table th { background:#f8f9fa; border-bottom:2px solid #dee2e6; padding:7px 10px; text-align:left; font-size:11.5px; }
            .prt-table td { padding:7px 10px; border-bottom:1px solid #f0f0f0; vertical-align:top; }

            /* Badges */
            .badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:10.5px; font-weight:600; }
            .bg-success  { background:#198754; color:#fff; }
            .bg-secondary{ background:#6c757d; color:#fff; }
            .bg-warning  { background:#ffc107; color:#000; }
            .bg-danger   { background:#dc3545; color:#fff; }
            .bg-primary  { background:#0d6efd; color:#fff; }
            .bg-info     { background:#0dcaf0; color:#000; }

            /* Footer */
            .prt-footer { border-top:1px solid #eee; padding-top:8px; text-align:center;
                          font-size:10px; color:#888; margin-top:24px; }
            .prt-footer strong { color:#0d6efd; }

            @page { margin:16mm; }
        </style>
    </head><body>

        <div class="prt-header">
            \${logoHtml}
            <div class="co-name">\${companyName}</div>
            <div class="doc-title">SUB-CONTRACTOR PROFILE</div>
            <div class="sc-name"><strong><?= htmlspecialchars($sc['supplier_name']) ?></strong><?= $sc['supplier_code'] ? ' &bull; ' . htmlspecialchars($sc['supplier_code']) : '' ?></div>
            <div class="gen-date">Generated: <?= date('d M Y, H:i') ?></div>
        </div>

        <table class="stat-row">
            <tr>
                <td><h4><?= $total_projects ?></h4><p>Total Projects</p></td>
                <td><h4><?= format_currency($contract_value) ?></h4><p>Contract Value</p></td>
                <td><h4><?= $milestones_count ?></h4><p>Milestones</p></td>
                <td><h4><?= format_currency($paid_amount) ?></h4><p>Paid Amount</p></td>
            </tr>
        </table>

        <table class="info-row">
            <tr>
                <td>
                    <div class="info-col-head">Basic Information</div>
                    <div class="info-col-body"><table>
                        <tr><td>Status</td><td><span class="badge bg-<?= get_status_badge($sc['status']) ?>"><?= ucfirst($sc['status']) ?></span></td></tr>
                        <tr><td>Type</td><td><?= htmlspecialchars($sc['supplier_type'] ?: 'N/A') ?></td></tr>
                        <tr><td>Category</td><td><?= htmlspecialchars($sc['category_name'] ?? 'General') ?></td></tr>
                        <tr><td>Year</td><td><?= htmlspecialchars($sc['year'] ?? 'N/A') ?></td></tr>
                        <tr><td>Projects</td><td><?= $total_projects ?></td></tr>
                        <tr><td>TIN</td><td><?= htmlspecialchars($sc['tax_id'] ?? 'N/A') ?></td></tr>
                        <tr><td>VAT</td><td><?= htmlspecialchars($sc['vat_number'] ?? 'N/A') ?></td></tr>
                        <tr><td>Credit Limit</td><td><?= format_currency($sc['credit_limit'] ?? 0) ?></td></tr>
                    </table></div>
                </td>
                <td>
                    <div class="info-col-head">Contact &amp; Bank</div>
                    <div class="info-col-body"><table>
                        <tr><td>Contact</td><td><?= htmlspecialchars($sc['contact_person'] ?: 'N/A') ?></td></tr>
                        <tr><td>Title</td><td><?= htmlspecialchars($sc['contact_title'] ?: 'N/A') ?></td></tr>
                        <tr><td>Email</td><td><?= htmlspecialchars($sc['email'] ?: 'N/A') ?></td></tr>
                        <tr><td>Phone</td><td><?= htmlspecialchars($sc['phone'] ?: 'N/A') ?></td></tr>
                        <tr><td>Mobile</td><td><?= htmlspecialchars($sc['mobile'] ?: 'N/A') ?></td></tr>
                        <tr><td>Bank</td><td><?= htmlspecialchars($sc['bank_name'] ?: 'N/A') ?></td></tr>
                        <tr><td>Account</td><td><?= htmlspecialchars($sc['bank_account'] ?: 'N/A') ?></td></tr>
                        <tr><td>Currency</td><td><?= htmlspecialchars($sc['currency'] ?: 'TZS') ?></td></tr>
                    </table></div>
                </td>
                <td>
                    <div class="info-col-head">Address</div>
                    <div class="info-col-body"><table>
                        <tr><td>Physical</td><td><?= nl2br(htmlspecialchars($sc['address'] ?: 'N/A')) ?></td></tr>
                        <tr><td>Postal</td><td><?= htmlspecialchars($sc['postal_address'] ?: 'N/A') ?></td></tr>
                        <tr><td>District</td><td><?= htmlspecialchars($sc['city'] ?: 'N/A') ?></td></tr>
                        <tr><td>Region</td><td><?= htmlspecialchars($sc['state'] ?: 'N/A') ?></td></tr>
                        <tr><td>Ward</td><td><?= htmlspecialchars($sc['ward'] ?: 'N/A') ?></td></tr>
                        <tr><td>Country</td><td><?= htmlspecialchars($sc['country'] ?: 'Tanzania') ?></td></tr>
                    </table></div>
                </td>
            </tr>
        </table>

        <div class="sec-title">Projects Involved (<?= $total_projects ?>)</div>
        <table class="prt-table">
            <thead><tr><th>Project Name</th><th>Status</th><th>Contract Value</th><th>Assigned On</th></tr></thead>
            <tbody>
                <?php if (empty($sc_projects)): ?>
                <tr><td colspan="4" style="text-align:center;color:#888;">Not assigned to any project.</td></tr>
                <?php else: foreach ($sc_projects as $proj): ?>
                <tr>
                    <td><?= htmlspecialchars($proj['project_name']) ?></td>
                    <td><span class="badge bg-<?= get_status_badge($proj['status']) ?>"><?= ucfirst($proj['status']) ?></span></td>
                    <td><?= format_currency($proj['contract_sum'] ?? 0) ?></td>
                    <td><?= $proj['assigned_at'] ? date('d M Y', strtotime($proj['assigned_at'])) : '—' ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if (!empty($purchase_orders)): ?>
        <div class="sec-title" style="margin-top:16px;">Recent Purchase Orders</div>
        <table class="prt-table">
            <thead><tr><th>PO Number</th><th>Date</th><th>Total Amount</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($purchase_orders as $po): ?>
                <tr>
                    <td><?= htmlspecialchars($po['order_number']) ?></td>
                    <td><?= format_date($po['order_date']) ?></td>
                    <td><?= format_currency($po['grand_total']) ?></td>
                    <td><span class="badge bg-<?= get_status_badge($po['status']) ?>"><?= ucfirst($po['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="prt-footer">
            Printed by <strong>\${printedBy}</strong> on <?= date('d M Y \a\t H:i') ?><br>
            <strong style="color:#0d6efd;">Powered by BJP Technologies &copy; <?= date('Y') ?></strong>
        </div>
        <script>window.onload=function(){ window.print(); window.onafterprint=function(){ window.close(); }; }<\/script>
    </body></html>`);
    win.document.close();
}

const scId = <?= (int)$supplier_id ?>;

$('#editSCModal').on('shown.bs.modal', function () {
    const sel = $('#edit_project_id');
    if (!sel.hasClass('select2-hidden-accessible')) {
        sel.select2({ theme: 'bootstrap-5', dropdownParent: $('#editSCModal'), placeholder: 'Search project...', allowClear: true, width: '100%' });
    }
});

$('#assignProjectModal').on('shown.bs.modal', function () {
    const sel = $('#assignProjectSelect');
    if (!sel.hasClass('select2-hidden-accessible')) {
        sel.select2({ theme: 'bootstrap-5', dropdownParent: $('#assignProjectModal'), placeholder: 'Search project...', allowClear: true, width: '100%' });
    }
    sel.val(null).trigger('change');
});

function openAssignProjectModal() {
    $('#assignProjectModal').modal('show');
}

// Use event delegation so the binding works even though modal HTML is after this script
$(document).on('submit', '#assignProjectForm', function(e) {
    e.preventDefault();
    const projectId = $('#assignProjectSelect').val();
    if (!projectId) { Swal.fire('Warning', 'Please select a project.', 'warning'); return; }
    $.post(APP_URL + '/api/assign_sc_to_project.php', {
        action: 'assign', supplier_id: scId, project_id: projectId
    }, function(res) {
        if (res.success) {
            $('#assignProjectModal').modal('hide');
            Swal.fire({ icon: 'success', title: 'Assigned!', text: res.message, timer: 1500, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
});

$(document).ready(function () {
    $('#scProjectsTable').DataTable({
        pageLength: 10,
        responsive: false,
        order: [[0, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, -1] }],
        language: { emptyTable: 'Not assigned to any project yet.', zeroRecords: 'No matching projects found.' }
    });
    $('#scPOTable').DataTable({
        pageLength: 10,
        responsive: false,
        order: [[0, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, -1] }],
        language: { emptyTable: 'No recent purchase orders found.', zeroRecords: 'No matching purchase orders found.' }
    });
    $('#scPaymentsTable').DataTable({
        pageLength: 10,
        responsive: false,
        order: [[0, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, -1] }],
        language: { emptyTable: 'No recent payment history found.', zeroRecords: 'No matching payments found.' }
    });
});

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

function removeFromProject(projectId, projectName) {
    Swal.fire({
        title: 'Remove from Project?',
        text: 'Remove this sub-contractor from "' + projectName + '"?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Remove'
    }).then(r => {
        if (r.isConfirmed) {
            $.post(APP_URL + '/api/assign_sc_to_project.php', {
                action: 'unassign', supplier_id: scId, project_id: projectId
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
</script>

<!-- Assign to Project Modal -->
<div class="modal fade" id="assignProjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:12px;">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i> Assign to Project</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="assignProjectForm">
                <div class="modal-body p-4">
                    <label class="form-label fw-bold">Select Project</label>
                    <select class="form-select select2-static" id="assignProjectSelect" required>
                        <option value="">-- Choose a project --</option>
                        <?php foreach ($all_projects as $proj): ?>
                        <option value="<?= $proj['project_id'] ?>"><?= htmlspecialchars($proj['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted mt-1">Projects already assigned will be ignored (no duplicate).</div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
includeFooter();
?>
