<?php
// File: app/bms/stock/warehouse_view.php
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('warehouses');
logActivity($pdo, $_SESSION['user_id'], 'VIEW', '[Warehouse View] Page viewed');

// Include the header
includeHeader();


// Get warehouse ID
$warehouse_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

if ($warehouse_id <= 0) {
    $_SESSION['error'] = isShopLabel() ? "Invalid shop ID" : "Invalid warehouse ID";
    header("Location: warehouses.php");
    exit();
}
// Phase 6 (pos_upgrade_plan.md): gate directly on warehouse scope, not the
// warehouse's project — a user's project assignment no longer implies access
// to every warehouse tied to it.
require_once __DIR__ . '/../../../core/warehouse_scope.php';
if (!userCan('warehouse', $warehouse_id)) {
    if (!headers_sent()) http_response_code(403);
    die(isShopLabel() ? 'Access denied: this shop is not in your assigned scope.' : 'Access denied: this warehouse is not in your assigned scope.');
}

// "Batches" tab (2026-09-17 request) — Simple POS only; a normal tenant
// sees the Recent Activity card exactly as before, no tab at all.
require_once __DIR__ . '/../../../core/pos_nav.php';
$pos_simple_mode      = posSimpleModeEnabled();
$can_edit_warehouse   = canEdit('warehouses');

// Fetch warehouse details
$query = "
    SELECT w.*, 
           u_c.username as creator_name,
           u_u.username as updater_name
    FROM warehouses w
    LEFT JOIN users u_c ON w.created_by = u_c.user_id
    LEFT JOIN users u_u ON w.updated_by = u_u.user_id
    WHERE w.warehouse_id = ? AND w.status != 'deleted'
";
$stmt = $pdo->prepare($query);
$stmt->execute([$warehouse_id]);
$warehouse = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$warehouse) {
    $_SESSION['error'] = isShopLabel() ? "Shop not found" : "Warehouse not found";
    header("Location: warehouses.php");
    exit();
}

// Project context for the print header (Project / Contract No), matching the
// pattern used by other project-scoped print templates (do_view.php, print_purchase_order.php).
$project_name = '';
$contract_no  = '';
if ($project_id > 0) {
    $proj_stmt = $pdo->prepare("SELECT project_name, contract_number FROM projects WHERE project_id = ?");
    $proj_stmt->execute([$project_id]);
    $proj_row     = $proj_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $project_name = $proj_row['project_name'] ?? '';
    $contract_no  = $proj_row['contract_number'] ?? '';
}

// Fetch locations for this warehouse
$query = "SELECT * FROM locations WHERE warehouse_id = ? AND status != 'deleted' ORDER BY location_name";
$stmt = $pdo->prepare($query);
$stmt->execute([$warehouse_id]);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch current stock in this warehouse
$query = "
    SELECT ps.*, p.product_name, p.sku, p.product_code, p.cost_price, p.selling_price,
           p.reorder_level, p.max_stock_level,
           c.category_name, b.brand_name, l.location_name
    FROM product_stocks ps
    JOIN products p ON ps.product_id = p.product_id
    LEFT JOIN categories c ON p.category_id = c.category_id
    LEFT JOIN brands b ON p.brand_id = b.brand_id
    LEFT JOIN locations l ON ps.location_id = l.location_id
    WHERE ps.warehouse_id = ?
    ORDER BY p.product_name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$warehouse_id]);
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals and health counts
$total_items      = count($stocks);
$total_quantity   = array_sum(array_column($stocks, 'stock_quantity'));
$total_value_cost = 0;
$health_out = $health_low = $health_over = 0;
foreach ($stocks as $s) {
    $total_value_cost += $s['stock_quantity'] * $s['cost_price'];
    $qty     = (float)$s['stock_quantity'];
    $reorder = (float)($s['reorder_level']   ?? 0);
    $maxlvl  = (float)($s['max_stock_level'] ?? 0);
    if ($qty <= 0)                              $health_out++;
    elseif ($reorder > 0 && $qty < $reorder)   $health_low++;
    elseif ($maxlvl  > 0 && $qty > $maxlvl)    $health_over++;
}

// Recent activity — last 8 movements for this warehouse
$stmt_recent = $pdo->prepare("
    SELECT sm.movement_type, sm.quantity, sm.movement_date, sm.created_at,
           p.product_name, sm.reference_number
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.product_id
    WHERE sm.warehouse_id = ?
    ORDER BY sm.created_at DESC
    LIMIT 8
");
$stmt_recent->execute([$warehouse_id]);
$recent_movements = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4">

        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">WAREHOUSE INVENTORY REPORT</h2>
        <h5 class="text-muted"><?= caseFormat($warehouse['warehouse_name']) ?> (<?= htmlspecialchars($warehouse['warehouse_code']) ?>)</h5>
        <?php if (!empty($project_name)): ?>
        <p class="mb-0" style="font-size: 11pt; font-weight: 700;">Project: <?= htmlspecialchars($project_name) ?></p>
        <?php endif; ?>
        <?php if (!empty($contract_no)): ?>
        <p class="mb-0 text-muted" style="font-size: 10pt; font-weight: 600;">Contract No: <?= htmlspecialchars($contract_no) ?></p>
        <?php endif; ?>
        <p class="small text-muted">Generated on: <?= date('F d, Y H:i') ?></p>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Breadcrumb & Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <?php if($project_id > 0): ?>
                        <li class="breadcrumb-item"><a href="<?= getUrl('project_view') . '?id=' . $project_id . '#procurements' ?>">Project Details</a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item"><a href="warehouses.php"><?= wLabel('Warehouses', 'Shops') ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active"><?= caseFormat($warehouse['warehouse_name']) ?></li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-house-door text-success"></i> <?= caseFormat($warehouse['warehouse_name'] ?? '') ?></h2>
                    <p class="text-muted mb-0">Code: <span class="badge bg-light text-dark"><?= htmlspecialchars($warehouse['warehouse_code'] ?? '') ?></span></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= $project_id > 0 ? getUrl('project_view') . '?id=' . $project_id . '#procurements' : getUrl('warehouses') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> <?= $project_id > 0 ? 'Back to Project' : 'Back to List' ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($pos_simple_mode): ?>
    <!-- Public Shop Catalog link — Simple POS only (2026-09-17 request) -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h6 class="mb-1 fw-bold"><i class="bi bi-link-45deg text-primary me-1"></i> <?= t('Public Shop Catalog Link') ?></h6>
                        <p class="text-muted small mb-0" id="scLinkStatus">
                            <?php if (!empty($warehouse['public_catalog_token_hash'])): ?>
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <?= t('A link is active') ?>
                                <?php if (!empty($warehouse['public_catalog_token_created_at'])): ?>
                                    &middot; <?= t('Generated') ?> <?= date('d M Y', strtotime($warehouse['public_catalog_token_created_at'])) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <i class="bi bi-dash-circle text-muted"></i> <?= t('No link generated yet — customers cannot view this shop\'s products online.') ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($can_edit_warehouse): ?>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-primary" id="scGenerateBtn">
                            <i class="bi bi-magic"></i>
                            <?= !empty($warehouse['public_catalog_token_hash']) ? t('Regenerate Link') : t('Generate Link') ?>
                        </button>
                        <?php if (!empty($warehouse['public_catalog_token_hash'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="scRevokeBtn">
                            <i class="bi bi-x-circle"></i> <?= t('Revoke') ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row mb-5 g-4">
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100 p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-geo-alt"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= count($locations) ?></h4>
                        <small class="text-uppercase small fw-bold">Active Locations</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100 p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-box"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($total_items) ?></h4>
                        <small class="text-uppercase small fw-bold">Total Stock Item</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100 p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-layers"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($total_quantity, 0) ?></h4>
                        <small class="text-uppercase small fw-bold">Total Quantity</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100 p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-cash-stack"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= format_currency($total_value_cost) ?></h4>
                        <small class="text-uppercase small fw-bold">Stock Value (Cost)</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Sidebar / Details -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><?= wLabel('Warehouse Information', 'Shop Information') ?></h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">Status</span>
                            <?= get_status_badge($warehouse['status']) ?>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">Primary</span>
                            <span><?= $warehouse['is_primary'] ? '<span class="text-primary fw-bold">Yes</span>' : 'No' ?></span>
                        </li>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block mb-1">Capacity</span>
                            <?php
                            $cap_total = (float)($warehouse['capacity'] ?? 0);
                            if ($cap_total > 0):
                                $cap_pct   = min(100, (int)round($total_quantity / $cap_total * 100));
                                $cap_color = $cap_pct >= 90 ? 'danger' : ($cap_pct >= 70 ? 'warning' : 'success');
                            ?>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold"><?= number_format($total_quantity, 0) ?> / <?= number_format($cap_total, 0) ?> units</span>
                                <span class="badge bg-<?= $cap_color ?>"><?= $cap_pct ?>%</span>
                            </div>
                            <div style="height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;">
                                <div style="height:100%;width:<?= $cap_pct ?>%;background:var(--bs-<?= $cap_color ?>);border-radius:5px;"></div>
                            </div>
                            <?php else: ?>
                            <span class="text-muted fst-italic small">Not set</span>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block mb-1">Location</span>
                            <strong><i class="bi bi-geo-alt"></i> <?= !empty($warehouse['location']) ? caseFormat($warehouse['location']) : 'Not specified' ?></strong>
                        </li>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block mb-1">Full Address</span>
                            <span><?= !empty($warehouse['address']) ? nl2br(caseFormat($warehouse['address'])) : 'No address provided' ?></span>
                        </li>
                        <li class="list-group-item px-0">
                            <span class="text-muted d-block mb-1">Contact Person</span>
                            <strong><?= !empty($warehouse['contact_person']) ? caseFormat($warehouse['contact_person']) : 'N/A' ?></strong>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">Phone</span>
                            <span><?= htmlspecialchars($warehouse['phone'] ?: 'N/A') ?></span>
                        </li>
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span class="text-muted">Email</span>
                            <span><?= htmlspecialchars($warehouse['email'] ?: 'N/A') ?></span>
                        </li>
                    </ul>
                </div>
                <div class="card-footer bg-light">
                    <small class="text-muted">Created by: <?= !empty($warehouse['creator_name']) ? caseFormat($warehouse['creator_name']) : 'System' ?> (<?= date('M d, Y', strtotime($warehouse['created_at'])) ?>)</small>
                </div>
            </div>

            <!-- Locations List -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Storage Locations</h5>
                    <button class="btn btn-sm btn-link" onclick="manageLocations(<?= $warehouse_id ?>)">Manage</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Location Name</th>
                                    <th>Code</th>
                                    <th class="text-center">Active</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($locations)): ?>
                                    <tr><td colspan="3" class="text-center py-3">No locations defined</td></tr>
                                <?php else: ?>
                                    <?php foreach ($locations as $loc): ?>
                                        <tr>
                                            <td class="ps-3"><?= htmlspecialchars($loc['location_name'] ?? '') ?></td>
                                            <td><code class="text-dark"><?= htmlspecialchars($loc['location_code'] ?? '') ?></code></td>
                                            <td class="text-center">
                                                <span class="status-dot <?= ($loc['status'] ?? '') == 'active' ? 'active-dot' : 'inactive-dot' ?>"></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php
            // Shared with both branches below so the Recent Activity DATA
            // and its rendering logic stay single-sourced regardless of
            // which chrome wraps it (plain card vs. Simple-POS tab pane) —
            // only the wrapper differs, never the content.
            if (!function_exists('render_warehouse_activity_items')) {
                function render_warehouse_activity_items(array $recent_movements, bool $empty_translated = false): string {
                    if (empty($recent_movements)) {
                        $msg = $empty_translated ? t('No movements recorded yet') : 'No movements recorded yet';
                        return '<div class="text-center py-3 text-muted small">' . htmlspecialchars($msg) . '</div>';
                    }
                    $in_types = ['purchase_in','adjustment_in','transfer_in','return_in','production_in','found','correction'];
                    $html = '<ul class="list-group list-group-flush">';
                    foreach ($recent_movements as $mv) {
                        $mv_in    = in_array($mv['movement_type'], $in_types);
                        $mv_color = $mv_in ? 'success' : 'danger';
                        $mv_icon  = $mv_in ? 'bi-arrow-down-circle-fill' : 'bi-arrow-up-circle-fill';
                        $mv_sign  = $mv_in ? '+' : '−';
                        $mv_label = ucwords(str_replace('_', ' ', $mv['movement_type']));
                        $mv_date  = date('d M', strtotime($mv['movement_date'] ?: $mv['created_at']));
                        $html .= '<li class="list-group-item px-3 py-2">'
                               . '<div class="d-flex align-items-start gap-2">'
                               . '<i class="bi ' . $mv_icon . ' text-' . $mv_color . ' mt-1" style="font-size:0.85rem;flex-shrink:0;"></i>'
                               . '<div class="flex-grow-1" style="min-width:0;">'
                               . '<div class="fw-semibold text-truncate" style="font-size:0.8rem;">' . caseFormat($mv['product_name']) . '</div>'
                               . '<div class="text-muted" style="font-size:0.68rem;">' . $mv_label . ($mv['reference_number'] ? ' · ' . htmlspecialchars($mv['reference_number']) : '') . '</div>'
                               . '</div>'
                               . '<div class="text-end flex-shrink-0">'
                               . '<div class="fw-bold small text-' . $mv_color . '">' . $mv_sign . number_format((float)$mv['quantity'], 0) . '</div>'
                               . '<div class="text-muted" style="font-size:0.68rem;">' . $mv_date . '</div>'
                               . '</div></div></li>';
                    }
                    $html .= '</ul>';
                    return $html;
                }
            }
            ?>
            <?php if ($pos_simple_mode): ?>
            <!-- Recent Activity / Batches — Simple POS only (request 2026-09-17) -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white p-0">
                    <ul class="nav nav-tabs card-header-tabs px-2 pt-2" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-recent-activity" type="button" role="tab">
                                <i class="bi bi-clock-history me-1"></i> <?= t('Recent Activity') ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-warehouse-batches" type="button" role="tab">
                                <i class="bi bi-box-seam me-1"></i> <?= t('Batches') ?>
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="pane-recent-activity" role="tabpanel">
                        <div class="d-flex justify-content-end px-3 pt-2">
                            <span class="badge bg-light text-dark border"><?= t('Last 8') ?></span>
                        </div>
                        <div class="card-body p-0">
                            <?= render_warehouse_activity_items($recent_movements, true) ?>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="pane-warehouse-batches" role="tabpanel">
                        <?php include __DIR__ . '/warehouse_batches_tab.php'; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Recent Activity — unchanged for non-Simple-POS tenants -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history text-primary me-1"></i> Recent Activity</h5>
                    <span class="badge bg-light text-dark border">Last 8</span>
                </div>
                <div class="card-body p-0">
                    <?= render_warehouse_activity_items($recent_movements, false) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Main Content (Stock List) -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 d-print-none">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-box-seam text-success"></i> Current Inventory</h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-success" onclick="transferStock(<?= $warehouse_id ?>)">
                            <i class="bi bi-truck"></i> Transfer Stock
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Actions Row -->
                    <div id="warehouseViewActionsRow" class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="btn-group dropdown shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; color: #444;">
                                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                                </button>
                                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportTable()" style="background: #fff; color: #444;">
                                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                                </button>
                                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="window.print()" style="background: #fff; color: #444;">
                                    <i class="bi bi-printer text-primary me-1"></i> Print
                                </button>
                            </div>

                            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                                <select class="form-select form-select-sm border-0 fw-bold p-0" id="tableLength" style="width: 60px; box-shadow: none; background: transparent;">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="-1">All</option>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex align-items-center warehouse-search-box">
                            <div class="input-group input-group-sm shadow-sm" style="width: 250px;">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" id="customSearch" class="form-control border-start-0" placeholder="Search inventory...">
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="warehouseStockTable">
                            <thead class="table-light">
                                <tr>
                                    <th>S/NO</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Location</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-center">Health</th>
                                    <th class="text-end">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stocks as $index => $s):
                                    $s_value  = $s['stock_quantity'] * $s['cost_price'];
                                    $s_qty    = (float)$s['stock_quantity'];
                                    $s_reord  = (float)($s['reorder_level']   ?? 0);
                                    $s_maxlvl = (float)($s['max_stock_level'] ?? 0);
                                    if ($s_qty <= 0) {
                                        $h_label = 'Out'; $h_badge = 'danger';  $h_row = 'table-danger';
                                    } elseif ($s_reord > 0 && $s_qty < $s_reord) {
                                        $h_label = 'Low'; $h_badge = 'warning'; $h_row = 'table-warning';
                                    } elseif ($s_maxlvl > 0 && $s_qty > $s_maxlvl) {
                                        $h_label = 'Over'; $h_badge = 'info';   $h_row = '';
                                    } else {
                                        $h_label = 'OK';  $h_badge = 'success'; $h_row = '';
                                    }
                                ?>
                                <tr class="<?= $h_row ?>">
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <strong><?= caseFormat($s['product_name'] ?? '') ?></strong><br>
                                        <small class="text-muted"><?= caseFormat($s['category_name'] ?? 'N/A') ?> | <?= caseFormat($s['brand_name'] ?? 'N/A') ?></small>
                                    </td>
                                    <td><code class="custom-code text-dark"><?= htmlspecialchars(($s['sku'] ?: $s['product_code']) ?? '') ?></code></td>
                                    <td>
                                        <span class="badge bg-light text-dark"><i class="bi bi-geo"></i> <?= htmlspecialchars(($s['location_name'] ?: 'Default') ?? '') ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bold"><?= format_number($s['stock_quantity'], 0) ?></span>
                                        <?php if ($s_reord > 0): ?>
                                        <div style="font-size:0.65rem;color:#aaa;">min <?= number_format($s_reord, 0) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $h_badge ?>"><?= $h_label ?></span>
                                    </td>
                                    <td class="text-end">
                                        <?= format_currency($s_value) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="4" class="text-end">Total:</td>
                                    <td class="text-center"><?= number_format($total_quantity, 0) ?></td>
                                    <td></td>
                                    <td class="text-end"><?= format_currency($total_value_cost) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.active-dot { background-color: #198754; }
.inactive-dot { background-color: #dc3545; }
.custom-code {
    background: #f8f9fa;
    padding: 2px 5px;
    border-radius: 4px;
}

@page { margin: 10mm 8mm 16mm 8mm; }
@media print {
    .d-print-none, .breadcrumb, .btn, .btn-group, .col-lg-4, .card-header,
    .dataTables_info, .dataTables_paginate, .dataTables_length, .dataTables_filter,
    footer {
        display: none !important;
    }
    .col-lg-8 { 
        width: 100% !important; 
        flex: 0 0 100% !important; 
        max-width: 100% !important; 
    }
    .card {
        box-shadow: none !important;
        border: 1px solid #dee2e6 !important;
    }
    body { background: white !important; }

    /* The site-wide print rule (assets/css/responsive.css) marks every .card
       as break-inside:avoid, so the whole inventory card — including its full
       table — was treated as one unsplittable block: if it didn't entirely
       fit in whatever space remained on page 1 after the header/stat cards,
       the browser deferred the ENTIRE card to page 2, leaving page 1 mostly
       blank. Overriding it here lets the table start filling page 1
       immediately and continue naturally onto page 2+; individual rows stay
       intact via responsive.css's `tr { page-break-inside: avoid }`. */
    .col-lg-8 .card {
        break-inside: auto !important;
        page-break-inside: auto !important;
    }

    /* Stats cards — 4 across in one row regardless of portrait/landscape.
       Font sizes are tightened (and the icon dropped) so even the longest
       currency value on this page fits on a single line at quarter-width. */
    .row.mb-5.g-4 {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        gap: 8px !important;
    }
    .row.mb-5.g-4 .col-md-3 {
        width: calc(25% - 6px) !important;
        flex: 0 0 calc(25% - 6px) !important;
        max-width: calc(25% - 6px) !important;
        margin-bottom: 10px !important;
    }
    .custom-stat-card {
        padding: 6px 8px !important;
        background-color: #d1e7dd !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        min-height: 60px !important;
    }
    .custom-stat-card .stats-icon {
        display: none !important;
    }
    .custom-stat-card h4 {
        font-size: 0.82rem !important;
        white-space: nowrap !important;
    }
    .custom-stat-card small {
        font-size: 0.56rem !important;
        white-space: nowrap !important;
    }
}

@media (max-width: 767.98px) {
    body { overflow-x: hidden; }
    .container-fluid { overflow-x: hidden; padding-left: 0.75rem; padding-right: 0.75rem; }

    /* Actions Row (Copy/Excel/Print + Show:N on the left, a fixed 250px
       search box on the right) previously forced onto one un-wrapped flex
       line — far wider than any phone viewport, dragging the whole page
       left/right. Let it wrap and stack, and drop the fixed search width. */
    #warehouseViewActionsRow {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    #warehouseViewActionsRow > div {
        width: 100%;
        justify-content: center;
    }
    #warehouseViewActionsRow .btn-group .btn {
        padding-left: 0.6rem !important;
        padding-right: 0.6rem !important;
        font-size: 0.8rem;
    }
    #warehouseViewActionsRow .warehouse-search-box .input-group {
        width: 100% !important;
    }

    .custom-stat-card h4 { font-size: 1.35rem; }
    .stats-icon { width: 38px; height: 38px; font-size: 1.2rem; margin-right: 0.85rem; }
}
</style>

<script>
$(document).ready(function() {
    const table = $('#warehouseStockTable').DataTable({
        pageLength: 25,
        order: [[0, 'asc']],
        dom: 'rtip',
        language: {
            search: "Filter Stock:"
        }
    });

    // Custom Search
    $('#customSearch').on('keyup', function() {
        table.search(this.value).draw();
    });

    // Custom Length
    $('#tableLength').on('change', function() {
        table.page.len(this.value).draw();
    });
});

function copyTable() {
    const table = document.getElementById('warehouseStockTable');
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table data copied to clipboard', timer: 1000, showConfirmButton: false });
}

function exportTable() {
    const table = document.getElementById('warehouseStockTable');
    let csv = [];
    const rows = table.querySelectorAll("tr");
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length; j++) row.push('"' + cols[j].innerText.trim() + '"');
        csv.push(row.join(","));
    }
    const csv_string = csv.join("\n");
    const link = document.createElement("a");
    link.style.display = 'none';
    link.setAttribute('target', '_blank');
    link.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv_string));
    link.setAttribute('download', 'Warehouse_Inventory.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function manageLocations(id) {
    location.href = 'locations?warehouse_id=' + id + '<?= $project_id > 0 ? "&project_id=$project_id" : "" ?>';
}

function transferStock(id) {
    location.href = 'stock_transfers?warehouse_id=' + id + '<?= $project_id > 0 ? "&project_id=$project_id" : "" ?>';
}
</script>

<?php if ($pos_simple_mode && $can_edit_warehouse): ?>
<script>
$(document).ready(function () {
    const scWarehouseId = <?= (int)$warehouse_id ?>;
    const scCsrf = <?= json_encode(csrf_token()) ?>;

    function scShowLink(url) {
        const $wrap = $('<div class="text-start"></div>');
        $wrap.append($('<p class="small text-muted mb-2"></p>').text(<?= json_encode(t('Copy this link now — for your security, it will not be shown again. If you lose it, generate a new one.')) ?>));
        const $box = $('<div class="input-group"></div>');
        const $input = $('<input type="text" class="form-control form-control-sm" readonly>').val(url);
        const $btn = $('<button type="button" class="btn btn-sm btn-outline-primary"></button>').html('<i class="bi bi-clipboard"></i> ' + <?= json_encode(t('Copy')) ?>);
        $btn.on('click', function () {
            const markCopied = function () {
                $btn.html('<i class="bi bi-check2"></i> ' + <?= json_encode(t('Copied!')) ?>);
                setTimeout(function () { $btn.html('<i class="bi bi-clipboard"></i> ' + <?= json_encode(t('Copy')) ?>); }, 1500);
            };
            const fallback = function () {
                $input.select();
                document.execCommand('copy');
                markCopied();
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(markCopied).catch(fallback);
            } else {
                fallback();
            }
        });
        $box.append($input).append($btn);
        $wrap.append($box);
        Swal.fire({
            icon: 'success',
            title: <?= json_encode(t('Link generated')) ?>,
            html: $wrap,
            confirmButtonText: <?= json_encode(t('Done')) ?>,
        });
    }

    $('#scGenerateBtn').on('click', function () {
        const $btn = $(this);
        const isRegenerate = $btn.text().trim().indexOf(<?= json_encode(t('Regenerate Link')) ?>) !== -1;
        const proceed = function () {
            const orig = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
            $.post(<?= json_encode(buildUrl('api/stock/generate_shop_catalog_link.php')) ?>, {
                warehouse_id: scWarehouseId, _csrf: scCsrf
            }, function (res) {
                if (res.success) {
                    scShowLink(res.url);
                    $('#scLinkStatus').html('<i class="bi bi-check-circle-fill text-success"></i> ' + <?= json_encode(t('A link is active')) ?>);
                } else {
                    Swal.fire(<?= json_encode(t('Error')) ?>, res.message, 'error');
                }
            }, 'json').fail(function () {
                Swal.fire(<?= json_encode(t('Error')) ?>, <?= json_encode(t('Server error.')) ?>, 'error');
            }).always(function () {
                $btn.prop('disabled', false).html(orig);
            });
        };

        if (isRegenerate) {
            Swal.fire({
                icon: 'warning',
                title: <?= json_encode(t('Regenerate this link?')) ?>,
                text: <?= json_encode(t('The old link will stop working immediately.')) ?>,
                showCancelButton: true,
                confirmButtonColor: '#0d6efd',
                confirmButtonText: <?= json_encode(t('Yes, regenerate')) ?>,
            }).then(function (r) { if (r.isConfirmed) proceed(); });
        } else {
            proceed();
        }
    });

    $('#scRevokeBtn').on('click', function () {
        Swal.fire({
            icon: 'warning',
            title: <?= json_encode(t('Revoke this link?')) ?>,
            text: <?= json_encode(t('Customers using the current link will no longer be able to view this shop\'s products.')) ?>,
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: <?= json_encode(t('Yes, revoke')) ?>,
        }).then(function (r) {
            if (!r.isConfirmed) return;
            $.post(<?= json_encode(buildUrl('api/stock/revoke_shop_catalog_link.php')) ?>, {
                warehouse_id: scWarehouseId, _csrf: scCsrf
            }, function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: <?= json_encode(t('Revoked')) ?>, text: res.message, timer: 1800, showConfirmButton: false })
                        .then(function () { location.reload(); });
                } else {
                    Swal.fire(<?= json_encode(t('Error')) ?>, res.message, 'error');
                }
            }, 'json').fail(function () {
                Swal.fire(<?= json_encode(t('Error')) ?>, <?= json_encode(t('Server error.')) ?>, 'error');
            });
        });
    });
});
</script>
<?php endif; ?>

<?php
includeFooter();
ob_end_flush();
?>
