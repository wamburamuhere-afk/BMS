<?php
// File: services.php — Non-Inventory Products
// scope-audit: skip — multi-project scope enforced below via p.project_id (NULL = global, IN = scoped)
ob_start();
require_once 'header.php';

requireViewPermission('products');

$can_create = canCreate('products');
$can_edit   = canEdit('products');
$can_delete = canDelete('products');

// Filter parameters
$search      = isset($_GET['search'])   ? trim($_GET['search'])        : '';
$category_id = isset($_GET['category']) ? intval($_GET['category'])    : 0;
$status_filter = isset($_GET['status']) ? $_GET['status']              : 'active';
// Pagination
$per_page_raw = isset($_GET['per_page']) ? $_GET['per_page'] : 25;
$per_page = ($per_page_raw === 'all') ? 1000000 : (int)$per_page_raw;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Base query — NON-INVENTORY ONLY (is_service = 1)
$query = "
    SELECT
        p.product_id, p.product_name, p.sku, p.barcode,
        p.selling_price, p.cost_price, p.status,
        p.is_service, p.track_inventory, p.created_at,
        p.unit, p.description, p.category_id, p.tax_id,
        p.brand_id, p.supplier_id, p.contract_item_no, p.assembly_quantity,
        p.warehouse_id,
        c.category_name,
        s.supplier_name,
        t.rate_name AS tax_name,
        t.rate_percentage AS tax_rate_percentage,
        wh.warehouse_name,
        proj.project_name,
        COALESCE(p.project_id, wh.project_id) AS project_id
    FROM products p
    LEFT JOIN categories c  ON p.category_id  = c.category_id
    LEFT JOIN suppliers s   ON p.supplier_id   = s.supplier_id
    LEFT JOIN tax_rates t   ON p.tax_id        = t.rate_id
    LEFT JOIN warehouses wh ON p.warehouse_id  = wh.warehouse_id
    LEFT JOIN projects proj ON proj.project_id = COALESCE(p.project_id, wh.project_id)
    WHERE p.is_service = 1
";

$params     = [];
$conditions = [];

if ($status_filter !== 'all') {
    $conditions[] = "p.status = :status";
    $params[':status'] = $status_filter;
}
if (!empty($search)) {
    $conditions[] = "(p.product_name LIKE :search OR p.sku LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($category_id > 0) {
    $conditions[] = "p.category_id = :category";
    $params[':category'] = $category_id;
}

// Project scope: NULL = global (visible to all); set = only users assigned to that project
if (empty($_SESSION['scope']['is_admin'])) {
    $sp_ids = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    if (empty($sp_ids)) {
        $conditions[] = '0';
    } else {
        $conditions[] = "(p.project_id IS NULL OR p.project_id IN (" . implode(',', $sp_ids) . "))";
    }
}

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}
$query .= " ORDER BY p.product_name ASC LIMIT :limit OFFSET :offset";

// Count query
$count_query = "SELECT COUNT(*) as total FROM products p WHERE p.is_service = 1";
if (!empty($conditions)) {
    $count_query .= " AND " . implode(" AND ", $conditions);
}
$count_stmt = $pdo->prepare($count_query);
foreach ($params as $k => $v) { $count_stmt->bindValue($k, $v); }
$count_stmt->execute();
$total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_count / $per_page);

// Main query
$stmt = $pdo->prepare($query);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
$stmt->execute();
$all_nip_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Dropdown data
$categories = $pdo->query("SELECT category_id, category_name FROM categories WHERE status='active' AND type='product' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$tax_rates  = $pdo->query("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE status='active' ORDER BY rate_name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers  = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
$brands     = $pdo->query("SELECT brand_id, brand_name FROM brands WHERE status='active' ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);
$units      = $pdo->query("SELECT unit_code, unit_name FROM product_units WHERE status='active' ORDER BY unit_name ASC")->fetchAll(PDO::FETCH_ASSOC);
if (empty($units)) $units = [['unit_code'=>'pcs','unit_name'=>'Pieces']];
// Projects dropdown — admins see all; non-admins see only their assigned projects
if (!empty($_SESSION['scope']['is_admin'])) {
    $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status='active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, project_id FROM warehouses WHERE status='active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $assigned = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    if (empty($assigned)) {
        $projects   = [];
        $warehouses = [];
    } else {
        $ph = implode(',', array_fill(0, count($assigned), '?'));
        $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status='active' AND project_id IN ($ph) ORDER BY project_name");
        $pstmt->execute($assigned);
        $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
        $wstmt = $pdo->prepare("SELECT warehouse_id, warehouse_name, project_id FROM warehouses WHERE status='active' AND (project_id IS NULL OR project_id IN ($ph)) ORDER BY warehouse_name");
        $wstmt->execute($assigned);
        $warehouses = $wstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Pagination URL helper
function svc_pagination_url($p) {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

// SKU / Barcode generators
function generate_svc_sku()     { return 'NIP' . date('ymd') . rand(10,99); }
function generate_svc_barcode() { return '69' . (rand(1000000000, 9999999999)); }
?>

<style>
.svc-header { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); }
.table-hover tbody tr:hover { background:#f0f4ff; }
@media screen { .print-footer { display:none !important; } }
@media print {
    .d-print-none { display:none !important; }
    .print-footer { position:fixed; bottom:0; left:0; right:0; height:1.4cm;
        display:flex; flex-direction:column; justify-content:center; text-align:center;
        background:#fff; border-top:2px solid #0d6efd; font-size:7.5px; }
    
    /* Force Table View for Print */
    #tableView { display: block !important; }
    #cardView { display: none !important; }
}

/* Default View States (Desktop) */
#tableView { display: block; }
#cardView { display: none; }

/* Mobile View: Force Card View */
@media (max-width: 768px) {
    #tableView { display: none !important; }
    #cardView { display: block !important; }
    .btn-group.shadow-sm { display: none !important; } /* Hide toggle buttons on mobile */
}

/* Custom Tab Styling */
.nav-tabs .nav-link { color: #0d6efd !important; border: none !important; border-bottom: 3px solid transparent !important; transition: all 0.3s ease; }
.nav-tabs .nav-link.active { color: #000000 !important; border-bottom: 3px solid #000000 !important; font-weight: bold !important; background: transparent !important; }
    .nav-tabs .nav-link:hover:not(.active) { border-bottom: 3px solid #0d6efd !important; opacity: 0.8; }

    /* Small Buttons on Mobile */
    .btn-mobile-sm {
        padding: 4px 8px !important;
        font-size: 0.7rem !important;
    }
}
</style>

<div class="container-fluid mt-3 pb-5">

    <!-- Print Only Header -->
    <div class="d-none d-print-block">
        <div class="text-center mb-4 pb-3" style="border-bottom: 2px solid #0d6efd;">
          

            <h4 class="text-dark">Non-Inventory Products Report</h4>
            
        </div>
    </div>

    <!-- Page Header -->
    <div class="rounded-4 svc-header text-white p-4 mb-4 d-print-none">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-box-seam me-2"></i>Non-Inventory Products</h4>
                <p class="mb-0 opacity-75 small">Virtual products & services — used in Sales, Invoices and POS only</p>
            </div>
            <div class="d-flex gap-2 flex-nowrap">
                <?php if ($can_create): ?>
                <button type="button" class="btn btn-light fw-bold shadow-sm btn-mobile-sm"
                    onclick="openAddSvcModal()">
                    <i class="bi bi-plus-circle me-1"></i> 
                    <span class="d-none d-md-inline">Add Non-Inventory Product</span>
                    <span class="d-md-none">Add New</span>
                </button>
                <?php endif; ?>
                <button class="btn btn-outline-light btn-sm btn-mobile-sm fw-bold" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
            </div>
        </div>
    </div>

    <!-- Stats row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 text-center">
                    <div class="text-primary fw-bold fs-4"><?= $total_count ?></div>
                    <small class="text-muted">Total Products</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 text-center">
                    <?php
                    $active_count = $pdo->query("SELECT COUNT(*) FROM products WHERE is_service=1 AND status='active'")->fetchColumn();
                    ?>
                    <div class="text-success fw-bold fs-4"><?= $active_count ?></div>
                    <small class="text-muted">Active</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 text-center">
                    <?php
                    $inactive_count = $pdo->query("SELECT COUNT(*) FROM products WHERE is_service=1 AND status='inactive'")->fetchColumn();
                    ?>
                    <div class="text-danger fw-bold fs-4"><?= $inactive_count ?></div>
                    <small class="text-muted">Inactive</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 text-center">
                    <?php
                    $cat_count = $pdo->query("SELECT COUNT(DISTINCT category_id) FROM products WHERE is_service=1 AND category_id IS NOT NULL")->fetchColumn();
                    ?>
                    <div class="text-info fw-bold fs-4"><?= $cat_count ?></div>
                    <small class="text-muted">Categories</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions & Pagination Bar -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-body p-3">
            <div class="row g-3 align-items-center">
                <div class="col-md-8">
                    <form method="GET" class="row g-2">
                        <div class="col-md-5">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control border-0 bg-light" name="search"
                                    placeholder="Search products..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select border-0 bg-light" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>" <?= $category_id==$cat['category_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select border-0 bg-light" name="status">
                                <option value="active"   <?= $status_filter=='active'  ?'selected':'' ?>>Active</option>
                                <option value="inactive" <?= $status_filter=='inactive'?'selected':'' ?>>Inactive</option>
                                <option value="all"      <?= $status_filter=='all'     ?'selected':'' ?>>All</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel"></i>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="d-flex justify-content-md-end align-items-center gap-3">
                        <div class="btn-group shadow-sm d-none d-md-flex" role="group">
                            <button type="button" class="btn btn-primary btn-sm" id="btn-table-view" onclick="toggleView('table')" title="Table View">
                                <i class="bi bi-table"></i>
                            </button>
                            <button type="button" class="btn btn-light btn-sm" id="btn-card-view" onclick="toggleView('card')" title="Card View">
                                <i class="bi bi-grid-3x3-gap"></i>
                            </button>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="small text-muted mb-0">Show:</label>
                            <select class="form-select form-select-sm border-0 bg-light" style="width: auto;" 
                                onchange="updatePerPage(this.value)">
                                <?php foreach ([10, 25, 50, 100, 'all'] as $val): ?>
                                <option value="<?= $val ?>" <?= ($per_page == $val || ($val == 'all' && $per_page > 10000)) ? 'selected' : '' ?>>
                                    <?= ucfirst($val) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card View -->
    <div id="cardView" class="view-section">
        <h5 class="fw-bold mb-3 d-md-none text-primary"><i class="bi bi-grid-3x3-gap me-2"></i>Product Cards</h5>
        <?php if (!empty($all_nip_services)): ?>
            <div class="row g-3">
                <?php foreach ($all_nip_services as $i => $svc): ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card h-100 border-0 shadow-sm rounded-4" style="border: 1px solid #eee !important;">
                        <div class="card-body p-3 d-flex flex-column">
                            <!-- Header: S/NO and Status -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="badge bg-light text-muted border" style="font-size: 0.7rem;">#<?= $offset + $i + 1 ?></span>
                                <?php if ($svc['status'] === 'active'): ?>
                                <span class="badge bg-success" style="font-size: 0.65rem;">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary" style="font-size: 0.65rem;">Inactive</span>
                                <?php endif; ?>
                            </div>

                            <!-- Product Identity -->
                            <div class="mb-3">
                                <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem;"><?= htmlspecialchars($svc['product_name']) ?></h6>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">SKU/Code:</span>
                                    <code class="small fw-bold text-primary" style="font-size: 0.75rem;"><?= htmlspecialchars($svc['sku'] ?? 'N/A') ?></code>
                                </div>
                            </div>

                            <!-- Data Grid: Label Left, Value Right -->
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center border-bottom border-light py-2">
                                    <span class="text-muted small">Project:</span>
                                    <span class="small fw-bold text-end"><?= !empty($svc['project_name']) ? htmlspecialchars($svc['project_name']) : '—' ?></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center border-bottom border-light py-2">
                                    <span class="text-muted small">Tax:</span>
                                    <span class="small fw-bold text-end">
                                        <?= !empty($svc['tax_name']) ? htmlspecialchars($svc['tax_name']) : 'No Tax' ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-2 mb-2">
                                    <span class="text-muted small">Price:</span>
                                    <span class="fw-bold text-primary" style="font-size: 1rem;"><?= format_currency($svc['selling_price']) ?></span>
                                </div>
                            </div>

                            <!-- Actions Row: Smaller Buttons -->
                            <div class="d-flex gap-1 pt-3 mt-auto border-top">
                                <a class="btn btn-sm btn-outline-primary flex-grow-1 py-1" 
                                   href="<?= getUrl('service_view') ?>?id=<?= $svc['product_id'] ?>" 
                                   style="font-size: 0.7rem; border-radius: 6px;">
                                    <i class="bi bi-eye"></i> View
                                </a>
                                <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning flex-grow-1 py-1"
                                    onclick="openEditSvcModal(<?= htmlspecialchars(json_encode($svc)) ?>)"
                                    style="font-size: 0.7rem; border-radius: 6px;">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger flex-grow-1 py-1"
                                    onclick="deleteSvc(<?= $svc['product_id'] ?>, '<?= addslashes($svc['product_name']) ?>')"
                                    style="font-size: 0.7rem; border-radius: 6px;">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            ...

            <!-- Pagination for Card View -->
            <?php if ($total_pages > 1): ?>
            <div class="mt-4 text-center">
                <nav>
                    <ul class="pagination pagination-sm justify-content-center shadow-sm d-inline-flex mb-0 rounded-pill overflow-hidden">
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                            <a class="page-link border-0 px-3" href="<?= svc_pagination_url($i) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-5 bg-white rounded-4 shadow-sm">
                <i class="bi bi-gear display-1 text-muted opacity-25 d-block mb-3"></i>
                <h5 class="fw-bold">No Services Found</h5>
                <p class="text-muted small">Try adjusting your filters.</p>
            </div>
        <?php endif; ?>
    </div> <!-- end cardView -->

    <!-- Table View -->
    <div id="tableView" class="view-section">
        <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <?php if (count($all_nip_services) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="servicesTable" style="width:100%;">
                    <thead class="table-light text-uppercase small fw-bold">
                        <tr>
                            <th class="ps-3" style="width:70px;">S/NO</th>
                            <th>Product Name</th>
                            <th style="width:150px;">Project</th>
                            <th style="width:130px;">Selling Price</th>
                            <th style="width:100px;">Tax</th>
                            <th style="width:90px;">Status</th>
                            <th class="text-end pe-3 d-print-none" style="width:80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                <?php foreach ($all_nip_services as $i => $svc): ?>
                        <tr>
                            <td class="ps-3 text-muted fw-bold text-center"><?= $offset + $i + 1 ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0"
                                        style="width:36px;height:36px;">
                                        <i class="bi bi-gear text-primary"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($svc['product_name']) ?></div>
                                        <?php if (!empty($svc['supplier_name'])): ?>
                                        <small class="text-muted"><?= htmlspecialchars($svc['supplier_name']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($svc['project_name'])): ?>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><?= htmlspecialchars($svc['project_name']) ?></span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold"><?= format_currency($svc['selling_price']) ?></td>
                            <td>
                                <?php if (!empty($svc['tax_name'])): ?>
                                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                    <?= htmlspecialchars($svc['tax_name']) ?> (<?= $svc['tax_rate_percentage'] ?>%)
                                </span>
                                <?php else: ?>
                                <span class="text-muted small">No Tax</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($svc['status'] === 'active'): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3 d-print-none">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light border dropdown-toggle px-2" type="button"
                                        data-bs-toggle="dropdown" style="border-radius:6px;">
                                        <i class="bi bi-gear"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                        <li>
                                            <a class="dropdown-item py-2" href="<?= getUrl('service_view') ?>?id=<?= $svc['product_id'] ?>">
                                                <i class="bi bi-layout-text-window text-primary me-2"></i> View Details
                                            </a>
                                        </li>
                                        <?php if ($can_edit): ?>
                                        <li>
                                            <a class="dropdown-item py-2" href="javascript:void(0)"
                                                onclick="openEditSvcModal(<?= htmlspecialchars(json_encode($svc)) ?>)">
                                                <i class="bi bi-pencil text-warning me-2"></i> Edit Service
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <?php if ($can_delete): ?>
                                        <li>
                                            <a class="dropdown-item py-2 text-danger" href="javascript:void(0)"
                                                onclick="deleteSvc(<?= $svc['product_id'] ?>, '<?= addslashes($svc['product_name']) ?>')">
                                                <i class="bi bi-trash me-2"></i> Delete Service
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center px-3 py-3 border-top d-print-none">
                <small class="text-muted">
                    Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_count) ?> of <?= $total_count ?> items
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= svc_pagination_url(1) ?>"><i class="bi bi-chevron-double-left"></i></a>
                        </li>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= svc_pagination_url($page - 1) ?>"><i class="bi bi-chevron-left"></i></a>
                        </li>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                            <a class="page-link" href="<?= svc_pagination_url($i) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= svc_pagination_url($page + 1) ?>"><i class="bi bi-chevron-right"></i></a>
                        </li>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= svc_pagination_url($total_pages) ?>"><i class="bi bi-chevron-double-right"></i></a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-gear display-1 text-muted opacity-25 d-block mb-3"></i>
                <h5 class="fw-bold text-dark">No Services Found</h5>
                <p class="text-muted small mb-4">
                    <?= !empty($search) ? 'No services match your search.' : 'You have not added any Non-Inventory services yet.' ?>
                </p>
                <?php if ($can_create): ?>
                <button type="button" class="btn btn-primary" onclick="openAddSvcModal()">
                    <i class="bi bi-plus-circle me-1"></i> Add First Service
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div> <!-- end tableView -->
    

    <script>
    function toggleView(view) {
        const tableView = document.getElementById('tableView');
        const cardView = document.getElementById('cardView');
        const btnTable = document.getElementById('btn-table-view');
        const btnCard = document.getElementById('btn-card-view');

        if (view === 'card') {
            tableView.style.setProperty('display', 'none', 'important');
            cardView.style.setProperty('display', 'block', 'important');
            btnCard.classList.replace('btn-light', 'btn-primary');
            btnTable.classList.replace('btn-primary', 'btn-light');
            localStorage.setItem('services_view_preference', 'card');
        } else {
            cardView.style.setProperty('display', 'none', 'important');
            tableView.style.setProperty('display', 'block', 'important');
            btnTable.classList.replace('btn-light', 'btn-primary');
            btnCard.classList.replace('btn-primary', 'btn-light');
            localStorage.setItem('services_view_preference', 'table');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (window.innerWidth > 768) {
            const pref = localStorage.getItem('services_view_preference');
            if (pref === 'card') {
                toggleView('card');
            }
        }
    });
    </script>
</div>


<!-- ══ ADD SERVICE MODAL ═══════════════════════════════════════ -->
<div class="modal fade" id="addServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-plus-circle me-2"></i> Add Non-Inventory Product
<span class="badge bg-white bg-opacity-25 text-white ms-2 small">Non-Inventory</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addServiceForm" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="is_service" value="1">
                <input type="hidden" name="track_inventory" value="0">
                <input type="hidden" name="unit" value="job">
                <input type="hidden" name="status" value="active">
                
                <div class="modal-body p-4">
                    <div id="add-service-message" class="mb-3"></div>

                    <!-- ── NAVIGATION HEADERS ────────────────────────────────── -->
                    <div class="d-flex gap-4 mb-4 border-bottom pb-2 px-1">
                        <h6 class="fw-bold cursor-pointer mb-0 pb-2" id="svc_add_tab1" onclick="toggleSvcAddStep(1)" style="color: #0d6efd; border-bottom: 2px solid #0d6efd; transition: all 0.3s; cursor: pointer;">
                            <i class="bi bi-info-circle me-2"></i>Product Identity
                        </h6>
                        <h6 class="fw-bold cursor-pointer mb-0 pb-2" id="svc_add_tab2" onclick="toggleSvcAddStep(2)" style="color: #000; transition: all 0.3s; cursor: pointer;">
                            <i class="bi bi-cash-stack me-2"></i>Pricing & Planning
                        </h6>
                    </div>

                    <!-- ── STEP 1: IDENTITY ──────────────────────────────────── -->
                    <div id="svc_add_step1">
                        <div class="row g-4 mb-4">
                        <div class="col-md-7 border-end pe-md-4">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-bold small">Non-Inventory Product Name <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-lg bg-light border-0 shadow-sm"
                                        name="product_name" required rows="2" placeholder="e.g. Consulting, Delivery Charge"></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small">Description</label>
                                    <textarea class="form-control bg-light border-0" name="description"
                                        rows="2" placeholder="Describe this service..."></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-primary">Item Code</label>
                                    <input type="text" class="form-control form-control-sm border-0 bg-light fw-bold" name="contract_item_no" placeholder="e.g. ITEM-001">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-primary">Unit</label>
                                    <div id="svc_unit_container">
                                        <select class="form-select form-select-sm fw-bold border border-secondary border-opacity-25" name="unit" id="svc_unit_select" onchange="checkOtherUnit(this, 'svc_unit_container')">
                                            <option value="job">Job</option>
                                            <option value="pcs">Pieces</option>
                                            <option value="set">Set</option>
                                            <option value="box">Box</option>
                                            <option value="ltr">Litre</option>
                                            <option value="kg">Kg</option>
                                            <option value="other">Other (specify)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-primary">Qty</label>
                                    <input type="number" class="form-control form-control-sm border-0 bg-secondary bg-opacity-10 fw-bold" 
                                        name="assembly_quantity" id="svc_assembly_qty" value="1" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-5 ps-md-4">
                            <div class="p-4 bg-primary bg-opacity-10 rounded-4 h-100 border border-primary border-opacity-10">
                                <h5 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-2"></i>Non-Inventory Product</h5>
                                <p class="small text-muted mb-3">This product will be available in:</p>
                                <ul class="list-unstyled small">
                                    <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Sales Orders</li>
                                    <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Invoices</li>
                                    <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>POS</li>
                                    <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Budget</li>
                                    <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i>Warehouse / GRN</li>
                                    <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i>Delivery Notes</li>
                                    <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i>Stock Tracking</li>
                                </ul>
                            </div>
                        </div>
                        </div>
                    </div>

                    <!-- ── STEP 2: PRICING & BOM ──────────────────────────────── -->
                    <div id="svc_add_step2" style="display:none;">

                        <div class="row g-3 mb-4 p-3 bg-white rounded-3 shadow-sm border border-primary border-opacity-10">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-success">Selling Price <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-success text-white">TZS</span>
                                    <input type="number" class="form-control border-0 bg-light fw-bold text-success" name="selling_price" id="svc_sell" value="0.00" step="0.01" required onkeyup="calcSvcMargin()" onchange="calcSvcMargin()">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Cost Price (Auto-Sum)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-light">TZS</span>
                                    <input type="number" class="form-control border-0 bg-secondary bg-opacity-10 fw-bold" name="cost_price" id="svc_cost" value="0.00" step="0.01" readonly>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Tax Rate</label>
                                <select class="form-select form-select-sm border-0 bg-light" name="tax_id">
                                    <option value="">No Tax</option>
                                    <?php foreach ($tax_rates as $tax): ?>
                                    <option value="<?= $tax['rate_id'] ?>"><?= htmlspecialchars($tax['rate_name']) ?> (<?= $tax['rate_percentage'] ?>%)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mt-3 pt-3 border-top">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Select Project (Optional)</label>
                                        <select class="form-select form-select-sm fw-bold shadow-sm border border-secondary border-opacity-25" name="project_id" id="svc_project_id" onchange="filterWarehouses(this.value, 'svc_warehouse_id')">
                                            <option value="">Select Project</option>
                                            <?php foreach ($projects as $p): ?>
                                            <option value="<?= $p['project_id'] ?>"><?= htmlspecialchars($p['project_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Select Warehouse <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-sm fw-bold text-primary shadow-sm border border-primary border-opacity-25" name="warehouse_id" id="svc_warehouse_id" onchange="refreshAllComponentCosts()">
                                            <option value="">Select Warehouse</option>
                                            <?php foreach ($warehouses as $w): ?>
                                            <option value="<?= $w['warehouse_id'] ?>"><?= htmlspecialchars($w['warehouse_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive rounded-3 bg-white shadow-sm border overflow-hidden">
                            <table class="table table-hover align-middle mb-0" id="svcComponentTable">
                                <thead class="bg-dark text-white text-center">
                                    <tr class="small">
                                        <th style="width: 60px;">S/NO</th>
                                        <th class="ps-3" style="width: 45%;">Materials Description</th>
                                        <th style="width: 15%;">Unit</th>
                                        <th style="width: 15%;">Qty per Unit</th>
                                        <th style="width: 15%;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="svcComponentBody">
                                    <!-- Dynamic Rows -->
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="5" class="ps-3 py-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="addSvcComponentRow()">
                                                <i class="bi bi-plus-circle me-1"></i> Add Material Component
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="saveSvcAddAnother()">
                            Save &amp; Add Another
                        </button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold">
                            <i class="bi bi-check-circle me-1"></i> Create Product
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ EDIT PRODUCT MODAL ══════════════════════════════════════ -->
<div class="modal fade" id="editSvcModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-pencil-square me-2"></i> Edit Non-Inventory Product
                    <span class="badge bg-white bg-opacity-25 text-white ms-2 small">Non-Inventory</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editSvcForm" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="product_id" id="edit_svc_id">
                <input type="hidden" name="is_service" value="1">
                <input type="hidden" name="track_inventory" value="0">
                <input type="hidden" name="unit" id="edit_svc_unit" value="job">
                <input type="hidden" name="status" id="edit_svc_status" value="active">

                <div class="modal-body p-4">
                    <div id="edit-svc-message" class="mb-3"></div>

                    <!-- ── NAVIGATION HEADERS ────────────────────────────────── -->
                    <div class="d-flex gap-4 mb-4 border-bottom pb-2 px-1">
                        <h6 class="fw-bold cursor-pointer mb-0 pb-2" id="svc_edit_tab1" onclick="toggleSvcEditStep(1)" style="color: #0d6efd; border-bottom: 2px solid #0d6efd; transition: all 0.3s; cursor: pointer;">
                            <i class="bi bi-info-circle me-2"></i>Product Identity
                        </h6>
                        <h6 class="fw-bold cursor-pointer mb-0 pb-2" id="svc_edit_tab2" onclick="toggleSvcEditStep(2)" style="color: #000; transition: all 0.3s; cursor: pointer;">
                            <i class="bi bi-cash-stack me-2"></i>Pricing & Planning
                        </h6>
                    </div>

                    <!-- ── STEP 1: IDENTITY ──────────────────────────────────── -->
                    <div id="svc_edit_step1">
                        <div class="row g-4 mb-4">
                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold small">Non-Inventory Product Name <span class="text-danger">*</span></label>
                                        <textarea class="form-control form-control-lg bg-light border-0 shadow-sm"
                                            name="product_name" id="edit_svc_name" required rows="2"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold small">Description</label>
                                        <textarea class="form-control bg-light border-0" name="description" id="edit_svc_desc" rows="2"></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Item Code</label>
                                        <input type="text" class="form-control form-control-sm border-0 bg-light fw-bold" name="contract_item_no" id="edit_svc_contract_no">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Unit</label>
                                        <div id="edit_svc_unit_container">
                                            <select class="form-select form-select-sm fw-bold border border-secondary border-opacity-25" name="unit" id="edit_svc_unit" onchange="checkOtherUnit(this, 'edit_svc_unit_container')">
                                                <option value="job">Job</option>
                                                <option value="pcs">Pieces</option>
                                                <option value="set">Set</option>
                                                <option value="box">Box</option>
                                                <option value="ltr">Litre</option>
                                                <option value="kg">Kg</option>
                                                <option value="other">Other (specify)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-primary">Qty</label>
                                        <input type="number" class="form-control form-control-sm border-0 bg-secondary bg-opacity-10 fw-bold" 
                                            name="assembly_quantity" id="edit_svc_assembly_qty" value="1" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-4 bg-primary bg-opacity-10 rounded-4 h-100 border border-primary border-opacity-10">
                                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-2"></i>Non-Inventory Product</h5>
                                    <p class="small text-muted mb-3">This product will be available in:</p>
                                    <ul class="list-unstyled small">
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Sales Orders</li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Invoices</li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>POS</li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i>Budget</li>
                                        <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i>Warehouse / GRN</li>
                                        <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i>Delivery Notes</li>
                                        <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i>Stock Tracking</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── STEP 2: PRICING & BOM ──────────────────────────────── -->
                    <div id="svc_edit_step2" style="display:none;">

                        <div class="row g-3 mb-4 p-3 bg-white rounded-3 shadow-sm border border-primary border-opacity-10">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-success">Selling Price <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-success text-white">TZS</span>
                                    <input type="number" class="form-control border-0 bg-light fw-bold text-success" name="selling_price" id="edit_svc_sell" step="0.01" required onkeyup="calcSvcMarginEdit()" onchange="calcSvcMarginEdit()">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Cost Price (Auto-Sum)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-light">TZS</span>
                                    <input type="number" class="form-control border-0 bg-secondary bg-opacity-10 fw-bold" name="cost_price" id="edit_svc_cost" step="0.01" readonly>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Tax Rate</label>
                                <select class="form-select form-select-sm border-0 bg-light" name="tax_id" id="edit_svc_tax">
                                    <option value="">No Tax</option>
                                    <?php foreach ($tax_rates as $tax): ?>
                                    <option value="<?= $tax['rate_id'] ?>"><?= htmlspecialchars($tax['rate_name']) ?> (<?= $tax['rate_percentage'] ?>%)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mt-3 pt-3 border-top">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Select Project (Optional)</label>
                                        <select class="form-select form-select-sm fw-bold shadow-sm border border-secondary border-opacity-25" name="project_id" id="edit_svc_project_id" onchange="filterWarehouses(this.value, 'edit_svc_warehouse_id')">
                                            <option value="">Select Project</option>
                                            <?php foreach ($projects as $p): ?>
                                            <option value="<?= $p['project_id'] ?>"><?= htmlspecialchars($p['project_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Select Warehouse <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-sm fw-bold text-primary shadow-sm border border-primary border-opacity-25" name="warehouse_id" id="edit_svc_warehouse_id" onchange="refreshAllComponentCostsEdit()">
                                            <option value="">Select Warehouse</option>
                                            <?php foreach ($warehouses as $w): ?>
                                            <option value="<?= $w['warehouse_id'] ?>"><?= htmlspecialchars($w['warehouse_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive rounded-3 bg-white shadow-sm border overflow-hidden">
                            <table class="table table-hover align-middle mb-0" id="editSvcComponentTable">
                                <thead class="bg-dark text-white text-center">
                                    <tr class="small">
                                        <th style="width: 60px;">S/NO</th>
                                        <th class="ps-3" style="width: 45%;">Materials Description</th>
                                        <th style="width: 15%;">Unit</th>
                                        <th style="width: 15%;">Qty per Unit</th>
                                        <th style="width: 15%;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="editSvcComponentBody">
                                    <!-- Dynamic Rows -->
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="5" class="ps-3 py-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="addSvcComponentRowEdit()">
                                                <i class="bi bi-plus-circle me-1"></i> Add Material Component
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
@media print {
    body { background: white !important; color: black !important; margin: 0; padding: 0; }
    .svc-header, .btn, .d-print-none, .dropdown-toggle, .pagination, .modal, .dropdown-menu { display: none !important; }
    .card { border: none !important; box-shadow: none !important; margin-bottom: 10px !important; }
    .table-responsive { overflow: visible !important; }
    table { width: 100% !important; border-collapse: collapse !important; table-layout: fixed !important; }
    th, td { border: 1px solid #dee2e6 !important; padding: 6px 4px !important; font-size: 9pt !important; word-wrap: break-word !important; vertical-align: middle !important; }
    th { background-color: #f8f9fa !important; color: black !important; }
    .badge { border: 1px solid #000 !important; color: black !important; background: transparent !important; }
    .container-fluid { width: 100% !important; max-width: none !important; padding: 0 !important; margin: 0 !important; }
    @page { margin: 1cm; size: auto; }
    
    .row > [class*="col-"] { float: left !important; width: 25% !important; }
    .card-body { padding: 10px !important; }
    .table-responsive table { table-layout: auto !important; }

    /* Fixed Footer for Print */
    .d-print-block.mt-5.pt-3.border-top.text-center {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        width: 100%;
        background: white;
        padding-top: 10px;
        border-top: 1px solid #ccc !important;
    }
}
</style>

<script>
const SVC_APP_URL = '<?= rtrim(getUrl(''), '/') ?>';
const ALL_WAREHOUSES = <?= json_encode($warehouses) ?>;

function filterWarehouses(projectId, targetId) {
    const select = document.getElementById(targetId);
    const currentValue = select.value;
    select.innerHTML = '<option value="">Select Warehouse</option>';
    
    ALL_WAREHOUSES.forEach(w => {
        if (projectId) {
            if (w.project_id == projectId) {
                const opt = new Option(w.warehouse_name, w.warehouse_id);
                if (w.warehouse_id == currentValue) opt.selected = true;
                select.add(opt);
            }
        } else {
            if (!w.project_id || w.project_id == 0) {
                const opt = new Option(w.warehouse_name, w.warehouse_id);
                if (w.warehouse_id == currentValue) opt.selected = true;
                select.add(opt);
            }
        }
    });
}

function checkOtherUnit(select, containerId) {
    if (select.value === 'other') {
        const container = document.getElementById(containerId);
        container.innerHTML = `
            <div class="input-group input-group-sm">
                <input type="text" class="form-control fw-bold border border-secondary border-opacity-25" 
                    name="unit" placeholder="Enter unit..." autofocus required>
                <button class="btn btn-outline-secondary" type="button" onclick="resetUnitSelect('${containerId}')">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        `;
    }
}

function resetUnitSelect(containerId) {
    const container = document.getElementById(containerId);
    const isEdit = containerId.includes('edit');
    const selectId = isEdit ? 'edit_svc_unit' : 'svc_unit_select';
    const onchange = `checkOtherUnit(this, '${containerId}')`;
    
    container.innerHTML = `
        <select class="form-select form-select-sm fw-bold border border-secondary border-opacity-25" 
            name="unit" id="${selectId}" onchange="${onchange}">
            <option value="job">Job</option>
            <option value="pcs">Pieces</option>
            <option value="set">Set</option>
            <option value="box">Box</option>
            <option value="ltr">Litre</option>
            <option value="kg">Kg</option>
            <option value="other">Other (specify)</option>
        </select>
    `;
}

function toggleSvcAddStep(step) {
    if (step === 1) {
        document.getElementById('svc_add_step1').style.display = 'block';
        document.getElementById('svc_add_step2').style.display = 'none';
    } else {
        document.getElementById('svc_add_step1').style.display = 'none';
        document.getElementById('svc_add_step2').style.display = 'block';
    }
}

function toggleSvcEditStep(step) {
    if (step === 1) {
        document.getElementById('svc_edit_step1').style.display = 'block';
        document.getElementById('svc_edit_step2').style.display = 'none';
    } else {
        document.getElementById('svc_edit_step1').style.display = 'none';
        document.getElementById('svc_edit_step2').style.display = 'block';
    }
}

// Assembly Component Logic
let componentRowIndex = 0;

function addSvcComponentRow(data = null) {
    const body = document.getElementById('svcComponentBody');
    const index = componentRowIndex++;
    const row = document.createElement('tr');
    row.id = `comp-row-${index}`;
    
    row.innerHTML = `
        <td class="text-center fw-bold text-muted svc-sno"></td>
        <td class="ps-3 text-center">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control border-start-0 ps-0" placeholder="Search product..." 
                    onkeyup="searchInventoryProduct(this, ${index})" onclick="searchInventoryProduct(this, ${index})" value="${data ? data.product_name : ''}">
                <input type="hidden" name="components[${index}][product_id]" value="${data ? data.product_id : ''}" id="comp-id-${index}">
            </div>
            <div id="search-results-${index}" class="position-absolute bg-white shadow-sm rounded-3 border d-none text-start" style="z-index: 1050; width: 330px; max-height: 200px; overflow-y: auto;"></div>
        </td>
        <td class="text-center">
            <input type="text" class="form-control text-center" name="components[${index}][unit]" value="${data ? data.unit : 'EA'}" id="comp-unit-${index}">
        </td>
        <td class="text-center">
            <input type="number" class="form-control text-center fw-bold" name="components[${index}][qty_per_unit]" value="${data ? data.qty_per_unit : 1}" 
                min="0" step="any" onchange="calculateComponentTotal(${index})" onkeyup="calculateComponentTotal(${index})" id="comp-qty-unit-${index}">
        </td>
        <input type="hidden" name="components[${index}][total_qty]" value="${data ? data.total_qty : 0}" id="comp-total-${index}">
        <input type="hidden" id="comp-cost-${index}" value="${data ? (data.cost_price || 0) : 0}">
        <td class="text-center">
            <button type="button" class="btn btn-outline-danger btn-sm shadow-sm" onclick="removeComponentRow(${index})">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    body.appendChild(row);
    updateSvcSNo();
    if (data) calculateComponentTotal(index);
}

function updateSvcSNo() {
    document.querySelectorAll('#svcComponentBody .svc-sno').forEach((td, i) => {
        td.textContent = i + 1;
    });
}

function removeComponentRow(index) {
    const row = document.getElementById(`comp-row-${index}`);
    if (row) {
        row.remove();
        updateSvcSNo();
        recalculateSvcTotalCost();
    }
}

function searchInventoryProduct(input, index) {
    const query = input.value;
    const resultsDiv = document.getElementById(`search-results-${index}`);
    const warehouseId = document.getElementById('svc_warehouse_id').value;

    if (!warehouseId) {
        resultsDiv.innerHTML = '<div class="p-2 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> Please select a warehouse first</div>';
        resultsDiv.classList.remove('d-none');
        resultsDiv.style.width = "330px";
        return;
    }

    resultsDiv.style.width = "450px"; 
    resultsDiv.innerHTML = '<div class="p-3 text-muted small"><div class="spinner-border spinner-border-sm me-2 text-primary" role="status"></div>Searching...</div>';
    resultsDiv.classList.remove('d-none');

    fetch(`${SVC_APP_URL}/api/account/get_products.php?search=${encodeURIComponent(query)}&warehouse_id=${warehouseId}&is_service=0&active_only=1&limit=10`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                const products = data.data;
                resultsDiv.innerHTML = `
                    <div class="list-group list-group-flush rounded-3 overflow-hidden shadow-sm">
                        ${products.map(prod => {
                            const price = parseFloat(prod.cost_price) || parseFloat(prod.purchase_price) || parseFloat(prod.selling_price) || 0;
                            return `
                                <button type="button" class="list-group-item list-group-item-action p-2 border-bottom" 
                                    onclick='selectSvcInventoryProduct(${index}, ${JSON.stringify(prod).replace(/'/g, "&#39;")})'>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div style="flex: 1; min-width: 0;">
                                            <div class="fw-bold text-dark text-truncate" style="font-size: 13px;">${prod.product_name}</div>
                                            <div class="text-muted small text-truncate" style="font-size: 11px;">
                                                <i class="bi bi-upc-scan me-1"></i>${prod.sku || 'No SKU'}
                                            </div>
                                        </div>
                                        <div class="text-end ms-2" style="white-space: nowrap;">
                                            <span class="badge bg-light text-dark border mb-1 d-block" style="font-size: 10px;">Stock: ${parseFloat(prod.current_stock) || 0}</span>
                                            <span class="fw-bold text-primary d-block" style="font-size: 12px;">TZS ${price.toLocaleString()}</span>
                                        </div>
                                    </div>
                                </button>
                            `;
                        }).join('')}
                    </div>
                `;
                resultsDiv.classList.remove('d-none');
            } else {
                resultsDiv.innerHTML = '<div class="p-3 text-muted small"><i class="bi bi-info-circle me-1"></i> No products found in this warehouse</div>';
                resultsDiv.classList.remove('d-none');
            }
        });
}

function selectSvcInventoryProduct(index, prod) {
    const resultsDiv = document.getElementById(`search-results-${index}`);
    const input = document.querySelector(`[onkeyup="searchInventoryProduct(this, ${index})"]`);
    
    let price = 0;
    if (parseFloat(prod.cost_price) > 0) price = parseFloat(prod.cost_price);
    else if (parseFloat(prod.purchase_price) > 0) price = parseFloat(prod.purchase_price);
    else if (parseFloat(prod.selling_price) > 0) price = parseFloat(prod.selling_price);

    if (input) {
        input.value = prod.product_name;
        document.getElementById(`comp-id-${index}`).value = prod.product_id;
        document.getElementById(`comp-unit-${index}`).value = prod.unit;
        document.getElementById(`comp-cost-${index}`).value = price;
        resultsDiv.classList.add('d-none');
        calculateComponentTotal(index);
    }
}

function calculateComponentTotal(index) {
    const baseQty = parseFloat(document.getElementById('svc_assembly_qty').value) || 0;
    const unitQty = parseFloat(document.getElementById(`comp-qty-unit-${index}`).value) || 0;
    const totalInput = document.getElementById(`comp-total-${index}`);
    if (totalInput) totalInput.value = (baseQty * unitQty).toFixed(2);
    recalculateSvcTotalCost();
}

function recalculateSvcTotalCost() {
    let total = 0;
    document.querySelectorAll('[id^="comp-qty-unit-"]').forEach(input => {
        const index = input.id.replace('comp-qty-unit-', '');
        const unitQty = parseFloat(input.value) || 0;
        const unitCost = parseFloat(document.getElementById(`comp-cost-${index}`).value) || 0;
        total += (unitQty * unitCost);
    });
    const costInput = document.getElementById('svc_cost');
    if (costInput) {
        costInput.value = total.toFixed(2);
        calcSvcMargin();
    }
}

function updateAllComponentTotals() {
    const baseQty = parseFloat(document.getElementById('svc_assembly_qty').value) || 0;
    document.querySelectorAll('[id^="comp-qty-unit-"]').forEach(input => {
        const index = input.id.replace('comp-qty-unit-', '');
        const unitQty = parseFloat(input.value) || 0;
        const totalInput = document.getElementById(`comp-total-${index}`);
        if (totalInput) totalInput.value = (baseQty * unitQty).toFixed(2);
    });
}

// Same for Edit Modal
function addSvcComponentRowEdit(data = null) {
    const body = document.getElementById('editSvcComponentBody');
    const index = componentRowIndex++;
    const row = document.createElement('tr');
    row.id = `edit-comp-row-${index}`;
    
    row.innerHTML = `
        <td class="text-center fw-bold text-muted edit-svc-sno"></td>
        <td class="ps-3 text-center">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control border-start-0 ps-0" placeholder="Search product..." 
                    onkeyup="searchInventoryProductEdit(this, ${index})" onclick="searchInventoryProductEdit(this, ${index})" value="${data ? data.product_name : ''}">
                <input type="hidden" name="components[${index}][product_id]" value="${data ? data.product_id : ''}" id="edit-comp-id-${index}">
            </div>
            <div id="edit-search-results-${index}" class="position-absolute bg-white shadow-sm rounded-3 border d-none text-start" style="z-index: 1050; width: 330px; max-height: 200px; overflow-y: auto;"></div>
        </td>
        <td class="text-center">
            <input type="text" class="form-control text-center" name="components[${index}][unit]" value="${data ? data.unit : 'EA'}" id="edit-comp-unit-${index}">
        </td>
        <td class="text-center">
            <input type="number" class="form-control text-center fw-bold" name="components[${index}][qty_per_unit]" value="${data ? data.qty_per_unit : 1}" 
                min="0" step="any" onchange="calculateComponentTotalEdit(${index})" onkeyup="calculateComponentTotalEdit(${index})" id="edit-comp-qty-unit-${index}">
        </td>
        <input type="hidden" name="components[${index}][total_qty]" value="${data ? data.total_qty : 0}" id="edit-comp-total-${index}">
        <input type="hidden" id="edit-comp-cost-${index}" value="${data ? (data.cost_price || 0) : 0}">
        <td class="text-center">
            <button type="button" class="btn btn-outline-danger btn-sm shadow-sm" onclick="removeComponentRowEdit(${index})">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    body.appendChild(row);
    updateSvcSNoEdit();
    if (data) calculateComponentTotalEdit(index);
}

function updateSvcSNoEdit() {
    document.querySelectorAll('#editSvcComponentBody .edit-svc-sno').forEach((td, i) => {
        td.textContent = i + 1;
    });
}

function removeComponentRowEdit(index) {
    const row = document.getElementById(`edit-comp-row-${index}`);
    if (row) {
        row.remove();
        updateSvcSNoEdit();
        recalculateSvcTotalCostEdit();
    }
}

function calculateComponentTotalEdit(index) {
    const baseQty = parseFloat(document.getElementById('edit_svc_assembly_qty').value) || 0;
    const unitQty = parseFloat(document.getElementById(`edit-comp-qty-unit-${index}`).value) || 0;
    const totalInput = document.getElementById(`edit-comp-total-${index}`);
    if (totalInput) totalInput.value = (baseQty * unitQty).toFixed(2);
    recalculateSvcTotalCostEdit();
}

function recalculateSvcTotalCostEdit() {
    let total = 0;
    document.querySelectorAll('[id^="edit-comp-qty-unit-"]').forEach(input => {
        const index = input.id.replace('edit-comp-qty-unit-', '');
        const unitQty = parseFloat(input.value) || 0;
        const unitCost = parseFloat(document.getElementById(`edit-comp-cost-${index}`).value) || 0;
        total += (unitQty * unitCost);
    });
    const costInput = document.getElementById('edit_svc_cost');
    if (costInput) {
        costInput.value = total.toFixed(2);
        calcSvcMarginEdit();
    }
}

function updateAllComponentTotalsEdit() {
    const baseQty = parseFloat(document.getElementById('edit_svc_assembly_qty').value) || 0;
    document.querySelectorAll('[id^="edit-comp-qty-unit-"]').forEach(input => {
        const index = input.id.replace('edit-comp-qty-unit-', '');
        const unitQty = parseFloat(input.value) || 0;
        const totalInput = document.getElementById(`edit-comp-total-${index}`);
        if (totalInput) totalInput.value = (baseQty * unitQty).toFixed(2);
    });
}

function refreshAllComponentCosts() {
    const warehouseId = document.getElementById('svc_warehouse_id').value;
    if (!warehouseId) return;
    
    document.querySelectorAll('[id^="comp-id-"]').forEach(hiddenInput => {
        const productId = hiddenInput.value;
        if (!productId) return;
        const index = hiddenInput.id.replace('comp-id-', '');
        
        fetch(`${SVC_APP_URL}/api/account/get_products.php?warehouse_id=${warehouseId}&product_id=${productId}`)
            .then(res => res.json())
            .then(json => {
                if (json.success && json.data.length > 0) {
                    const prod = json.data[0];
                    const cost = parseFloat(prod.cost_price) || parseFloat(prod.purchase_price) || parseFloat(prod.selling_price) || 0;
                    document.getElementById(`comp-cost-${index}`).value = cost;
                    recalculateSvcTotalCost();
                }
            });
    });
}

function refreshAllComponentCostsEdit() {
    const warehouseId = document.getElementById('edit_svc_warehouse_id').value;
    if (!warehouseId) return;
    
    document.querySelectorAll('[id^="edit-comp-id-"]').forEach(hiddenInput => {
        const productId = hiddenInput.value;
        if (!productId) return;
        const index = hiddenInput.id.replace('edit-comp-id-', '');
        
        fetch(`${SVC_APP_URL}/api/account/get_products.php?warehouse_id=${warehouseId}&product_id=${productId}`)
            .then(res => res.json())
            .then(json => {
                if (json.success && json.data.length > 0) {
                    const prod = json.data[0];
                    const cost = parseFloat(prod.cost_price) || parseFloat(prod.purchase_price) || parseFloat(prod.selling_price) || 0;
                    document.getElementById(`edit-comp-cost-${index}`).value = cost;
                    recalculateSvcTotalCostEdit();
                }
            });
    });
}

function searchInventoryProductEdit(input, index) {
    const query = input.value;
    const resultsDiv = document.getElementById(`edit-search-results-${index}`);
    const warehouseId = document.getElementById('edit_svc_warehouse_id').value;

    if (!warehouseId) {
        resultsDiv.innerHTML = '<div class="p-2 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> Please select a warehouse first</div>';
        resultsDiv.classList.remove('d-none');
        resultsDiv.style.width = "330px";
        return;
    }

    /* 
    if (query.length < 2) {
        resultsDiv.classList.add('d-none');
        return;
    }
    */

    resultsDiv.style.width = "450px"; 
    resultsDiv.innerHTML = '<div class="p-3 text-muted small"><div class="spinner-border spinner-border-sm me-2 text-primary" role="status"></div>Searching...</div>';
    resultsDiv.classList.remove('d-none');

    fetch(`${SVC_APP_URL}/api/account/get_products.php?search=${encodeURIComponent(query)}&warehouse_id=${warehouseId}&is_service=0&active_only=1&limit=10`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                const products = data.data;
                resultsDiv.innerHTML = `
                    <div class="list-group list-group-flush rounded-3 overflow-hidden shadow-sm">
                        ${products.map(prod => {
                            const price = parseFloat(prod.cost_price) || parseFloat(prod.purchase_price) || parseFloat(prod.selling_price) || 0;
                            return `
                                <button type="button" class="list-group-item list-group-item-action p-2 border-bottom" 
                                    onclick='selectSvcInventoryProductEdit(${index}, ${JSON.stringify(prod).replace(/'/g, "&#39;")})'>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div style="flex: 1; min-width: 0;">
                                            <div class="fw-bold text-dark text-truncate" style="font-size: 13px;">${prod.product_name}</div>
                                            <div class="text-muted small text-truncate" style="font-size: 11px;">
                                                <i class="bi bi-upc-scan me-1"></i>${prod.sku || 'No SKU'}
                                            </div>
                                        </div>
                                        <div class="text-end ms-2" style="white-space: nowrap;">
                                            <span class="badge bg-light text-dark border mb-1 d-block" style="font-size: 10px;">Stock: ${parseFloat(prod.current_stock) || 0}</span>
                                            <span class="fw-bold text-primary d-block" style="font-size: 12px;">TZS ${price.toLocaleString()}</span>
                                        </div>
                                    </div>
                                </button>
                            `;
                        }).join('')}
                    </div>
                `;
                resultsDiv.classList.remove('d-none');
            } else {
                resultsDiv.innerHTML = '<div class="p-3 text-muted small"><i class="bi bi-info-circle me-1"></i> No products found in this warehouse</div>';
                resultsDiv.classList.remove('d-none');
            }
        });
}

function selectSvcInventoryProductEdit(index, prod) {
    const resultsDiv = document.getElementById(`edit-search-results-${index}`);
    const input = document.querySelector(`[onkeyup="searchInventoryProductEdit(this, ${index})"]`);
    
    let price = 0;
    if (parseFloat(prod.cost_price) > 0) price = parseFloat(prod.cost_price);
    else if (parseFloat(prod.purchase_price) > 0) price = parseFloat(prod.purchase_price);
    else if (parseFloat(prod.selling_price) > 0) price = parseFloat(prod.selling_price);

    if (input) {
        input.value = prod.product_name;
        document.getElementById(`edit-comp-id-${index}`).value = prod.product_id;
        document.getElementById(`edit-comp-unit-${index}`).value = prod.unit;
        document.getElementById(`edit-comp-cost-${index}`).value = price;
        resultsDiv.classList.add('d-none');
        calculateComponentTotalEdit(index);
    }
}

function openAddSvcModal() {
    document.getElementById('addServiceForm').reset();
    resetUnitSelect('svc_unit_container');
    document.getElementById('svcComponentBody').innerHTML = '';
    document.getElementById('add-service-message').innerHTML = '';
    addSvcComponentRow(); // Start with one empty row
    new bootstrap.Modal(document.getElementById('addServiceModal')).show();
}

function openEditSvcModal(product) {
    document.getElementById('editSvcForm').reset();
    document.getElementById('edit_svc_id').value = product.product_id;
    document.getElementById('edit_svc_name').value = product.product_name;
    document.getElementById('edit_svc_desc').value = product.description || '';
    
    // Reset and handle custom units
    resetUnitSelect('edit_svc_unit_container');
    const unitSelect = document.getElementById('edit_svc_unit');
    const standardUnits = ['job', 'pcs', 'set', 'box', 'ltr', 'kg'];
    if (product.unit && !standardUnits.includes(product.unit)) {
        unitSelect.value = 'other';
        checkOtherUnit(unitSelect, 'edit_svc_unit_container');
        const customInput = document.querySelector('#edit_svc_unit_container input[name="unit"]');
        if (customInput) customInput.value = product.unit;
    } else {
        unitSelect.value = product.unit || 'job';
    }

    document.getElementById('edit_svc_cost').value = product.cost_price || '0.00';
    document.getElementById('edit_svc_sell').value = product.selling_price || '0.00';
    document.getElementById('edit_svc_tax').value = product.tax_id || '';
    document.getElementById('edit_svc_status').value = product.status || 'active';
    
    document.getElementById('edit_svc_project_id').value = product.project_id || '';
    filterWarehouses(product.project_id, 'edit_svc_warehouse_id'); // Re-filter before setting value
    document.getElementById('edit_svc_warehouse_id').value = product.warehouse_id || '';
    document.getElementById('edit_svc_contract_no').value = product.contract_item_no || '';
    document.getElementById('edit_svc_assembly_qty').value = product.assembly_quantity || 1;
    
    document.getElementById('edit-svc-message').innerHTML = '';
    document.getElementById('editSvcComponentBody').innerHTML = '';
    
    // Fetch components if they exist
    fetch(`${SVC_APP_URL}/api/get_nip_components.php?id=${product.product_id}`)
        .then(res => res.json())
        .then(json => {
            if (json.success && json.data.length > 0) {
                json.data.forEach(comp => addSvcComponentRowEdit(comp));
                // Immediately refresh costs to ensure we are using current warehouse prices
                setTimeout(refreshAllComponentCostsEdit, 100); 
            } else {
                addSvcComponentRowEdit();
            }
        })
        .catch(() => addSvcComponentRowEdit());

    new bootstrap.Modal(document.getElementById('editSvcModal')).show();
    calcSvcMarginEdit();
}

function refreshSvcSKU() {
    document.getElementById('svc_modal_sku').value = 'NIP' + Date.now().toString().slice(-6);
}

function calcSvcMargin() {
    const cost = parseFloat(document.getElementById('svc_cost').value) || 0;
    const sell = parseFloat(document.getElementById('svc_sell').value) || 0;
    const profit = sell - cost;
    const margin = cost > 0 ? ((profit / cost) * 100).toFixed(2) : (sell > 0 ? '100.00' : '0.00');
    document.getElementById('svc_margin_badge').textContent = margin + '%';
    document.getElementById('svc_profit_badge').textContent = 'Profit: TZS ' + profit.toLocaleString();
}

function calcSvcMarginEdit() {
    const cost = parseFloat(document.getElementById('edit_svc_cost').value) || 0;
    const sell = parseFloat(document.getElementById('edit_svc_sell').value) || 0;
    const profit = sell - cost;
    const margin = cost > 0 ? ((profit / cost) * 100).toFixed(2) : (sell > 0 ? '100.00' : '0.00');
    document.getElementById('edit_svc_margin_badge').textContent = margin + '%';
    document.getElementById('edit_svc_profit_badge').textContent = 'Profit: TZS ' + profit.toLocaleString();
}

function submitSvcForm(addAnother) {
    const form = document.getElementById('addServiceForm');
    const msgEl = document.getElementById('add-service-message');
    const name = form.querySelector('[name="product_name"]').value.trim();
    if (!name) {
        msgEl.innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-2"></i>Product name is required.</div>';
        toggleSvcAddStep(1);
        return;
    }
    Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    const data = new FormData(form);
    fetch(`${SVC_APP_URL}/api/create_nip_product.php`, { method:'POST', body:data })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            Swal.fire({ icon:'success', title:'Product Created!', text:res.message || 'Product added successfully.', confirmButtonColor:'#0d6efd' })
            .then(() => {
                if (addAnother) {
                    bootstrap.Modal.getInstance(document.getElementById('addServiceModal')).hide();
                    setTimeout(openAddSvcModal, 300);
                } else {
                    location.reload();
                }
            });
        } else {
            Swal.close();
            msgEl.innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-2"></i>' + (res.message || 'Error saving product.') + '</div>';
        }
    })
    .catch((err) => {
        Swal.close();
        console.error('Create Error:', err);
        msgEl.innerHTML = '<div class="alert alert-danger py-2">Server error: ' + err.message + '</div>';
    });
}

document.getElementById('editSvcForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const msgEl = document.getElementById('edit-svc-message');
    
    Swal.fire({ title:'Saving Changes...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    const data = new FormData(form);
    fetch(`${SVC_APP_URL}/api/update_nip_product.php`, { method:'POST', body:data })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            Swal.fire({ icon:'success', title:'Updated!', text:res.message || 'Product updated successfully.', confirmButtonColor:'#0d6efd' })
            .then(() => location.reload());
        } else {
            Swal.close();
            msgEl.innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-2"></i>' + (res.message || 'Error updating product.') + '</div>';
        }
    })
    .catch(() => {
        Swal.close();
        msgEl.innerHTML = '<div class="alert alert-danger py-2">Server error. Please try again.</div>';
    });
});

function saveSvcAddAnother() { submitSvcForm(true); }

document.getElementById('addServiceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    submitSvcForm(false);
});

function deleteSvc(id, name) {
    Swal.fire({
        title: 'Delete Non-Inventory Product?',
        html: `Are you sure you want to delete <strong>${name}</strong>?<br><small class="text-danger">This action cannot be undone and may affect transaction history if this product was sold.</small>`,
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(`${SVC_APP_URL}/api/delete_product.php`, { product_id: id }, function(res) {
            if (res.success) { Swal.fire({icon:'success',title:'Deleted!',timer:1200,showConfirmButton:false}).then(()=>location.reload()); }
            else { Swal.fire({icon:'error',title:'Error',text:res.message}); }
        }, 'json');
    });
}


function toggleSvcAddStep(step) {
    document.getElementById('svc_add_step1').style.display = (step === 1) ? 'block' : 'none';
    document.getElementById('svc_add_step2').style.display = (step === 2) ? 'block' : 'none';
    
    // Tab Highlighting
    const tab1 = document.getElementById('svc_add_tab1');
    const tab2 = document.getElementById('svc_add_tab2');
    
    if (step === 1) {
        tab1.style.color = '#0d6efd'; tab1.style.borderBottom = '2px solid #0d6efd';
        tab2.style.color = '#000'; tab2.style.borderBottom = 'none';
    } else {
        tab2.style.color = '#0d6efd'; tab2.style.borderBottom = '2px solid #0d6efd';
        tab1.style.color = '#000'; tab1.style.borderBottom = 'none';
    }
}

function toggleSvcEditStep(step) {
    document.getElementById('svc_edit_step1').style.display = (step === 1) ? 'block' : 'none';
    document.getElementById('svc_edit_step2').style.display = (step === 2) ? 'block' : 'none';
    
    // Tab Highlighting
    const tab1 = document.getElementById('svc_edit_tab1');
    const tab2 = document.getElementById('svc_edit_tab2');
    
    if (step === 1) {
        tab1.style.color = '#0d6efd'; tab1.style.borderBottom = '2px solid #0d6efd';
        tab2.style.color = '#000'; tab2.style.borderBottom = 'none';
    } else {
        tab2.style.color = '#0d6efd'; tab2.style.borderBottom = '2px solid #0d6efd';
        tab1.style.color = '#000'; tab1.style.borderBottom = 'none';
    }
}

function updatePerPage(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', val);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}
</script>

<?php includeFooter(); ?>
