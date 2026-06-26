<?php
// scope-audit: skip — multi-project scope gate below checks sub_contractor_projects junction table
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';

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

// Multi-project scope gate
if (empty($_SESSION['scope']['is_admin'])) {
    $sp_ids = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    $ids_sql = empty($sp_ids) ? '0' : implode(',', $sp_ids);
    $gate = $pdo->prepare("
        SELECT 1 FROM sub_contractors s
        WHERE s.supplier_id = ?
          AND (
              (s.project_id IS NULL AND NOT EXISTS (SELECT 1 FROM sub_contractor_projects WHERE supplier_id = s.supplier_id))
              OR s.project_id IN ($ids_sql)
              OR EXISTS (SELECT 1 FROM sub_contractor_projects WHERE supplier_id = s.supplier_id AND project_id IN ($ids_sql))
          )
        LIMIT 1
    ");
    $gate->execute([intval($supplier_id)]);
    if (!$gate->fetchColumn()) {
        if (!headers_sent()) http_response_code(403);
        die('Access denied: this sub-contractor is not in your project scope.');
    }
}

// Context — where did the user come from?
$from_project    = (($_GET['from'] ?? '') === 'project');
$back_project_id = intval($_GET['project_id'] ?? 0);
$back_project    = null;
if ($from_project && $back_project_id > 0) {
    $bp = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE project_id = ?");
    $bp->execute([$back_project_id]);
    $back_project = $bp->fetch(PDO::FETCH_ASSOC);
}
$back_url = $from_project && $back_project
    ? getUrl('project_view') . '?id=' . $back_project_id . '&tab=sub-contractors'
    : getUrl('sub_contractors');

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

// Projects for assign modal and edit modal — admins see all; non-admins see only assigned
if (!empty($_SESSION['scope']['is_admin'])) {
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

// Fetch all projects this sub-contractor is involved in: the UNION of the
// sub_contractor_projects junction AND the PRIMARY project chosen at creation
// (sub_contractors.project_id), which is otherwise missing from "Projects Involved".
$sc_projects_stmt = $pdo->prepare("
    SELECT p.project_id, p.project_name, p.status, p.contract_sum,
           scp.assigned_at,
           CONCAT(u.first_name, ' ', u.last_name) AS assigned_by_name,
           u.user_role AS assigned_by_role,
           0 AS is_primary
    FROM sub_contractor_projects scp
    JOIN projects p ON scp.project_id = p.project_id
    LEFT JOIN users u ON scp.assigned_by = u.user_id
    WHERE scp.supplier_id = ?
    UNION
    SELECT p.project_id, p.project_name, p.status, p.contract_sum,
           s.created_at AS assigned_at,
           CONCAT(cu.first_name, ' ', cu.last_name) AS assigned_by_name,
           cu.user_role AS assigned_by_role,
           1 AS is_primary
    FROM sub_contractors s
    JOIN projects p ON p.project_id = s.project_id
    LEFT JOIN users cu ON cu.user_id = s.created_by
    WHERE s.supplier_id = ?
      AND s.project_id IS NOT NULL
      AND s.project_id NOT IN (SELECT project_id FROM sub_contractor_projects WHERE supplier_id = ?)
    ORDER BY is_primary DESC, assigned_at DESC
");
$sc_projects_stmt->execute([$supplier_id, $supplier_id, $supplier_id]);
$sc_projects = $sc_projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// Cash/bank (Paid From) source accounts — same canonical list every payment form uses.
$bank_accounts = cashBankAccounts($pdo);

// Count milestones across assigned projects
$milestones_count = 0;
if (!empty($sc_projects)) {
    $proj_ids     = array_column($sc_projects, 'project_id');
    $placeholders = implode(',', array_fill(0, count($proj_ids), '?'));
    $mil_stmt = $pdo->prepare("SELECT COUNT(*) FROM project_milestones WHERE project_id IN ($placeholders)");
    $mil_stmt->execute($proj_ids);
    $milestones_count = (int)$mil_stmt->fetchColumn();
}

// Fetch recent SC payments for this sub-contractor
$payments_stmt = $pdo->prepare("
    SELECT sp.id AS payment_id, sp.reference_number, sp.payment_date, sp.amount,
           sp.payment_method, sp.currency, sp.receipt_number
    FROM sc_payments sp
    WHERE sp.supplier_id = ? AND sp.status != 'deleted'
    ORDER BY sp.payment_date DESC
    LIMIT 10
");
$payments_stmt->execute([$supplier_id]);
$payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Total paid amount from sc_payments
$paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM sc_payments WHERE supplier_id = ? AND status != 'deleted'");
$paid_stmt->execute([$supplier_id]);
$paid_amount = (float)$paid_stmt->fetchColumn();

// Received invoices count
$ri_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_invoices WHERE supplier_id = ? AND status != 'deleted'");
$ri_count_stmt->execute([$supplier_id]);
$received_invoices_count = (int)$ri_count_stmt->fetchColumn();

// Company settings (needed for print dialog JS)
$company_name = getSetting('company_name') ?: 'BJP Technologies';
$company_logo = getSetting('company_logo') ?: '';

// Statistics
$total_projects = count($sc_projects);
$contract_value = array_sum(array_column($sc_projects, 'contract_sum'));

?>

<div class="container-fluid mt-2 mt-md-4 px-2 px-md-4 mb-5">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <?php if ($from_project && $back_project): ?>
            <li class="breadcrumb-item"><a href="<?= getUrl('project_view') ?>?id=<?= $back_project_id ?>">Projects</a></li>
            <li class="breadcrumb-item"><a href="<?= $back_url ?>"><?= htmlspecialchars($back_project['project_name']) ?></a></li>
            <li class="breadcrumb-item">Sub-Contractors</li>
            <?php else: ?>
            <li class="breadcrumb-item"><a href="<?= getUrl('sub_contractors') ?>">Sub-Contractors</a></li>
            <?php endif; ?>
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
                    <a href="<?= $back_url ?>" class="btn btn-secondary btn-sm px-2 shadow-sm" title="<?= $from_project ? 'Back to Project' : 'Back to list' ?>">
                        <i class="bi bi-arrow-left text-white"></i>
                    </a>
                    <button onclick="printScDetails()" class="btn btn-info btn-sm px-2 text-white shadow-sm" title="Print details">
                        <i class="bi bi-printer"></i>
                    </button>
                    <?php if ($can_create): ?>
                    <button onclick="openRiScModal()" class="btn btn-primary btn-sm px-2 shadow-sm" title="Record received invoice">
                        <i class="bi bi-receipt me-1"></i><span class="d-none d-lg-inline">Record Invoice</span>
                    </button>
                    <?php endif; ?>
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
                            <?php if ($can_create): ?>
                            <li><button class="dropdown-item py-2" onclick="openRiScModal()"><i class="bi bi-receipt me-2 text-primary"></i> Record Invoice</button></li>
                            <?php endif; ?>
                            <?php if ($can_edit): ?>
                            <li><button class="dropdown-item py-2" onclick="editSC(<?= $sc['supplier_id'] ?>)"><i class="bi bi-pencil me-2 text-primary"></i> Edit</button></li>
                            <?php endif; ?>
                            <li><button class="dropdown-item py-2" onclick="printScDetails()"><i class="bi bi-printer me-2 text-info"></i> Print</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2" href="<?= $back_url ?>"><i class="bi bi-arrow-left me-2"></i> <?= $from_project ? 'Back to Project' : 'Back to List' ?></a></li>
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

    <!-- Section Tab Buttons -->
    <div class="row mt-4">
        <div class="col-12 mb-3">
            <div class="d-flex gap-2 flex-wrap">
                <button id="btn-tab-projects" class="btn btn-primary btn-sm sc-tab-btn" onclick="switchScTab('projects')">
                    <i class="bi bi-diagram-3 me-1"></i> Projects Involved
                    <span class="badge bg-light text-dark border ms-1"><?= $total_projects ?></span>
                </button>
                <button id="btn-tab-invoices" class="btn btn-outline-primary btn-sm sc-tab-btn" onclick="switchScTab('invoices')">
                    <i class="bi bi-receipt me-1"></i> Bills
                    <span class="badge bg-light text-dark border ms-1" id="ri-sc-badge"><?= $received_invoices_count ?></span>
                </button>
                <button id="btn-tab-payments" class="btn btn-outline-primary btn-sm sc-tab-btn" onclick="switchScTab('payments')">
                    <i class="bi bi-cash-stack me-1"></i> Recent Payments
                    <span class="badge bg-light text-dark border ms-1"><?= count($payments) ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Projects Involved Pane -->
    <div id="pane-projects" class="sc-tab-pane">
        <div class="row">
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
                        <div id="scProjectsTableView">
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
                                                <?php if (!empty($proj['is_primary'])): ?>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle ms-1" title="Linked when this sub-contractor was created">Primary</span>
                                                <?php endif; ?>
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
                                                            <a class="dropdown-item py-2 rounded" href="<?= getUrl('project_view') ?>?id=<?= $proj['project_id'] ?>&sc_id=<?= $supplier_id ?>">
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
                        <div id="scProjectsCardView" class="p-2 d-none"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Received Invoices Pane -->
    <div id="pane-invoices" class="sc-tab-pane d-none">
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 d-flex align-items-center">
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="bi bi-receipt text-primary me-2"></i>
                            Bills
                        </h6>
                        <?php if ($can_create): ?>
                        <button class="btn btn-sm btn-primary shadow-sm ms-auto" onclick="openRiScModal()" title="Record a received invoice">
                            <i class="bi bi-plus-circle me-1"></i> Record Invoice
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <div id="scRiTableView">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered mb-0" id="scRiTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width:50px">S/No</th>
                                            <th>Invoice Ref</th>
                                            <th>Date Raised</th>
                                            <th>Date Recorded</th>
                                            <th>Project</th>
                                            <th>Basis</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="scRiTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                        <div id="scRiCardView" class="p-2 d-none"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Payments Pane -->
    <div id="pane-payments" class="sc-tab-pane d-none">
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-cash-stack"></i> Recent Payments</h6>
                            <?php if ($can_create): ?>
                            <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#scRecordPaymentModal">
                                <i class="bi bi-plus-circle me-1"></i> Record Payment
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="scPaymentsTableView">
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
                        <div id="scPaymentsCardView" class="p-2 d-none"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Record / Edit Received Invoice Modal (SC) -->
<?php if ($can_create || $can_edit): ?>
<div class="modal fade" id="riScModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-receipt me-1"></i> <span id="riScModalTitle">Record Bill</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="riScForm" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action"       id="riScAction"   value="create">
                <input type="hidden" name="id"            id="riScId"       value="">
                <input type="hidden" name="invoice_type" value="sub_contractor">
                <input type="hidden" name="supplier_id"  value="<?= (int)$supplier_id ?>">
                <div class="modal-body">
                    <div id="risc-message" class="mb-2"></div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Invoice Reference <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="invoice_ref" id="risc_invoice_ref" placeholder="Auto-generating..." required>
                                <button type="button" class="btn btn-outline-secondary" id="riScBtnRefresh" onclick="generateRiScRef()" title="Regenerate reference"><i class="bi bi-arrow-clockwise"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Project <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="project_id" id="risc_project_id" required>
                                <option value="">-- Select Project --</option>
                                <?php foreach ($sc_projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>"><?= safe_output($proj['project_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Invoice Basis <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="sc_invoice_basis" id="risc_basis" required>
                                <option value="">-- Select Basis --</option>
                                <option value="IPC">IPC (Interim Payment Certificate)</option>
                                <option value="Milestone">Milestone</option>
                                <option value="Scope">Scope Completion</option>
                                <option value="Final">Final Invoice</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Basis Reference</label>
                            <input type="text" class="form-control" name="sc_basis_ref" id="risc_basis_ref" placeholder="e.g. IPC-03, Milestone-2">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Date Raised <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date_raised" id="risc_date_raised" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Date Recorded <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date_recorded" id="risc_date_recorded" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" id="risc_amount" step="0.01" min="0" placeholder="0.00" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Attachment</label>
                            <input type="file" class="form-control" name="attachment" id="risc_attachment" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx">
                            <div id="risc_current_attachment" class="mt-1 small text-muted"></div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="risc_notes" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="riScSaveBtn"><i class="bi bi-check-circle me-1"></i> Save Invoice</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Record Sub-Contractor Payment Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="scRecordPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i> Record Payment — <?= safe_output($sc['supplier_name'] ?? 'Sub-Contractor') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="scRpMsg" class="mb-2"></div>
                <input type="hidden" id="scRpSupplierId" value="<?= (int)$supplier_id ?>">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold small">Project <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" id="scRpProject">
                            <option value="">-- Select Project --</option>
                            <?php foreach ($sc_projects as $proj): ?>
                            <option value="<?= (int)$proj['project_id'] ?>"><?= safe_output($proj['project_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($sc_projects)): ?>
                        <small class="text-danger">This sub-contractor is not assigned to any project yet. Assign a project before recording a payment.</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="scRpDate" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="scRpAmount" step="0.01" min="0.01" placeholder="0.00">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Currency</label>
                        <input type="text" class="form-control" id="scRpCurrency" value="<?= htmlspecialchars($sc['currency'] ?: 'TZS') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="scRpMethod">
                            <option value="">Select...</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Paid From <span class="text-danger">*</span></label>
                        <select class="form-select" id="scRpAccount">
                            <option value="">Select account…</option>
                            <?php foreach ($bank_accounts as $acc): ?>
                            <option value="<?= (int)$acc['account_id'] ?>"><?= safe_output($acc['account_name'] . ($acc['account_code'] ? ' (' . $acc['account_code'] . ')' : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Cash/bank account the money is paid from.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Reference Number</label>
                        <input type="text" class="form-control" id="scRpRef" placeholder="e.g. bank ref, cheque no...">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Receipt Number <span class="text-muted small fw-normal">(from SC)</span></label>
                        <input type="text" class="form-control" id="scRpReceipt" placeholder="Receipt no. provided by SC">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Notes</label>
                        <textarea class="form-control" id="scRpNotes" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary fw-bold px-4" id="scRpSaveBtn" onclick="saveScRecordPayment()">
                    <i class="bi bi-check-circle me-1"></i> Save Payment
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
@page { margin: 10mm 8mm 16mm 8mm; }
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
function safeOutput(str) {
    if (str === null || str === undefined || str === false) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
        return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m];
    });
}

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
        language: { emptyTable: 'Not assigned to any project yet.', zeroRecords: 'No matching projects found.' },
        drawCallback: function () {
            renderProjectCards(this.api().rows({ page: 'current' }).data().toArray());
        }
    });
    $('#scPaymentsTable').DataTable({
        pageLength: 10,
        responsive: false,
        order: [[0, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, -1] }],
        language: { emptyTable: 'No recent payment history found.', zeroRecords: 'No matching payments found.' },
        drawCallback: function () {
            renderPaymentCards(this.api().rows({ page: 'current' }).data().toArray());
        }
    });
    initRiScTable();
    applyProjectsView();
    $(window).on('resize', function () {
        const activeTab = document.querySelector('.sc-tab-btn.btn-primary')?.id?.replace('btn-tab-', '');
        if (activeTab === 'projects') applyProjectsView();
        else if (activeTab === 'invoices') applyRiScView();
        else if (activeTab === 'payments') applyPaymentsView();
    });
});


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
            // These rows are sub-contractor payments (sc_payments) — use the SC
            // endpoint so the cash/bank outflow is reversed and the balance restored.
            $.post(APP_URL + '/api/sc/delete_payment.php', { id: paymentId }, function(res) {
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

// Record a payment for THIS sub-contractor (sc_payments + consolidated outflow).
function saveScRecordPayment() {
    const supplierId = $('#scRpSupplierId').val();
    const projectId  = $('#scRpProject').val();
    const amount     = parseFloat($('#scRpAmount').val());
    const method     = $('#scRpMethod').val();
    const account    = $('#scRpAccount').val();
    const $msg = $('#scRpMsg');

    if (!projectId) { $msg.html('<div class="alert alert-warning py-2 small mb-0">Please select the project this payment is for.</div>'); return; }
    if (!amount || amount <= 0) { $msg.html('<div class="alert alert-warning py-2 small mb-0">Please enter a valid amount.</div>'); return; }
    if (!method) { $msg.html('<div class="alert alert-warning py-2 small mb-0">Please select a payment method.</div>'); return; }
    if (!account) { $msg.html('<div class="alert alert-warning py-2 small mb-0">Please choose the account the payment was made from (Paid From).</div>'); return; }

    const $btn = $('#scRpSaveBtn');
    const orig = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    $.post(APP_URL + '/api/sc/add_payment.php', {
        supplier_id: supplierId,
        project_id: projectId,
        payment_date: $('#scRpDate').val(),
        amount: amount,
        currency: $('#scRpCurrency').val(),
        payment_method: method,
        paid_from_account_id: account,
        reference_number: $('#scRpRef').val(),
        receipt_number: $('#scRpReceipt').val(),
        notes: $('#scRpNotes').val()
    }, function(res) {
        if (res.success) {
            $('#scRecordPaymentModal').modal('hide');
            Swal.fire({ icon: 'success', title: 'Payment Recorded', timer: 1500, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            $msg.html('<div class="alert alert-danger py-2 small mb-0">' + (res.message || 'Failed to record payment.') + '</div>');
        }
    }, 'json').fail(function() {
        $msg.html('<div class="alert alert-danger py-2 small mb-0">Server error. Please try again.</div>');
    }).always(function() {
        $btn.prop('disabled', false).html(orig);
    });
}

// ─── Received Invoices (Sub-Contractor) ───────────────────────────
const RI_SC_ID      = <?= (int)$supplier_id ?>;
const RI_SC_API_URL = '<?= buildUrl('api/received_invoices.php') ?>';
const RI_CAN_EDIT   = <?= json_encode($can_edit) ?>;
const RI_CAN_DEL    = <?= json_encode($can_delete) ?>;

let riScTable = null;

function fmtDateSc(d) {
    if (!d) return '—';
    const parts = d.split('-');
    if (parts.length < 3) return d;
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return parts[2] + ' ' + (months[parseInt(parts[1]) - 1] || parts[1]) + ' ' + parts[0];
}

function initRiScTable() {
    if (riScTable) { riScTable.destroy(); riScTable = null; }
    riScTable = $('#scRiTable').DataTable({
        responsive: false,
        pageLength: 10,
        order: [[3, 'desc']],
        columnDefs: [{ orderable: false, targets: [0, -1] }],
        language: { emptyTable: 'No received invoices recorded yet.', zeroRecords: 'No matching invoices.' },
        drawCallback: function () {
            renderRiScCards(this.api().rows({ page: 'current' }).data().toArray());
        }
    });
    loadRiSc();
}

function loadRiSc() {
    $.getJSON(RI_SC_API_URL, { action: 'list', supplier_id: RI_SC_ID }, function (res) {
        riScTable.clear();
        const rows = (res.success && res.data) ? res.data : [];
        rows.forEach(function (row, i) {
            const basis = safeOutput(row.sc_invoice_basis || '—')
                + (row.sc_basis_ref ? ' <small class="text-muted">(' + safeOutput(row.sc_basis_ref) + ')</small>' : '');
            const amt = row.amount
                ? parseFloat(row.amount).toLocaleString('en-TZ', { minimumFractionDigits: 2 })
                : '0.00';
            const statusBadge = '<span class="badge bg-' + riScStatusBadge(row.status) + '">' + safeOutput(row.status) + '</span>';
            riScTable.row.add([
                i + 1,
                safeOutput(row.invoice_ref),
                fmtDateSc(row.date_raised),
                fmtDateSc(row.date_recorded),
                safeOutput(row.project_name || '—'),
                basis,
                amt,
                statusBadge,
                riScActions(row)
            ]);
        });
        $('#ri-sc-badge').text(rows.length);
        riScTable.draw();
    }).fail(function (xhr) {
        console.error('loadRiSc failed — HTTP ' + xhr.status + ': ' + xhr.responseText);
        Swal.fire({ icon: 'error', title: 'Could not load invoices', text: 'HTTP ' + xhr.status + '. Check console for details.' });
    });
}

function riScStatusBadge(status) {
    const map = { draft: 'secondary', submitted: 'primary', approved: 'success', paid: 'success', deleted: 'danger' };
    return map[status] || 'secondary';
}

function riScActions(row) {
    let items = '';
    if (row.attachment) {
        items += `<li><button class="dropdown-item py-2 rounded" onclick="riScViewAttachment('${safeOutput(row.attachment)}')"><i class="bi bi-paperclip text-info me-2"></i> View Attachment</button></li>`;
    }
    if (RI_CAN_EDIT) {
        items += `<li><button class="dropdown-item py-2 rounded" onclick="riScEditRow(${row.id})"><i class="bi bi-pencil text-primary me-2"></i> Edit</button></li>`;
    }
    if (RI_CAN_DEL) {
        items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" onclick="riScDeleteRow(${row.id}, '${safeOutput(row.invoice_ref)}')"><i class="bi bi-trash text-danger me-2"></i> Delete</button></li>`;
    }
    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-gear-fill me-1"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul>
    </div>`;
}

function riScViewAttachment(path) {
    window.open(APP_URL + '/' + path, '_blank');
}

function generateRiScRef() {
    $.getJSON(RI_SC_API_URL, { action: 'get_next_ref' }, function (res) {
        if (res.success) $('#risc_invoice_ref').val(res.ref);
    });
}

function openRiScModal(isEdit) {
    if (!isEdit) {
        $('#riScAction').val('create');
        $('#riScId').val('');
        $('#riScModalTitle').text('Record Bill');
        $('#riScSaveBtn').html('<i class="bi bi-check-circle me-1"></i> Save Invoice');
        document.getElementById('riScForm').reset();
        $('#risc_date_recorded').val('<?= date('Y-m-d') ?>');
        $('#risc_current_attachment').empty();
        $('#riScBtnRefresh').removeClass('d-none');
        generateRiScRef();
    } else {
        $('#riScBtnRefresh').addClass('d-none');
    }
    $('#riScModal').modal('show');
}

function riScEditRow(id) {
    $.getJSON(RI_SC_API_URL, { action: 'get', id: id }, function (res) {
        if (!res.success) { Swal.fire('Error', res.message || 'Could not load record.', 'error'); return; }
        const d = res.data;
        $('#riScAction').val('update');
        $('#riScId').val(d.id);
        $('#riScModalTitle').text('Edit Bill');
        $('#riScSaveBtn').html('<i class="bi bi-check-circle me-1"></i> Update Invoice');
        $('#risc_invoice_ref').val(d.invoice_ref);
        $('#risc_project_id').val(d.project_id).trigger('change');
        $('#risc_basis').val(d.sc_invoice_basis || '').trigger('change');
        $('#risc_basis_ref').val(d.sc_basis_ref || '');
        $('#risc_date_raised').val(d.date_raised);
        $('#risc_date_recorded').val(d.date_recorded);
        $('#risc_amount').val(d.amount);
        $('#risc_notes').val(d.notes || '');
        if (d.attachment) {
            const fname = d.attachment.split('/').pop();
            $('#risc_current_attachment').html('<i class="bi bi-paperclip me-1"></i>Current: <a href="' + APP_URL + '/' + d.attachment + '" target="_blank">' + fname + '</a>');
        } else {
            $('#risc_current_attachment').empty();
        }
        openRiScModal(true);
    });
}

function riScDeleteRow(id, ref) {
    Swal.fire({
        title: 'Delete Invoice?',
        text: 'Delete invoice "' + ref + '"? This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(RI_SC_API_URL, {
            action: 'delete', id: id,
            _csrf: $('input[name="_csrf"]').first().val()
        }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1500, showConfirmButton: false });
                loadRiSc();
            } else {
                Swal.fire('Error', res.message || 'Could not delete.', 'error');
            }
        }, 'json');
    });
}

$('#riScForm').on('submit', function (e) {
    e.preventDefault();
    const btn  = $('#riScSaveBtn');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    $.ajax({
        url: RI_SC_API_URL,
        type: 'POST',
        data: new FormData(this),
        contentType: false,
        processData: false,
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                $('#riScModal').modal('hide');
                loadRiSc();
                Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
            }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
});

$('#riScModal').on('shown.bs.modal', function () {
    $('#risc_project_id, #risc_basis').each(function () {
        if (!$(this).hasClass('select2-hidden-accessible')) {
            $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#riScModal'), placeholder: 'Select...', allowClear: true, width: '100%' });
        }
    });
}).on('hidden.bs.modal', function () {
    $('#risc-message').html('');
    $('#risc_project_id, #risc_basis').each(function () {
        if ($(this).hasClass('select2-hidden-accessible')) { $(this).select2('destroy'); }
    });
});

function renderRiScCards(rows) {
    const $cv = $('#scRiCardView');
    if (!rows.length) {
        $cv.html('<div class="text-center py-4 text-muted small">No invoices recorded yet.</div>');
        return;
    }
    let html = '';
    rows.forEach(row => {
        const ref    = row[1];
        const raised = row[2];
        const proj   = row[4];
        const basis  = row[5];
        const amt    = row[6];
        const status = row[7];
        const acts   = row[8];
        html += `
        <div class="card border-0 shadow-sm mb-2">
            <div class="card-body p-3" style="font-size:0.8rem;">
                <div class="fw-bold">${ref}</div>
                <div class="text-muted">${proj}</div>
                <div class="text-muted">${basis}</div>
                <div class="mt-1 d-flex justify-content-between">
                    <span>Raised: ${raised}</span>
                    <span class="ms-2">${status}</span>
                </div>
                <div class="mt-1 fw-bold">Amount: ${amt}</div>
            </div>
            <div class="card-footer bg-white border-top p-0">
                <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${acts}</div>
            </div>
        </div>`;
    });
    $cv.html(html);
}

function applyRiScView() {
    if (window.innerWidth < 768) {
        $('#scRiTableView').addClass('d-none');
        $('#scRiCardView').removeClass('d-none');
    } else {
        $('#scRiTableView').removeClass('d-none');
        $('#scRiCardView').addClass('d-none');
    }
}

function switchScTab(tab) {
    document.querySelectorAll('.sc-tab-pane').forEach(p => p.classList.add('d-none'));
    document.getElementById('pane-' + tab).classList.remove('d-none');
    document.querySelectorAll('.sc-tab-btn').forEach(b => {
        b.classList.remove('btn-primary');
        b.classList.add('btn-outline-primary');
    });
    const btn = document.getElementById('btn-tab-' + tab);
    btn.classList.remove('btn-outline-primary');
    btn.classList.add('btn-primary');
    if (tab === 'projects') {
        applyProjectsView();
        if ($.fn.DataTable.isDataTable('#scProjectsTable')) $('#scProjectsTable').DataTable().columns.adjust();
    } else if (tab === 'invoices') {
        applyRiScView();
        if (riScTable) riScTable.columns.adjust();
    } else if (tab === 'payments') {
        applyPaymentsView();
        if ($.fn.DataTable.isDataTable('#scPaymentsTable')) $('#scPaymentsTable').DataTable().columns.adjust();
    }
}

function applyProjectsView() {
    if (window.innerWidth < 768) {
        $('#scProjectsTableView').addClass('d-none');
        $('#scProjectsCardView').removeClass('d-none');
    } else {
        $('#scProjectsTableView').removeClass('d-none');
        $('#scProjectsCardView').addClass('d-none');
    }
}

function renderProjectCards(rows) {
    const $cv = $('#scProjectsCardView');
    if (!rows || !rows.length) {
        $cv.html('<div class="text-center py-4 text-muted small">Not assigned to any project.</div>');
        return;
    }
    let html = '';
    rows.forEach(row => {
        html += `
        <div class="card border-0 shadow-sm mb-2">
            <div class="card-body p-3" style="font-size:0.8rem;">
                <div class="fw-bold">${row[1]}</div>
                <div class="text-muted">Contract: ${row[2]}</div>
                <div class="d-flex justify-content-between mt-1">
                    <span>Assigned: ${row[3]}</span>
                    <span>${row[5]}</span>
                </div>
            </div>
            <div class="card-footer bg-white border-top p-0">
                <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${row[6]}</div>
            </div>
        </div>`;
    });
    $cv.html(html);
}

function applyPaymentsView() {
    if (window.innerWidth < 768) {
        $('#scPaymentsTableView').addClass('d-none');
        $('#scPaymentsCardView').removeClass('d-none');
    } else {
        $('#scPaymentsTableView').removeClass('d-none');
        $('#scPaymentsCardView').addClass('d-none');
    }
}

function renderPaymentCards(rows) {
    const $cv = $('#scPaymentsCardView');
    if (!rows || !rows.length) {
        $cv.html('<div class="text-center py-4 text-muted small">No payment history found.</div>');
        return;
    }
    let html = '';
    rows.forEach(row => {
        html += `
        <div class="card border-0 shadow-sm mb-2">
            <div class="card-body p-3" style="font-size:0.8rem;">
                <div class="fw-bold">${row[1]}</div>
                <div class="text-muted">${row[5]}</div>
                <div class="d-flex justify-content-between mt-1">
                    <span>Date: ${row[2]}</span>
                    <span>${row[4]}</span>
                </div>
                <div class="mt-1 fw-bold">Amount: ${row[3]}</div>
            </div>
            <div class="card-footer bg-white border-top p-0">
                <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${row[6]}</div>
            </div>
        </div>`;
    });
    $cv.html(html);
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
