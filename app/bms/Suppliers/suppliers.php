<?php
// scope-audit: skip — multi-project scope enforced below via supplier_projects junction table
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('suppliers');

includeHeader();

// Check user permissions dynamically
$can_view_suppliers = canView('suppliers');
$can_create_suppliers = canCreate('suppliers');
$can_edit_suppliers = canEdit('suppliers');
$can_delete_suppliers = canDelete('suppliers');

if (!$can_view_suppliers) {
    header("Location: unauthorized");
    exit();
}

// Get company type for conditional features
$settings_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_type'");
$settings_stmt->execute();
$company_type = $settings_stmt->fetchColumn() ?: 'microfinance';

// Display name from global header
$display_company_name = $GLOBALS['DISPLAY_COMPANY_NAME'];

// Fetch company logo and name for printing
$company_logo = getSetting('company_logo');
$company_name = getSetting('company_name') ?: $display_company_name;

// Build multi-project scope filter — visible if global (no project anywhere)
// OR primary project in scope OR at least one supplier_projects entry in scope
if (!empty($_SESSION['scope']['is_admin'])) {
    $supplier_scope_sql = '';
} else {
    $sp_ids = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    if (empty($sp_ids)) {
        $supplier_scope_sql = ' AND 0 ';
    } else {
        $ids = implode(',', $sp_ids);
        $supplier_scope_sql = " AND (
            (s.project_id IS NULL AND NOT EXISTS (SELECT 1 FROM supplier_projects x WHERE x.supplier_id = s.supplier_id))
            OR s.project_id IN ($ids)
            OR EXISTS (SELECT 1 FROM supplier_projects x WHERE x.supplier_id = s.supplier_id AND x.project_id IN ($ids))
        ) ";
    }
}

// Fetch suppliers with additional data
$query = "
    SELECT
        s.*,
        sc.category_name,
        COUNT(DISTINCT sp.project_id) as project_count,
        COUNT(DISTINCT po.purchase_order_id) as total_orders,
        COUNT(DISTINCT pr.purchase_return_id) as total_returns,
        SUM(CASE WHEN po.status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN po.status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        u1.username as created_by_name,
        u2.username as updated_by_name
    FROM suppliers s
    LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
    LEFT JOIN supplier_projects sp ON s.supplier_id = sp.supplier_id
    LEFT JOIN purchase_orders po ON s.supplier_id = po.supplier_id
    LEFT JOIN purchase_returns pr ON s.supplier_id = pr.supplier_id
    LEFT JOIN users u1 ON s.created_by = u1.user_id
    LEFT JOIN users u2 ON s.updated_by = u2.user_id
    WHERE s.status != 'deleted' $supplier_scope_sql
    GROUP BY s.supplier_id
    ORDER BY s.supplier_name ASC
";
$stmt = $pdo->query($query);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active WHT rates for the "Default WHT" picker (auto-fills on this supplier's payments).
$sup_wht_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage
                                FROM tax_rates WHERE tax_kind = 'wht' AND status = 'active'
                            ORDER BY rate_percentage")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_suppliers = count($suppliers);
$active_suppliers = array_filter($suppliers, function($supplier) {
    return $supplier['status'] == 'active';
});
$inactive_suppliers = array_filter($suppliers, function($supplier) {
    return $supplier['status'] == 'inactive';
});
$suspended_suppliers = array_filter($suppliers, function($supplier) {
    return $supplier['status'] == 'suspended';
});
$blacklisted_suppliers = array_filter($suppliers, function($supplier) {
    return $supplier['status'] == 'blacklisted';
});

// Get supplier categories
$categories = $pdo->query("SELECT * FROM supplier_categories WHERE status = 'active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch projects for linking — admins see all; non-admins see only their assigned projects
if (isAdmin()) {
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
    <!-- Standardized Print Header (Visible only in print) -->
    <div class="bms-print-header d-none d-print-block">
        
        <h2 class="bph-title">Official Suppliers Report</h2>
        <p class="bph-sub">Generated on: <?= date('d M Y, H:i') ?></p>
        <div class="bph-bar"></div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="flex-grow-1">
                    <h2 class="mb-0 fs-4 fs-md-3 fw-bold text-nowrap"><i class="bi bi-truck"></i> Supplier Management</h2>
                    <p class="text-muted mb-0 d-none d-md-block small mt-1">Manage your suppliers and vendor relationships</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($can_create_suppliers): ?>
                    <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm text-nowrap" style="border-radius: 6px;" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                        <i class="bi bi-plus-circle me-1"></i> Add New Supplier
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function resizeTextToFit() {
            const elements = document.querySelectorAll('.custom-stat-card h4.auto-resize');
            elements.forEach(el => {
                let size = 1.3; // Starting size
                el.style.fontSize = size + 'rem';
                const container = el.closest('.overflow-hidden');
                if (container) {
                    const containerWidth = container.clientWidth;
                    while (el.scrollWidth > containerWidth && size > 0.7) {
                        size -= 0.05;
                        el.style.fontSize = size + 'rem';
                    }
                }
            });
        }
        window.addEventListener('load', resizeTextToFit);
        window.addEventListener('resize', resizeTextToFit);
    </script>

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
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Suppliers</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" style="font-size: 1.1rem;"><?= $total_suppliers ?></h4>
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
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Active</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" style="font-size: 1.1rem;"><?= count($active_suppliers) ?></h4>
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
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Suspended</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-suspended-suppliers" style="font-size: 1.1rem;"><?= count($suspended_suppliers) ?></h4>
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
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Blacklisted</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-blacklisted-suppliers" style="font-size: 1.1rem;"><?= count($blacklisted_suppliers) ?></h4>
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
                    <button class="btn btn-sm btn-outline-secondary border-0" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </div>
                <div class="collapse show" id="filterCollapse">
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
                                        <option value="<?= $category['category_id'] ?>"><?= safe_output($category['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="countryFilter" class="form-label small fw-bold">Country</label>
                                <input type="text" class="form-control" id="countryFilter" placeholder="Filter by country" autocomplete="off">
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="cityFilter" class="form-label small fw-bold">City</label>
                                <input type="text" class="form-control" id="cityFilter" placeholder="Filter by city" autocomplete="off">
                            </div>
                            <div class="col-md-12 d-flex flex-column flex-sm-row justify-content-end pt-2 gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm px-3" onclick="clearFilters()">
                                    <i class="bi bi-arrow-clockwise"></i> Clear
                                </button>
                                <button type="button" class="btn btn-primary btn-sm px-4" onclick="applyFilters()">
                                    <i class="bi bi-filter"></i> Apply Filters
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Section -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex flex-wrap align-items-center gap-2 flex-grow-1">
                    
                    <!-- Action Buttons -->
                    <div class="d-flex flex-wrap shadow-sm bg-white" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; height: 38px;">
                            <i class="bi bi-clipboard text-info me-1"></i> Copy
                        </button>
                        <div class="bg-light d-none d-sm-block" style="width: 1px; height: 38px;"></div>
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="exportSuppliers()" style="background: #fff; height: 38px;">
                            <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> CSV
                        </button>
                        <div class="bg-light d-none d-sm-block" style="width: 1px; height: 38px;"></div>
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="printTable()" style="background: #fff; height: 38px;">
                            <i class="bi bi-printer text-primary me-1"></i> Print
                        </button>
                        <?php if ($can_create_suppliers): ?>
                        <div class="bg-light d-none d-sm-block" style="width: 1px; height: 38px;"></div>
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" data-bs-toggle="modal" data-bs-target="#importSuppliersModal" style="background: #fff; height: 38px;">
                            <i class="bi bi-upload text-info me-1"></i> Import
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Toolbar -->
                    <div class="d-flex align-items-center gap-2 flex-grow-1">
                        <div class="d-flex align-items-center bg-white shadow-sm px-2 py-1 d-print-none" style="border: 1px solid #dee2e6; border-radius: 8px; height: 38px;">
                            <span class="small text-muted me-2 text-nowrap">Show:</span>
                            <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 45px; background: transparent;" onchange="$('#suppliersTable').DataTable().page.len(this.value).draw();">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="-1">All</option>
                            </select>
                        </div>
                        <div class="input-group input-group-sm shadow-sm flex-grow-1 d-print-none" style="border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6; height: 38px; min-width: 150px; max-width: 350px;">
                            <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" class="form-control border-0" id="searchSuppliers" placeholder="Search suppliers..." onkeyup="quickSearch()">
                        </div>
                    </div>
                </div>
                <div class="d-none d-xl-block">
                    <span class="badge bg-success-soft text-success border border-success px-3 py-2 rounded-pill shadow-sm">
                        <i class="bi bi-check-circle-fill me-1"></i> <?= $total_suppliers ?> records
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Section -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="width: 100% !important;">
                <div class="page-header card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark">Suppliers Records</h5>
                    <div class="btn-group shadow-sm d-none d-md-flex" role="group">
                        <button type="button" class="btn btn-light btn-sm border" onclick="toggleView('table')" id="btn-table-view" title="Table View">
                            <i class="bi bi-table"></i>
                        </button>
                        <button type="button" class="btn btn-light btn-sm border" onclick="toggleView('card')" id="btn-card-view" title="Card View">
                            <i class="bi bi-grid"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="form-message" class="mx-3 mt-3"></div>
            
            <?php if (count($suppliers) > 0): ?>
                <!-- Table View -->
                <div id="tableView" class="table-responsive">
                    <table id="suppliersTable" class="table table-striped table-hover" style="width: 100% !important;">
                        <thead>
                            <tr>
                                <th class="align-middle text-center col-sno">S/NO</th>
                                <th class="align-middle col-info">Supplier Code</th>
                                <th class="align-middle col-info">Supplier Name</th>
                                <th class="align-middle col-info">Contact Info</th>
                                <th class="align-middle col-info">Address</th>
                                <th class="align-middle col-info">Category</th>
                                <th class="align-middle col-info">Project</th>
                                <th class="text-center align-middle col-stat">Total Orders</th>
                                <th class="text-center align-middle col-stat">Pending</th>
                                <th class="text-center align-middle col-stat">Completed</th>
                                <th class="align-middle col-status">Status</th>
                                <th class="align-middle d-print-none">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sn = 1; foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td class="text-center text-muted small fw-bold"><?= $sn++ ?></td>
                                <td>
                                    <span class="custom-code"><?= safe_output($supplier['supplier_code']) ?></span>
                                </td>
                                <td>
                                    <strong><?= safe_output($supplier['supplier_name']) ?></strong>
                                </td>
                                <td>
                                    <div class="small text-muted" style="line-height: 1.2;">
                                        <?php if (!empty($supplier['contact_person'])): ?>
                                        <span class="text-dark fw-bold"><?= safe_output($supplier['contact_person']) ?></span><br>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier['phone'])): ?>
                                        <i class="bi bi-telephone small"></i> <?= safe_output($supplier['phone']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="small text-muted" style="max-width: 150px; line-height: 1.2;">
                                        <?php if (!empty($supplier['address'])): ?>
                                        <?= safe_output(substr($supplier['address'], 0, 40)) ?><br>
                                        <?php endif; ?>
                                        <span class="fw-bold text-dark"><?= safe_output($supplier['city'] ?? '') ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($supplier['category_name'])): ?>
                                    <span class="badge bg-secondary"><?= safe_output($supplier['category_name']) ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-light text-dark">No Category</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($supplier['project_count'] > 0): ?>
                                    <a href="<?= getUrl('suppliers/view') ?>?id=<?= $supplier['supplier_id'] ?>" class="badge bg-primary text-white text-decoration-none">
                                        <?= (int)$supplier['project_count'] ?> <?= $supplier['project_count'] == 1 ? 'project' : 'projects' ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?= $supplier['total_orders'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning text-dark"><?= $supplier['pending_orders'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success"><?= $supplier['completed_orders'] ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($supplier['status']) ?>">
                                        <?= ucfirst($supplier['status']) ?>
                                    </span>
                                </td>
                                <td class="d-print-none text-center">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear-fill me-1"></i> Actions
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                            <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('suppliers/view') ?>?id=<?= $supplier['supplier_id'] ?>"><i class="bi bi-eye text-info me-2"></i> View Details</a></li>
                                            <?php if ($can_edit_suppliers): ?>
                                            <li><a class="dropdown-item py-2 rounded" href="#" onclick="editSupplier(<?= $supplier['supplier_id'] ?>)"><i class="bi bi-pencil text-primary me-2"></i> Edit Supplier</a></li>
                                            <?php endif; ?>
                                            <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('purchase_orders') ?>?supplier=<?= $supplier['supplier_id'] ?>"><i class="bi bi-cart text-success me-2"></i> View Orders</a></li>
                                            <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('suppliers/payments') ?>?id=<?= $supplier['supplier_id'] ?>"><i class="bi bi-cash text-warning me-2"></i> View Payments</a></li>
                                            <?php if ($company_type != 'microfinance' && $can_edit_suppliers): ?>
                                            <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('purchase_order_create') ?>?supplier=<?= $supplier['supplier_id'] ?>"><i class="bi bi-file-plus me-2"></i> New Order</a></li>
                                            <?php endif; ?>

                                            <?php if ($can_edit_suppliers): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php if ($supplier['status'] == 'active'): ?>
                                            <li><a class="dropdown-item py-2 rounded" href="#" onclick="updateStatus(<?= $supplier['supplier_id'] ?>, 'inactive')"><i class="bi bi-pause-circle text-warning me-2"></i> Deactivate</a></li>
                                            <?php elseif ($supplier['status'] == 'inactive'): ?>
                                            <li><a class="dropdown-item py-2 rounded" href="#" onclick="updateStatus(<?= $supplier['supplier_id'] ?>, 'active')"><i class="bi bi-play-circle text-success me-2"></i> Activate</a></li>
                                            <?php endif; ?>
                                            <?php if ($supplier['status'] !== 'suspended'): ?>
                                            <li><a class="dropdown-item py-2 rounded" href="#" onclick="updateStatus(<?= $supplier['supplier_id'] ?>, 'suspended')"><i class="bi bi-exclamation-triangle text-warning me-2"></i> Suspend</a></li>
                                            <?php endif; ?>
                                            <?php if ($supplier['status'] !== 'blacklisted'): ?>
                                            <li><a class="dropdown-item py-2 rounded" href="#" onclick="updateStatus(<?= $supplier['supplier_id'] ?>, 'blacklisted')"><i class="bi bi-ban text-danger me-2"></i> Blacklist</a></li>
                                            <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($can_delete_suppliers): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item py-2 rounded text-danger" href="#" onclick="confirmDelete(<?= $supplier['supplier_id'] ?>)"><i class="bi bi-trash me-2"></i> Delete</a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <!-- Buffer tfoot: reserves space for fixed footer during print -->
                        <tfoot class="cust-print-buf d-none d-print-table-footer-group">
                            <tr><td colspan="11" style="height: 1.2cm; border:none !important;"></td></tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Card View (Hidden by default) -->
                <div id="cardView" class="row g-3 p-3 d-none">
                    <?php foreach ($suppliers as $supplier): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0"><?= safe_output($supplier['supplier_name']) ?></h6>
                                <span class="badge bg-<?= get_status_badge($supplier['status']) ?>">
                                    <?= ucfirst($supplier['status']) ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <small class="text-muted">Code: <?= safe_output($supplier['supplier_code']) ?></small><br>
                                    <?php if (!empty($supplier['company_name'])): ?>
                                    <strong><?= safe_output($supplier['company_name']) ?></strong><br>
                                    <?php endif; ?>
                                    <?php if (!empty($supplier['contact_person'])): ?>
                                    <small><i class="bi bi-person"></i> <?= safe_output($supplier['contact_person']) ?></small><br>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if (!empty($supplier['email'])): ?>
                                    <small><i class="bi bi-envelope"></i> <?= safe_output($supplier['email']) ?></small><br>
                                    <?php endif; ?>
                                    <?php if (!empty($supplier['phone'])): ?>
                                    <small><i class="bi bi-telephone"></i> <?= safe_output($supplier['phone']) ?></small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if (!empty($supplier['city'])): ?>
                                    <small><i class="bi bi-geo-alt"></i> <?= safe_output($supplier['city']) ?></small>
                                    <?php if (!empty($supplier['country'])): ?>
                                    , <small><?= safe_output($supplier['country']) ?></small>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="mb-2">
                                    <?php if ($supplier['project_count'] > 0): ?>
                                    <a href="<?= getUrl('suppliers/view') ?>?id=<?= $supplier['supplier_id'] ?>" class="badge bg-primary text-white text-decoration-none">
                                        <i class="bi bi-briefcase me-1"></i><?= (int)$supplier['project_count'] ?> <?= $supplier['project_count'] == 1 ? 'project' : 'projects' ?>
                                    </a>
                                    <?php else: ?>
                                    <small class="text-muted"><i class="bi bi-globe me-1"></i> General Supplier</small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-3">
                                    <div class="text-center">
                                        <div class="badge bg-primary"><?= $supplier['total_orders'] ?></div>
                                        <br>
                                        <small>Orders</small>
                                    </div>
                                    <div class="text-center">
                                        <div class="badge bg-success"><?= $supplier['completed_orders'] ?></div>
                                        <br>
                                        <small>Completed</small>
                                    </div>
                                    <div class="text-center">
                                        <div class="badge bg-warning"><?= $supplier['pending_orders'] ?></div>
                                        <br>
                                        <small>Pending</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-white border-top p-0">
                                <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                                    <a href="<?= getUrl('suppliers/details') ?>?id=<?= $supplier['supplier_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Details" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($can_edit_suppliers): ?>
                                    <button class="btn btn-sm btn-outline-warning" onclick="editSupplier(<?= $supplier['supplier_id'] ?>)" title="Edit" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <a href="<?= getUrl('purchase_orders') ?>?supplier=<?= $supplier['supplier_id'] ?>" class="btn btn-sm btn-outline-success" title="View Orders" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;">
                                        <i class="bi bi-cart"></i>
                                    </a>
                                    <?php if ($company_type != 'microfinance' && $can_edit_suppliers): ?>
                                    <a href="<?= getUrl('purchase_order_create') ?>?supplier=<?= $supplier['supplier_id'] ?>" class="btn btn-sm btn-outline-info" title="New Order" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;">
                                        <i class="bi bi-plus-circle"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-truck" style="font-size: 4rem; color: #6c757d;"></i>
                            <h4 class="mt-3 text-muted">No Suppliers Found</h4>
                            <p class="text-muted">Get started by adding your first supplier.</p>
                            <?php if ($can_edit_suppliers): ?>
                            <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                                <i class="bi bi-plus-circle"></i> Add Your First Supplier
                            </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Supplier Modal -->
<?php if ($can_edit_suppliers): ?>
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-labelledby="addSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addSupplierModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Supplier
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addSupplierForm" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="add-supplier-message" class="mb-3"></div>
                    
                    <!-- Tabs Navigation -->
                <ul class="nav nav-tabs mb-3" id="addSupplierTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="add-basic-tab" data-bs-toggle="tab" data-bs-target="#tab-add-basic" type="button" role="tab" aria-controls="tab-add-basic" aria-selected="true">
                            <i class="bi bi-info-circle me-1"></i>Basic Info
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="add-contact-tab" data-bs-toggle="tab" data-bs-target="#tab-add-contact" type="button" role="tab" aria-controls="tab-add-contact" aria-selected="false">
                            <i class="bi bi-person-lines-fill me-1"></i>Contact Details
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="add-address-tab" data-bs-toggle="tab" data-bs-target="#tab-add-address" type="button" role="tab" aria-controls="tab-add-address" aria-selected="false">
                            <i class="bi bi-geo-alt me-1"></i>Address
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="add-financial-tab" data-bs-toggle="tab" data-bs-target="#tab-add-financial" type="button" role="tab" aria-controls="tab-add-financial" aria-selected="false">
                            <i class="bi bi-wallet2 me-1"></i>Financial
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="addSupplierTabsContent">
                    <!-- Tab 1: Basic Info -->
                    <div class="tab-pane fade show active" id="tab-add-basic" role="tabpanel" aria-labelledby="add-basic-tab">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="supplier_name" class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="supplier_name" name="supplier_name" required placeholder="Enter supplier name">
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
                                    <label for="supplier_type" class="form-label">Supplier Type</label>
                                    <select class="form-select" id="supplier_type" name="supplier_type">
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
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="supplier_year" class="form-label">Year <span class="text-danger">*</span></label>
                                    <select class="form-select" id="supplier_year" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php 
                                        $current_year = date('Y');
                                        for ($y = $current_year; $y >= $current_year - 10; $y--) {
                                            echo "<option value=\"$y\">$y</option>";
                                        }
                                        ?>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="supplier_year_other_wrap" class="mt-2" style="display:none;">
                                        <input type="text" class="form-control" id="supplier_year_other" name="year_other" placeholder="Ingiza mwaka mwingine...">
                                    </div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="category_id" class="form-label">Category</label>
                                    <select class="form-select select2-enable" id="category_id" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['category_id'] ?>"><?= safe_output($category['category_name']) ?></option>
                                        <?php endforeach; ?>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="category_id_other_wrap" class="mt-2" style="display:none;">
                                        <input type="text" class="form-control" id="category_id_other" name="category_other" placeholder="Ingiza kategoria nyingine...">
                                    </div>
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
                                <div class="col-12 mb-3">
                                    <label for="project_id" class="form-label">Linked Project <span class="text-muted small fw-normal">(Optional)</span></label>
                                    <select class="form-select select2-enable" id="project_id" name="project_id">
                                        <option value="">-- No Project --</option>
                                        <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['project_id'] ?>"><?= safe_output($project['project_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="credit_limit" class="form-label">Credit Limit</label>
                                    <input type="number" class="form-control" id="credit_limit" name="credit_limit" placeholder="0.00" step="0.01">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="2" placeholder="Supplier description or notes"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Contact Details -->
                    <div class="tab-pane fade" id="tab-add-contact" role="tabpanel" aria-labelledby="add-contact-tab">
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
                    <div class="tab-pane fade" id="tab-add-address" role="tabpanel" aria-labelledby="add-address-tab">
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
                                    <label for="council" class="form-label">Council</label>
                                    <input type="text" class="form-control" id="council" name="council" placeholder="e.g. Ilala Municipal">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="ward" class="form-label">Ward</label>
                                    <input type="text" class="form-control" id="ward" name="ward" placeholder="e.g. Kariakoo">
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
                    <div class="tab-pane fade" id="tab-add-financial" role="tabpanel" aria-labelledby="add-financial-tab">
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
                                        <?php foreach ($sup_wht_rates as $w): $pct = rtrim(rtrim(number_format((float)$w['rate_percentage'], 2), '0'), '.'); ?>
                                        <option value="<?= (int)$w['rate_id'] ?>"><?= safe_output($w['rate_name']) ?> (<?= $pct ?>%)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Auto-fills the WHT rate when recording this supplier's payments.</div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="payment_terms" class="form-label">Payment Terms</label>
                                    <div class="other-select-wrap">
                                        <select class="form-select select2-enable other-select" id="payment_terms" name="payment_terms"
                                                data-other-input="payment_terms_input" data-other-name="payment_terms">
                                            <option value="">Select Terms</option>
                                            <option value="cod">Cash on Delivery</option>
                                            <option value="7_days">7 Days</option>
                                            <option value="15_days">15 Days</option>
                                            <option value="30_days">30 Days</option>
                                            <option value="60_days">60 Days</option>
                                            <option value="90_days">90 Days</option>
                                            <option value="other">Other...</option>
                                        </select>
                                        <div class="other-input-wrap" style="display:none;">
                                            <input type="text" class="form-control other-custom-input" id="payment_terms_input" name="payment_terms" placeholder="Specify">
                                            <small class="other-back-link text-primary" style="cursor:pointer;" data-target-select="payment_terms"><i class="bi bi-arrow-left"></i> Back to List
 </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="currency" class="form-label">Currency</label>
                                    <div class="other-select-wrap">
                                        <select class="form-select select2-enable other-select" id="currency" name="currency"
                                                data-other-input="currency_input" data-other-name="currency">
                                            <option value="TZS" selected>Tanzanian Shilling (TZS)</option>
                                            <option value="USD">US Dollar (USD)</option>
                                            <option value="EUR">Euro (EUR)</option>
                                            <option value="GBP">British Pound (GBP)</option>
                                            <option value="KES">Kenyan Shilling (KES)</option>
                                            <option value="other">Other...</option>
                                        </select>
                                        <div class="other-input-wrap" style="display:none;">
                                            <input type="text" class="form-control other-custom-input" id="currency_input" name="currency" placeholder="e.g. AED, CHF...">
                                            <small class="other-back-link text-primary" style="cursor:pointer;" data-target-select="currency"><i class="bi bi-arrow-left"></i> Back to List
 </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="bank_name" class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="bank_account" class="form-label">Bank Account</label>
                                    <input type="text" class="form-control" id="bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label for="bank_address" class="form-label">Bank Address</label>
                                    <textarea class="form-control" id="bank_address" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Suppliers Modal -->
<div class="modal fade" id="importSuppliersModal" tabindex="-1" aria-labelledby="importSuppliersModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="importSuppliersModalLabel">
                    <i class="bi bi-upload"></i> Import Suppliers
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="importSuppliersForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="import-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Download the template file first</li>
                            <li>Fill in the supplier data</li>
                            <li>Upload the completed file</li>
                            <li>File must be in CSV format</li>
                            <li>Maximum file size: 5MB</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_file" class="form-label">Select CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="import_file" name="import_file" accept=".csv" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_action" class="form-label">Import Action</label>
                        <select class="form-select" id="import_action" name="import_action">
                            <option value="add_new">Add New Suppliers Only</option>
                            <option value="update_existing">Update Existing Suppliers</option>
                            <option value="add_update">Add New & Update Existing</option>
                        </select>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="skip_errors" name="skip_errors">
                        <label class="form-check-label" for="skip_errors">
                            Skip rows with errors and continue
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Download Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-upload"></i> Import Suppliers
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1" aria-labelledby="editSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editSupplierModalLabel">
                    <i class="bi bi-pencil"></i> Edit Supplier
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editSupplierForm" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="edit-supplier-message" class="mb-3"></div>
                    <input type="hidden" id="edit_supplier_id" name="supplier_id">
                    
                    <!-- Tabs Navigation -->
                <ul class="nav nav-tabs mb-3" id="editSupplierTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="edit-tab-basic" data-bs-toggle="tab" data-bs-target="#tab-edit-basic" type="button" role="tab" aria-controls="tab-edit-basic" aria-selected="true">
                            <i class="bi bi-info-circle me-1"></i>Basic Info
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="edit-tab-contact" data-bs-toggle="tab" data-bs-target="#tab-edit-contact" type="button" role="tab" aria-controls="tab-edit-contact" aria-selected="false">
                            <i class="bi bi-person-lines-fill me-1"></i>Contact Details
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="edit-tab-address" data-bs-toggle="tab" data-bs-target="#tab-edit-address" type="button" role="tab" aria-controls="tab-edit-address" aria-selected="false">
                            <i class="bi bi-geo-alt me-1"></i>Address
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="edit-tab-financial" data-bs-toggle="tab" data-bs-target="#tab-edit-financial" type="button" role="tab" aria-controls="tab-edit-financial" aria-selected="false">
                            <i class="bi bi-wallet2 me-1"></i>Financial
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="editSupplierTabsContent">
                    <!-- Tab 1: Basic Info -->
                    <div class="tab-pane fade show active" id="tab-edit-basic" role="tabpanel" aria-labelledby="edit-tab-basic">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_supplier_name" class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="edit_supplier_name" name="supplier_name" required placeholder="Enter supplier name">
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
                                    <div id="logo_container" class="mt-2" style="display:none;">
                                        <img id="edit_logo_preview" src="" alt="Logo" class="img-thumbnail" style="height: 50px;">
                                        <button type="button" class="btn btn-sm btn-danger remove-logo-btn" onclick="$('#edit_logo_preview').attr('src', ''); $('#logo_container').hide(); $('#remove_logo').val('1');"><i class="bi bi-trash"></i></button>
                                        <input type="hidden" id="remove_logo" name="remove_logo" value="0">
                                    </div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_supplier_type" class="form-label">Supplier Type</label>
                                    <select class="form-select" id="edit_supplier_type" name="supplier_type">
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
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_supplier_year" class="form-label">Year <span class="text-danger">*</span></label>
                                    <select class="form-select" id="edit_supplier_year" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php 
                                        $current_year = date('Y');
                                        for ($y = $current_year; $y >= $current_year - 10; $y--) {
                                            echo "<option value=\"$y\">$y</option>";
                                        }
                                        ?>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_supplier_year_other_wrap" class="mt-2" style="display:none;">
                                        <input type="text" class="form-control" id="edit_supplier_year_other" name="year_other" placeholder="Ingiza mwaka mwingine...">
                                    </div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_category_id" class="form-label">Category</label>
                                    <select class="form-select select2-enable" id="edit_category_id" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['category_id'] ?>"><?= safe_output($category['category_name']) ?></option>
                                        <?php endforeach; ?>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_category_id_other_wrap" class="mt-2" style="display:none;">
                                        <input type="text" class="form-control" id="edit_category_id_other" name="category_other" placeholder="Ingiza kategoria nyingine...">
                                    </div>
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
                                <div class="col-12 mb-3">
                                    <label for="edit_project_id" class="form-label">Linked Project <span class="text-muted small fw-normal">(Optional)</span></label>
                                    <select class="form-select select2-enable" id="edit_project_id" name="project_id">
                                        <option value="">-- No Project --</option>
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
                                    <textarea class="form-control" id="edit_description" name="description" rows="2" placeholder="Supplier description or notes"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Contact Details -->
                    <div class="tab-pane fade" id="tab-edit-contact" role="tabpanel" aria-labelledby="edit-tab-contact">
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
                    <div class="tab-pane fade" id="tab-edit-address" role="tabpanel" aria-labelledby="edit-tab-address">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_country" class="form-label">Country</label>
                                    <input type="text" class="form-control" id="edit_country" name="country" placeholder="Country">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_state" class="form-label">Region</label>
                                    <input type="text" class="form-control" id="edit_state" name="state" placeholder="Region">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_city" class="form-label">District</label>
                                    <input type="text" class="form-control" id="edit_city" name="city" placeholder="District">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_council" class="form-label">Council</label>
                                    <input type="text" class="form-control" id="edit_council" name="council" placeholder="Council">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_ward" class="form-label">Ward</label>
                                    <input type="text" class="form-control" id="edit_ward" name="ward" placeholder="Ward">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_postal_code" class="form-label">Zip Code</label>
                                    <input type="text" class="form-control" id="edit_postal_code" name="postal_code" placeholder="Postal code">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="edit_address" class="form-label">Physical Address</label>
                                    <textarea class="form-control" id="edit_address" name="address" rows="2" placeholder="Street address"></textarea>
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="edit_postal_address" class="form-label">Postal Address</label>
                                    <input type="text" class="form-control" id="edit_postal_address" name="postal_address" placeholder="Postal address">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Financial -->
                    <div class="tab-pane fade" id="tab-edit-financial" role="tabpanel" aria-labelledby="edit-tab-financial">
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
                                        <?php foreach ($sup_wht_rates as $w): $pct = rtrim(rtrim(number_format((float)$w['rate_percentage'], 2), '0'), '.'); ?>
                                        <option value="<?= (int)$w['rate_id'] ?>"><?= safe_output($w['rate_name']) ?> (<?= $pct ?>%)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Auto-fills the WHT rate when recording this supplier's payments.</div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_payment_terms" class="form-label">Payment Terms</label>
                                    <div class="other-select-wrap">
                                        <select class="form-select select2-enable other-select" id="edit_payment_terms" name="payment_terms"
                                                data-other-input="edit_payment_terms_input" data-other-name="payment_terms">
                                            <option value="">Select Terms</option>
                                            <option value="cod">Cash on Delivery</option>
                                            <option value="7_days">7 Days</option>
                                            <option value="15_days">15 Days</option>
                                            <option value="30_days">30 Days</option>
                                            <option value="60_days">60 Days</option>
                                            <option value="90_days">90 Days</option>
                                            <option value="other">Other...</option>
                                        </select>
                                        <div class="other-input-wrap" style="display:none;">
                                            <input type="text" class="form-control other-custom-input" id="edit_payment_terms_input" name="payment_terms" placeholder="Ingiza masharti mengine...">
                                            <small class="other-back-link text-primary" style="cursor:pointer;" data-target-select="edit_payment_terms"><i class="bi bi-arrow-left"></i> Back to List
 </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_currency" class="form-label">Currency</label>
                                    <div class="other-select-wrap">
                                        <select class="form-select select2-enable other-select" id="edit_currency" name="currency"
                                                data-other-input="edit_currency_input" data-other-name="currency">
                                            <option value="TZS">Tanzanian Shilling (TZS)</option>
                                            <option value="USD">US Dollar (USD)</option>
                                            <option value="EUR">Euro (EUR)</option>
                                            <option value="GBP">British Pound (GBP)</option>
                                            <option value="KES">Kenyan Shilling (KES)</option>
                                            <option value="other">Other...</option>
                                        </select>
                                        <div class="other-input-wrap" style="display:none;">
                                            <input type="text" class="form-control other-custom-input" id="edit_currency_input" name="currency" placeholder="e.g. AED, CHF...">
                                            <small class="other-back-link text-primary" style="cursor:pointer;" data-target-select="edit_currency"><i class="bi bi-arrow-left"></i> Back to List
 </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_bank_name" class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="edit_bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="edit_bank_account" class="form-label">Bank Account</label>
                                    <input type="text" class="form-control" id="edit_bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label for="edit_bank_address" class="form-label">Bank Address</label>
                                    <textarea class="form-control" id="edit_bank_address" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Update Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Scripts are included via header.php and footer.php -->

<script>
$(document).ready(function() {
    // Initialize Select2
    // Initialize Select2 safely
    function initSelect2(selector, modal) {
        try {
            const $modal = $(modal);
            if (!$modal.length) return;
            
            $(selector).each(function() {
                const $this = $(this);
                if ($this.hasClass("select2-hidden-accessible")) return;
                
                $this.select2({
                    theme: 'bootstrap-5',
                    dropdownParent: $modal,
                    width: '100%',
                    placeholder: 'Select or Search...'
                });
            });
        } catch (e) {
            console.warn("Select2 initialization failed for " + selector, e);
        }
    }

    // Initialize for Add Modal
    $('#addSupplierModal').on('shown.bs.modal', function() {
        initSelect2('#addSupplierModal .select2-enable', $('#addSupplierModal'));
        const countrySelect = $('#country');
        if (countrySelect.val()) {
            countrySelect.trigger('change');
        }
    });

    // Initialize for Edit Modal
    $('#editSupplierModal').on('shown.bs.modal', function() {
        initSelect2('#editSupplierModal .select2-enable', $('#editSupplierModal'));
    });

    // Location fields are now text inputs - cascading logic removed.

    // =========================================================
    // Handle "Other" option — inline swap: hide select, show input
    // =========================================================

    // When "Other" is chosen: hide the select, show inline text input in its place
    $(document).on('change', 'select.other-select', function() {
        if ($(this).val() === 'other') {
            var $select   = $(this);
            var inputId   = $select.data('other-input');
            var $wrap     = $select.closest('.other-select-wrap');
            var $inputWrap = $wrap.find('.other-input-wrap');
            var $input    = $wrap.find('#' + inputId);

            // Hide select, remove its name so it won't be submitted
            $select.hide().prop('name', '');
            // Show input area and focus
            $inputWrap.show();
            $input.val('').focus();
        }
    });

    // When "← Rudi" is clicked: restore the select, hide the input
    $(document).on('click', '.other-back-link', function() {
        var selectId  = $(this).data('target-select');
        var $select   = $('#' + selectId);
        var origName  = $select.data('other-name');   // stored original name
        // For year selects, name is always 'year'; category name is category_id; payment_terms; currency
        // We stored correct name in data-other-name — but for category we need category_id not category_other
        // Fix: restore using the select's original attribute captured at init
        var $wrap      = $select.closest('.other-select-wrap');
        var $inputWrap = $wrap.find('.other-input-wrap');
        var $input     = $wrap.find('.other-custom-input');

        // Reset input
        $input.val('').prop('required', false);
        // Restore select name from saved data
        $select.prop('name', $select.data('orig-name')).show().val('').trigger('change.select2');
        $inputWrap.hide();
    });

    // Save each select's original name on DOM ready so we can restore it
    $('select.other-select').each(function() {
        $(this).data('orig-name', $(this).attr('name'));
    });

    // Reset all "other" swap fields (called on modal close)
    function resetOtherFields(containerSelector) {
        if (!containerSelector) return;
        $(containerSelector).find('select.other-select').each(function() {
            var $select   = $(this);
            var $wrap     = $select.closest('.other-select-wrap');
            var $inputWrap = $wrap.find('.other-input-wrap');
            var $input    = $wrap.find('.other-custom-input');

            $input.val('').prop('required', false);
            $inputWrap.hide();
            $select.prop('name', $select.data('orig-name')).show().val('').trigger('change');
            if ($select.hasClass('select2-hidden-accessible')) {
                $select.trigger('change.select2');
            }
        });
    }

    // Data Load Functions removed as location fields are now text inputs.

    // Initialize DataTable
    // Initialize DataTable with safety check
    let suppliersTable;
    if ($('#suppliersTable').length) {
        try {
            suppliersTable = $('#suppliersTable').DataTable({
                language: {
                    search: "Search suppliers:",
                    lengthMenu: "Show _MENU_ suppliers per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ suppliers",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                responsive: true,
                dom: 'rtipB',
                buttons: [
                    {
                        extend: 'copyHtml5',
                        text: '<i class="bi bi-clipboard"></i> Copy',
                        className: 'btn btn-sm btn-primary shadow-sm',
                        titleAttr: 'Copy to clipboard',
                        exportOptions: { columns: ':not(:last-child)' }
                    },
                    {
                        extend: 'excelHtml5',
                        className: 'd-none',
                        exportOptions: {
                            columns: ':not(:last-child)'
                        }
                    },
                    {
                        extend: 'print',
                        className: 'd-none',
                        title: '',
                        exportOptions: {
                            columns: ':not(:last-child)'
                        },
                        customize: function (win) {
                            $(win.document.body).css('font-family', 'Inter, sans-serif');
                            $(win.document.body).find('table').addClass('compact').css('font-size', '10pt');
                                $(win.document.body).prepend(`
                                    <div style="text-align:center; padding: 20px 0; border-bottom: 3px solid #0d6efd; margin-bottom: 20px;">
                                        <?php if ($company_logo): ?>
                                            <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="height: 80px; margin-bottom: 10px;">
                                        <?php endif; ?>
                                        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= htmlspecialchars($company_name) ?></h1>
                                        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Official Suppliers Report</h2>
                                        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: ${new Date().toLocaleString()}</p>
                                    </div>
                                `);

                                // Clone and append stats cards to the print window
                                const statsCards = $('#print-stats-cards').clone();
                                if (statsCards.length) {
                                    statsCards.removeClass('mb-4').addClass('mb-5');
                                    statsCards.find('.col-md-3').css({
                                        'flex': '1 1 25%',
                                        'max-width': '25%',
                                        'width': '25%',
                                        'display': 'inline-block',
                                        'vertical-align': 'top'
                                    });
                                    statsCards.find('.card').css({
                                        'background-color': '#d1e7dd',
                                        'border': '1px solid #badbcc',
                                        'border-radius': '12px',
                                        'padding': '15px',
                                        'margin': '5px'
                                    });
                                    statsCards.find('h4, p, i').css('color', '#0f5132');
                                    $(win.document.body).find('div:first').after(statsCards);
                                }
                        }
                    }
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[1, 'asc']]
            });
        } catch (e) {
            console.error("DataTable initialization failed:", e);
        }
    }

    // Add supplier form submission
    $('#addSupplierForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: 'api/add_supplier.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message || 'Supplier added successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Failed to add supplier.', 'error');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Supplier');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred. Please try again.', 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Supplier');
            }
        });
    });

    // Import form submission
    $('#importSuppliersForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...');

        $.ajax({
            url: 'api/import_suppliers.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    let resultMsg = response.message || 'Suppliers imported successfully.';
                    if (response.results) {
                        resultMsg += `<br><small>Successful: ${response.results.successful}, Failed: ${response.results.failed}</small>`;
                    }
                    Swal.fire({
                        icon: 'success',
                        title: 'Imported!',
                        html: resultMsg,
                        showConfirmButton: true
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Import failed.', 'error');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Suppliers');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred during import.', 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Suppliers');
            }
        });
    });

    // Edit supplier form submission
    $('#editSupplierForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        $.ajax({
            url: 'api/update_supplier.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: response.message || 'Supplier updated successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Update failed.', 'error');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Supplier');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred. Please try again.', 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Supplier');
            }
        });
    });

    // Reset modal on hide
    $('#addSupplierModal').on('hidden.bs.modal', function () {
        $('#addSupplierForm')[0].reset();
        $('#category_id').val('').trigger('change');
        resetOtherFields('#addSupplierModal');
        $('#addSupplierTabs .nav-link:first').tab('show');
    });
    
    $('#importSuppliersModal').on('hidden.bs.modal', function() {
        $('#importSuppliersForm')[0].reset();
        $('#import-message').html('');
        $('#importSuppliersForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Import Suppliers');
    });
    
    $('#editSupplierModal').on('hidden.bs.modal', function() {
        $('#editSupplierForm')[0].reset();
        $('#editSupplierModal .select2-enable').val(null).trigger('change');
        $('#edit-supplier-message').html('');
        $('#editSupplierForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Supplier');
        resetOtherFields('#editSupplierModal');
        $('#editSupplierTabs .nav-link:first').tab('show');
    });

    console.log("Suppliers module initialized successfully.");
});

function applyFilters() {
    const table = $('#suppliersTable').DataTable();
    
    // Status filter
    const status = $('#statusFilter').val();
    if (status) {
        table.column(6).search('^' + status + '$', true, false).draw();
    } else {
        table.column(6).search('').draw();
    }
    
    // Search filter
    const search = $('#searchFilter').val();
    table.search(search).draw();
    
    // Category filter (custom since category is not a direct column)
    const category = $('#categoryFilter').val();
    if (category) {
        table.column(4).search(category).draw();
    } else {
        table.column(4).search('').draw();
    }
    
    // Country and city filters
    const country = $('#countryFilter').val();
    const city = $('#cityFilter').val();
    
    if (country || city) {
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const addressData = data[3].toLowerCase();
            const countryMatch = country ? addressData.includes(country.toLowerCase()) : true;
            const cityMatch = city ? addressData.includes(city.toLowerCase()) : true;
            return countryMatch && cityMatch;
        });
        table.draw();
        $.fn.dataTable.ext.search.pop();
    }
}

function clearFilters() {
    $('#statusFilter, #categoryFilter, #countryFilter, #cityFilter, #searchFilter, #searchSuppliers').val('');
    const table = $('#suppliersTable').DataTable();
    table.search('').columns().search('').draw();
}

function editSupplier(supplierId) {
    // Load supplier data for full edit
    $.ajax({
        url: 'api/get_supplier.php',
        type: 'GET',
        data: { id: supplierId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate edit form
                $('#edit_supplier_id').val(response.data.supplier_id);
                $('#edit_supplier_name').val(response.data.supplier_name);
                $('#edit_company_name').val(response.data.company_name || '');
                $('#edit_acronym').val(response.data.acronym || '');
                $('#edit_supplier_type').val(response.data.supplier_type || '');
                $('#edit_supplier_year').val(response.data.year || '').trigger('change');
                $('#edit_category_id').val(response.data.category_id || '');
                $('#edit_project_id').val(response.data.project_id || '').trigger('change');
                $('#edit_status').val(response.data.status);
                $('#edit_description').val(response.data.description || '');
                
                $('#edit_contact_person').val(response.data.contact_person || '');
                $('#edit_contact_title').val(response.data.contact_title || '');
                $('#edit_email').val(response.data.email || '');
                $('#edit_company_email').val(response.data.company_email || '');
                $('#edit_phone').val(response.data.phone || '');
                $('#edit_mobile').val(response.data.mobile || '');
                $('#edit_fax').val(response.data.fax || '');
                $('#edit_website').val(response.data.website || '');
                
                // Address & Location fields
                $('#edit_address').val(response.data.address || '');
                $('#edit_postal_address').val(response.data.postal_address || '');
                
                // Directly set text values for location fields
                $('#edit_country').val(response.data.country || '');
                $('#edit_state').val(response.data.state || '');
                $('#edit_city').val(response.data.city || '');

                $('#edit_council').val(response.data.council || '');
                $('#edit_ward').val(response.data.ward || '');

                $('#edit_postal_code').val(response.data.postal_code || '');
                
                // Financial & Other
                $('#edit_tax_id').val(response.data.tax_id || '');
                $('#edit_vat_number').val(response.data.vat_number || '');
                $('#edit_default_wht_rate_id').val(response.data.default_wht_rate_id || '');
                
                // Handle custom payment terms
                const pt = response.data.payment_terms || '';
                if (pt && pt !== 'other' && $('#edit_payment_terms option[value="' + pt + '"]').length === 0) {
                    $('#edit_payment_terms').append(new Option(pt, pt, true, true));
                } else {
                    $('#edit_payment_terms').val(pt);
                }
                $('#edit_payment_terms').trigger('change');

                // Handle custom currency
                const curr = response.data.currency || 'TZS';
                if (curr && curr !== 'other' && $('#edit_currency option[value="' + curr + '"]').length === 0) {
                    $('#edit_currency').append(new Option(curr, curr, true, true));
                } else {
                    $('#edit_currency').val(curr || 'TZS');
                }
                $('#edit_currency').trigger('change');

                $('#edit_bank_name').val(response.data.bank_name || '');
                $('#edit_bank_account').val(response.data.bank_account || '');
                $('#edit_bank_address').val(response.data.bank_address || '');
                $('#edit_credit_limit').val(response.data.credit_limit || '0.00');

                // Handle Logo Preview
                if (response.data.logo_path) {
                    $('#edit_logo_preview').attr('src', '<?= buildUrl('') ?>' + response.data.logo_path);
                    $('#logo_container').show();
                    $('#remove_logo').val('0');
                } else {
                    $('#edit_logo_preview').attr('src', '');
                    $('#logo_container').hide();
                    $('#remove_logo').val('0');
                }
                
                // Reset to first tab
                $('#editSupplierTabs .nav-link:first').tab('show');
                
                // Show edit modal
                $('#editSupplierModal').modal('show');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Load Error',
                    text: 'Error loading supplier data: ' + response.message
                });
            }
        },
        error: function(xhr, status, error) {
            Swal.fire({
                icon: 'error',
                title: 'Server Error',
                text: 'Error loading supplier data. Please try again.'
            });
            console.error('Error:', error);
        }
    });
}

function updateStatus(supplierId, status) {
    const actionMap = {
        'active': 'activate',
        'inactive': 'deactivate',
        'suspended': 'suspend',
        'blacklisted': 'blacklist'
    };
    
    const action = actionMap[status] || 'update';
    
    Swal.fire({
        title: 'Are you sure?',
        text: 'Do you want to ' + action + ' this supplier?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, ' + action + ' it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/update_supplier_status.php',
                type: 'POST',
                data: { 
                    supplier_id: supplierId,
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Status Updated',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Update Failed',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Server Error',
                        text: 'Error updating status. Please try again.'
                    });
                }
            });
        }
    });
}

function confirmDelete(supplierId) {
    Swal.fire({
        title: 'Delete Supplier?',
        text: "This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/delete_supplier.php',
                method: 'POST',
                data: { supplier_id: supplierId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'Supplier has been deleted.',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error deleting supplier. Please try again.', 'error');
                }
            });
        }
    });
}

function toggleView(viewType) {
    const isMobile = window.innerWidth <= 767;
    if (isMobile) viewType = 'card';
    const tableView = $('#tableView');
    const cardView = $('#cardView');

    if (viewType === 'table') {
        tableView.removeClass('d-none');
        cardView.addClass('d-none');
        $('#btn-table-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-card-view').removeClass('btn-primary text-white').addClass('btn-light');
    } else {
        tableView.addClass('d-none');
        cardView.removeClass('d-none');
        $('#btn-card-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-table-view').removeClass('btn-primary text-white').addClass('btn-light');
    }

    if (!isMobile) localStorage.setItem('suppliersView', viewType);
}

// Load view preference on page load
$(document).ready(function() {
    const isMobile = window.innerWidth <= 767;
    const savedView = isMobile ? 'card' : (localStorage.getItem('suppliersView') || 'table');
    toggleView(savedView);
    $(window).on('resize', function() { toggleView(window.innerWidth <= 767 ? 'card' : (localStorage.getItem('suppliersView') || 'table')); });

    // Init Select2 for categoryFilter (outside modal)
    if ($('#categoryFilter').length) {
        $('#categoryFilter').select2({
            theme: 'bootstrap-5',
            placeholder: 'All Categories',
            allowClear: true,
            width: '100%'
        });
    }
});

function copyTable() {
    $('#suppliersTable').DataTable().button('.buttons-copy').trigger();
}

function printTable() {
    window.print();
}

function exportSuppliers() {
    $('#suppliersTable').DataTable().button('.buttons-excel').trigger();
}

function downloadTemplate() {
    logReportAction('Downloaded Supplier Template', 'Downloaded the CSV template for supplier imports');
    // Create a CSV template file
    const headers = [
        'supplier_name', 'company_name', 'contact_person', 'contact_title',
        'email', 'phone', 'mobile', 'fax', 'website', 'address', 'city',
        'state', 'country', 'postal_code', 'tax_id', 'vat_number',
        'payment_terms', 'currency', 'bank_name', 'bank_account',
        'bank_address', 'description', 'status'
    ];
    
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\nExample Supplier,Example Corp,John Doe,Manager,john@example.com,+255123456789,+255987654321,,http://example.com,123 Street,Dar es Salaam,Dar es Salaam,Tanzania,12345,TIN123,VAT123,30_days,TZS,Example Bank,123456789,123 Bank Street,Good supplier,active";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "suppliers_import_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Quick search function for DataTables
function quickSearch() {
    const searchValue = $('#searchSuppliers').val();
    $('#suppliersTable').DataTable().search(searchValue).draw();
}

// Bind enter key to search
$('#searchSuppliers').on('keyup', function(e) {
    if (e.keyCode === 13) {
        quickSearch();
    }
});

// Check for action/edit parameter in URL — runs after document is ready so Bootstrap modals are available
$(document).ready(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const action = urlParams.get('action');
    if (action === 'add') {
        // Clean up the URL first to keep it clean
        window.history.replaceState({}, document.title, window.location.pathname);
        // Small delay to ensure Bootstrap modal is fully initialized
        setTimeout(function() {
            var addModal = new bootstrap.Modal(document.getElementById('addSupplierModal'));
            addModal.show();
        }, 300);
    }

    // Check for edit parameter in URL
    const editId = urlParams.get('edit');
    if (editId) {
        editSupplier(editId);
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.dropdown-menu {
    font-size: 0.875rem;
    min-width: 200px;
}

/* Button Group Width Control */
.supplier-action-btns {
    width: auto;
}

@media (max-width: 768px) {
    .supplier-action-btns {
        width: 100% !important;
    }
    .dataTables_paginate {
        display: flex !important;
        justify-content: flex-end !important;
        margin-top: 15px !important;
    }
}
@media (max-width: 767px) {
    .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
}

.dropdown-item {
    padding: 0.25rem 1rem;
}

.dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.table td, .table th {
    padding: 0.6rem 0.4rem !important;
    vertical-align: middle;
    font-size: 0.82rem;
}

.badge {
    font-size: 0.75em;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-success,
.card.bg-info,
.card.bg-warning {
    border: none;
}

/* Card view styling */
#cardView .card {
    transition: transform 0.2s;
}

#cardView .card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

/* Tab styling */
.nav-tabs .nav-link {
    font-size: 0.85rem;
    padding: 0.5rem 1rem;
}

.nav-tabs .nav-link.active {
    font-weight: 600;
}

/* DataTables customization */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    padding: 1rem 0;
}

.dt-buttons {
    margin-bottom: 1rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    /* Scope the stacking behavior to page headers only */
    .page-header .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    .page-header .d-flex.justify-content-between.align-items-center > div:last-child {
        width: 100%;
    }
    
    /* Ensure card buttons stay in a row */
    #cardView .card-footer .d-flex {
        flex-direction: row !important;
    }
    
    #cardView .col-xl-3 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
    
    .nav-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    
    .nav-tabs .nav-item {
        white-space: nowrap;
    }
}

@media (max-width: 576px) {
    .btn-group {
        width: 100%;
        margin-top: 0.5rem;
    }
    
    .btn-group .btn {
        flex: 1;
    }
    
    .table-responsive {
        font-size: 0.85rem;
    } 
}

/* Screen: hide any local print elements if they exist */
.cust-print-footer { display: none; }
@media (max-width: 768px) {
    /* Auto-switch to Card View on Mobile */
    #tableView { display: none !important; }
    #cardView { display: flex !important; }
    
    #btn-table-view { display: none !important; }
    #btn-card-view { display: none !important; }

    .container-fluid {
        overflow-x: hidden !important;
    }
}

@media print {
    /* Page margin: move header upward */
    @page { 
        size: auto; 
        margin: 10mm 10mm 15mm 10mm; 
    }
    body {
        padding-top: 0 !important;
        margin-top: 0 !important;
        background: white !important;
    }

    /* Hide UI elements from print */
    .navbar, .breadcrumb, .btn, .dropdown, 
    .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate,
    .card-header, #filterCollapse, .input-group, .badge.rounded-pill, 
    .d-print-none, .wh-toolbar, .actions-column, .btn-group {
        display: none !important;
    }

    /* Table View must be visible during print, Card View hidden */
    #tableView { display: block !important; padding: 0 !important; width: 100% !important; }
    #cardView { display: none !important; }
    
    /* Buffer row must repeat on every page to prevent footer overlap */
    .cust-print-buf {
        display: table-footer-group !important;
    }
    .cust-print-buf td {
        height: 1.2cm !important;
        border: none !important;
    }
    
    /* Ensure badges wrap and don't overflow during print */
    .badge {
        white-space: normal !important;
        display: inline-block !important;
        text-align: left !important;
        word-break: break-word !important;
    }
    
    .card, .card-body, .table-responsive, #tableView { 
        border: none !important; 
        box-shadow: none !important; 
        padding: 0 !important;
        margin: 0 !important;
        break-inside: auto !important;
        page-break-inside: auto !important;
    }
    
    /* Table styling for print - Use flexible widths to avoid clipping in Portrait */
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;
        font-size: 7.5pt !important;
        break-inside: auto !important;
        page-break-inside: auto !important;
    }
    th, td {
        border: 1px solid #ccc !important;
        padding: 4px 2px !important;
        word-wrap: break-word !important;
        word-break: normal !important;
        white-space: normal !important;
        overflow: visible !important;
        vertical-align: middle !important;
    }
    thead th {
        background-color: #f2f2f2 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        font-weight: bold !important;
        text-transform: uppercase !important;
    }

    /* Standardized widths for print - Using percentages for Portrait/Landscape compatibility */
    .col-sno   { width: 4% !important; }
    .col-info  { width: 11.5% !important; }
    .col-stat  { width: 6% !important; }
    .col-status { width: 9% !important; }

    /* Ensure stats cards are well formatted in print */
    #print-stats-cards {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        background-color: #d1e7dd !important;
        border: 1px solid #badbcc !important;
        border-radius: 8px !important;
        padding: 5px 10px !important;
        margin-bottom: 10px !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    #print-stats-cards > div {
        flex: 1 !important;
        width: 25% !important;
        border: none !important;
    }

    /* Fix header logo sizing */
    .bms-print-header img {
        max-height: 50px !important;
    }
    .bms-print-header h1 { font-size: 18pt !important; }
    .bms-print-header h2 { font-size: 12pt !important; }
}

/* Screen Styles */
.stat-icon-circle {
    width: 48px;
    height: 48px;
    background: rgba(15, 81, 50, 0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0f5132;
    transition: all 0.3s ease;
    flex-shrink: 0;
}
.stat-icon-circle i {
    font-size: 1.5rem;
}
.custom-stat-card:hover .stat-icon-circle {
    background: #0f5132;
    color: #fff;
    transform: scale(1.1);
}

.bg-success-soft {
    background-color: rgba(25, 135, 84, 0.1) !important;
}

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
    font-family: inherit;
    font-weight: 600;
}

.container-fluid {
    padding-left: 20px !important;
    padding-right: 20px !important;
}

@media (max-width: 576px) {
    .container-fluid {
        padding-left: 12px !important;
        padding-right: 12px !important;
    }
}

.card {
    width: 100% !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.table thead th {
    background-color: #f8f9fa !important;
    border-bottom: 2px solid #dee2e6;
    color: #333;
    font-weight: 600;
}

.dt-buttons {
    display: none !important;
}

/* Mobile responsive tabs */
@media (max-width: 576px) {
    .nav-tabs {
        display: flex !important;
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
        border-bottom: 2px solid #dee2e6 !important;
    }
    .nav-tabs::-webkit-scrollbar {
        display: none !important;
    }
    .nav-tabs .nav-item {
        flex: 0 0 auto !important;
    }
    .nav-tabs .nav-link {
        padding: 8px 12px !important;
        font-size: 0.75rem !important;
        white-space: nowrap !important;
    }
}
</style>


<?php
includeFooter();
ob_end_flush();
?>
