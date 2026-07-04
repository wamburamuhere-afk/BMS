<?php
// scope-audit: skip — multi-project scope enforced below via sub_contractor_projects junction table
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user permissions dynamically (Mirroring Suppliers permission logic)
$can_view_sc = canView('suppliers');
$can_create_sc = canCreate('suppliers');
$can_edit_sc = canEdit('suppliers');
$can_delete_sc = canDelete('suppliers');

if (!$can_view_sc) {
    header("Location: unauthorized");
    exit();
}

// Get company type for conditional features
$company_type = getSetting('company_type') ?: 'microfinance';

// Display name from global header
$display_company_name = $GLOBALS['DISPLAY_COMPANY_NAME'];

// Fetch company logo and name for printing
$company_logo = getSetting('company_logo');
$company_name = getSetting('company_name') ?: $display_company_name;

// Multi-project scope filter for sub-contractors
if (!empty($_SESSION['scope']['is_admin'])) {
    $sc_scope_sql = '';
} else {
    $sp_ids = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    if (empty($sp_ids)) {
        $sc_scope_sql = ' AND 0 ';
    } else {
        $ids = implode(',', $sp_ids);
        $sc_scope_sql = " AND (
            (s.project_id IS NULL AND NOT EXISTS (SELECT 1 FROM sub_contractor_projects x WHERE x.supplier_id = s.supplier_id))
            OR s.project_id IN ($ids)
            OR EXISTS (SELECT 1 FROM sub_contractor_projects x WHERE x.supplier_id = s.supplier_id AND x.project_id IN ($ids))
        ) ";
    }
}

// Fetch sub-contractors with project count from junction table
$query = "
    SELECT
        s.*,
        sc.category_name,
        pp.project_name AS primary_project_name,
        u1.username as created_by_name,
        u2.username as updated_by_name,
        COUNT(scp.project_id) as project_count
    FROM sub_contractors s
    LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
    LEFT JOIN projects pp ON pp.project_id = s.project_id
    LEFT JOIN users u1 ON s.created_by = u1.user_id
    LEFT JOIN users u2 ON s.updated_by = u2.user_id
    LEFT JOIN sub_contractor_projects scp ON s.supplier_id = scp.supplier_id
    WHERE s.status != 'deleted' $sc_scope_sql
    GROUP BY s.supplier_id
    ORDER BY s.supplier_name ASC
";
$stmt = $pdo->query($query);
$sub_contractors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Project context (clean deep-link from Project Details) ───────────────────
// Arriving as ?project=<id>&back=<tab> pre-selects & locks the project, shows a
// "Back to Project" affordance, and returns there after save — mirroring the
// Purchase Order create/edit pattern. The return URL is rebuilt server-side from
// the project id + short tab key, so the link in the address bar stays clean
// (no full URL embedded in the query string, and no project name to pass).
$proj_ctx_id     = isset($_GET['project']) ? intval($_GET['project']) : 0;
$proj_ctx_back   = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['back'] ?? ''));
$proj_ctx_name   = '';
$proj_ctx_return = '';
if ($proj_ctx_id > 0) {
    $pcs = $pdo->prepare("SELECT project_name FROM projects WHERE project_id = ?");
    $pcs->execute([$proj_ctx_id]);
    $proj_ctx_name   = $pcs->fetchColumn() ?: '';
    $proj_ctx_tab    = $proj_ctx_back !== '' ? $proj_ctx_back : 'sub-contractors';
    $proj_ctx_return = getUrl('project_view') . '?id=' . $proj_ctx_id . '&tab=' . $proj_ctx_tab;
}

// Calculate statistics
$total_sc = count($sub_contractors);
$active_sc = array_filter($sub_contractors, function($sc) {
    return $sc['status'] == 'active';
});
$suspended_sc = array_filter($sub_contractors, function($sc) {
    return $sc['status'] == 'suspended';
});
$blacklisted_sc = array_filter($sub_contractors, function($sc) {
    return $sc['status'] == 'blacklisted';
});

// Get categories
$categories = $pdo->query("SELECT * FROM supplier_categories WHERE status = 'active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

// Self-growing dropdown catalogues (Other → type new → saved). Type is its own
// list; payment_terms + currency are shared with suppliers.
require_once __DIR__ . '/../../../core/form_lookups.php';
$lk_sc_type       = formLookupOptions($pdo, 'sub_contractor_type');
$lk_payment_terms = formLookupOptions($pdo, 'payment_terms');
$lk_currency      = formLookupOptions($pdo, 'currency');
$lk_categories    = array_map(fn($c) => ['value' => (string)$c['category_id'], 'label' => $c['category_name']], $categories);
$lk_years = [];
for ($y = (int)date('Y') + 1; $y >= 1950; $y--) { $lk_years[] = ['value' => (string)$y, 'label' => (string)$y]; }
// Active WHT rates for the sub-contractor "Default WHT" picker (auto-fills on their invoice payments).
$sc_wht_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE tax_kind = 'wht' AND status = 'active' ORDER BY rate_percentage")->fetchAll(PDO::FETCH_ASSOC);

// Projects for linking — admins see all; non-admins see only their assigned projects
if (!empty($_SESSION['scope']['is_admin'])) {
    $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $assigned = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    if (empty($assigned)) {
        $projects = [];
    } else {
        $ph = implode(',', array_fill(0, count($assigned), '?'));
        $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($ph) ORDER BY project_name");
        $pstmt->execute($assigned);
        $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Standardized Print Header -->
    <div class="bms-print-header d-none d-print-block">
        <h2 class="bph-title">Official Sub-Contractors Report</h2>
        <p class="bph-sub">Generated on: <?= date('d M Y, H:i') ?></p>
        <div class="bph-bar"></div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="flex-grow-1">
                    <h2 class="mb-0 fs-4 fs-md-3 fw-bold text-nowrap"><i class="bi bi-person-workspace text-info"></i> Sub-Contractor Management</h2>
                    <p class="text-muted mb-0 d-none d-md-block small mt-1">Manage your sub-contractors and field service providers</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($can_create_sc): ?>
                    <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm text-nowrap" style="border-radius: 6px;" data-bs-toggle="modal" data-bs-target="#addSubContractorModal">
                        <i class="bi bi-plus-circle me-1"></i> Add Sub-Contractor
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4" id="print-stats-cards">
        <div class="col-6 col-lg-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap" style="font-size: 0.65rem;">Total</p>
                            <h4 class="mb-0 fw-bold"><?= $total_sc ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap" style="font-size: 0.65rem;">Active</p>
                            <h4 class="mb-0 fw-bold"><?= count($active_sc) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap" style="font-size: 0.65rem;">Suspended</p>
                            <h4 class="mb-0 fw-bold"><?= count($suspended_sc) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-x-circle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap" style="font-size: 0.65rem;">Blacklisted</p>
                            <h4 class="mb-0 fw-bold"><?= count($blacklisted_sc) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-funnel"></i> Filters & Search</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label for="statusFilter" class="form-label small fw-bold">Status</label>
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                                <option value="blacklisted">Blacklisted</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="categoryFilter" class="form-label small fw-bold">Category</label>
                            <select class="form-select select2-static" id="categoryFilter">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= safe_output($category['category_name']) ?>"><?= safe_output($category['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="countryFilter" class="form-label small fw-bold">Country</label>
                            <input type="text" class="form-control" id="countryFilter" placeholder="Filter by country">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="cityFilter" class="form-label small fw-bold">City</label>
                            <input type="text" class="form-control" id="cityFilter" placeholder="Filter by city">
                        </div>
                        <div class="col-md-12 d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">Clear</button>
                            <button type="button" class="btn btn-primary btn-sm" onclick="applyFilters()">Apply</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Section -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div class="d-flex gap-2 d-print-none">
                        <button class="btn btn-outline-info btn-sm" onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
                        <button class="btn btn-outline-success btn-sm" onclick="exportSC()"><i class="bi bi-file-earmark-spreadsheet"></i> Export</button>
                    </div>
                    <div class="btn-group shadow-sm d-none d-md-flex" role="group">
                        <button type="button" class="btn btn-primary btn-sm border" onclick="toggleSCView('table')" id="sc-btn-table" title="Table View"><i class="bi bi-table"></i></button>
                        <button type="button" class="btn btn-light btn-sm border" onclick="toggleSCView('card')" id="sc-btn-card" title="Card View"><i class="bi bi-grid"></i></button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="tableView" class="table-responsive">
                        <table id="scTable" class="table table-striped table-hover mb-0">
                            <thead>
                                <tr>
                                    <th class="text-center">S/NO</th>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Contact Info</th>
                                    <th>Address</th>
                                    <th>Category</th>
                                    <th>Projects</th>
                                    <th>Status</th>
                                    <th class="d-print-none text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sn = 1; foreach ($sub_contractors as $sc): ?>
                                <tr>
                                    <td class="text-center"><?= $sn++ ?></td>
                                    <td><span class="custom-code"><?= safe_output($sc['supplier_code']) ?></span></td>
                                    <td><strong><?= safe_output($sc['supplier_name']) ?></strong></td>
                                    <td>
                                        <div class="small">
                                            <?= safe_output($sc['contact_person'] ?? '') ?><br>
                                            <i class="bi bi-telephone"></i> <?= safe_output($sc['phone'] ?? '') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <?= safe_output(substr($sc['address'] ?? '', 0, 30)) ?>...<br>
                                            <strong><?= safe_output($sc['city'] ?? '') ?></strong>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= safe_output($sc['category_name'] ?? 'General') ?></span></td>
                                    <td>
                                        <?php if (!empty($sc['primary_project_name'])): ?>
                                        <a href="<?= getUrl('sub_contractors/view') ?>?id=<?= $sc['supplier_id'] ?>" class="badge bg-primary text-white text-decoration-none">
                                            <i class="bi bi-briefcase me-1"></i><?= safe_output($sc['primary_project_name']) ?><?php if ($sc['project_count'] > 0): ?> +<?= (int)$sc['project_count'] ?><?php endif; ?>
                                        </a>
                                        <?php elseif ($sc['project_count'] > 0): ?>
                                        <a href="<?= getUrl('sub_contractors/view') ?>?id=<?= $sc['supplier_id'] ?>" class="badge bg-primary text-white text-decoration-none">
                                            <?= (int)$sc['project_count'] ?> <?= $sc['project_count'] == 1 ? 'project' : 'projects' ?>
                                        </a>
                                        <?php else: ?>
                                        <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?= get_status_badge($sc['status']) ?>"><?= ucfirst($sc['status']) ?></span></td>
                                    <td class="d-print-none text-center">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear-fill"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                                <!-- View Options -->
                                                <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('sub_contractors/view') ?>?id=<?= $sc['supplier_id'] ?>"><i class="bi bi-eye text-info me-2"></i> View Details</a></li>
                                                <?php if ($can_edit_sc): ?>
                                                <li><a class="dropdown-item py-2 rounded" href="#" onclick="editSC(<?= $sc['supplier_id'] ?>)"><i class="bi bi-pencil text-primary me-2"></i> Edit Sub-Contractor</a></li>
                                                <?php endif; ?>
                                                <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('purchase_orders') ?>?supplier=<?= $sc['supplier_id'] ?>"><i class="bi bi-cart text-success me-2"></i> View Orders</a></li>
                                                <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('suppliers/payments') ?>?id=<?= $sc['supplier_id'] ?>"><i class="bi bi-cash-stack text-warning me-2"></i> View Payments</a></li>
                                                <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('vendor_statement') ?>?vendor_id=<?= $sc['supplier_id'] ?>&vendor_type=sub_contractor"><i class="bi bi-file-earmark-text text-primary me-2"></i> View Account</a></li>

                                                <li><hr class="dropdown-divider"></li>

                                                <!-- Status Management -->
                                                <?php if ($can_edit_sc): ?>
                                                <?php if ($sc['status'] === 'active'): ?>
                                                <li><a class="dropdown-item py-2 rounded" href="#" onclick="updateStatusSC(<?= $sc['supplier_id'] ?>, 'inactive')"><i class="bi bi-pause-circle text-warning me-2"></i> Deactivate</a></li>
                                                <?php elseif ($sc['status'] === 'inactive'): ?>
                                                <li><a class="dropdown-item py-2 rounded" href="#" onclick="updateStatusSC(<?= $sc['supplier_id'] ?>, 'active')"><i class="bi bi-play-circle text-success me-2"></i> Activate</a></li>
                                                <?php endif; ?>
                                                <?php if ($sc['status'] !== 'suspended'): ?>
                                                <li><a class="dropdown-item py-2 rounded" href="#" onclick="updateStatusSC(<?= $sc['supplier_id'] ?>, 'suspended')"><i class="bi bi-exclamation-triangle text-warning me-2"></i> Suspend</a></li>
                                                <?php endif; ?>
                                                <?php if ($sc['status'] !== 'blacklisted'): ?>
                                                <li><a class="dropdown-item py-2 rounded" href="#" onclick="updateStatusSC(<?= $sc['supplier_id'] ?>, 'blacklisted')"><i class="bi bi-slash-circle text-danger me-2"></i> Blacklist</a></li>
                                                <?php endif; ?>
                                                
                                                <li><hr class="dropdown-divider"></li>
                                                
                                                <!-- Delete -->
                                                <li><a class="dropdown-item py-2 rounded text-danger" href="#" onclick="confirmDeleteSC(<?= $sc['supplier_id'] ?>)"><i class="bi bi-trash me-2"></i> Delete</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Card Grid View -->
                    <div id="scCardGrid" class="row g-3 p-3 d-none">
                        <?php foreach ($sub_contractors as $sc): ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm" style="border-radius:10px;">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <div class="fw-bold" style="font-size:0.9rem"><?= safe_output($sc['supplier_name']) ?></div>
                                            <small class="text-muted"><?= safe_output($sc['supplier_code']) ?> &bull; <?= safe_output($sc['category_name'] ?? 'General') ?></small>
                                        </div>
                                        <span class="badge bg-<?= get_status_badge($sc['status']) ?>" style="font-size:0.65rem"><?= ucfirst($sc['status']) ?></span>
                                    </div>
                                    <?php if (!empty($sc['contact_person']) || !empty($sc['phone'])): ?>
                                    <div class="small text-muted mb-1">
                                        <?php if (!empty($sc['contact_person'])): ?><i class="bi bi-person me-1"></i><?= safe_output($sc['contact_person']) ?><?php endif; ?>
                                        <?php if (!empty($sc['phone'])): ?> &bull; <i class="bi bi-telephone me-1"></i><?= safe_output($sc['phone']) ?><?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($sc['city'])): ?>
                                    <div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i><?= safe_output($sc['city']) ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted">
                                        <i class="bi bi-briefcase me-1"></i>
                                        <?php if (!empty($sc['primary_project_name'])): ?>
                                        <?= safe_output($sc['primary_project_name']) ?><?php if ($sc['project_count'] > 0): ?> +<?= (int)$sc['project_count'] ?><?php endif; ?>
                                        <?php elseif ($sc['project_count'] > 0): ?>
                                        <?= (int)$sc['project_count'] ?> project<?= $sc['project_count'] != 1 ? 's' : '' ?>
                                        <?php else: ?><span>No projects</span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-footer bg-white border-top p-0" style="border-radius:0 0 10px 10px;">
                                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                                        <a href="<?= getUrl('sub_contractors/view') ?>?id=<?= $sc['supplier_id'] ?>" class="btn btn-sm btn-outline-info" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" title="View"><i class="bi bi-eye"></i></a>
                                        <?php if ($can_edit_sc): ?>
                                        <button class="btn btn-sm btn-outline-primary" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" onclick="editSC(<?= $sc['supplier_id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                                        <?php endif; ?>
                                        <a href="<?= getUrl('purchase_orders') ?>?supplier=<?= $sc['supplier_id'] ?>" class="btn btn-sm btn-outline-success" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" title="Orders"><i class="bi bi-cart"></i></a>
                                        <?php if ($can_delete_sc): ?>
                                        <button class="btn btn-sm btn-outline-danger" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" onclick="confirmDeleteSC(<?= $sc['supplier_id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addSubContractorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addSubContractorModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Sub-Contractor
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addSubContractorForm" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="add-sc-message" class="mb-3"></div>
                    <?php if ($proj_ctx_id > 0): ?>
                    <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 px-3 mb-3">
                        <span class="small mb-0"><i class="bi bi-diagram-3 me-1"></i>Adding to project: <strong><?= safe_output($proj_ctx_name) ?></strong></span>
                        <a href="<?= htmlspecialchars($proj_ctx_return) ?>" class="btn btn-outline-primary btn-sm text-nowrap">
                            <i class="bi bi-arrow-left me-1"></i> Back to Project
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Tabs Navigation -->
                <ul class="nav nav-tabs mb-3" id="addSubContractorTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="add-sc-basic-tab" data-bs-toggle="tab" data-bs-target="#tab-add-basic" type="button" role="tab" aria-controls="tab-add-basic" aria-selected="true">
                            <i class="bi bi-info-circle me-1"></i>Basic Info
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="add-sc-contact-tab" data-bs-toggle="tab" data-bs-target="#tab-add-contact" type="button" role="tab" aria-controls="tab-add-contact" aria-selected="false">
                            <i class="bi bi-person-lines-fill me-1"></i>Contact Details
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="add-sc-address-tab" data-bs-toggle="tab" data-bs-target="#tab-add-address" type="button" role="tab" aria-controls="tab-add-address" aria-selected="false">
                            <i class="bi bi-geo-alt me-1"></i>Address
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="add-sc-financial-tab" data-bs-toggle="tab" data-bs-target="#tab-add-financial" type="button" role="tab" aria-controls="tab-add-financial" aria-selected="false">
                            <i class="bi bi-wallet2 me-1"></i>Financial
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="addSubContractorTabsContent">
                    <!-- Tab 1: Basic Info -->
                    <div class="tab-pane fade show active" id="tab-add-basic" role="tabpanel" aria-labelledby="add-sc-basic-tab">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="sc_name" class="form-label">Sub-Contractor Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="sc_name" name="supplier_name" required placeholder="Enter sub-contractor name">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="company_name" class="form-label">Company Name</label>
                                    <input type="text" class="form-control" id="company_name" name="company_name" placeholder="Company name (if different)">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="acronym" class="form-label">Acronym</label>
                                    <input type="text" class="form-control" id="acronym" name="acronym" placeholder="e.g. TANESCO, TRA">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="logo" class="form-label">Company Logo</label>
                                    <input type="file" class="form-control" id="logo" name="logo" accept="image/*">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="sc_type" class="form-label">Sub-Contractor Type</label>
                                    <?= renderOtherSelect('sc_type', 'supplier_type', $lk_sc_type, '', 'supplier_type_other', 'Select Type') ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="sc_year" class="form-label">Year <span class="text-danger">*</span></label>
                                    <?= renderOtherSelect('sc_year', 'year', $lk_years, '', 'year_other', 'Select Year', true) ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="category_id" class="form-label">Category</label>
                                    <?= renderOtherSelect('category_id', 'category_id', $lk_categories, '', 'category_other', 'Select Category') ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="project_id" class="form-label">Linked Project (Optional)</label>
                                    <select class="form-select select2-enable" id="project_id" name="project_id">
                                        <option value="">-- General Sub-Contractor (No Project) --</option>
                                        <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['project_id'] ?>"><?= safe_output($project['project_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Associate this sub-contractor with a specific project context.</div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="credit_limit" class="form-label">Credit Limit</label>
                                    <input type="number" class="form-control" id="credit_limit" name="credit_limit" placeholder="0.00" step="0.01">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="2" placeholder="Sub-Contractor description or notes"></textarea>
                                </div>
                            </div>
                        </div>
                        <!-- Tab 2: Contact Details -->
                    <div class="tab-pane fade" id="tab-add-contact" role="tabpanel" aria-labelledby="add-sc-contact-tab">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="contact_person" class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" id="contact_person" name="contact_person" placeholder="Primary contact person">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="contact_title" class="form-label">Contact Title</label>
                                    <input type="text" class="form-control" id="contact_title" name="contact_title" placeholder="e.g., Manager, Director">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="email" class="form-label">Contact Email</label>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="contact@example.com">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="company_email" class="form-label">Company Email</label>
                                    <input type="email" class="form-control" id="company_email" name="company_email" placeholder="company@example.com">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="phone" name="phone" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="mobile" class="form-label">Mobile Number</label>
                                    <input type="text" class="form-control" id="mobile" name="mobile" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="fax" class="form-label">Fax Number</label>
                                    <input type="text" class="form-control" id="fax" name="fax" placeholder="Fax number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label for="website" class="form-label">Website</label>
                                    <input type="url" class="form-control" id="website" name="website" placeholder="https://www.example.com">
                                </div>
                            </div>
                        </div>
                        <!-- Tab 3: Address -->
                    <div class="tab-pane fade" id="tab-add-address" role="tabpanel" aria-labelledby="add-sc-address-tab">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="country" class="form-label">Country</label>
                                    <input type="text" class="form-control" id="country" name="country" placeholder="e.g. Tanzania">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="state" class="form-label">Region</label>
                                    <input type="text" class="form-control" id="state" name="state" placeholder="e.g. Dar es Salaam">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="city" class="form-label">District</label>
                                    <input type="text" class="form-control" id="city" name="city" placeholder="e.g. Ilala">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="ward" class="form-label">Ward</label>
                                    <input type="text" class="form-control" id="ward" name="ward" placeholder="e.g. Kariakoo">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="village" class="form-label">Street/Village</label>
                                    <input type="text" class="form-control" id="village" name="village" placeholder="e.g. Mtaa wa Kariakoo">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="postal_code" class="form-label">Zip Code</label>
                                    <input type="text" class="form-control" id="postal_code" name="postal_code" placeholder="Zip code">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="address" class="form-label">Physical Address</label>
                                    <textarea class="form-control" id="address" name="address" rows="2" placeholder="e.g. Ilala - Dar-es-salaam"></textarea>
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="postal_address" class="form-label">Postal Address</label>
                                    <input type="text" class="form-control" id="postal_address" name="postal_address" placeholder="e.g. p.o. box 120, mbezi">
                                </div>
                            </div>
                        </div>
                        <!-- Tab 4: Financial -->
                    <div class="tab-pane fade" id="tab-add-financial" role="tabpanel" aria-labelledby="add-sc-financial-tab">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="tax_id" class="form-label">Tax ID (TIN)</label>
                                    <input type="text" class="form-control" id="tax_id" name="tax_id" placeholder="Tax Identification Number">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="vat_number" class="form-label">VAT Number</label>
                                    <input type="text" class="form-control" id="vat_number" name="vat_number" placeholder="VAT registration number">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="default_wht_rate_id" class="form-label">Default Withholding Tax</label>
                                    <select class="form-select" id="default_wht_rate_id" name="default_wht_rate_id">
                                        <option value="">None</option>
                                        <?php foreach ($sc_wht_rates as $w): $pct = rtrim(rtrim(number_format((float)$w['rate_percentage'], 2), '0'), '.'); ?>
                                        <option value="<?= (int)$w['rate_id'] ?>"><?= safe_output($w['rate_name']) ?> (<?= $pct ?>%)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Auto-fills WHT when recording this sub-contractor's invoice payments.</div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="payment_terms" class="form-label">Payment Terms</label>
                                    <?= renderOtherSelect('payment_terms', 'payment_terms', $lk_payment_terms, '', 'payment_terms_other', 'Select Terms') ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="currency" class="form-label">Currency</label>
                                    <?= renderOtherSelect('currency', 'currency', $lk_currency, 'TZS', 'currency_other', 'Select Currency') ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="bank_name" class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="bank_account" class="form-label">Bank Account</label>
                                    <input type="text" class="form-control" id="bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="bank_address" class="form-label">Bank Address</label>
                                    <textarea class="form-control" id="bank_address" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveSubContractorBtn"><i class="bi bi-check-circle me-1"></i> Save Sub-Contractor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Sub-Contractor Modal -->
<div class="modal fade" id="editSCModal" tabindex="-1" aria-labelledby="editSCModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editSCModalLabel">
                    <i class="bi bi-pencil"></i> Edit Sub-Contractor
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editSCForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="supplier_id" id="edit_sc_id">
                <div class="modal-body">
                    <div id="edit-sc-message" class="mb-3"></div>
                    <?php if ($proj_ctx_id > 0): ?>
                    <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 px-3 mb-3">
                        <span class="small mb-0"><i class="bi bi-diagram-3 me-1"></i>Editing within project: <strong><?= safe_output($proj_ctx_name) ?></strong></span>
                        <a href="<?= htmlspecialchars($proj_ctx_return) ?>" class="btn btn-outline-primary btn-sm text-nowrap">
                            <i class="bi bi-arrow-left me-1"></i> Back to Project
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Tabs Navigation -->
                <ul class="nav nav-tabs mb-3" id="editSubContractorTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="edit-sc-basic-tab" data-bs-toggle="tab" data-bs-target="#tab-edit-basic" type="button" role="tab" aria-controls="tab-edit-basic" aria-selected="true">
                            <i class="bi bi-info-circle me-1"></i>Basic Info
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="edit-sc-contact-tab" data-bs-toggle="tab" data-bs-target="#tab-edit-contact" type="button" role="tab" aria-controls="tab-edit-contact" aria-selected="false">
                            <i class="bi bi-person-lines-fill me-1"></i>Contact Details
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="edit-sc-address-tab" data-bs-toggle="tab" data-bs-target="#tab-edit-address" type="button" role="tab" aria-controls="tab-edit-address" aria-selected="false">
                            <i class="bi bi-geo-alt me-1"></i>Address
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="edit-sc-financial-tab" data-bs-toggle="tab" data-bs-target="#tab-edit-financial" type="button" role="tab" aria-controls="tab-edit-financial" aria-selected="false">
                            <i class="bi bi-wallet2 me-1"></i>Financial
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="editSubContractorTabsContent">
                    <!-- Tab 1: Basic Info -->
                    <div class="tab-pane fade show active" id="tab-edit-basic" role="tabpanel" aria-labelledby="edit-sc-basic-tab">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_sc_name" class="form-label">Sub-Contractor Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="edit_sc_name" name="supplier_name" required placeholder="Enter sub-contractor name">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_company_name" class="form-label">Company Name</label>
                                    <input type="text" class="form-control" id="edit_company_name" name="company_name" placeholder="Company name (if different)">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_acronym" class="form-label">Acronym</label>
                                    <input type="text" class="form-control" id="edit_acronym" name="acronym" placeholder="e.g. TANESCO, TRA">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_logo" class="form-label">Company Logo</label>
                                    <input type="file" class="form-control" id="edit_logo" name="logo" accept="image/*">
                                    <div id="sc_logo_container" class="mt-2" style="display:none;">
                                        <img id="edit_sc_logo_preview" src="" alt="Logo" class="img-thumbnail" style="height:50px;">
                                        <button type="button" class="btn btn-sm btn-danger ms-2" onclick="$('#edit_sc_logo_preview').attr('src',''); $('#sc_logo_container').hide(); $('#sc_remove_logo').val('1');"><i class="bi bi-trash"></i></button>
                                        <input type="hidden" id="sc_remove_logo" name="remove_logo" value="0">
                                    </div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_sc_type" class="form-label">Sub-Contractor Type</label>
                                    <?= renderOtherSelect('edit_sc_type', 'supplier_type', $lk_sc_type, '', 'supplier_type_other', 'Select Type') ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_sc_year" class="form-label">Year <span class="text-danger">*</span></label>
                                    <?= renderOtherSelect('edit_sc_year', 'year', $lk_years, '', 'year_other', 'Select Year', true) ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_category_id" class="form-label">Category</label>
                                    <?= renderOtherSelect('edit_category_id', 'category_id', $lk_categories, '', 'category_other', 'Select Category') ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_status" class="form-label">Status</label>
                                    <select class="form-select" id="edit_status" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_project_id" class="form-label">Linked Project (Optional)</label>
                                    <select class="form-select select2-enable" id="edit_project_id" name="project_id">
                                        <option value="">-- General Sub-Contractor (No Project) --</option>
                                        <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['project_id'] ?>"><?= safe_output($project['project_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_credit_limit" class="form-label">Credit Limit</label>
                                    <input type="number" class="form-control" id="edit_credit_limit" name="credit_limit" placeholder="0.00" step="0.01">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="edit_description" class="form-label">Description</label>
                                    <textarea class="form-control" id="edit_description" name="description" rows="2" placeholder="Sub-Contractor description or notes"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Contact Details -->
                    <div class="tab-pane fade" id="tab-edit-contact" role="tabpanel" aria-labelledby="edit-sc-contact-tab">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_contact_person" class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" id="edit_contact_person" name="contact_person" placeholder="Primary contact person">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_contact_title" class="form-label">Contact Title</label>
                                    <input type="text" class="form-control" id="edit_contact_title" name="contact_title" placeholder="e.g., Manager, Director">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_email" class="form-label">Contact Email</label>
                                    <input type="email" class="form-control" id="edit_email" name="email" placeholder="contact@example.com">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_company_email" class="form-label">Company Email</label>
                                    <input type="email" class="form-control" id="edit_company_email" name="company_email" placeholder="company@example.com">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="edit_phone" name="phone" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_mobile" class="form-label">Mobile Number</label>
                                    <input type="text" class="form-control" id="edit_mobile" name="mobile" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_fax" class="form-label">Fax Number</label>
                                    <input type="text" class="form-control" id="edit_fax" name="fax" placeholder="Fax number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label for="edit_website" class="form-label">Website</label>
                                    <input type="url" class="form-control" id="edit_website" name="website" placeholder="https://www.example.com">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Address -->
                    <div class="tab-pane fade" id="tab-edit-address" role="tabpanel" aria-labelledby="edit-sc-address-tab">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_country" class="form-label">Country</label>
                                    <input type="text" class="form-control" id="edit_country" name="country" placeholder="e.g. Tanzania">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_state" class="form-label">Region</label>
                                    <input type="text" class="form-control" id="edit_state" name="state" placeholder="e.g. Dar es Salaam">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_city" class="form-label">District</label>
                                    <input type="text" class="form-control" id="edit_city" name="city" placeholder="e.g. Ilala">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_ward" class="form-label">Ward</label>
                                    <input type="text" class="form-control" id="edit_ward" name="ward" placeholder="e.g. Kariakoo">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_village" class="form-label">Street/Village</label>
                                    <input type="text" class="form-control" id="edit_village" name="village" placeholder="e.g. Mtaa wa Kariakoo">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_postal_code" class="form-label">Zip Code</label>
                                    <input type="text" class="form-control" id="edit_postal_code" name="postal_code" placeholder="Zip code">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="edit_address" class="form-label">Physical Address</label>
                                    <textarea class="form-control" id="edit_address" name="address" rows="2" placeholder="e.g. Ilala - Dar-es-salaam"></textarea>
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="edit_postal_address" class="form-label">Postal Address</label>
                                    <input type="text" class="form-control" id="edit_postal_address" name="postal_address" placeholder="e.g. p.o. box 120, mbezi">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Financial -->
                    <div class="tab-pane fade" id="tab-edit-financial" role="tabpanel" aria-labelledby="edit-sc-financial-tab">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_tax_id" class="form-label">Tax ID (TIN)</label>
                                    <input type="text" class="form-control" id="edit_tax_id" name="tax_id" placeholder="Tax Identification Number">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_vat_number" class="form-label">VAT Number</label>
                                    <input type="text" class="form-control" id="edit_vat_number" name="vat_number" placeholder="VAT registration number">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_default_wht_rate_id" class="form-label">Default Withholding Tax</label>
                                    <select class="form-select" id="edit_default_wht_rate_id" name="default_wht_rate_id">
                                        <option value="">None</option>
                                        <?php foreach ($sc_wht_rates as $w): $pct = rtrim(rtrim(number_format((float)$w['rate_percentage'], 2), '0'), '.'); ?>
                                        <option value="<?= (int)$w['rate_id'] ?>"><?= safe_output($w['rate_name']) ?> (<?= $pct ?>%)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Auto-fills WHT when recording this sub-contractor's invoice payments.</div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_payment_terms" class="form-label">Payment Terms</label>
                                    <?= renderOtherSelect('edit_payment_terms', 'payment_terms', $lk_payment_terms, '', 'payment_terms_other', 'Select Terms') ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_currency" class="form-label">Currency</label>
                                    <?= renderOtherSelect('edit_currency', 'currency', $lk_currency, '', 'currency_other', 'Select Currency') ?>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_bank_name" class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="edit_bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_bank_account" class="form-label">Bank Account</label>
                                    <input type="text" class="form-control" id="edit_bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="edit_bank_address" class="form-label">Bank Address</label>
                                    <textarea class="form-control" id="edit_bank_address" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="updateSubContractorBtn"><i class="bi bi-check-circle me-1"></i> Update Sub-Contractor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= getUrl('assets/js/location_cascade.js') ?>"></script>
<script>
// Location cascade engine — Tanzania gets defined dropdowns (Region→District→
// Ward→Street/Village), other countries fall back to free text automatically.
let addLocationCascade, editLocationCascade;
$(document).ready(function() {
    $('#scTable').DataTable({
        pageLength: 25,
        responsive: true,
        dom: 'rtip'
    });

    // Location cascade (OOP location engine): defined dropdowns for Tanzania,
    // free-text automatically for countries without imported subdivisions.
    addLocationCascade = initLocationCascade({
        endpoint: '<?= buildUrl('api/location/options.php') ?>',
        fields: { country: '#country', region: '#state', district: '#city', ward: '#ward', village: '#village' },
        dropdownParent: '#addSubContractorModal'
    });
    editLocationCascade = initLocationCascade({
        endpoint: '<?= buildUrl('api/location/options.php') ?>',
        fields: { country: '#edit_country', region: '#edit_state', district: '#edit_city', ward: '#edit_ward', village: '#edit_village' },
        dropdownParent: '#editSCModal'
    });

    // Select2 for filter (outside modal)
    $('#categoryFilter').select2({
        theme: 'bootstrap-5',
        placeholder: 'All Categories',
        allowClear: true,
        width: '100%'
    });

    // "Other" dropdowns (renderOtherSelect): searchable Select2; choosing "Other"
    // swaps the dropdown for a text input. Typed value is saved server-side and
    // appears next time. Generic — every .other-trigger in the given scope.
    function initOtherSelects(scopeSelector, $parent) {
        $(scopeSelector).find('.other-trigger').each(function() {
            const $el = $(this);
            if ($el.hasClass('select2-hidden-accessible')) return;
            $el.select2({
                theme: 'bootstrap-5',
                dropdownParent: $parent,
                width: '100%',
                allowClear: true,
                placeholder: $el.data('placeholder') || 'Select…'
            });
        });
    }
    $(document).off('change.otherSel').on('change.otherSel', 'select.other-trigger', function() {
        const $sel = $(this), $wrap = $sel.closest('.other-field-wrap'), $box = $wrap.find('.other-input-box');
        if ($sel.val() === 'other') {
            $sel.addClass('d-none').next('.select2-container').addClass('d-none');
            $box.removeClass('d-none').find('.other-input').val('').trigger('focus');
        } else { $box.addClass('d-none'); }
    });
    $(document).off('click.otherBack').on('click.otherBack', '.other-back', function() {
        const $wrap = $(this).closest('.other-field-wrap'), $sel = $wrap.find('select.other-trigger');
        $wrap.find('.other-input-box').addClass('d-none').find('.other-input').val('');
        $sel.removeClass('d-none').next('.select2-container').removeClass('d-none');
        $sel.val('').trigger('change.select2');
    });

    // Select2 for Add modal
    $('#addSubContractorModal').on('shown.bs.modal', function() {
        $(this).find('.select2-enable').each(function() {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({
                    theme: 'bootstrap-5',
                    dropdownParent: $('#addSubContractorModal'),
                    placeholder: 'Select...',
                    allowClear: true,
                    width: '100%'
                });
            }
        });
        initOtherSelects('#addSubContractorModal', $('#addSubContractorModal'));
    });

    // Select2 for Edit modal
    $('#editSCModal').on('shown.bs.modal', function() {
        $(this).find('.select2-enable').each(function() {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({
                    theme: 'bootstrap-5',
                    dropdownParent: $('#editSCModal'),
                    placeholder: 'Select...',
                    allowClear: true,
                    width: '100%'
                });
            }
        });
        initOtherSelects('#editSCModal', $('#editSCModal'));
    });

    // ── other-select-wrap: show free-text input when "Other..." is chosen ────
    $(document).on('change', 'select.other-select', function() {
        if ($(this).val() === 'other') {
            var $select    = $(this);
            var inputId    = $select.data('other-input');
            var $wrap      = $select.closest('.other-select-wrap');
            var $inputWrap = $wrap.find('.other-input-wrap');
            var $input     = $wrap.find('#' + inputId);
            $select.hide().prop('name', '');
            $inputWrap.show();
            $input.val('').focus();
        }
    });

    $(document).on('click', '.other-back-link', function() {
        var selectId   = $(this).data('target-select');
        var $select    = $('#' + selectId);
        var $wrap      = $select.closest('.other-select-wrap');
        var $inputWrap = $wrap.find('.other-input-wrap');
        var $input     = $wrap.find('.other-custom-input');
        $input.val('').prop('required', false);
        $select.prop('name', $select.data('orig-name')).show().val('').trigger('change.select2');
        $inputWrap.hide();
    });

    $('select.other-select').each(function() {
        $(this).data('orig-name', $(this).attr('name'));
    });

    function resetOtherFields(containerSelector) {
        if (!containerSelector) return;
        $(containerSelector).find('select.other-select').each(function() {
            var $select    = $(this);
            var $wrap      = $select.closest('.other-select-wrap');
            var $inputWrap = $wrap.find('.other-input-wrap');
            var $input     = $wrap.find('.other-custom-input');
            $input.val('').prop('required', false);
            $inputWrap.hide();
            $select.prop('name', $select.data('orig-name')).show().val('').trigger('change');
            if ($select.hasClass('select2-hidden-accessible')) $select.trigger('change.select2');
        });
        // New "Other → input" widgets: restore the dropdown, clear/hide the input.
        $(containerSelector).find('.other-field-wrap').each(function() {
            var $wrap = $(this);
            $wrap.find('.other-input-box').addClass('d-none').find('.other-input').val('');
            var $sel = $wrap.find('select.other-trigger');
            $sel.removeClass('d-none');
            $sel.next('.select2-container').removeClass('d-none');
        });
    }

    // Modal close — reset forms and other-fields
    $('#addSubContractorModal').on('hidden.bs.modal', function() {
        $('#addSubContractorForm')[0].reset();
        resetOtherFields('#addSubContractorModal');
        addLocationCascade.setValues({ country: 'Tanzania' }); // back to defaults
    });

    $('#editSCModal').on('hidden.bs.modal', function() {
        $('#editSCForm')[0].reset();
        $('#sc_logo_container').hide();
        $('#edit_sc_logo_preview').attr('src', '');
        $('#sc_remove_logo').val('0');
        resetOtherFields('#editSCModal');
    });
    // ─────────────────────────────────────────────────────────────────────────

    checkSCResponsiveView();
    $(window).on('resize', function() { checkSCResponsiveView(); });

    // Add Form Submit
    $('#addSubContractorForm').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        $.ajax({
            url: 'api/add_sub_contractor.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if(res.success) {
                    Swal.fire('Success', res.message, 'success').then(() => { if (window.__projReturnUrl) window.location.href = window.__projReturnUrl; else location.reload(); });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }
        });
    });

    // Edit Form Submit
    $('#editSCForm').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        $.ajax({
            url: 'api/update_sub_contractor.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if(res.success) {
                    Swal.fire('Updated', res.message, 'success').then(() => { if (window.__projReturnUrl) window.location.href = window.__projReturnUrl; else location.reload(); });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }
        });
    });
});

// Open Add/Edit modal + lock the project when arriving from a project view,
// and return to that project after a successful save.
$(document).ready(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const action   = urlParams.get('action');
    const editId   = urlParams.get('edit');
    // Project context resolved server-side (clean URL: ?project=<id>&back=<tab>).
    const projLock  = <?= $proj_ctx_id ?: 'null' ?>;
    const projName  = <?= json_encode($proj_ctx_name) ?>;
    window.__projReturnUrl = <?= $proj_ctx_return !== '' ? json_encode($proj_ctx_return) : 'null' ?>;

    function lockProjectField(selId) {
        if (!projLock) return;
        const $sel = $(selId);
        if (!$sel.length) return;
        if ($sel.find('option[value="' + projLock + '"]').length === 0) {
            const label = projName || ('Project #' + projLock);
            $sel.append(new Option(label, projLock, true, true));
        }
        $sel.val(projLock).trigger('change');
        $sel.prop('disabled', true).trigger('change.select2');
        const $form = $sel.closest('form');
        if ($form.find('input[type=hidden][name="project_id"]').length === 0) {
            $('<input type="hidden" name="project_id">').val(projLock).appendTo($form);
        }
    }
    $('#addSubContractorModal').on('shown.bs.modal', function(){ lockProjectField('#project_id'); });
    $('#editSCModal').on('shown.bs.modal', function(){ lockProjectField('#edit_project_id'); });

    if (action === 'add') {
        setTimeout(function(){ new bootstrap.Modal(document.getElementById('addSubContractorModal')).show(); }, 300);
    }
    if (editId) {
        setTimeout(function(){ editSC(editId); }, 300);
    }
});

function editSC(id) {
    $.getJSON('api/get_sub_contractor.php', { id: id }, function(res) {
        if(res.success) {
            const d = res.data;
            $('#edit_sc_id').val(d.supplier_id);
            $('#edit_sc_name').val(d.supplier_name);
            $('#edit_company_name').val(d.company_name);
            $('#edit_acronym').val(d.acronym);
            // Set value, injecting an option first if the stored value isn't listed
            // (e.g. a previously-typed "Other" value), so it shows as selected.
            function setOrInject(sel, val) {
                val = (val == null) ? '' : String(val);
                if (val !== '' && $(sel + ' option').filter(function(){ return this.value === val; }).length === 0) {
                    $(sel).append(new Option(val, val, true, true));
                }
                $(sel).val(val).trigger('change');
            }
            setOrInject('#edit_sc_type', d.supplier_type);
            setOrInject('#edit_sc_year', d.year);
            setOrInject('#edit_category_id', d.category_id);
            $('#edit_status').val(d.status);
            $('#edit_project_id').val(d.project_id);
            $('#edit_credit_limit').val(d.credit_limit);
            $('#edit_description').val(d.description);
            
            // Contact
            $('#edit_contact_person').val(d.contact_person);
            $('#edit_contact_title').val(d.contact_title);
            $('#edit_email').val(d.email);
            $('#edit_company_email').val(d.company_email);
            $('#edit_phone').val(d.phone);
            $('#edit_mobile').val(d.mobile);
            $('#edit_fax').val(d.fax);
            $('#edit_website').val(d.website);
            
            // Address — location cascade prefill (unmatched legacy values are
            // kept as extra options instead of being wiped).
            editLocationCascade.setValues({
                country:  d.country || 'Tanzania',
                region:   d.state || '',
                district: d.city || '',
                ward:     d.ward || '',
                village:  d.village || ''
            });
            $('#edit_postal_code').val(d.postal_code);
            $('#edit_address').val(d.address);
            $('#edit_postal_address').val(d.postal_address);
            
            // Financial
            $('#edit_tax_id').val(d.tax_id);
            $('#edit_vat_number').val(d.vat_number);
            $('#edit_default_wht_rate_id').val(d.default_wht_rate_id || '');
            setOrInject('#edit_payment_terms', d.payment_terms);
            setOrInject('#edit_currency', d.currency);
            $('#edit_bank_name').val(d.bank_name);
            $('#edit_bank_account').val(d.bank_account);
            $('#edit_bank_address').val(d.bank_address);

            // Handle current logo display
            if (d.logo_path) {
                $('#edit_sc_logo_preview').attr('src', '<?= buildUrl('') ?>' + d.logo_path);
                $('#sc_logo_container').show();
            } else {
                $('#edit_sc_logo_preview').attr('src', '');
                $('#sc_logo_container').hide();
            }
            $('#sc_remove_logo').val('0');
            
            $('#editSCModal').modal('show');
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    });
}

function confirmDeleteSC(id) {
    Swal.fire({
        title: 'Delete Sub-Contractor?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('api/delete_sub_contractor.php', { supplier_id: id }, function(res) {
                if(res.success) {
                    Swal.fire('Deleted!', res.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function applyFilters() {
    const table = $('#scTable').DataTable();
    const status = $('#statusFilter').val();
    const category = $('#categoryFilter').val().toLowerCase();
    const country = $('#countryFilter').val().toLowerCase();
    const city = $('#cityFilter').val().toLowerCase();

    // Status filter — column 7
    table.column(7).search(status).draw();

    // Category filter — column 5
    table.column(5).search(category).draw();

    // Country/city filter — column 4 (Address)
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        const address = data[4].toLowerCase();
        return address.includes(country) && address.includes(city);
    });
    table.draw();
    $.fn.dataTable.ext.search.pop();
}

function clearFilters() {
    $('#statusFilter, #countryFilter, #cityFilter').val('');
    $('#categoryFilter').val('').trigger('change');
    $('#scTable').DataTable().search('').columns().search('').draw();
}

function toggleSCView(mode) {
    const isMobile = window.innerWidth <= 767;
    if (isMobile) mode = 'card';
    if (mode === 'card') {
        $('#tableView').addClass('d-none');
        $('#scCardGrid').removeClass('d-none');
        $('#sc-btn-table').removeClass('btn-primary').addClass('btn-light');
        $('#sc-btn-card').removeClass('btn-light').addClass('btn-primary');
    } else {
        $('#tableView').removeClass('d-none');
        $('#scCardGrid').addClass('d-none');
        $('#sc-btn-table').removeClass('btn-light').addClass('btn-primary');
        $('#sc-btn-card').removeClass('btn-primary').addClass('btn-light');
    }
    if (!isMobile) localStorage.setItem('scView', mode);
}

function checkSCResponsiveView() {
    if (window.innerWidth <= 767) {
        toggleSCView('card');
    } else {
        toggleSCView(localStorage.getItem('scView') || 'table');
    }
}

function updateStatusSC(id, status) {
    const statusText = status.charAt(0).toUpperCase() + status.slice(1);
    Swal.fire({
        title: `${statusText} Sub-Contractor?`,
        text: `Are you sure you want to change status to ${statusText}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: status === 'active' ? '#198754' : (status === 'blacklisted' ? '#dc3545' : '#ffc107'),
        confirmButtonText: `Yes, ${statusText}!`
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('api/update_sub_contractor_status.php', { supplier_id: id, status: status }, function(res) {
                if(res.success) {
                    Swal.fire('Success', res.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function printTable() { window.print(); }
function exportSC() {
    // Simple alert for now, can implement CSV export if needed
    Swal.fire('Info', 'CSV Export will be available soon', 'info');
}
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd;
    border-radius: 12px;
    transition: transform 0.2s;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.stat-icon-circle {
    width: 40px;
    height: 40px;
    background: rgba(13, 110, 253, 0.1);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0d6efd;
}
.custom-code {
    background: #e9ecef;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
    font-weight: bold;
}
.bg-primary-soft { background-color: rgba(13, 110, 253, 0.1); }
@media (max-width: 767px) {
    .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
}
</style>

<?php
// Include the footer
require_once 'footer.php';
ob_end_flush();
?>
