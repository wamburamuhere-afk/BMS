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
$per_page_raw = isset($_GET['per_page']) ? $_GET['per_page'] : 'all';
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
$svc_base_query = $query;   // reused for the ?edit=N deep link below

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

// Project scope: NULL = global (visible to all); set = only users assigned to that project.
// Warehouse scope (Phase 6, pos_upgrade_plan.md): NULL = global; set = only
// users granted that warehouse. Both carry their own leading AND, so they are
// appended to each query rather than pushed into $conditions (joined with " AND ").
// This single $scope_sql feeds the table, the count, AND all four stat cards,
// so they can never disagree with each other.
$scope_sql = scopeFilterSqlNullable('project', 'p') . scopeFilterSqlNullable('warehouse', 'p');

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}
$query .= $scope_sql;
$query .= " ORDER BY p.product_name ASC LIMIT :limit OFFSET :offset";

// Count query
$count_query = "SELECT COUNT(*) as total FROM products p WHERE p.is_service = 1";
if (!empty($conditions)) {
    $count_query .= " AND " . implode(" AND ", $conditions);
}
$count_query .= $scope_sql;
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

// Services priced by term show "From <cheapest> · N terms" instead of one price.
require_once ROOT_DIR . '/core/service_price_terms.php';
require_once ROOT_DIR . '/core/vendor_context.php';
require_once ROOT_DIR . '/core/service_plans.php';
$svc_terms = sptSummary($pdo, array_column($all_nip_services, 'product_id'));
$svc_plans = spListSummary($pdo, array_column($all_nip_services, 'product_id'));
$svc_price_html = function (array $svc) use ($svc_terms, $svc_plans): string {
    $pill = fn(string $txt) => ' <span class="badge rounded-pill ms-1" style="background:#e7f0ff;color:#0d6efd;border:1px solid #b6ccfe;font-weight:600;">' . $txt . '</span>';
    $pl = $svc_plans[(int)$svc['product_id']] ?? null;
    if ($pl && $pl['platform_key'] !== null) {
        // Prices live in Superadmin; selling_price is kept in step with its cheapest one.
        return t('From') . ' ' . format_currency($svc['selling_price']) . $pill(t('From Superadmin'));
    }
    $t = $svc_terms[(int)$svc['product_id']] ?? null;
    if (!$t) return format_currency($svc['selling_price']);
    if ($pl && $pl['plans'] > 0) {
        return t('From') . ' ' . format_currency($t['min']) . $pill($pl['plans'] . ' ' . ($pl['plans'] === 1 ? t('plan') : t('plans')));
    }
    return t('From') . ' ' . format_currency($t['min']) . $pill((int)$t['count'] . ' ' . ($t['count'] === 1 ? t('term') : t('terms')));
};
$isVendor = isPlatformVendorContext();
// "This service sells" (BJP's install, admins): link the service to a Superadmin platform product.
$canMapPlatform = $isVendor && isAdmin();
$platformProducts = $isVendor ? spPlatformProducts() : [];
$platformPlans = [];
foreach (array_keys($platformProducts) as $ppk) $platformPlans[$ppk] = spPlatformPlans($ppk);
$contractTemplates = $isVendor
    ? $pdo->query("SELECT id, template_name FROM document_templates WHERE is_active = 1 AND content IS NOT NULL ORDER BY template_name")->fetchAll(PDO::FETCH_ASSOC)
    : [];

// ?edit=N (the service page's Edit button, pos_detail_pages_plan.md C4): open that
// service's Edit form on load — same row shape as the list, same scope.
$edit_svc = null;
if ($can_edit && ctype_digit((string)($_GET['edit'] ?? ''))) {
    $es = $pdo->prepare($svc_base_query . " AND p.product_id = :id AND p.status <> 'deleted'" . $scope_sql);
    $es->execute([':id' => (int)$_GET['edit']]);
    $edit_svc = $es->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Dropdown data
// Filter lists only categories some service actually uses (was every product category) — B4.
$categories = $pdo->query("SELECT category_id, category_name FROM categories WHERE status='active' AND type='product'
    AND category_id IN (SELECT DISTINCT category_id FROM products WHERE is_service = 1 AND status <> 'deleted' AND category_id IS NOT NULL)
    ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$tax_rates  = $pdo->query("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE status='active' ORDER BY rate_name")->fetchAll(PDO::FETCH_ASSOC);
$form_categories = $pdo->query("SELECT category_id, category_name FROM categories WHERE status='active' AND type='product' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers  = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
$brands     = $pdo->query("SELECT brand_id, brand_name FROM brands WHERE status='active' ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);
$units      = $pdo->query("SELECT unit_code, unit_name FROM product_units WHERE status='active' ORDER BY unit_name ASC")->fetchAll(PDO::FETCH_ASSOC);
if (empty($units)) $units = [['unit_code'=>'pcs','unit_name'=>'Pieces']];
// Projects dropdown — admins see all; non-admins see only their assigned projects
$projects = [];
if (projectsModuleActive()) {
    if (!empty($_SESSION['scope']['is_admin'])) {
        $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status='active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $assigned = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
        if (!empty($assigned)) {
            $ph = implode(',', array_fill(0, count($assigned), '?'));
            $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status='active' AND project_id IN ($ph) ORDER BY project_name");
            $pstmt->execute($assigned);
            $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
// Warehouses: shared helper — covers project-derived AND explicitly-granted
// external warehouses (Phase 6, pos_upgrade_plan.md). The hand-rolled version
// this replaced returned zero warehouses for any user with no project
// assignment, even if they'd been explicitly granted one directly.
require_once ROOT_DIR . '/core/warehouse_scope.php';
$warehouses = warehousesForSelect($pdo);

// Simple POS: collapse the Add/Edit Service modals to Name + Amount to Sell
// (+ Shop, only if the user is assigned to more than one). Same combined gate
// as Products (products_simple_pos_plan.md) — a Simple POS tenant that has
// been explicitly switched to "Advanced Product" keeps the full form.
$simpleServiceForm = posSimpleModeEnabled() && !advancedProductEnabled();
$showShopPicker    = count($warehouses) > 1;
$onlyWarehouseId   = (count($warehouses) === 1) ? (int)$warehouses[0]['warehouse_id'] : 0;

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

/* Mobile View: Force Card View
   Scoped to `screen` explicitly — without it, this media query also matches
   print (Portrait A4's print width is commonly <=768px), and since it's
   declared after the `@media print` block above with equal specificity
   (#cardView, both !important), it was winning and showing card view
   instead of the table on print. */
@media screen and (max-width: 768px) {
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
          

            <h4 class="text-dark"><?= wLabel('Non-Inventory Products Report', 'Services Report') ?></h4>
            
        </div>
    </div>

    <!-- Page Header -->
    <div class="rounded-4 svc-header text-white p-4 mb-4 d-print-none">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-box-seam me-2"></i><?= wLabel('Non-Inventory Products', 'Services') ?></h4>
                <p class="mb-0 opacity-75 small"><?= tenantFeatureEnabled('sales') ? wLabel('Virtual products & services — used in Sales, Invoices and POS only', 'Virtual products & services — used in Sales, Invoices and the Sell screen only') : wLabel('Services you sell at the POS', 'Services you sell') ?></p>
            </div>
            <div class="d-flex gap-2 flex-nowrap">
                <?php if ($can_create): ?>
                <button type="button" class="btn btn-light fw-bold shadow-sm btn-mobile-sm"
                    onclick="openAddSvcModal()">
                    <i class="bi bi-plus-circle me-1"></i>
                    <span class="d-none d-md-inline"><?= wLabel('Add Non-Inventory Product', 'Add Service') ?></span>
                    <span class="d-md-none"><?= t('Add New') ?></span>
                </button>
                <?php endif; ?>
                <button class="btn btn-outline-light btn-sm btn-mobile-sm fw-bold" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> <?= t('Print') ?>
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
                    <small class="text-muted"><?= wLabel('Total Products', 'Total Services') ?></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 text-center">
                    <?php
                    $active_count = $pdo->query("SELECT COUNT(*) FROM products p WHERE p.is_service=1 AND p.status='active'" . $scope_sql)->fetchColumn();
                    ?>
                    <div class="text-success fw-bold fs-4"><?= $active_count ?></div>
                    <small class="text-muted"><?= t('Active') ?></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 text-center">
                    <?php
                    $inactive_count = $pdo->query("SELECT COUNT(*) FROM products p WHERE p.is_service=1 AND p.status='inactive'" . $scope_sql)->fetchColumn();
                    ?>
                    <div class="text-danger fw-bold fs-4"><?= $inactive_count ?></div>
                    <small class="text-muted"><?= t('Inactive') ?></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 text-center">
                    <?php
                    $cat_count = $pdo->query("SELECT COUNT(DISTINCT p.category_id) FROM products p WHERE p.is_service=1 AND p.category_id IS NOT NULL" . $scope_sql)->fetchColumn();
                    ?>
                    <div class="text-info fw-bold fs-4"><?= $cat_count ?></div>
                    <small class="text-muted"><?= t('Categories') ?></small>
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
                                <input type="text" class="form-control border-0 bg-light" id="svcTableSearch"
                                    placeholder="<?= wLabel('Search products...', 'Search services...') ?>" autocomplete="off">
                            </div>
                        </div>
                        <div class="col-md-3<?= $categories ? '' : ' d-none' ?>">
                            <select class="form-select border-0 bg-light select2-static" id="svcCategoryFilter" name="category">
                                <option value=""><?= t('All Categories') ?></option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>" <?= $category_id==$cat['category_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select border-0 bg-light" name="status">
                                <option value="active"   <?= $status_filter=='active'  ?'selected':'' ?>><?= t('Active') ?></option>
                                <option value="inactive" <?= $status_filter=='inactive'?'selected':'' ?>><?= t('Inactive') ?></option>
                                <option value="all"      <?= $status_filter=='all'     ?'selected':'' ?>><?= t('All') ?></option>
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
                            <button type="button" class="btn btn-primary btn-sm" id="btn-table-view" onclick="toggleView('table')" title="<?= t('Table View') ?>">
                                <i class="bi bi-table"></i>
                            </button>
                            <button type="button" class="btn btn-light btn-sm" id="btn-card-view" onclick="toggleView('card')" title="<?= t('Card View') ?>">
                                <i class="bi bi-grid-3x3-gap"></i>
                            </button>
                        </div>
                        <div class="d-flex align-items-center gap-2 d-none">
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Card View -->
    <div id="cardView" class="view-section">
        <h5 class="fw-bold mb-3 d-md-none text-primary"><i class="bi bi-grid-3x3-gap me-2"></i><?= t('Product Cards') ?></h5>
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
                                <span class="badge bg-success" style="font-size: 0.65rem;"><?= t('Active') ?></span>
                                <?php else: ?>
                                <span class="badge bg-secondary" style="font-size: 0.65rem;"><?= t('Inactive') ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Product Identity -->
                            <div class="mb-3">
                                <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem;"><?= caseFormat($svc['product_name']) ?></h6>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small"><?= t('SKU/Code:') ?></span>
                                    <code class="small fw-bold text-primary" style="font-size: 0.75rem;"><?= htmlspecialchars($svc['sku'] ?? 'N/A') ?></code>
                                </div>
                            </div>

                            <!-- Data Grid: Label Left, Value Right -->
                            <div class="flex-grow-1">
                                <?php if (projectsModuleActive()): ?>
                                <div class="d-flex justify-content-between align-items-center border-bottom border-light py-2">
                                    <span class="text-muted small"><?= t('Project:') ?></span>
                                    <span class="small fw-bold text-end"><?= !empty($svc['project_name']) ? caseFormat($svc['project_name']) : '—' ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between align-items-center border-bottom border-light py-2">
                                    <span class="text-muted small"><?= t('Tax:') ?></span>
                                    <span class="small fw-bold text-end">
                                        <?= !empty($svc['tax_name']) ? caseFormat($svc['tax_name']) : t('No Tax') ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-2 mb-2">
                                    <span class="text-muted small"><?= t('Price:') ?></span>
                                    <span class="fw-bold text-primary" style="font-size: 1rem;"><?= $svc_price_html($svc) ?></span>
                                </div>
                            </div>

                            <!-- Actions Row: Smaller Buttons -->
                            <div class="d-flex gap-1 pt-3 mt-auto border-top">
                                <a class="btn btn-sm btn-outline-primary flex-grow-1 py-1"
                                   href="<?= getUrl('service_view') ?>?id=<?= $svc['product_id'] ?>"
                                   style="font-size: 0.7rem; border-radius: 6px;">
                                    <i class="bi bi-eye"></i> <?= t('View') ?>
                                </a>
                                <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning flex-grow-1 py-1"
                                    onclick="openEditSvcModal(<?= htmlspecialchars(json_encode($svc)) ?>)"
                                    style="font-size: 0.7rem; border-radius: 6px;">
                                    <i class="bi bi-pencil"></i> <?= t('Edit') ?>
                                </button>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger flex-grow-1 py-1"
                                    onclick="deleteSvc(<?= $svc['product_id'] ?>, '<?= addslashes($svc['product_name']) ?>')"
                                    style="font-size: 0.7rem; border-radius: 6px;">
                                    <i class="bi bi-trash"></i> <?= t('Delete') ?>
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
                <h5 class="fw-bold"><?= t('No Services Found') ?></h5>
                <p class="text-muted small"><?= t('Try adjusting your filters.') ?></p>
            </div>
        <?php endif; ?>
    </div> <!-- end cardView -->

    <!-- Table View -->
    <div id="tableView" class="view-section">
        <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div>
                <table class="table table-hover align-middle mb-0" id="servicesTable" style="width:100%; table-layout:fixed;">
                    <thead class="table-light text-uppercase small fw-bold">
                        <tr>
                            <th class="ps-3" style="width:50px;"><?= t('S/NO') ?></th>
                            <th style="width:110px;"><?= t('Item Code') ?></th>
                            <th><?= wLabel('Product Name', 'Service Name') ?></th>
                            <th style="width:120px;" class="<?= projectsModuleActive() ? '' : 'd-none' ?>"><?= t('Project') ?></th>
                            <th style="width:120px;"><?= t('Selling Price') ?></th>
                            <th style="width:90px;"><?= t('Tax') ?></th>
                            <th style="width:80px;"><?= t('Status') ?></th>
                            <th class="text-end pe-3 d-print-none" style="width:70px;"><?= t('Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                <?php foreach ($all_nip_services as $i => $svc): ?>
                        <tr>
                            <td class="ps-3 text-muted fw-bold text-center"><?= $offset + $i + 1 ?></td>
                            <td><code class="small fw-bold text-primary"><?= htmlspecialchars($svc['contract_item_no'] ?? '—') ?></code></td>
                            <td style="word-break:break-word; overflow-wrap:break-word; white-space:normal;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0"
                                        style="width:36px;height:36px;">
                                        <i class="bi bi-gear text-primary"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?= caseFormat($svc['product_name']) ?></div>
                                        <?php if (!empty($svc['supplier_name'])): ?>
                                        <small class="text-muted"><?= caseFormat($svc['supplier_name']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="<?= projectsModuleActive() ? '' : 'd-none' ?>">
                                <?php if (!empty($svc['project_name'])): ?>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><?= caseFormat($svc['project_name']) ?></span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold"><?= $svc_price_html($svc) ?></td>
                            <td>
                                <?php if (!empty($svc['tax_name'])): ?>
                                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                    <?= caseFormat($svc['tax_name']) ?> (<?= $svc['tax_rate_percentage'] ?>%)
                                </span>
                                <?php else: ?>
                                <span class="text-muted small"><?= t('No Tax') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($svc['status'] === 'active'): ?>
                                <span class="badge bg-success"><?= t('Active') ?></span>
                                <?php else: ?>
                                <span class="badge bg-secondary"><?= t('Inactive') ?></span>
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
                                                <i class="bi bi-layout-text-window text-primary me-2"></i> <?= t('View Details') ?>
                                            </a>
                                        </li>
                                        <?php if ($can_edit): ?>
                                        <li>
                                            <a class="dropdown-item py-2" href="javascript:void(0)"
                                                onclick="openEditSvcModal(<?= htmlspecialchars(json_encode($svc)) ?>)">
                                                <i class="bi bi-pencil text-warning me-2"></i> <?= t('Edit Service') ?>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <?php if ($can_delete): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item py-2 text-danger" href="javascript:void(0)"
                                                onclick="deleteSvc(<?= $svc['product_id'] ?>, '<?= addslashes($svc['product_name']) ?>')">
                                                <i class="bi bi-trash me-2"></i> <?= t('Delete Service') ?>
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
                    <i class="bi bi-plus-circle me-2"></i> <?= wLabel('Add Non-Inventory Product', 'Add Service') ?>
<span class="badge bg-white bg-opacity-25 text-white ms-2 small"><?= wLabel('Non-Inventory', 'Service') ?></span>
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
                    <?php if (!$simpleServiceForm): ?>
                    <div class="d-flex gap-4 mb-4 border-bottom pb-2 px-1">
                        <h6 class="fw-bold cursor-pointer mb-0 pb-2" id="svc_add_tab1" onclick="toggleSvcAddStep(1)" style="color: #0d6efd; border-bottom: 2px solid #0d6efd; transition: all 0.3s; cursor: pointer;">
                            <i class="bi bi-info-circle me-2"></i><?= t('Product Identity') ?>
                        </h6>
                        <h6 class="fw-bold cursor-pointer mb-0 pb-2" id="svc_add_tab2" onclick="toggleSvcAddStep(2)" style="color: #000; transition: all 0.3s; cursor: pointer;">
                            <i class="bi bi-cash-stack me-2"></i><?= t('Pricing & Planning') ?>
                        </h6>
                    </div>
                    <?php endif; ?>

                    <!-- ── STEP 1: IDENTITY ──────────────────────────────────── -->
                    <div id="svc_add_step1">
                        <div class="row g-4 mb-4">
                        <div class="<?= $simpleServiceForm ? 'col-12' : 'col-md-7 border-end pe-md-4' ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-bold small"><?= wLabel('Non-Inventory Product Name', 'Service Name') ?> <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-lg bg-light border-0 shadow-sm"
                                        name="product_name" required rows="2" placeholder="<?= t('e.g. Consulting, Delivery Charge') ?>"></textarea>
                                </div>
                                <div class="col-12<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                    <label class="form-label fw-bold small"><?= t('Description') ?></label>
                                    <textarea class="form-control bg-light border-0" name="description"
                                        rows="2" placeholder="<?= t('Describe this service...') ?>"></textarea>
                                </div>
                                <?php if (!$simpleServiceForm): ?>
                                <div class="col-12">
                                    <label class="form-label fw-bold small"><?= t('Category') ?> <span class="text-muted fw-normal">(<?= t('optional') ?>)</span></label>
                                    <select class="form-select form-select-sm bg-light border-0" name="category_id" id="svc_category_id">
                                        <option value=""><?= t('Uncategorised') ?></option>
                                        <?php foreach ($form_categories as $fc): ?>
                                        <option value="<?= (int)$fc['category_id'] ?>"><?= htmlspecialchars($fc['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                    <label class="form-label fw-bold small text-primary"><?= t('Item Code') ?></label>
                                    <input type="text" class="form-control form-control-sm border-0 bg-secondary bg-opacity-10 fw-bold text-muted" name="contract_item_no" placeholder="<?= t('Auto-generated on save') ?>" readonly>
                                </div>
                                <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                    <label class="form-label fw-bold small text-primary"><?= t('Unit') ?></label>
                                    <div id="svc_unit_container">
                                        <select class="form-select form-select-sm fw-bold border border-secondary border-opacity-25" name="unit" id="svc_unit_select" onchange="checkOtherUnit(this, 'svc_unit_container')">
                                            <option value="job"><?= t('Job') ?></option>
                                            <option value="pcs"><?= t('Pieces') ?></option>
                                            <option value="set"><?= t('Set') ?></option>
                                            <option value="box"><?= t('Box') ?></option>
                                            <option value="ltr"><?= t('Litre') ?></option>
                                            <option value="kg"><?= t('Kg') ?></option>
                                            <option value="other"><?= t('Other (specify)') ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                    <label class="form-label fw-bold small text-primary"><?= t('Qty') ?></label>
                                    <input type="number" class="form-control form-control-sm border-0 bg-secondary bg-opacity-10 fw-bold"
                                        name="assembly_quantity" id="svc_assembly_qty" value="1" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-5 ps-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                            <div class="p-4 bg-primary bg-opacity-10 rounded-4 h-100 border border-primary border-opacity-10">
                                <h5 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-2"></i><?= wLabel('Non-Inventory Product', 'Service') ?></h5>
                                <p class="small text-muted mb-3"><?= t('This product will be available in:') ?></p>
                                <ul class="list-unstyled small">
                                    <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i><?= t('Sales Orders') ?></li>
                                    <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i><?= t('Invoices') ?></li>
                                    <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i><?= t('POS') ?></li>
                                    <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i><?= t('Budget') ?></li>
                                    <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i><?= wLabel('Warehouse / GRN', 'Shop / GRN') ?></li>
                                    <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i><?= t('Delivery Notes') ?></li>
                                    <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i><?= t('Stock Tracking') ?></li>
                                </ul>
                            </div>
                        </div>
                        </div>
                    </div>

                    <!-- ── STEP 2: PRICING & BOM ──────────────────────────────── -->
                    <div id="svc_add_step2" style="<?= $simpleServiceForm ? '' : 'display:none;' ?>">
                        <?php if (!$simpleServiceForm) { $sptPrefix = 'svc'; include ROOT_DIR . '/includes/service_type_switches.php'; } ?>

                        <div class="row g-3 mb-4 p-3 bg-white rounded-3 shadow-sm border border-primary border-opacity-10">
                            <div class="<?= $simpleServiceForm ? 'col-12' : 'col-md-4' ?>">
                                <label class="form-label fw-bold small text-success"><?= $simpleServiceForm ? t('Amount to Sell') : t('Selling Price') ?> <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-success text-white"><?= htmlspecialchars(bmsCompanyCurrency()) ?></span>
                                    <input type="number" class="form-control border-0 bg-light fw-bold text-success" name="selling_price" id="svc_sell" value="0.00" step="0.01" required onkeyup="calcSvcMargin()" onchange="calcSvcMargin()">
                                </div>
                            </div>
                            <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                <label class="form-label fw-bold small text-muted"><?= t('Cost Price (Auto-Sum)') ?></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-light"><?= htmlspecialchars(bmsCompanyCurrency()) ?></span>
                                    <input type="number" class="form-control border-0 bg-secondary bg-opacity-10 fw-bold" name="cost_price" id="svc_cost" value="0.00" step="0.01" readonly>
                                </div>
                            </div>
                            <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                <label class="form-label fw-bold small"><?= t('Tax Rate') ?></label>
                                <select class="form-select form-select-sm border-0 bg-light" name="tax_id" id="svc_tax_id">
                                    <option value=""><?= t('No Tax') ?></option>
                                    <?php foreach ($tax_rates as $tax): ?>
                                    <option value="<?= $tax['rate_id'] ?>"><?= htmlspecialchars($tax['rate_name']) ?> (<?= $tax['rate_percentage'] ?>%)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mt-3 pt-3 border-top">
                                <div class="row g-3">
                                    <?php if (projectsModuleActive()): ?>
                                    <div class="col-md-6<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                        <label class="form-label fw-bold small"><?= t('Select Project (Optional)') ?></label>
                                        <select class="form-select form-select-sm fw-bold shadow-sm border border-secondary border-opacity-25" name="project_id" id="svc_project_id" onchange="filterWarehouses(this.value, 'svc_warehouse_id')">
                                            <option value=""><?= t('Select Project') ?></option>
                                            <?php foreach ($projects as $p): ?>
                                            <option value="<?= $p['project_id'] ?>"><?= htmlspecialchars($p['project_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($simpleServiceForm && !$showShopPicker): ?>
                                    <input type="hidden" name="warehouse_id" id="svc_warehouse_id" value="<?= $onlyWarehouseId ?>">
                                    <?php else: ?>
                                    <div class="col-md-<?= ($simpleServiceForm || !projectsModuleActive()) ? 12 : 6 ?>">
                                        <label class="form-label fw-bold small"><?= wLabel('Select Warehouse', 'Select Shop') ?> <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-sm fw-bold text-primary shadow-sm border border-primary border-opacity-25" name="warehouse_id" id="svc_warehouse_id" onchange="refreshAllComponentCosts()">
                                            <option value=""><?= wLabel('Select Warehouse', 'Select Shop') ?></option>
                                            <?php foreach ($warehouses as $w): ?>
                                            <option value="<?= $w['warehouse_id'] ?>"><?= htmlspecialchars($w['warehouse_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!$simpleServiceForm) { $sptPrefix = 'svc'; include ROOT_DIR . '/includes/service_price_terms_editor.php'; } ?>

                        <div class="table-responsive rounded-3 bg-white shadow-sm border overflow-hidden<?= $simpleServiceForm ? ' d-none' : '' ?>">
                            <table class="table table-hover align-middle mb-0" id="svcComponentTable">
                                <thead class="bg-dark text-white text-center">
                                    <tr class="small">
                                        <th style="width: 60px;"><?= t('S/NO') ?></th>
                                        <th class="ps-3" style="width: 45%;"><?= t('Materials Description') ?></th>
                                        <th style="width: 15%;"><?= t('Unit') ?></th>
                                        <th style="width: 15%;"><?= t('Qty per Unit') ?></th>
                                        <th style="width: 15%;"><?= t('Action') ?></th>
                                    </tr>
                                </thead>
                                <tbody id="svcComponentBody">
                                    <!-- Dynamic Rows -->
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="5" class="ps-3 py-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="addSvcComponentRow()">
                                                <i class="bi bi-plus-circle me-1"></i> <?= t('Add Material Component') ?>
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="saveSvcAddAnother()">
                            <?= t('Save & Add Another') ?>
                        </button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold">
                            <i class="bi bi-check-circle me-1"></i> <?= t('Create Product') ?>
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
                    <i class="bi bi-pencil-square me-2"></i> <?= wLabel('Edit Non-Inventory Product', 'Edit Service') ?>
                    <span class="badge bg-white bg-opacity-25 text-white ms-2 small"><?= wLabel('Non-Inventory', 'Service') ?></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editSvcForm" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="product_id" id="edit_svc_id">
                <input type="hidden" name="is_service" value="1">
                <input type="hidden" name="track_inventory" value="0">
                <input type="hidden" name="status" id="edit_svc_status" value="active">

                <div class="modal-body p-4">
                    <div id="edit-svc-message" class="mb-3"></div>

                    <!-- ── NAVIGATION HEADERS ────────────────────────────────── -->
                    <?php if (!$simpleServiceForm): ?>
                    <div class="d-flex gap-4 mb-4 border-bottom pb-2 px-1">
                        <h6 class="fw-bold cursor-pointer mb-0 pb-2" id="svc_edit_tab1" onclick="toggleSvcEditStep(1)" style="color: #0d6efd; border-bottom: 2px solid #0d6efd; transition: all 0.3s; cursor: pointer;">
                            <i class="bi bi-info-circle me-2"></i><?= t('Product Identity') ?>
                        </h6>
                        <h6 class="fw-bold cursor-pointer mb-0 pb-2" id="svc_edit_tab2" onclick="toggleSvcEditStep(2)" style="color: #000; transition: all 0.3s; cursor: pointer;">
                            <i class="bi bi-cash-stack me-2"></i><?= t('Pricing & Planning') ?>
                        </h6>
                    </div>
                    <?php endif; ?>

                    <!-- ── STEP 1: IDENTITY ──────────────────────────────────── -->
                    <div id="svc_edit_step1">
                        <div class="row g-4 mb-4">
                            <div class="<?= $simpleServiceForm ? 'col-12' : 'col-md-8' ?>">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold small"><?= wLabel('Non-Inventory Product Name', 'Service Name') ?> <span class="text-danger">*</span></label>
                                        <textarea class="form-control form-control-lg bg-light border-0 shadow-sm"
                                            name="product_name" id="edit_svc_name" required rows="2"></textarea>
                                    </div>
                                    <div class="col-12<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                        <label class="form-label fw-bold small"><?= t('Description') ?></label>
                                        <textarea class="form-control bg-light border-0" name="description" id="edit_svc_desc" rows="2"></textarea>
                                    </div>
                                    <?php if (!$simpleServiceForm): ?>
                                    <div class="col-12">
                                        <label class="form-label fw-bold small"><?= t('Category') ?> <span class="text-muted fw-normal">(<?= t('optional') ?>)</span></label>
                                        <select class="form-select form-select-sm bg-light border-0" name="category_id" id="edit_svc_category_id">
                                            <option value=""><?= t('Uncategorised') ?></option>
                                            <?php foreach ($form_categories as $fc): ?>
                                            <option value="<?= (int)$fc['category_id'] ?>"><?= htmlspecialchars($fc['category_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                        <label class="form-label fw-bold small text-primary"><?= t('Item Code') ?></label>
                                        <input type="text" class="form-control form-control-sm border-0 bg-secondary bg-opacity-10 fw-bold text-muted" name="contract_item_no" id="edit_svc_contract_no" readonly>
                                    </div>
                                    <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                        <label class="form-label fw-bold small text-primary"><?= t('Unit') ?></label>
                                        <div id="edit_svc_unit_container">
                                            <select class="form-select form-select-sm fw-bold border border-secondary border-opacity-25" name="unit" id="edit_svc_unit" onchange="checkOtherUnit(this, 'edit_svc_unit_container')">
                                                <option value="job"><?= t('Job') ?></option>
                                                <option value="pcs"><?= t('Pieces') ?></option>
                                                <option value="set"><?= t('Set') ?></option>
                                                <option value="box"><?= t('Box') ?></option>
                                                <option value="ltr"><?= t('Litre') ?></option>
                                                <option value="kg"><?= t('Kg') ?></option>
                                                <option value="other"><?= t('Other (specify)') ?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                        <label class="form-label fw-bold small text-primary"><?= t('Qty') ?></label>
                                        <input type="number" class="form-control form-control-sm border-0 bg-secondary bg-opacity-10 fw-bold"
                                            name="assembly_quantity" id="edit_svc_assembly_qty" value="1" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                <div class="p-4 bg-primary bg-opacity-10 rounded-4 h-100 border border-primary border-opacity-10">
                                    <h5 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-2"></i><?= wLabel('Non-Inventory Product', 'Service') ?></h5>
                                    <p class="small text-muted mb-3"><?= t('This product will be available in:') ?></p>
                                    <ul class="list-unstyled small">
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i><?= t('Sales Orders') ?></li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i><?= t('Invoices') ?></li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i><?= t('POS') ?></li>
                                        <li class="mb-2 d-flex align-items-center"><i class="bi bi-check-circle-fill text-success me-3"></i><?= t('Budget') ?></li>
                                        <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i><?= wLabel('Warehouse / GRN', 'Shop / GRN') ?></li>
                                        <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i><?= t('Delivery Notes') ?></li>
                                        <li class="mb-2 d-flex align-items-center text-muted"><i class="bi bi-x-circle-fill text-danger me-3"></i><?= t('Stock Tracking') ?></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── STEP 2: PRICING & BOM ──────────────────────────────── -->
                    <div id="svc_edit_step2" style="<?= $simpleServiceForm ? '' : 'display:none;' ?>">
                        <?php if (!$simpleServiceForm) { $sptPrefix = 'edit_svc'; include ROOT_DIR . '/includes/service_type_switches.php'; } ?>

                        <div class="row g-3 mb-4 p-3 bg-white rounded-3 shadow-sm border border-primary border-opacity-10">
                            <div class="<?= $simpleServiceForm ? 'col-12' : 'col-md-4' ?>">
                                <label class="form-label fw-bold small text-success"><?= $simpleServiceForm ? t('Amount to Sell') : t('Selling Price') ?> <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-success text-white"><?= htmlspecialchars(bmsCompanyCurrency()) ?></span>
                                    <input type="number" class="form-control border-0 bg-light fw-bold text-success" name="selling_price" id="edit_svc_sell" step="0.01" required onkeyup="calcSvcMarginEdit()" onchange="calcSvcMarginEdit()">
                                </div>
                            </div>
                            <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                <label class="form-label fw-bold small text-muted"><?= t('Cost Price (Auto-Sum)') ?></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text border-0 bg-light"><?= htmlspecialchars(bmsCompanyCurrency()) ?></span>
                                    <input type="number" class="form-control border-0 bg-secondary bg-opacity-10 fw-bold" name="cost_price" id="edit_svc_cost" step="0.01" readonly>
                                </div>
                            </div>
                            <div class="col-md-4<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                <label class="form-label fw-bold small"><?= t('Tax Rate') ?></label>
                                <select class="form-select form-select-sm border-0 bg-light" name="tax_id" id="edit_svc_tax">
                                    <option value=""><?= t('No Tax') ?></option>
                                    <?php foreach ($tax_rates as $tax): ?>
                                    <option value="<?= $tax['rate_id'] ?>"><?= htmlspecialchars($tax['rate_name']) ?> (<?= $tax['rate_percentage'] ?>%)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mt-3 pt-3 border-top">
                                <div class="row g-3">
                                    <?php if (projectsModuleActive()): ?>
                                    <div class="col-md-6<?= $simpleServiceForm ? ' d-none' : '' ?>">
                                        <label class="form-label fw-bold small"><?= t('Select Project (Optional)') ?></label>
                                        <select class="form-select form-select-sm fw-bold shadow-sm border border-secondary border-opacity-25" name="project_id" id="edit_svc_project_id" onchange="filterWarehouses(this.value, 'edit_svc_warehouse_id')">
                                            <option value=""><?= t('Select Project') ?></option>
                                            <?php foreach ($projects as $p): ?>
                                            <option value="<?= $p['project_id'] ?>"><?= htmlspecialchars($p['project_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($simpleServiceForm && !$showShopPicker): ?>
                                    <input type="hidden" name="warehouse_id" id="edit_svc_warehouse_id" value="<?= $onlyWarehouseId ?>">
                                    <?php else: ?>
                                    <div class="col-md-<?= ($simpleServiceForm || !projectsModuleActive()) ? 12 : 6 ?>">
                                        <label class="form-label fw-bold small"><?= wLabel('Select Warehouse', 'Select Shop') ?> <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-sm fw-bold text-primary shadow-sm border border-primary border-opacity-25" name="warehouse_id" id="edit_svc_warehouse_id" onchange="refreshAllComponentCostsEdit()">
                                            <option value=""><?= wLabel('Select Warehouse', 'Select Shop') ?></option>
                                            <?php foreach ($warehouses as $w): ?>
                                            <option value="<?= $w['warehouse_id'] ?>"><?= htmlspecialchars($w['warehouse_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!$simpleServiceForm) { $sptPrefix = 'edit_svc'; include ROOT_DIR . '/includes/service_price_terms_editor.php'; } ?>

                        <div class="table-responsive rounded-3 bg-white shadow-sm border overflow-hidden<?= $simpleServiceForm ? ' d-none' : '' ?>">
                            <table class="table table-hover align-middle mb-0" id="editSvcComponentTable">
                                <thead class="bg-dark text-white text-center">
                                    <tr class="small">
                                        <th style="width: 60px;"><?= t('S/NO') ?></th>
                                        <th class="ps-3" style="width: 45%;"><?= t('Materials Description') ?></th>
                                        <th style="width: 15%;"><?= t('Unit') ?></th>
                                        <th style="width: 15%;"><?= t('Qty per Unit') ?></th>
                                        <th style="width: 15%;"><?= t('Action') ?></th>
                                    </tr>
                                </thead>
                                <tbody id="editSvcComponentBody">
                                    <!-- Dynamic Rows -->
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="5" class="ps-3 py-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="addSvcComponentRowEdit()">
                                                <i class="bi bi-plus-circle me-1"></i> <?= t('Add Material Component') ?>
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">
                        <i class="bi bi-check-circle me-1"></i> <?= t('Save Changes') ?>
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
    /* Bootstrap's .badge sets white-space:nowrap directly on the element,
       which isn't inherited-overridden by the th/td white-space rule above
       (an explicit rule on the badge itself wins). That let the Project
       badge ("Upgrade of Transmission Line") ignore its column width and
       visually run into Selling Price — same bug already fixed on
       sub_contractors.php. */
    .badge {
        border: 1px solid #000 !important;
        color: black !important;
        background: transparent !important;
        white-space: normal !important;
        word-break: break-word !important;
        display: inline-block !important;
        max-width: 100% !important;
    }
    .container-fluid { width: 100% !important; max-width: none !important; padding: 0 !important; margin: 0 !important; }
    @page { margin: 1cm; size: auto; }

    .row > [class*="col-"] { float: left !important; width: 25% !important; }
    .card-body { padding: 10px !important; }
    .table-responsive table { table-layout: auto !important; }

    /* "FANITURE" (a normal 8-letter word) was rendering one letter per line in
       Product Name — DataTables' columnDefs bakes inline pixel widths onto 6
       columns (50px/110px/120px/120px/90px/80px) plus one 35% column, and
       under table-layout:fixed that arithmetic doesn't hold up at print width,
       starving Product Name down to ~47px. Neutralise DataTables' inline
       widths for print and size all 7 printed columns explicitly instead
       (Actions is excluded via d-print-none). Sums to 100%. */
    #servicesTable { table-layout: fixed !important; }
    #servicesTable th, #servicesTable td {
        width: auto !important;
        min-width: 0 !important;
        max-width: none !important;
    }
    #servicesTable th:nth-child(1), #servicesTable td:nth-child(1) { width: 5%  !important; } /* S/NO */
    #servicesTable th:nth-child(2), #servicesTable td:nth-child(2) { width: 12% !important; } /* Item Code */
    #servicesTable th:nth-child(3), #servicesTable td:nth-child(3) { width: 28% !important; } /* Product Name */
    #servicesTable th:nth-child(4), #servicesTable td:nth-child(4) { width: 15% !important; } /* Project */
    #servicesTable th:nth-child(5), #servicesTable td:nth-child(5) { width: 14% !important; } /* Selling Price */
    #servicesTable th:nth-child(6), #servicesTable td:nth-child(6) { width: 13% !important; } /* Tax */
    #servicesTable th:nth-child(7), #servicesTable td:nth-child(7) { width: 13% !important; } /* Status */

    /* Hide DataTable controls — show only the raw table */
    .dataTables_length,
    .dataTables_filter,
    .dataTables_info,
    .dataTables_paginate { display: none !important; }

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
const SIMPLE_SERVICE_FORM = <?= json_encode($simpleServiceForm) ?>;
const SVC_I18N = <?= json_encode([
    'select_warehouse' => wLabel('Select Warehouse', 'Select Shop'),
    'please_select_warehouse_first' => wLabel('Please select a warehouse first', 'Please select a shop first'),
    'searching' => t('Searching...'),
    'no_sku' => t('No SKU'),
    'stock_label' => t('Stock:'),
    'no_products_in_warehouse' => wLabel('No products found in this warehouse', 'No products found in this shop'),
    'enter_unit' => t('Enter unit...'),
    'job' => t('Job'), 'pieces' => t('Pieces'), 'set' => t('Set'), 'box' => t('Box'),
    'litre' => t('Litre'), 'kg' => t('Kg'), 'other_specify' => t('Other (specify)'),
    'missing_field' => t('Missing Field'),
    'service_product_name' => t('Service/Product Name'),
    'enter_field_before_saving' => t('Please enter the %s before saving.'),
    'saving' => t('Saving...'),
    'product_created' => t('Product Created!'),
    'product_added' => t('Product added successfully.'),
    'error_saving_product' => t('Error saving product.'),
    'server_error_colon' => t('Server error:'),
    'saving_changes' => t('Saving Changes...'),
    'updated' => t('Updated!'),
    'product_updated_msg' => t('Product updated successfully.'),
    'error_updating_product' => t('Error updating product.'),
    'server_error_retry' => t('Server error. Please try again.'),
    'delete_nip_title' => wLabel('Delete Non-Inventory Product?', 'Delete Service?'),
    'delete_nip_confirm' => t('Are you sure you want to delete %s?'),
    'delete_nip_warning' => t('This action cannot be undone and may affect transaction history if this product was sold.'),
    'yes_delete' => t('Yes, Delete'),
    'deleted' => t('Deleted!'),
    'error' => t('Error'),
    'filter_table' => t('Filter table...'),
    'show_menu' => t('Show _MENU_'),
    'no_nip_found' => t('No non-inventory products found.'),
    'showing_items_info' => t('Showing _START_ to _END_ of _TOTAL_ items'),
    'no_items_to_show' => t('No items to show'),
    'filtered_from_max' => t('(filtered from _MAX_ total)'),
    'all_categories' => t('All Categories'),
    'profit_label' => t('Profit:'),
    'search_product' => t('Search product...'),
    'cancel' => t('Cancel'),
], JSON_UNESCAPED_UNICODE) ?>;
const PROJECTS_MODULE_ACTIVE = <?= json_encode(projectsModuleActive()) ?>;

function filterWarehouses(projectId, targetId) {
    const select = document.getElementById(targetId);
    // Simple POS with exactly one shop renders warehouse_id as a hidden input
    // (auto-assigned, no picker) — nothing to rebuild in that case.
    if (!select || select.tagName !== 'SELECT') return;
    const currentValue = select.value;
    select.innerHTML = `<option value="">${SVC_I18N.select_warehouse}</option>`;
    
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

    // §UI-3 — if this warehouse <select> is a Select2, sync its display to the
    // rebuilt options. 'change.select2' re-renders WITHOUT firing the native
    // onchange (refreshAllComponentCosts), so the dynamic chain stays intact.
    if (window.jQuery && jQuery('#' + targetId).hasClass('select2-hidden-accessible')) {
        jQuery('#' + targetId).trigger('change.select2');
    }
}

function checkOtherUnit(select, containerId) {
    if (select.value === 'other') {
        const container = document.getElementById(containerId);
        container.innerHTML = `
            <div class="input-group input-group-sm">
                <input type="text" class="form-control fw-bold border border-secondary border-opacity-25"
                    name="unit" placeholder="${SVC_I18N.enter_unit}" autofocus required>
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
            <option value="job">${SVC_I18N.job}</option>
            <option value="pcs">${SVC_I18N.pieces}</option>
            <option value="set">${SVC_I18N.set}</option>
            <option value="box">${SVC_I18N.box}</option>
            <option value="ltr">${SVC_I18N.litre}</option>
            <option value="kg">${SVC_I18N.kg}</option>
            <option value="other">${SVC_I18N.other_specify}</option>
        </select>
    `;
}

function toggleSvcAddStep(step) {
    if (SIMPLE_SERVICE_FORM) return; // Simple POS: both sections always shown together, no tabs
    if (step === 1) {
        document.getElementById('svc_add_step1').style.display = 'block';
        document.getElementById('svc_add_step2').style.display = 'none';
    } else {
        document.getElementById('svc_add_step1').style.display = 'none';
        document.getElementById('svc_add_step2').style.display = 'block';
    }
}

function toggleSvcEditStep(step) {
    if (SIMPLE_SERVICE_FORM) return; // Simple POS: both sections always shown together, no tabs
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
                <input type="text" class="form-control border-start-0 ps-0" placeholder="${SVC_I18N.search_product}"
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
        resultsDiv.innerHTML = `<div class="p-2 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> ${SVC_I18N.please_select_warehouse_first}</div>`;
        resultsDiv.classList.remove('d-none');
        resultsDiv.style.width = "330px";
        return;
    }

    resultsDiv.style.width = "450px";
    resultsDiv.innerHTML = `<div class="p-3 text-muted small"><div class="spinner-border spinner-border-sm me-2 text-primary" role="status"></div>${SVC_I18N.searching}</div>`;
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
                                                <i class="bi bi-upc-scan me-1"></i>${prod.sku || SVC_I18N.no_sku}
                                            </div>
                                        </div>
                                        <div class="text-end ms-2" style="white-space: nowrap;">
                                            <span class="badge bg-light text-dark border mb-1 d-block" style="font-size: 10px;">${SVC_I18N.stock_label} ${parseFloat(prod.current_stock) || 0}</span>
                                            <span class="fw-bold text-primary d-block" style="font-size: 12px;"><?= htmlspecialchars(bmsCompanyCurrency()) ?> ${price.toLocaleString()}</span>
                                        </div>
                                    </div>
                                </button>
                            `;
                        }).join('')}
                    </div>
                `;
                resultsDiv.classList.remove('d-none');
            } else {
                resultsDiv.innerHTML = `<div class="p-3 text-muted small"><i class="bi bi-info-circle me-1"></i> ${SVC_I18N.no_products_in_warehouse}</div>`;
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
                <input type="text" class="form-control border-start-0 ps-0" placeholder="${SVC_I18N.search_product}"
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
        resultsDiv.innerHTML = `<div class="p-2 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> ${SVC_I18N.please_select_warehouse_first}</div>`;
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
    resultsDiv.innerHTML = `<div class="p-3 text-muted small"><div class="spinner-border spinner-border-sm me-2 text-primary" role="status"></div>${SVC_I18N.searching}</div>`;
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
                                                <i class="bi bi-upc-scan me-1"></i>${prod.sku || SVC_I18N.no_sku}
                                            </div>
                                        </div>
                                        <div class="text-end ms-2" style="white-space: nowrap;">
                                            <span class="badge bg-light text-dark border mb-1 d-block" style="font-size: 10px;">${SVC_I18N.stock_label} ${parseFloat(prod.current_stock) || 0}</span>
                                            <span class="fw-bold text-primary d-block" style="font-size: 12px;"><?= htmlspecialchars(bmsCompanyCurrency()) ?> ${price.toLocaleString()}</span>
                                        </div>
                                    </div>
                                </button>
                            `;
                        }).join('')}
                    </div>
                `;
                resultsDiv.classList.remove('d-none');
            } else {
                resultsDiv.innerHTML = `<div class="p-3 text-muted small"><i class="bi bi-info-circle me-1"></i> ${SVC_I18N.no_products_in_warehouse}</div>`;
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

// ── Price by term (optional) — core/service_price_terms.php ─────────────────
const SPT_I18N = <?= json_encode([
    'units' => ['day' => t('Days'), 'week' => t('Weeks'), 'month' => t('Months'), 'year' => t('Years')],
    'name_ph' => t('e.g. 3 Months'), 'desc_ph' => t('Optional'), 'remove' => t('Remove term'),
    'hint' => t("Depends on term — this is the default term's price."),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function sptBody(prefix) { return document.getElementById(prefix + '_spt_body'); }
function sptSellInput(prefix) { return document.getElementById(prefix === 'svc' ? 'svc_sell' : 'edit_svc_sell'); }

function sptAddRow(prefix, term) {
    const body = sptBody(prefix);
    if (!body) return;
    term = term || {};
    const tr = document.createElement('tr');
    if (term.term_id) tr.dataset.termId = term.term_id;
    tr.innerHTML = `
        <td class="spt-sno small text-muted"></td>
        <td><input type="text" class="form-control form-control-sm spt-label" maxlength="100"></td>
        <td><div class="input-group input-group-sm">
            <input type="number" class="form-control spt-value" min="1" max="1000" step="1" style="max-width:80px;">
            <select class="form-select spt-unit"></select></div></td>
        <td><input type="number" class="form-control form-control-sm spt-price" min="0" step="0.01"></td>
        <td><input type="text" class="form-control form-control-sm spt-desc" maxlength="1000"></td>
        <td class="text-center"><input class="form-check-input spt-default" type="radio" name="${prefix}_spt_default"></td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger spt-remove"><i class="bi bi-trash"></i></button></td>`;
    const unit = tr.querySelector('.spt-unit');
    Object.entries(SPT_I18N.units).forEach(([k, v]) => unit.add(new Option(v, k)));
    tr.querySelector('.spt-label').placeholder = SPT_I18N.name_ph;
    tr.querySelector('.spt-desc').placeholder = SPT_I18N.desc_ph;
    tr.querySelector('.spt-remove').title = SPT_I18N.remove;
    tr.querySelector('.spt-label').value = term.term_label || '';
    tr.querySelector('.spt-value').value = term.duration_value || 1;
    unit.value = term.duration_unit || 'month';
    tr.querySelector('.spt-price').value = (term.price !== undefined && term.price !== null) ? term.price : '';
    tr.querySelector('.spt-desc').value = term.description || '';
    const isFirst = body.children.length === 0;
    tr.querySelector('.spt-default').checked = term.term_id ? !!term.is_default : isFirst;
    tr.querySelector('.spt-remove').addEventListener('click', () => {
        const wasDefault = tr.querySelector('.spt-default').checked;
        tr.remove();
        if (wasDefault && body.firstElementChild) body.firstElementChild.querySelector('.spt-default').checked = true;
        sptSync(prefix);
    });
    tr.addEventListener('input', () => sptSync(prefix));
    tr.addEventListener('change', () => sptSync(prefix));
    body.appendChild(tr);
    sptSync(prefix);
    if (!term.term_id) tr.querySelector('.spt-label').focus();
}

// With terms, the selling price is the default term's price (read-only); without, it is typed as before.
function sptSync(prefix) {
    const body = sptBody(prefix);
    if (!body) return;
    const rows = Array.from(body.children);
    rows.forEach((tr, i) => { tr.querySelector('.spt-sno').textContent = i + 1; });
    document.getElementById(prefix + '_spt_wrap').classList.toggle('d-none', rows.length === 0);
    const sell = sptSellInput(prefix);
    if (!sell) return;
    let hint = document.getElementById(prefix + '_spt_hint');
    if (!hint) {
        hint = document.createElement('div');
        hint.id = prefix + '_spt_hint';
        hint.className = 'form-text text-primary d-none';
        hint.textContent = SPT_I18N.hint;
        sell.closest('.input-group').after(hint);
    }
    const planPrice = spDefaultPrice(prefix);   // plans / Superadmin plans, when the service has them
    if (planPrice !== null) {
        sell.value = planPrice;
        sell.readOnly = true;
        hint.textContent = spState(prefix).mapped ? SP_I18N.hint_platform : SP_I18N.hint_plans;
        hint.classList.remove('d-none');
    } else if (rows.length) {
        const def = rows.find(tr => tr.querySelector('.spt-default').checked) || rows[0];
        sell.value = parseFloat(def.querySelector('.spt-price').value) || 0;
        sell.readOnly = true;
        hint.textContent = SPT_I18N.hint;
        hint.classList.remove('d-none');
    } else {
        sell.readOnly = false;
        hint.classList.add('d-none');
    }
    if (prefix === 'svc') calcSvcMargin(); else calcSvcMarginEdit();
}

function sptRows(prefix) {
    const body = sptBody(prefix);
    return body ? Array.from(body.children).map(tr => ({
        term_id: tr.dataset.termId ? parseInt(tr.dataset.termId, 10) : null,
        term_label: tr.querySelector('.spt-label').value.trim(),
        duration_value: tr.querySelector('.spt-value').value,
        duration_unit: tr.querySelector('.spt-unit').value,
        price: tr.querySelector('.spt-price').value,
        description: tr.querySelector('.spt-desc').value.trim(),
        is_default: tr.querySelector('.spt-default').checked ? 1 : 0,
    })) : [];
}

function sptCollect(prefix) {
    const field = document.getElementById(prefix + '_price_terms');
    if (!sptBody(prefix) || !field) return;
    const st = spState(prefix);
    const plansField = document.getElementById(prefix + '_service_plans');
    if (st.mapped) {
        // Superadmin's plans: nothing local is sent, and the service stays a subscription.
        field.value = '';
        if (plansField) plansField.disabled = true;
        const sub = document.getElementById(prefix + '_is_subscription');
        if (sub) sub.checked = true;
        return;
    }
    field.value = JSON.stringify(sptRows(prefix));
    if (plansField) {
        // Sent only when the service has (or had) plans — a plain service saves exactly as before.
        const send = st.plans.length > 0 || st.loadedPlans > 0;
        plansField.disabled = !send;
        plansField.value = send ? JSON.stringify(st.plans.map(p => ({
            plan_id: p.plan_id, plan_name: (p.plan_name || '').trim(), tagline: (p.tagline || '').trim(),
            is_popular: p.is_popular ? 1 : 0,
            items: p.items.map(it => ({ label: (it.label || '').trim(), value: (it.value || '').trim() })),
            terms: p.terms.map(t => ({ term_id: t.term_id || null, term_label: (t.term_label || '').trim(),
                duration_value: t.duration_value, duration_unit: t.duration_unit, price: t.price,
                description: (t.description || '').trim(), is_default: t.is_default ? 1 : 0 })),
        }))) : '';
    }
}

function sptReset(prefix) {
    const body = sptBody(prefix);
    if (!body) return;
    body.innerHTML = '';
    const sub = document.getElementById(prefix + '_is_subscription');
    if (sub) sub.checked = false;
    const tpl = document.getElementById(prefix + '_contract_template');
    if (tpl) $(tpl).val('').trigger('change');
    spHide(prefix);
    SP_STATE[prefix] = null;
    const map = document.getElementById(prefix + '_platform_product');
    if (map) $(map).val('').trigger('change');
    spRenderBar(prefix);
    sptSync(prefix);
    svcTypeApply(prefix);
}

function sptLoad(prefix, productId) {
    if (!sptBody(prefix)) return;
    fetch(`${SVC_APP_URL}/api/account/get_service_price_terms.php?product_id=${encodeURIComponent(productId)}`)
        .then(r => r.json())
        .then(json => {
            if (!json.success) return;
            const st = spState(prefix);
            st.plans = (json.plans || []).map(p => ({
                plan_id: p.plan_id, plan_name: p.plan_name, tagline: p.tagline || '', is_popular: !!p.is_popular,
                items: (p.items || []).map(it => ({ label: it.label, value: it.value || '' })),
                terms: (p.terms || []).map(t => Object.assign({}, t)),
            }));
            st.loadedPlans = st.plans.length;
            st.mapped = json.platform_product_key || null;
            st.platformPlans = json.platform_plans || [];
            // A plan's terms belong to the plan; only plain terms go in the flat table.
            (json.data || []).filter(t => !t.plan_id).forEach(t => sptAddRow(prefix, t));
            const map = document.getElementById(prefix + '_platform_product');
            if (map) {
                // A key no longer listed (product switched off) stays selected, so a save never unlinks it by accident.
                if (st.mapped && !Array.from(map.options).some(o => o.value === st.mapped)) map.add(new Option(st.mapped, st.mapped));
                $(map).val(st.mapped || '').trigger('change');
            }
            const sub = document.getElementById(prefix + '_is_subscription');
            if (sub) sub.checked = !!json.is_subscription;
            const tpl = document.getElementById(prefix + '_contract_template');
            if (tpl) $(tpl).val(json.contract_template_id ? String(json.contract_template_id) : '').trigger('change');
            // Open with the switch the service was saved with; a Superadmin link opens locked.
            const own = st.plans.length || sptBody(prefix).children.length || json.is_subscription || json.contract_template_id;
            st.mode = st.mapped ? 'sa' : (own ? 'own' : null);
            st.locked = !!st.mapped;
            st.legacySub = !!json.is_subscription && !st.mapped;   // older own-plan subscription: leave the choice visible
            spRenderBar(prefix);
            sptSync(prefix);
            svcTypeApply(prefix);
        })
        .catch(() => {});
}

// ── Plans (optional) — core/service_plans.php ───────────────────────────────
// A service is priced by plain terms OR by plans (each plan has its own terms);
// on BJP's install it can instead sell a Superadmin platform product (read-only here).
const SP_I18N = <?= json_encode([
    'plan' => t('plan'), 'plans' => t('plans'), 'from' => t('from'), 'untitled' => t('New plan'), 'standard' => t('Standard'),
    'add_plans' => t('Add plans'), 'add_hint' => t('For systems sold as Basic, Pro… per month or year.'),
    'edit' => t('Edit plans'), 'view' => t('View plans'), 'from_sa' => t('From Superadmin'),
    'no_platform' => t('Superadmin has no active plans with prices for this product yet.'),
    'lock' => t('Managed in Superadmin → Plans. To change prices or what\'s included, edit the plan there; this list updates by itself.'),
    'plan_name' => t('Plan name'), 'name_ph' => t('e.g. Starter'), 'tagline' => t('Short description'), 'tagline_ph' => t('Optional — e.g. For one shop'),
    'popular' => t('Most popular'), 'prices' => t('Prices'), 'term' => t('Term'), 'length' => t('Length'), 'price' => t('Price'),
    'default' => t('Default'), 'add_term' => t('Add term'), 'included' => t('What\'s included'), 'label_ph' => t('e.g. Users'),
    'value_ph' => t('e.g. 2'), 'add_line' => t('Add line'), 'remove_plan' => t('Remove plan'), 'add_plan' => t('Plan'),
    'cancel' => t('Cancel'), 'done' => t('Done'), 'close' => t('Close'), 'not_public' => t('Not public'),
    'users' => t('Users'), 'shops' => t('Shops'), 'unlimited' => t('Unlimited'),
    'move_title' => t('Move the terms into a plan?'),
    'move_text' => t('This service already has price terms. They will become the prices of its first plan.'),
    'move_ok' => t('Yes, move them'),
    'map_title' => t('Sell the Superadmin plans instead?'),
    'map_text' => t('This service\'s own plans and price terms will be removed when you save. Its prices will come from Superadmin.'),
    'map_ok' => t('Yes, use Superadmin plans'),
    'check' => t('Please check the plans'),
    'off_title' => t('Remove these plans?'),
    'off_sa' => t('The link to the Superadmin plans will be removed when you save.'),
    'off_own' => t('This service\'s plans and price terms will be removed when you save.'),
    'off_ok' => t('Yes, remove'),
    'e_name' => t('Every plan needs a name.'), 'e_dup' => t('Two plans have the same name.'),
    'e_terms' => t('needs at least one price.'), 'e_term_name' => t('every price needs a term name.'),
    'e_term_dup' => t('two prices have the same term name.'),
    'e_len' => t('length must be a whole number from 1 to 1000.'), 'e_price' => t('every price must be 0 or more.'),
    'e_item' => t('every included line needs a name.'),
    'hint_plans' => t('Depends on plan and term — this is the default price.'),
    'hint_platform' => t('Set by Superadmin — this is the default plan price.'),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const SP_PLATFORM = <?= json_encode($canMapPlatform ? $platformPlans : new stdClass(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const SP_STATE = {};

function spState(prefix) {
    return SP_STATE[prefix] || (SP_STATE[prefix] = { plans: [], active: 0, loadedPlans: 0, mapped: null, platformPlans: [], snap: null,
                                                     mode: null, locked: false, legacySub: false });
}
function spEsc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;'); }
function spMoney(v) { return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
function spClone(o) { return JSON.parse(JSON.stringify(o)); }
function spDrawer(prefix) { return document.getElementById(prefix + '_sp_drawer'); }
function spNewTerm() { return { term_id: null, term_label: '', duration_value: 1, duration_unit: 'month', price: '', description: '', is_default: false }; }
function spNewPlan(terms) {
    return { plan_id: null, plan_name: terms ? SP_I18N.standard : '', tagline: '', is_popular: false, items: [],
             terms: terms && terms.length ? terms : [spNewTerm()] };
}

// One default price across all plans; the first price when none is marked.
function spEnsureDefault(st) {
    const all = st.plans.flatMap(p => p.terms);
    if (all.length && !all.some(t => t.is_default)) all[0].is_default = true;
}

// The price the selling-price box shows: the default price (same rule as spSyncSellingPrice()).
function spDefaultPrice(prefix) {
    const st = spState(prefix);
    if (st.mapped) {
        let def = null, min = null;
        st.platformPlans.forEach(p => p.prices.forEach(pr => {
            min = min === null ? pr.price_tzs : Math.min(min, pr.price_tzs);
            if (def === null && pr.is_default) def = pr.price_tzs;
        }));
        return def !== null ? def : min;
    }
    const all = st.plans.flatMap(p => p.terms);
    if (!all.length) return null;
    const def = all.find(t => t.is_default) || all[0];
    return parseFloat(def.price) || 0;
}

function spRenderBar(prefix) {
    const bar = document.getElementById(prefix + '_sp_bar');
    if (!bar) return;
    const st = spState(prefix);
    const pill = txt => `<span class="badge rounded-pill me-2" style="background:#e7f0ff;color:#0d6efd;border:1px solid #b6ccfe;font-weight:600;">${spEsc(txt)}</span>`;
    const count = n => n + ' ' + (n === 1 ? SP_I18N.plan : SP_I18N.plans);
    let html;
    if (st.mapped) {
        const pp = st.platformPlans;
        let min = null;
        pp.forEach(p => p.prices.forEach(pr => { min = min === null ? pr.price_tzs : Math.min(min, pr.price_tzs); }));
        html = `<div class="small">${pill(SP_I18N.from_sa)}` + (pp.length
                ? `<strong>${count(pp.length)}</strong> · ${spEsc(pp.map(p => p.name).join(', '))}` + (min !== null ? ` · ${spEsc(SP_I18N.from)} ${spMoney(min)}` : '')
                : `<span class="text-muted">${spEsc(SP_I18N.no_platform)}</span>`) + `</div>
            <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="spOpen('${prefix}')"><i class="bi bi-eye me-1"></i>${spEsc(SP_I18N.view)}</button>`;
    } else if (st.plans.length) {
        const prices = st.plans.flatMap(p => p.terms).map(t => parseFloat(t.price)).filter(v => !isNaN(v));
        html = `<div class="small"><i class="bi bi-layers text-primary me-1"></i><strong>${count(st.plans.length)}</strong> · ${spEsc(st.plans.map(p => p.plan_name || SP_I18N.untitled).join(', '))}`
             + (prices.length ? ` · ${spEsc(SP_I18N.from)} ${spMoney(Math.min(...prices))}` : '') + `</div>
            <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="spOpen('${prefix}')"><i class="bi bi-pencil-square me-1"></i>${spEsc(SP_I18N.edit)}</button>`;
    } else {
        html = `<div class="small text-muted"><i class="bi bi-layers text-primary me-1"></i>${spEsc(SP_I18N.add_hint)}</div>
            <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="spOpen('${prefix}')"><i class="bi bi-plus-circle me-1"></i>${spEsc(SP_I18N.add_plans)}</button>`;
    }
    bar.innerHTML = html;
    // Plans (or Superadmin's) replace the plain terms table.
    const hideFlat = !!st.mapped || st.plans.length > 0;
    const add = document.getElementById(prefix + '_spt_add');
    if (add) add.classList.toggle('d-none', hideFlat);
    const wrap = document.getElementById(prefix + '_spt_wrap');
    if (wrap && hideFlat) wrap.classList.add('d-none');
}

function spOpen(prefix) {
    const st = spState(prefix);
    st.snap = { plans: spClone(st.plans), flat: st.mapped ? [] : sptRows(prefix) };
    if (!st.mapped && !st.plans.length) {
        const flat = st.snap.flat;
        if (flat.length) {
            Swal.fire({ icon: 'question', title: SP_I18N.move_title, text: SP_I18N.move_text, showCancelButton: true,
                        confirmButtonText: SP_I18N.move_ok, cancelButtonText: SP_I18N.cancel, confirmButtonColor: '#0d6efd' })
                .then(r => {
                    if (!r.isConfirmed) return;
                    // term_ids are kept, so the terms (and documents that used them) carry over.
                    st.plans = [spNewPlan(flat.map(t => Object.assign({}, t, { is_default: !!t.is_default })))];
                    sptBody(prefix).innerHTML = '';
                    spShow(prefix);
                });
            return;
        }
        st.plans = [spNewPlan()];
    }
    spShow(prefix);
}

function spShow(prefix) {
    const st = spState(prefix);
    st.active = Math.min(st.active, Math.max(0, st.plans.length - 1));
    spEnsureDefault(st);
    spRenderDrawer(prefix);
    bootstrap.Offcanvas.getOrCreateInstance(spDrawer(prefix)).show();
}

function spHide(prefix) {
    const el = spDrawer(prefix);
    if (!el) return;
    const inst = bootstrap.Offcanvas.getInstance(el);
    if (inst) inst.hide();
}

function spAfterChange(prefix) { spRenderBar(prefix); sptSync(prefix); svcTypeApply(prefix); }

// ── Service type strip (includes/service_type_switches.php) ─────────────────
// Both off: the form is exactly as before. 'sa' = Superadmin plans, 'own' = plans/terms typed here.
function svcTypeApply(prefix) {
    const el = id => document.getElementById(prefix + id);
    const sec = el('_spt_section');
    if (!sec) return;
    const st = spState(prefix), mode = st.mode;
    const show = (node, on) => { if (node) node.classList.toggle('d-none', !on); };
    show(sec, !!mode);
    const sa = el('_type_sa'), own = el('_type_own');
    if (sa) sa.checked = mode === 'sa';
    if (own) own.checked = mode === 'own';
    const locked = mode === 'sa' && !!st.mapped && st.locked;
    [sa, own].forEach(s => { if (s) s.disabled = locked; });
    show(el('_type_lock'), locked);
    show(el('_spt_head'), mode === 'own');
    show(el('_sp_bar'), mode === 'own' || !!st.mapped);
    show(el('_platform_wrap'), mode === 'sa');
    const sub = el('_is_subscription');
    if (sub) {
        // Superadmin plans always extend the tenant; an outside system is invoiced by hand.
        if (mode === 'sa') sub.checked = true;
        else if (!st.legacySub) sub.checked = false;
        show(el('_sub_wrap'), mode === 'sa' || (mode === 'own' && st.legacySub));
    }
    show(el('_contract_wrap'), !!mode);
    // Materials don't apply to a plan-sold service, nor a warehouse to a Superadmin plan.
    const comp = document.getElementById(prefix === 'svc' ? 'svcComponentTable' : 'editSvcComponentTable');
    if (comp) comp.closest('.table-responsive').classList.toggle('d-none', !!mode);
    const wh = el('_warehouse_id');
    if (wh && wh.tagName === 'SELECT') show(wh.closest('[class*="col-md-"]'), mode !== 'sa');
}

function svcTypeSwitch(prefix, kind, input) {
    const st = spState(prefix), from = st.mode, want = input.checked ? kind : null;
    if (want === from) return;
    const has = from === 'sa' ? !!st.mapped
              : from === 'own' ? (st.plans.length > 0 || sptBody(prefix).children.length > 0) : false;
    const go = () => {
        if (from) svcTypeClear(prefix, from);
        st.mode = want;
        spAfterChange(prefix);
    };
    if (!has) { go(); return; }
    Swal.fire({ icon: 'warning', title: SP_I18N.off_title, text: from === 'sa' ? SP_I18N.off_sa : SP_I18N.off_own,
                showCancelButton: true, confirmButtonText: SP_I18N.off_ok, cancelButtonText: SP_I18N.cancel, confirmButtonColor: '#0d6efd' })
        .then(r => { if (r.isConfirmed) go(); else svcTypeApply(prefix); });
}

// Leaving a mode removes what it held (sent on Save, so a saved service is cleaned too).
function svcTypeClear(prefix, mode) {
    const st = spState(prefix);
    spHide(prefix);
    if (mode === 'sa') {
        st.mapped = null; st.platformPlans = []; st.locked = false;
        const map = document.getElementById(prefix + '_platform_product');
        if (map) $(map).val('').trigger('change');
    } else {
        st.plans = [];
        sptBody(prefix).innerHTML = '';
    }
    st.legacySub = false;
    const sub = document.getElementById(prefix + '_is_subscription');
    if (sub) sub.checked = false;
    const tpl = document.getElementById(prefix + '_contract_template');
    if (tpl) $(tpl).val('').trigger('change');
}

function spCancel(prefix) {
    const st = spState(prefix);
    if (st.snap && !st.mapped) {
        st.plans = st.snap.plans;
        if (st.snap.flat.length && !sptBody(prefix).children.length) st.snap.flat.forEach(t => sptAddRow(prefix, t));
    }
    st.snap = null;
    spHide(prefix);
    spAfterChange(prefix);
}

// The same checks as spParse(), so most mistakes are caught before Save.
function spValidate(st) {
    const names = {};
    for (let i = 0; i < st.plans.length; i++) {
        const p = st.plans[i], name = (p.plan_name || '').trim();
        if (!name) return [i, SP_I18N.e_name];
        if (names[name.toLowerCase()]) return [i, SP_I18N.e_dup];
        names[name.toLowerCase()] = true;
        const q = `"${name}": `;
        if (!p.terms.length) return [i, `"${name}" ${SP_I18N.e_terms}`];
        const tn = {};
        for (const t of p.terms) {
            const tl = (t.term_label || '').trim().toLowerCase();
            if (!tl) return [i, q + SP_I18N.e_term_name];
            if (tn[tl]) return [i, q + SP_I18N.e_term_dup];
            tn[tl] = true;
            const len = Number(t.duration_value);
            if (!Number.isInteger(len) || len < 1 || len > 1000) return [i, q + SP_I18N.e_len];
            if (t.price === '' || t.price === null || isNaN(parseFloat(t.price)) || parseFloat(t.price) < 0) return [i, q + SP_I18N.e_price];
        }
        if (p.items.some(it => !(it.label || '').trim() && (it.value || '').trim())) return [i, q + SP_I18N.e_item];
    }
    return null;
}

function spDone(prefix) {
    const st = spState(prefix);
    // Fully blank included lines are dropped (the server skips them too).
    st.plans.forEach(p => { p.items = p.items.filter(it => (it.label || '').trim() || (it.value || '').trim()); });
    const err = spValidate(st);
    if (err) {
        st.active = err[0];
        spRenderDrawer(prefix);
        Swal.fire({ icon: 'warning', title: SP_I18N.check, text: err[1], confirmButtonColor: '#0d6efd' });
        return;
    }
    spEnsureDefault(st);
    st.snap = null;
    spHide(prefix);
    spAfterChange(prefix);
}

function spRenderDrawer(prefix) {
    const st = spState(prefix);
    const body = document.getElementById(prefix + '_sp_body');
    const foot = document.getElementById(prefix + '_sp_footer');
    if (!body) return;
    spBind(prefix, body);

    if (st.mapped) {
        const card = p => {
            const limits = [`${SP_I18N.users}: ${p.max_users === null ? SP_I18N.unlimited : p.max_users}`,
                            `${SP_I18N.shops}: ${p.max_shops === null ? SP_I18N.unlimited : p.max_shops}`];
            return `<div class="border rounded-3 p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <h6 class="fw-bold mb-0">${spEsc(p.name)}</h6>
                    <div>${p.is_popular ? `<span class="badge bg-primary">${spEsc(SP_I18N.popular)}</span>` : ''}
                         ${p.is_public ? '' : `<span class="badge bg-secondary ms-1">${spEsc(SP_I18N.not_public)}</span>`}</div>
                </div>
                ${p.tagline ? `<div class="small text-muted mb-2">${spEsc(p.tagline)}</div>` : ''}
                <table class="table table-sm small mb-2"><tbody>${p.prices.map(pr => `<tr><td>${spEsc(pr.term_label)}${pr.is_default ? ' <i class="bi bi-star-fill text-primary"></i>' : ''}</td>
                    <td class="text-end fw-bold">${spMoney(pr.price_tzs)}</td></tr>`).join('')}</tbody></table>
                <div class="small text-muted">${spEsc(limits.join(' · '))}</div>
                ${p.items.length ? `<ul class="small mb-0 mt-1 ps-3">${p.items.map(it => `<li>${spEsc(it.label)}${it.value ? ': <strong>' + spEsc(it.value) + '</strong>' : ''}</li>`).join('')}</ul>` : ''}
            </div>`;
        };
        body.innerHTML = `<div class="alert alert-primary small d-flex gap-2 align-items-start"><i class="bi bi-lock-fill mt-1"></i><div>${spEsc(SP_I18N.lock)}</div></div>`
            + (st.platformPlans.length ? st.platformPlans.map(card).join('') : `<div class="text-muted small">${spEsc(SP_I18N.no_platform)}</div>`);
        foot.innerHTML = `<button type="button" class="btn btn-secondary" onclick="spHide('${prefix}')">${spEsc(SP_I18N.close)}</button>`;
        return;
    }

    const p = st.plans[st.active];
    if (!p) { body.innerHTML = ''; foot.innerHTML = ''; return; }
    const tabs = st.plans.map((pl, i) => `<li class="nav-item"><button type="button" class="nav-link text-nowrap ${i === st.active ? 'active fw-bold' : ''}" data-act="tab" data-i="${i}">
            <span class="sp-tab-name">${spEsc(pl.plan_name || SP_I18N.untitled)}</span>${pl.is_popular ? ' <i class="bi bi-star-fill text-primary small"></i>' : ''}</button></li>`).join('')
        + `<li class="nav-item"><button type="button" class="nav-link text-primary text-nowrap" data-act="add-plan"><i class="bi bi-plus-lg"></i> ${spEsc(SP_I18N.add_plan)}</button></li>`;
    const unitOpts = sel => Object.entries(SPT_I18N.units).map(([k, v]) => `<option value="${k}" ${k === sel ? 'selected' : ''}>${spEsc(v)}</option>`).join('');
    const termRows = p.terms.map((t, ti) => `<tr>
            <td><input type="text" class="form-control form-control-sm" maxlength="100" data-f="term_label" data-t="${ti}" value="${spEsc(t.term_label)}" placeholder="${spEsc(SPT_I18N.name_ph)}"></td>
            <td><div class="input-group input-group-sm"><input type="number" class="form-control" min="1" max="1000" step="1" style="max-width:70px;" data-f="duration_value" data-t="${ti}" value="${spEsc(t.duration_value)}">
                <select class="form-select" data-f="duration_unit" data-t="${ti}">${unitOpts(t.duration_unit)}</select></div></td>
            <td><input type="number" class="form-control form-control-sm" min="0" step="0.01" data-f="price" data-t="${ti}" value="${spEsc(t.price)}"></td>
            <td class="text-center"><input class="form-check-input" type="radio" name="${prefix}_sp_default" data-act="default" data-t="${ti}" ${t.is_default ? 'checked' : ''}></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-act="del-term" data-t="${ti}" title="${spEsc(SPT_I18N.remove)}"><i class="bi bi-trash"></i></button></td></tr>`).join('');
    const itemRows = p.items.map((it, ii) => `<tr>
            <td><input type="text" class="form-control form-control-sm" maxlength="100" data-f="label" data-it="${ii}" value="${spEsc(it.label)}" placeholder="${spEsc(SP_I18N.label_ph)}"></td>
            <td><input type="text" class="form-control form-control-sm" maxlength="100" data-f="value" data-it="${ii}" value="${spEsc(it.value)}" placeholder="${spEsc(SP_I18N.value_ph)}"></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-act="del-item" data-it="${ii}"><i class="bi bi-trash"></i></button></td></tr>`).join('');

    body.innerHTML = `<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">${tabs}</ul>
        <div class="row g-2 mb-3">
            <div class="col-12"><label class="form-label small fw-bold">${spEsc(SP_I18N.plan_name)} <span class="text-danger">*</span></label>
                <input type="text" class="form-control" maxlength="100" data-f="plan_name" value="${spEsc(p.plan_name)}" placeholder="${spEsc(SP_I18N.name_ph)}"></div>
            <div class="col-12"><label class="form-label small fw-bold">${spEsc(SP_I18N.tagline)}</label>
                <input type="text" class="form-control" maxlength="255" data-f="tagline" value="${spEsc(p.tagline)}" placeholder="${spEsc(SP_I18N.tagline_ph)}"></div>
            <div class="col-12"><div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="${prefix}_sp_popular" data-act="popular" ${p.is_popular ? 'checked' : ''}>
                <label class="form-check-label small" for="${prefix}_sp_popular">${spEsc(SP_I18N.popular)}</label></div></div>
        </div>
        <h6 class="fw-bold small text-uppercase text-muted">${spEsc(SP_I18N.prices)}</h6>
        <div class="table-responsive"><table class="table table-sm align-middle mb-1">
            <thead class="table-light small"><tr><th style="min-width:120px;">${spEsc(SP_I18N.term)}</th><th style="min-width:150px;">${spEsc(SP_I18N.length)}</th>
                <th style="min-width:110px;">${spEsc(SP_I18N.price)}</th><th class="text-center">${spEsc(SP_I18N.default)}</th><th></th></tr></thead>
            <tbody>${termRows}</tbody></table></div>
        <button type="button" class="btn btn-sm btn-outline-primary mb-3" data-act="add-term"><i class="bi bi-plus-circle me-1"></i>${spEsc(SP_I18N.add_term)}</button>
        <h6 class="fw-bold small text-uppercase text-muted">${spEsc(SP_I18N.included)}</h6>
        ${p.items.length ? `<table class="table table-sm align-middle mb-1"><tbody>${itemRows}</tbody></table>` : ''}
        <button type="button" class="btn btn-sm btn-outline-primary mb-4" data-act="add-item"><i class="bi bi-plus-circle me-1"></i>${spEsc(SP_I18N.add_line)}</button>
        <div class="border-top pt-3"><button type="button" class="btn btn-sm btn-outline-danger" data-act="del-plan"><i class="bi bi-trash me-1"></i>${spEsc(SP_I18N.remove_plan)}</button></div>`;
    foot.innerHTML = `<button type="button" class="btn btn-secondary" onclick="spCancel('${prefix}')">${spEsc(SP_I18N.cancel)}</button>
        <button type="button" class="btn btn-primary" onclick="spDone('${prefix}')"><i class="bi bi-check-lg me-1"></i>${spEsc(SP_I18N.done)}</button>`;
}

// Inputs write straight into the plan model; structural changes re-render.
function spBind(prefix, body) {
    if (body.dataset.bound) return;
    body.dataset.bound = '1';
    const cur = () => { const st = spState(prefix); return st.plans[st.active]; };
    const onField = e => {
        const el = e.target, f = el.dataset.f, p = cur();
        if (!f || !p) return;
        if (el.dataset.t !== undefined) {
            p.terms[+el.dataset.t][f] = f === 'duration_value' ? (el.value === '' ? '' : Number(el.value)) : el.value;
        } else if (el.dataset.it !== undefined) {
            p.items[+el.dataset.it][f] = el.value;
        } else {
            p[f] = el.value;
            if (f === 'plan_name') {
                const tab = body.querySelector('.nav-link.active .sp-tab-name');
                if (tab) tab.textContent = el.value || SP_I18N.untitled;
            }
        }
    };
    body.addEventListener('input', onField);
    body.addEventListener('change', e => {
        const el = e.target, st = spState(prefix), p = cur();
        if (!p) return;
        if (el.dataset.f) { onField(e); return; }
        if (el.dataset.act === 'default') {
            st.plans.forEach(pl => pl.terms.forEach(t => { t.is_default = false; }));
            p.terms[+el.dataset.t].is_default = true;
        } else if (el.dataset.act === 'popular') {
            st.plans.forEach(pl => { pl.is_popular = false; });   // at most one "Most popular"
            p.is_popular = el.checked;
            spRenderDrawer(prefix);
        }
    });
    body.addEventListener('click', e => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const st = spState(prefix), p = cur(), act = btn.dataset.act;
        if (act === 'tab') st.active = +btn.dataset.i;
        else if (act === 'add-plan') { st.plans.push(spNewPlan()); st.active = st.plans.length - 1; }
        else if (act === 'add-term') p.terms.push(spNewTerm());
        else if (act === 'del-term') p.terms.splice(+btn.dataset.t, 1);
        else if (act === 'add-item') p.items.push({ label: '', value: '' });
        else if (act === 'del-item') p.items.splice(+btn.dataset.it, 1);
        else if (act === 'del-plan') {
            st.plans.splice(st.active, 1);
            if (!st.plans.length) { spDone(prefix); return; }   // last plan gone: back to one price / plain terms
            st.active = Math.max(0, st.active - 1);
        } else return;
        spEnsureDefault(st);
        spRenderDrawer(prefix);
        if (act === 'add-term' || act === 'add-item' || act === 'add-plan') {
            const sel = act === 'add-plan' ? '[data-f="plan_name"]' : (act === 'add-term' ? '[data-f="term_label"]' : '[data-f="label"]');
            const inputs = body.querySelectorAll(sel);
            if (inputs.length) inputs[inputs.length - 1].focus();
        }
    });
}

// "This service sells" (BJP admins): Superadmin's plans replace the service's own.
function spPlatformChanged(prefix) {
    const sel = document.getElementById(prefix + '_platform_product');
    if (!sel) return;
    const st = spState(prefix);
    const key = sel.value || null;
    if (key === st.mapped) return;
    const apply = () => {
        st.mapped = key;
        if (!key) st.locked = false;   // unlinked here: the switches work again
        st.platformPlans = key ? (SP_PLATFORM[key] || []) : [];
        if (key) {
            st.plans = [];
            sptBody(prefix).innerHTML = '';
            const sub = document.getElementById(prefix + '_is_subscription');
            if (sub) sub.checked = true;
        }
        spAfterChange(prefix);
    };
    if (key && !st.mapped && (st.plans.length || sptBody(prefix).children.length)) {
        Swal.fire({ icon: 'warning', title: SP_I18N.map_title, text: SP_I18N.map_text, showCancelButton: true,
                    confirmButtonText: SP_I18N.map_ok, cancelButtonText: SP_I18N.cancel, confirmButtonColor: '#0d6efd' })
            .then(r => { if (r.isConfirmed) apply(); else $(sel).val(st.mapped || '').trigger('change'); });
        return;
    }
    apply();
}

document.addEventListener('DOMContentLoaded', () => {
    ['svc', 'edit_svc'].forEach(prefix => {
        const drawer = spDrawer(prefix);
        if (!drawer) return;
        // Escape inside the drawer cancels the drawer only, not the whole service modal.
        drawer.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            e.preventDefault(); e.stopPropagation();
            if (spState(prefix).mapped) spHide(prefix); else spCancel(prefix);
        });
        const modal = drawer.closest('.modal');
        if (modal) {
            // Lift it out of the form's tab pane (a hidden pane would hide it) but keep it
            // inside the modal, whose focus trap would otherwise block typing in it.
            modal.appendChild(drawer);
            modal.addEventListener('hide.bs.modal', () => spHide(prefix));
        }
        // A service selling a platform product is always a subscription.
        const sub = document.getElementById(prefix + '_is_subscription');
        if (sub) sub.addEventListener('click', e => { if (spState(prefix).mapped && !sub.checked) e.preventDefault(); });
        spRenderBar(prefix);
        svcTypeApply(prefix);
    });
});

function openAddSvcModal() {
    document.getElementById('addServiceForm').reset();
    resetUnitSelect('svc_unit_container');
    document.getElementById('svcComponentBody').innerHTML = '';
    document.getElementById('add-service-message').innerHTML = '';
    addSvcComponentRow(); // Start with one empty row
    sptReset('svc');
    new bootstrap.Modal(document.getElementById('addServiceModal')).show();
}

function openEditSvcModal(product) {
    document.getElementById('editSvcForm').reset();
    document.getElementById('edit_svc_id').value = product.product_id;
    document.getElementById('edit_svc_name').value = product.product_name;
    document.getElementById('edit_svc_desc').value = product.description || '';
    const editCat = document.getElementById('edit_svc_category_id');
    if (editCat) editCat.value = product.category_id || '';
    
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
    
    if (PROJECTS_MODULE_ACTIVE) {
        document.getElementById('edit_svc_project_id').value = product.project_id || '';
        filterWarehouses(product.project_id, 'edit_svc_warehouse_id'); // Re-filter before setting value
    }
    // Simple POS with exactly one shop renders warehouse_id as a hidden input
    // already carrying that shop's id — leave it alone (never blank it out to
    // the record's possibly-null stored value); only sync it from the record
    // when it's an actual picker the user can see and change.
    const editWarehouseField = document.getElementById('edit_svc_warehouse_id');
    if (editWarehouseField && editWarehouseField.tagName === 'SELECT') {
        editWarehouseField.value = product.warehouse_id || '';
    }
    // Auto-generate Item Code if this product never had one
    const generatedCode = product.contract_item_no || ('NIP-' + String(product.product_id).padStart(5, '0'));
    document.getElementById('edit_svc_contract_no').value = generatedCode;
    document.getElementById('edit_svc_assembly_qty').value = product.assembly_quantity || 1;
    
    document.getElementById('edit-svc-message').innerHTML = '';
    document.getElementById('editSvcComponentBody').innerHTML = '';
    sptReset('edit_svc');
    sptLoad('edit_svc', product.product_id);
    
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
    // The badges are not on every form variant (removed from the modal markup) — writing to a missing
    // element threw on every Add/Edit open (pos_detail_pages_plan.md C4).
    const mBadge = document.getElementById('svc_margin_badge'), pBadge = document.getElementById('svc_profit_badge');
    if (mBadge) mBadge.textContent = margin + '%';
    if (pBadge) pBadge.textContent = SVC_I18N.profit_label + ' <?= htmlspecialchars(bmsCompanyCurrency()) ?> ' + profit.toLocaleString();
}

function calcSvcMarginEdit() {
    const cost = parseFloat(document.getElementById('edit_svc_cost').value) || 0;
    const sell = parseFloat(document.getElementById('edit_svc_sell').value) || 0;
    const profit = sell - cost;
    const margin = cost > 0 ? ((profit / cost) * 100).toFixed(2) : (sell > 0 ? '100.00' : '0.00');
    // The badges are not on every form variant (removed from the modal markup) — writing to a missing
    // element threw on every Add/Edit open (pos_detail_pages_plan.md C4).
    const mBadge = document.getElementById('edit_svc_margin_badge'), pBadge = document.getElementById('edit_svc_profit_badge');
    if (mBadge) mBadge.textContent = margin + '%';
    if (pBadge) pBadge.textContent = SVC_I18N.profit_label + ' <?= htmlspecialchars(bmsCompanyCurrency()) ?> ' + profit.toLocaleString();
}

function submitSvcForm(addAnother) {
    const form = document.getElementById('addServiceForm');
    const msgEl = document.getElementById('add-service-message');
    const name = form.querySelector('[name="product_name"]').value.trim();
    if (!name) {
        toggleSvcAddStep(1);
        Swal.fire({
            icon: 'warning',
            title: SVC_I18N.missing_field,
            text: SVC_I18N.enter_field_before_saving.replace('%s', SVC_I18N.service_product_name),
            confirmButtonColor: '#ffc107'
        });
        return;
    }
    Swal.fire({ title: SVC_I18N.saving, allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    sptCollect('svc');
    const data = new FormData(form);
    fetch(`${SVC_APP_URL}/api/create_nip_product.php`, { method:'POST', body:data })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            Swal.fire({ icon:'success', title: SVC_I18N.product_created, text: res.message || SVC_I18N.product_added, confirmButtonColor:'#0d6efd' })
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
            msgEl.innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-2"></i>' + (res.message || SVC_I18N.error_saving_product) + '</div>';
        }
    })
    .catch((err) => {
        Swal.close();
        console.error('Create Error:', err);
        msgEl.innerHTML = '<div class="alert alert-danger py-2">' + SVC_I18N.server_error_colon + ' ' + err.message + '</div>';
    });
}

document.getElementById('editSvcForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const msgEl = document.getElementById('edit-svc-message');
    
    Swal.fire({ title: SVC_I18N.saving_changes, allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    sptCollect('edit_svc');
    const data = new FormData(form);
    fetch(`${SVC_APP_URL}/api/update_nip_product.php`, { method:'POST', body:data })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            Swal.fire({ icon:'success', title: SVC_I18N.updated, text: res.message || SVC_I18N.product_updated_msg, confirmButtonColor:'#0d6efd' })
            .then(() => location.reload());
        } else {
            Swal.close();
            msgEl.innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-2"></i>' + (res.message || SVC_I18N.error_updating_product) + '</div>';
        }
    })
    .catch(() => {
        Swal.close();
        msgEl.innerHTML = '<div class="alert alert-danger py-2">' + SVC_I18N.server_error_retry + '</div>';
    });
});

function saveSvcAddAnother() { submitSvcForm(true); }

document.getElementById('addServiceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    submitSvcForm(false);
});

function deleteSvc(id, name) {
    Swal.fire({
        title: SVC_I18N.delete_nip_title,
        html: `${SVC_I18N.delete_nip_confirm.replace('%s', '<strong>' + name + '</strong>')}<br><small class="text-danger">${SVC_I18N.delete_nip_warning}</small>`,
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: SVC_I18N.yes_delete, cancelButtonText: SVC_I18N.cancel
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(`${SVC_APP_URL}/api/delete_product.php`, { product_id: id }, function(res) {
            if (res.success) { Swal.fire({icon:'success',title: SVC_I18N.deleted,timer:1200,showConfirmButton:false}).then(()=>location.reload()); }
            else { Swal.fire({icon:'error',title: SVC_I18N.error,text:res.message}); }
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

$(function () {
    // DataTable — Product Name column is wider; DataTable handles pagination & sorting
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#servicesTable')) {
        const svcTable = $('#servicesTable').DataTable({
            responsive: false,
            autoWidth: false,
            pageLength: 25,
            order: [[2, 'asc']],
            columnDefs: [
                $.extend({ targets: 0, width: '50px' }, BMSSerial.column()),
                { targets: 1, width: '110px' },  // Item Code
                { targets: 2, width: '35%'   },  // Product Name — widest, text wraps
                { targets: 3, width: '120px' },
                { targets: 4, width: '120px' },
                { targets: 5, width: '90px'  },
                { targets: 6, width: '80px'  },
                { targets: 7, width: '70px',  orderable: false, searchable: false }
            ],
            dom: '<"d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2"lf>rt<"d-flex justify-content-between align-items-center mt-2 px-1 flex-wrap gap-2"ip>',
            language: {
                search: '',
                searchPlaceholder: SVC_I18N.filter_table,
                lengthMenu: SVC_I18N.show_menu,
                emptyTable: SVC_I18N.no_nip_found,
                info: SVC_I18N.showing_items_info,
                infoEmpty: SVC_I18N.no_items_to_show,
                infoFiltered: SVC_I18N.filtered_from_max,
                paginate: { first: '«', last: '»', next: '›', previous: '‹' }
            }
        });

        // Wire the custom search input to DataTable (instant client-side search)
        $('#svcTableSearch').on('keyup input', function () {
            svcTable.search(this.value).draw();
        });
    }

    // §UI-3 — make the DB-backed Category filter a searchable Select2.
    if ($('#svcCategoryFilter').length && !$('#svcCategoryFilter').hasClass('select2-hidden-accessible')) {
        $('#svcCategoryFilter').select2({
            theme: 'bootstrap-5',
            placeholder: SVC_I18N.all_categories,
            allowClear: true,
            width: '100%'
        });
    }

    // §UI-3 — DB-backed dropdowns inside the Add / Edit Service modals.
    // Init on shown.bs.modal (so dropdownParent is correct and edit-prefill
    // values are already set). project→warehouse filtering keeps working
    // because Select2 fires the native 'change' on selection, and
    // filterWarehouses() re-syncs the warehouse Select2 after it rebuilds it.
    const svcModalSelects = {
        addServiceModal: ['#svc_tax_id', '#svc_project_id', '#svc_warehouse_id', '#svc_contract_template'],
        editSvcModal:    ['#edit_svc_tax', '#edit_svc_project_id', '#edit_svc_warehouse_id', '#edit_svc_contract_template']
    };
    Object.keys(svcModalSelects).forEach(function (modalId) {
        const modalEl = document.getElementById(modalId);
        if (!modalEl) return;
        $(modalEl).on('shown.bs.modal', function () {
            svcModalSelects[modalId].forEach(function (sel) {
                const $el = $(sel);
                // Simple POS with exactly one shop renders warehouse_id as a
                // hidden input (auto-assigned, no picker shown) — Select2 only
                // applies to actual <select> elements.
                if (!$el.length || !$el.is('select')) return;
                if (!$el.hasClass('select2-hidden-accessible')) {
                    $el.select2({ theme: 'bootstrap-5', dropdownParent: $(modalEl), width: '100%', allowClear: true });
                }
                $el.trigger('change.select2');
            });
        });
    });

    // ?edit=N deep link (C4) — after the handlers above are wired.
    const editSvcOnLoad = <?= json_encode($edit_svc) ?>;
    if (editSvcOnLoad) setTimeout(function () { openEditSvcModal(editSvcOnLoad); }, 0);
});
</script>

<?php includeFooter(); ?>
