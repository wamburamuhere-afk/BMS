<?php
// File: stock_adjustments.php
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';

autoEnforcePermission('stock_adjustments');
includeHeader();

$can_adjust_stock = isAdmin() || canEdit('stock_adjustments');

$search           = isset($_GET['search'])          ? trim($_GET['search'])          : '';
$product_id       = isset($_GET['product_id'])       ? intval($_GET['product_id'])       : 0;
$warehouse_id     = isset($_GET['warehouse_id'])     ? intval($_GET['warehouse_id'])     : 0;
$adjustment_type  = isset($_GET['adjustment_type'])  ? $_GET['adjustment_type']          : '';
$reason           = isset($_GET['reason'])           ? $_GET['reason']                   : '';
$user_id_filter   = isset($_GET['user_id'])          ? intval($_GET['user_id'])          : 0;
$date_from        = isset($_GET['date_from'])        ? $_GET['date_from']                : '';
$date_to          = isset($_GET['date_to'])          ? $_GET['date_to']                  : '';
$project_id_filter= isset($_GET['project_id'])       ? intval($_GET['project_id'])       : 0;
$page             = isset($_GET['page'])             ? max(1, intval($_GET['page']))     : 1;

if ($warehouse_id > 0 && !userCan('warehouse', $warehouse_id)) {
    $warehouse_id = 0; // ignore an out-of-scope filter rather than error on a list page
}
$per_page         = isset($_GET['per_page'])         ? max(1, intval($_GET['per_page'])) : 25;
if ($per_page > 500) $per_page = 500;

$offset = ($page - 1) * $per_page;

$query = "
    SELECT sm.*,
        p.product_id, p.product_name, p.sku, p.barcode,
        u.username as adjusted_by_name,
        w.warehouse_name,
        loc.location_name,
        proj.project_name,
        sm.quantity * sm.unit_cost as total_value,
        sm.stock_after - sm.stock_before as stock_change
    FROM stock_movements sm
    LEFT JOIN products p   ON sm.product_id   = p.product_id
    LEFT JOIN users u      ON sm.created_by   = u.user_id
    LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN locations loc ON sm.location_id = loc.location_id
    LEFT JOIN projects proj ON sm.project_id  = proj.project_id
    WHERE sm.movement_type IN ('adjustment_in','adjustment_out','correction','damaged','expired','found','theft','adjustment','stock_adjustment')
";

$params = [];
$conditions = [];

if (!empty($search)) {
    $conditions[] = "(p.product_name LIKE :search1 OR p.sku LIKE :search2 OR p.barcode LIKE :search3 OR sm.reference_number LIKE :search4 OR sm.notes LIKE :search5)";
    $params[':search1'] = $params[':search2'] = $params[':search3'] = $params[':search4'] = $params[':search5'] = "%$search%";
}
if ($product_id > 0)         { $conditions[] = "sm.product_id = :product_id";        $params[':product_id']        = $product_id; }
if ($warehouse_id > 0)       { $conditions[] = "sm.warehouse_id = :warehouse_id";    $params[':warehouse_id']      = $warehouse_id; }
if (!empty($adjustment_type)){ $conditions[] = "sm.movement_type = :adjustment_type";$params[':adjustment_type']   = $adjustment_type; }
if (!empty($reason))         { $conditions[] = "sm.reason = :reason";                $params[':reason']            = $reason; }
if ($user_id_filter > 0)     { $conditions[] = "sm.created_by = :user_id";           $params[':user_id']           = $user_id_filter; }
if (!empty($date_from))      { $conditions[] = "DATE(sm.created_at) >= :date_from";  $params[':date_from']         = $date_from; }
if (!empty($date_to))        { $conditions[] = "DATE(sm.created_at) <= :date_to";    $params[':date_to']           = $date_to; }
if ($project_id_filter > 0)  { $conditions[] = "sm.project_id = :project_id";        $params[':project_id']        = $project_id_filter; }

if (!empty($conditions)) $query .= " AND " . implode(" AND ", $conditions);
$query .= scopeFilterSqlNullable('project', 'sm');
$query .= scopeFilterSqlNullable('warehouse', 'sm');
$paged_query = $query . " ORDER BY sm.created_at DESC LIMIT :limit OFFSET :offset";

try {
    $stmt = $pdo->prepare($paged_query);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sq = "SELECT COUNT(*) as total,
        COALESCE(SUM(CASE WHEN sm.movement_type IN ('adjustment_in','found') THEN sm.quantity ELSE 0 END),0) as qty_in,
        COALESCE(SUM(CASE WHEN sm.movement_type NOT IN ('adjustment_in','found') THEN sm.quantity ELSE 0 END),0) as qty_out,
        COALESCE(SUM(CASE WHEN sm.movement_type IN ('adjustment_in','found') THEN sm.quantity*sm.unit_cost ELSE -(sm.quantity*sm.unit_cost) END),0) as net_value
        FROM stock_movements sm LEFT JOIN products p ON sm.product_id=p.product_id
        WHERE sm.movement_type IN ('adjustment_in','adjustment_out','correction','damaged','expired','found','theft','adjustment','stock_adjustment')";
    if (!empty($conditions)) $sq .= " AND " . implode(" AND ", $conditions);
    $sq .= scopeFilterSqlNullable('project', 'sm');
    $sq .= scopeFilterSqlNullable('warehouse', 'sm');
    $ss = $pdo->prepare($sq);
    foreach ($params as $k => $v) $ss->bindValue($k, $v);
    $ss->execute();
    $sd = $ss->fetch(PDO::FETCH_ASSOC);

    $total_count        = (int)($sd['total']     ?? 0);
    $total_quantity_in  = (float)($sd['qty_in']  ?? 0);
    $total_quantity_out = (float)($sd['qty_out'] ?? 0);
    $net_value_change   = (float)($sd['net_value']?? 0);
    $total_pages        = ceil($total_count / $per_page);
} catch (PDOException $e) {
    $adjustments = [];
    $total_count = $total_pages = 0;
    $total_quantity_in = $total_quantity_out = $net_value_change = 0;
}

try { $products   = $pdo->query("SELECT product_id, product_name, sku FROM products WHERE status='active' AND is_service = 0 ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){ $products=[]; }
// Shared helper — also respects the user's direct warehouse grant (Phase 6,
// pos_upgrade_plan.md). The client-side project cascade below (its own
// verified copy, not yet migrated to warehouse-project-filter.js — see
// tests/test_warehouse_project_filter_cli.php) narrows this already-scoped
// set further by project; it is untouched by this change.
try { $warehouses = warehousesForSelect($pdo); } catch(Exception $e){ $warehouses=[]; }
try { $users      = $pdo->query("SELECT user_id, username FROM users WHERE status='active' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){ $users=[]; }

$enable_projects = getSetting('enable_projects', 0);
$projects = [];
if ($enable_projects) {
    try { $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status!='cancelled' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){ $projects=[]; }
}

$c_name     = getSetting('company_name', 'BMS');
$c_logo     = getSetting('company_logo', '');
$print_user = ucwords(trim(($_SESSION['first_name']??'').' '.($_SESSION['last_name']??'')));
$print_role = ucwords($_SESSION['user_role'] ?? 'Staff');
$print_date = date('d M, Y \a\t h:i A');

$adjustment_types = [
    'adjustment_in'  => 'Stock In (Add)',
    'adjustment_out' => 'Stock Out (Remove)',
    'correction'     => 'Stock Correction',
    'damaged'        => 'Damaged Goods',
    'expired'        => 'Expired Products',
    'found'          => 'Found Stock',
    'theft'          => 'Theft/Loss'
];

$reasons = [
    'damaged'         => 'Damaged Goods',
    'expired'         => 'Expired Products',
    'found'           => 'Found Stock',
    'theft'           => 'Theft/Loss',
    'correction'      => 'Stock Correction',
    'purchase_return' => 'Purchase Return',
    'quality_check'   => 'Quality Check',
    'display_sample'  => 'Display Sample',
    'demo_unit'       => 'Demo Unit',
    'employee_use'    => 'Employee Use',
    'other'           => 'Other'
];

$total_adjustments = $total_count;

function get_adjustment_type_badge($type) {
    $b = ['adjustment_in'=>'success','adjustment_out'=>'danger','correction'=>'warning','damaged'=>'dark','expired'=>'secondary','found'=>'info','theft'=>'danger'];
    $l = ['adjustment_in'=>'Stock In','adjustment_out'=>'Stock Out','correction'=>'Correction','damaged'=>'Damaged','expired'=>'Expired','found'=>'Found','theft'=>'Theft'];
    return '<span class="badge bg-'.($b[$type]??'secondary').'">'.($l[$type]??$type).'</span>';
}

function get_pagination_url($page) {
    $p = $_GET; $p['page'] = $page;
    return getUrl('stock_adjustments').'?'.http_build_query($p);
}
?>

<div class="stock-adjustments-dashboard p-3 p-md-4" style="background:#fff;min-height:100vh;">

    <!-- ===== PRINT HEADER ===== -->
    <div class="d-none d-print-block text-center mb-3">
       
        <h2 style="color:#495057;font-weight:600;text-transform:uppercase;margin:6px 0 2px;font-size:13pt;letter-spacing:2px;">STOCK ADJUSTMENTS</h2>
        <p style="color:#444;margin:0;font-size:8pt;font-weight:600;text-transform:uppercase;">Generated At: <?= date('d M Y, H:i') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin-top:10px;margin-bottom:18px;"></div>
    </div>

    <!-- ===== PRINT SUMMARY CARDS ===== -->
    <div class="d-none d-print-block mb-3">
        <div class="row g-2">
            <?php foreach([
                ['Total Adjustments', $total_adjustments],
                ['Stock In (Qty)',    number_format($total_quantity_in,2)],
                ['Stock Out (Qty)',   number_format($total_quantity_out,2)],
                ['Net Value Change',  format_currency($net_value_change)]
            ] as $sc): ?>
            <div class="col" style="flex:1 0 0%;">
                <div style="border:1px solid #dee2e6;padding:8px;text-align:center;">
                    <p style="color:#666;font-size:7pt;text-transform:uppercase;margin-bottom:2px;font-weight:600;"><?= $sc[0] ?></p>
                    <h4 style="color:#333;font-weight:800;margin:0;font-size:12pt;"><?= $sc[1] ?></h4>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ===== PRINT FOOTER ===== -->
    <div class="print-footer d-none d-print-block">
        <p class="mb-1" style="font-size:8pt;">
            This document was Printed by
            <strong><?= safe_output($print_user) ?> - <?= safe_output($print_role) ?></strong>
            on <strong><?= $print_date ?></strong>
        </p>
        <p class="mb-0 fw-bold text-primary" style="font-size:9pt;letter-spacing:0.5px;">Powered By BJP Technologies &copy; 2026</p>
    </div>

    <!-- ===== BREADCRUMB ===== -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('products') ?>">Inventory</a></li>
            <li class="breadcrumb-item active">Stock Adjustments</li>
        </ol>
    </nav>

    <!-- ===== PAGE HEADER ===== -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4 d-print-none">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-arrow-left-right text-primary me-2"></i>Stock Adjustments</h2>
            <p class="text-muted mb-0 small">Manage stock adjustments, corrections and losses</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($project_id_filter > 0): ?>
            <a href="<?= getUrl('project_view?id='.$project_id_filter) ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Project
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#newAdjustmentModal">
                <i class="bi bi-plus-circle me-1"></i> New Adjustment
            </button>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="bulkAdjustment()">
                <i class="bi bi-upload me-1"></i> Bulk Upload
            </button>
        </div>
    </div>

    <!-- ===== STAT CARDS ===== -->
    <div class="row g-3 mb-4 d-print-none">
        <?php
        $cards = [
            ['bi-list-check',        $total_adjustments,                    'Total Adjustments'],
            ['bi-box-arrow-in-down', number_format($total_quantity_in,2),   'Stock In (Qty)'],
            ['bi-box-arrow-up',      number_format($total_quantity_out,2),  'Stock Out (Qty)'],
            ['bi-graph-up',          format_currency($net_value_change),    'Net Value Change'],
        ];
        foreach ($cards as $c): ?>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3 d-flex align-items-center gap-2">
                    <div class="stats-icon d-none d-sm-flex flex-shrink-0"><i class="bi <?= $c[0] ?>"></i></div>
                    <div class="overflow-hidden flex-grow-1">
                        <h4 class="mb-0 fw-bold" style="font-size:clamp(.9rem,2.5vw,1.2rem);line-height:1.2;word-break:break-word;"><?= $c[1] ?></h4>
                        <small class="text-uppercase fw-bold opacity-75" style="font-size:clamp(.6rem,1.5vw,.7rem);display:block;"><?= $c[2] ?></small>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ===== FILTERS ===== -->
    <div class="card shadow-sm border-0 mb-4 d-print-none">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel text-primary me-2"></i>Filters &amp; Parameters</h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label small fw-bold">Search</label>
                    <input type="text" class="form-control form-control-sm" name="search" value="<?= safe_output($search) ?>" placeholder="Ref #, SKU or Notes">
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label small fw-bold">Warehouse</label>
                    <select class="form-select form-select-sm" name="warehouse_id">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= $w['warehouse_id'] ?>" <?= $warehouse_id==$w['warehouse_id']?'selected':'' ?>><?= safe_output($w['warehouse_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label small fw-bold">Type</label>
                    <select class="form-select form-select-sm" name="adjustment_type">
                        <option value="">All Types</option>
                        <?php foreach ($adjustment_types as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $adjustment_type==$val?'selected':'' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3 col-md-2">
                    <label class="form-label small fw-bold">From</label>
                    <input type="date" class="form-control form-control-sm" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-6 col-sm-3 col-md-2">
                    <label class="form-label small fw-bold">To</label>
                    <input type="date" class="form-control form-control-sm" name="date_to" value="<?= $date_to ?>">
                </div>
                <?php if ($enable_projects): ?>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label small fw-bold">Project</label>
                    <select class="form-select form-select-sm" name="project_id">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $p): ?>
                        <option value="<?= $p['project_id'] ?>" <?= $project_id_filter==$p['project_id']?'selected':'' ?>><?= safe_output($p['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold"><i class="bi bi-filter me-1"></i> Apply</button>
                    <a href="<?= getUrl('stock_adjustments') ?>" class="btn btn-outline-secondary btn-sm px-4"><i class="bi bi-arrow-clockwise me-1"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== TOOLBAR ===== -->
    <div class="mb-3 d-print-none">
        <span class="badge bg-white text-dark border px-3 py-2 fs-6 rounded-2 shadow-sm">
            <i class="bi bi-arrow-left-right text-success me-1"></i> Stock Adjustment Records
        </span>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <div class="btn-group shadow-sm" style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
            <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background:#fff;color:#444;">
                <i class="bi bi-clipboard text-info me-1"></i> Copy
            </button>
            <div style="width:1px;background:#eee;height:24px;margin-top:6px;"></div>
            <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportAdjustments()" style="background:#fff;color:#444;">
                <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
            </button>
            <div style="width:1px;background:#eee;height:24px;margin-top:6px;"></div>
            <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="window.print()" style="background:#fff;color:#444;">
                <i class="bi bi-printer text-primary me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- ===== TABLE ===== -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-0">
            <?php if (count($adjustments) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="adjustmentsTable">
                    <thead class="bg-light text-uppercase small fw-bold">
                        <tr>
                            <th class="ps-3" style="width:45px;">S/NO</th>
                            <th>Date &amp; Time</th>
                            <th>Product Details</th>
                            <th>Warehouse</th>
                            <?php if ($enable_projects): ?><th>Project</th><?php endif; ?>
                            <th>Adjustment</th>
                            <th class="text-end">Value</th>
                            <th>Reason</th>
                            <th class="text-center d-print-none" style="width:60px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($adjustments as $index => $adjustment):
                            $row_number  = $offset + $index + 1;
                            $total_value = $adjustment['total_value'];
                        ?>
                        <tr>
                            <td class="ps-3"><span class="text-muted fw-bold small"><?= $row_number ?></span></td>
                            <td>
                                <div class="fw-bold small"><?= format_date($adjustment['created_at']) ?></div>
                                <?php if (!empty($adjustment['reference_number'])): ?>
                                <small class="text-muted">Ref: <?= safe_output($adjustment['reference_number']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold text-primary small"><?= safe_output($adjustment['product_name']) ?></div>
                                <code class="custom-code small"><?= safe_output($adjustment['sku']) ?></code>
                            </td>
                            <td>
                                <div class="fw-bold small"><?= safe_output($adjustment['warehouse_name']) ?></div>
                                <?php if (!empty($adjustment['location_name'])): ?>
                                <small class="text-muted">Loc: <?= safe_output($adjustment['location_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <?php if ($enable_projects): ?>
                            <td>
                                <?php if (!empty($adjustment['project_name'])): ?>
                                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 small"><?= safe_output($adjustment['project_name']) ?></span>
                                <?php else: ?>
                                <span class="text-muted small">N/A</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <div class="mb-1"><?= get_adjustment_type_badge($adjustment['movement_type']) ?></div>
                                <div class="fw-bold small <?= in_array($adjustment['movement_type'],['adjustment_in','found'])?'text-success':'text-danger' ?>">
                                    <?= in_array($adjustment['movement_type'],['adjustment_in','found'])?'+':'-' ?><?= number_format($adjustment['quantity'],2) ?> <?= $adjustment['unit'] ?>
                                </div>
                            </td>
                            <td class="text-end fw-bold small"><?= format_currency($total_value) ?></td>
                            <td>
                                <span class="badge bg-light text-dark border small"><?= safe_output($adjustment['reason']) ?></span>
                                <div class="small text-muted"><?= safe_output($adjustment['adjusted_by_name']) ?></div>
                            </td>
                            <td class="text-center d-print-none pe-2">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle px-2" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-gear"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0" style="border-radius:10px;padding:.4rem;">
                                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="viewAdjustmentDetails(<?= $adjustment['movement_id'] ?>)"><i class="bi bi-eye text-primary me-2"></i> View</a></li>
                                        <li><a class="dropdown-item py-2" href="?edit=<?= $adjustment['movement_id'] ?>"><i class="bi bi-pencil text-warning me-2"></i> Edit</a></li>
                                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="printAdjustment(<?= $adjustment['movement_id'] ?>)"><i class="bi bi-printer text-secondary me-2"></i> Print</a></li>
                                        <?php if (isAdmin()): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteAdjustment(<?= $adjustment['movement_id'] ?>)"><i class="bi bi-trash me-2"></i> Delete</a></li>
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
            <nav class="d-print-none px-3 py-2">
                <ul class="pagination justify-content-end mb-0">
                    <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= get_pagination_url(1) ?>"><i class="bi bi-chevron-double-left"></i></a></li>
                    <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= get_pagination_url($page-1) ?>"><i class="bi bi-chevron-left"></i></a></li>
                    <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
                    <li class="page-item <?= $page==$i?'active':'' ?>"><a class="page-link" href="<?= get_pagination_url($i) ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>"><a class="page-link" href="<?= get_pagination_url($page+1) ?>"><i class="bi bi-chevron-right"></i></a></li>
                    <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>"><a class="page-link" href="<?= get_pagination_url($total_pages) ?>"><i class="bi bi-chevron-double-right"></i></a></li>
                </ul>
            </nav>
            <?php endif; ?>

            <!-- Summary & Quick Actions -->
            <div class="row mt-3 px-3 pb-3 d-print-none">
                <div class="col-md-6 mb-3">
                    <div class="card border-0 bg-light">
                        <div class="card-body p-3">
                            <h6 class="fw-bold mb-2">Adjustment Summary</h6>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>Type</th><th>Count</th><th>Qty</th><th>Value</th></tr></thead>
                                    <tbody>
                                        <?php
                                        $tt = [];
                                        foreach ($adjustments as $adj) {
                                            $t = $adj['movement_type'];
                                            if (!isset($tt[$t])) $tt[$t]=['count'=>0,'quantity'=>0,'value'=>0];
                                            $tt[$t]['count']++;
                                            $tt[$t]['quantity'] += $adj['quantity'];
                                            $tt[$t]['value']    += ($adj['quantity']*$adj['unit_cost']);
                                        }
                                        foreach ($tt as $t => $tot): ?>
                                        <tr>
                                            <td><?= get_adjustment_type_badge($t) ?></td>
                                            <td><?= $tot['count'] ?></td>
                                            <td class="<?= in_array($t,['adjustment_in','found'])?'text-success':'text-danger' ?>">
                                                <?= in_array($t,['adjustment_in','found'])?'+':'-' ?><?= number_format($tot['quantity'],3) ?>
                                            </td>
                                            <td><?= format_currency($tot['value']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="card border-0 bg-light">
                        <div class="card-body p-3">
                            <h6 class="fw-bold mb-2">Quick Actions</h6>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newAdjustmentModal"><i class="bi bi-plus-circle me-1"></i> New Adjustment</button>
                                <button type="button" class="btn btn-success btn-sm" onclick="exportAdjustments()"><i class="bi bi-download me-1"></i> Export to Excel</button>
                                <button type="button" class="btn btn-info btn-sm" onclick="bulkAdjustment()"><i class="bi bi-upload me-1"></i> Bulk Adjustment</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-arrow-left-right" style="font-size:3rem;color:#6c757d;"></i>
                <h5 class="mt-3 text-muted">No Stock Adjustments Found</h5>
                <p class="text-muted small">No adjustments match your filter criteria.</p>
                <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#newAdjustmentModal">
                    <i class="bi bi-plus-circle me-1"></i> Make Your First Adjustment
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.stock-adjustments-dashboard -->


<!-- ===== NEW ADJUSTMENT MODAL ===== -->
<div class="modal fade" id="newAdjustmentModal" tabindex="-1" aria-labelledby="newAdjustmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="newAdjustmentModalLabel"><i class="bi bi-plus-circle me-1"></i> New Stock Adjustment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="adjustmentForm">
                    <div class="row">

                        <!-- 1. PROJECT (optional) -->
                        <?php if ($enable_projects): ?>
                        <div class="col-md-6 mb-3" <?= ($project_id_filter > 0) ? 'style="display:none;"' : '' ?>>
                            <label class="form-label fw-semibold">Project <span class="text-muted small">(Optional)</span></label>
                            <select class="form-select" id="adjustment_project_id" name="project_id"
                                onchange="filterAdjustmentWarehouses(this.value)">
                                <option value="">-- No Project --</option>
                                <?php foreach ($projects as $p): ?>
                                <option value="<?= $p['project_id'] ?>"
                                    <?= ($p['project_id'] == $project_id_filter) ? 'selected' : '' ?>>
                                    <?= safe_output($p['project_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Choose a project to see its warehouses, or leave blank for warehouses not linked to any project</small>
                        </div>
                        <?php endif; ?>

                        <!-- 2. WAREHOUSE -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Warehouse <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_warehouse_id" name="warehouse_id" required onchange="loadProductStock()">
                                <option value="">-- Select Warehouse --</option>
                            </select>
                            <small class="text-muted" id="adjWarehouseHint"></small>
                        </div>

                        <!-- 3. PRODUCT -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_product_id" name="product_id" required onchange="loadProductStock()">
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $product): ?>
                                <option value="<?= $product['product_id'] ?>">
                                    <?= safe_output($product['product_name']) ?> (<?= safe_output($product['sku']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                    <!-- Stock info -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Current Stock</label>
                            <input type="text" class="form-control" id="current_stock_display" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Available Stock</label>
                            <input type="text" class="form-control" id="available_stock_display" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit</label>
                            <input type="text" class="form-control" id="product_unit_display" readonly>
                        </div>
                    </div>

                    <!-- Type & Quantity -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Adjustment Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_type" name="movement_type" required onchange="updateQuantityPlaceholder()">
                                <option value="">Select Type</option>
                                <option value="adjustment_in">Add Stock (Stock In)</option>
                                <option value="adjustment_out">Remove Stock (Stock Out)</option>
                                <option value="correction">Stock Correction</option>
                                <option value="damaged">Damaged Goods</option>
                                <option value="expired">Expired Products</option>
                                <option value="found">Found Stock</option>
                                <option value="theft">Theft/Loss</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="adjustment_quantity" name="quantity" min="0.001" step="0.001" required>
                                <span class="input-group-text" id="quantity_unit_display">pcs</span>
                            </div>
                        </div>
                    </div>

                    <!-- Cost & Reason -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Unit Cost</label>
                            <div class="input-group">
                                <span class="input-group-text">TZS</span>
                                <input type="number" class="form-control" id="unit_cost" name="unit_cost" min="0" step="0.01" value="0.00">
                            </div>
                            <small class="text-muted">Leave as 0 to use product's cost price</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_reason_select" required>
                                <option value="">Select Reason</option>
                                <?php foreach ($reasons as $value => $label): ?>
                                <option value="<?= $value ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="manual_reason_container" style="display:none;" class="mt-2">
                                <input type="text" class="form-control" id="adjustment_reason_manual" placeholder="Type your reason...">
                            </div>
                            <input type="hidden" id="adjustment_reason" name="reason" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="adjustment_notes" name="notes" rows="2" placeholder="Additional information..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reference Number <span class="text-muted small">(Optional)</span></label>
                        <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="e.g., Adjustment-001">
                    </div>

                    <div class="alert alert-info d-none" id="new_stock_calculation">
                        <div class="row text-center">
                            <div class="col-4"><strong>Current:</strong> <span id="current_stock_value">0</span></div>
                            <div class="col-4"><strong>Adjustment:</strong> <span id="adjustment_value">0</span></div>
                            <div class="col-4"><strong>New Stock:</strong> <span id="new_stock_value">0</span></div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAdjustment()">Save Adjustment</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== BULK ADJUSTMENT MODAL ===== -->
<div class="modal fade" id="bulkAdjustmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-upload me-1"></i> Bulk Stock Adjustment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-1"></i> Upload a CSV file with product SKU, quantity, and details.
                    <a href="#" class="alert-link" onclick="downloadBulkTemplate()">Download template</a>
                </div>
                <div class="mb-3">
                    <label class="form-label">Upload CSV File</label>
                    <input type="file" class="form-control" id="bulkFile" accept=".csv">
                </div>
                <div class="mb-3">
                    <label class="form-label">Default Adjustment Type</label>
                    <select class="form-select" id="bulkAdjustmentType">
                        <option value="adjustment_in">Add Stock (Stock In)</option>
                        <option value="adjustment_out">Remove Stock (Stock Out)</option>
                        <option value="correction">Stock Correction</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Default Reason</label>
                    <select class="form-select" id="bulkReason">
                        <?php foreach ($reasons as $value => $label): ?>
                        <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Default Warehouse</label>
                    <select class="form-select" id="bulkWarehouse">
                        <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['warehouse_id'] ?>"><?= safe_output($wh['warehouse_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="bulkPreview" style="display:none;">
                    <h6>Preview</h6>
                    <div class="table-responsive">
                        <table class="table table-sm" id="bulkPreviewTable">
                            <thead><tr><th>Product</th><th>SKU</th><th>Quantity</th><th>Type</th><th>Reason</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="processBulkAdjustment()">Process Bulk Adjustment</button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Warehouse data from PHP ──────────────────────────────────────────────
const adjAllWarehouses = <?= json_encode(array_values(array_map(function($w){
    return [
        'warehouse_id'   => (int)$w['warehouse_id'],
        'warehouse_name' => $w['warehouse_name'],
        'project_id'     => (int)$w['project_id']   // 0 means no project
    ];
}, $warehouses))) ?>;

// ── Warehouse filter function ────────────────────────────────────────────
function filterAdjustmentWarehouses(projectId) {
    const sel  = document.getElementById('adjustment_warehouse_id');
    const hint = document.getElementById('adjWarehouseHint');

    // Keep current value if possible
    const curVal = parseInt(sel.value) || 0;

    // Clear dropdown
    sel.innerHTML = '<option value="">-- Select Warehouse --</option>';

    // Convert projectId to integer safely
    const pid = parseInt(projectId) || 0;

    let filtered;
    if (pid === 0) {
        // No project selected — show only warehouses not linked to any project
        filtered = adjAllWarehouses.filter(function(w) {
            return w.project_id === 0;
        });
        if (hint) hint.textContent = 'Showing warehouses not linked to any project.';
    } else {
        // Project selected — show only warehouses of that project
        filtered = adjAllWarehouses.filter(function(w) {
            return w.project_id === pid;
        });
        if (hint) hint.textContent = filtered.length === 0
            ? 'No warehouses found for this project.'
            : filtered.length + ' warehouse(s) available for this project.';
    }

    // Populate options
    filtered.forEach(function(w) {
        const opt = document.createElement('option');
        opt.value       = w.warehouse_id;
        opt.textContent = w.warehouse_name;
        if (w.warehouse_id === curVal) opt.selected = true;
        sel.appendChild(opt);
    });

    // Auto-select if only one result
    if (filtered.length === 1) {
        sel.value = filtered[0].warehouse_id;
    }

    // Reset stock display
    $('#current_stock_display, #available_stock_display, #product_unit_display').val('');
    $('#new_stock_calculation').addClass('d-none');
}

// ── Modal open: populate warehouses ─────────────────────────────────────
$('#newAdjustmentModal').on('show.bs.modal', function() {
    const presetProject = '<?= $project_id_filter > 0 ? $project_id_filter : "" ?>';
    if (presetProject) {
        $('#adjustment_project_id').val(presetProject);
        filterAdjustmentWarehouses(presetProject);
    } else {
        // No preset — show all warehouses, project dropdown is blank
        $('#adjustment_project_id').val('');
        filterAdjustmentWarehouses('');
    }
});

// ── DataTable ────────────────────────────────────────────────────────────
$(document).ready(function() {

    $('#adjustmentsTable').DataTable({
        language: {},
        pageLength: 500,
        order: [[1, 'desc']],
        paging: false,
        info: false,
        lengthChange: false,
        dom: '<"top d-print-none"f>rt<"clear">'
    });

    // Reason select logic
    $('#adjustment_reason_select').on('change', function() {
        const val = $(this).val();
        if (val === 'other') {
            $('#manual_reason_container').show();
            $('#adjustment_reason').val($('#adjustment_reason_manual').val());
            $('#adjustment_reason_manual').attr('required', true).focus();
        } else {
            $('#manual_reason_container').hide();
            $('#adjustment_reason').val(val);
            $('#adjustment_reason_manual').attr('required', false);
        }
    });

    $('#adjustment_reason_manual').on('input', function() {
        if ($('#adjustment_reason_select').val() === 'other') {
            $('#adjustment_reason').val($(this).val());
        }
    });

    $('#bulkFile').change(function() {
        if (this.files[0]) previewBulkFile(this.files[0]);
    });

    // Logging
    logReportAction('Viewed Stock Adjustments Page', 'User viewed the stock adjustments page');
    const urlParams = new URLSearchParams(window.location.search);

    // Edit mode
    if (urlParams.has('edit')) {
        const editId = urlParams.get('edit');
        $('#newAdjustmentModalLabel').html('<i class="bi bi-pencil me-1"></i> Edit Adjustment');
        $('#newAdjustmentModal').modal('show');
        $.ajax({
            url: '<?= getUrl("api/get_adjustment.php") ?>',
            type: 'GET', data: { id: editId }, dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    // Fill project first so warehouse filter works
                    if (data.project_id) {
                        $('#adjustment_project_id').val(data.project_id);
                        filterAdjustmentWarehouses(data.project_id);
                    } else {
                        filterAdjustmentWarehouses('');
                    }
                    setTimeout(function() {
                        $('#adjustment_warehouse_id').val(data.warehouse_id);
                    }, 100);
                    $('#adjustment_product_id').val(data.product_id);
                    $('#adjustment_type').val(data.movement_type);
                    $('#adjustment_quantity').val(data.quantity);
                    $('#unit_cost').val(data.unit_cost);
                    const reason = data.reason;
                    const exists = $('#adjustment_reason_select option[value="'+reason+'"]').length > 0;
                    if (exists && reason !== 'other') {
                        $('#adjustment_reason_select').val(reason);
                        $('#manual_reason_container').hide();
                        $('#adjustment_reason').val(reason);
                    } else if (reason) {
                        $('#adjustment_reason_select').val('other');
                        $('#adjustment_reason_manual').val(reason);
                        $('#manual_reason_container').show();
                        $('#adjustment_reason').val(reason);
                    }
                    $('#adjustment_notes').val(data.notes || '');
                    $('#reference_number').val(data.reference_number || '');
                    setTimeout(function() {
                        updateQuantityPlaceholder();
                        loadProductStock(true);
                    }, 200);
                } else {
                    Swal.fire('Error', response.message || 'Failed to load data', 'error');
                }
            }
        });
    }

    // Modal closed — reset
    $('#newAdjustmentModal').on('hidden.bs.modal', function() {
        const url = new URL(window.location.href);
        if (url.searchParams.has('edit')) {
            url.searchParams.delete('edit');
            window.history.pushState({}, '', url);
        }
        $('#adjustmentForm')[0].reset();
        $('#newAdjustmentModalLabel').html('<i class="bi bi-plus-circle me-1"></i> New Stock Adjustment');
        $('#adjustment_product_id').val('');
        $('#current_stock_display, #available_stock_display, #product_unit_display').val('');
        $('#new_stock_calculation').addClass('d-none');
        $('#adjustment_reason_select').val('');
        $('#adjustment_reason_manual').val('');
        $('#manual_reason_container').hide();
        $('#adjustment_reason').val('');
    });

    // Button click — clean state
    $('button[data-bs-target="#newAdjustmentModal"]').on('click', function() {
        $('#adjustmentForm')[0].reset();
        $('#newAdjustmentModalLabel').html('<i class="bi bi-plus-circle me-1"></i> New Stock Adjustment');
        $('#adjustment_reason_select, #adjustment_reason_manual').val('');
        $('#manual_reason_container').hide();
        $('#adjustment_reason').val('');
        $('#current_stock_display, #available_stock_display, #product_unit_display').val('');
        $('#new_stock_calculation').addClass('d-none');
        const presetProject = '<?= $project_id_filter > 0 ? $project_id_filter : "" ?>';
        $('#adjustment_project_id').val(presetProject);
        filterAdjustmentWarehouses(presetProject);
    });

});

// ── loadProductStock ──────────────────────────────────────────────────────
function loadProductStock(isEdit = false) {
    const productId   = $('#adjustment_product_id').val();
    const warehouseId = $('#adjustment_warehouse_id').val();
    if (!productId || !warehouseId) return;
    $.ajax({
        url: '<?= getUrl("api/get_product_stock.php") ?>',
        type: 'GET', data: { product_id: productId, warehouse_id: warehouseId }, dataType: 'json',
        success: function(r) {
            if (r.success) {
                const p = r.data;
                $('#current_stock_display').val(p.total_stock);
                $('#available_stock_display').val(p.available_stock);
                $('#product_unit_display').val(p.unit);
                $('#quantity_unit_display').text(p.unit);
                if (!isEdit) $('#unit_cost').val(p.cost_price);
                updateStockCalculation();
            }
        }
    });
}

function updateQuantityPlaceholder() {
    const type = $('#adjustment_type').val();
    $('#adjustment_quantity').attr('placeholder',
        ['adjustment_out','damaged','expired','theft'].includes(type) ? 'Quantity to remove' : 'Quantity to add');
    updateStockCalculation();
}

function updateStockCalculation() {
    const cur  = parseFloat($('#current_stock_display').val()) || 0;
    const qty  = parseFloat($('#adjustment_quantity').val())   || 0;
    const type = $('#adjustment_type').val();
    if (!type) { $('#new_stock_calculation').addClass('d-none'); return; }
    const isIn  = (type === 'adjustment_in' || type === 'found');
    const newSt = isIn ? cur + qty : cur - qty;
    $('#current_stock_value').text(cur);
    $('#adjustment_value').text((isIn ? '+ ' : '- ') + qty);
    $('#new_stock_value').text(newSt);
    $('#new_stock_calculation').removeClass('d-none');
}

$('#adjustment_quantity').on('input', updateStockCalculation);

// ── submitAdjustment ──────────────────────────────────────────────────────
function submitAdjustment() {
    if (!$('#adjustment_product_id').val())   { Swal.fire({icon:'warning',title:'Missing Product',text:'Please select a product.',confirmButtonColor:'#0d6efd'}); return; }
    if (!$('#adjustment_warehouse_id').val()) { Swal.fire({icon:'warning',title:'Missing Warehouse',text:'Please select a warehouse.',confirmButtonColor:'#0d6efd'}); return; }
    if (!$('#adjustment_type').val())         { Swal.fire({icon:'warning',title:'Missing Type',text:'Please select an adjustment type.',confirmButtonColor:'#0d6efd'}); return; }
    const qty = parseFloat($('#adjustment_quantity').val());
    if (!qty || qty <= 0) { Swal.fire({icon:'warning',title:'Invalid Quantity',text:'Please enter a valid quantity.',confirmButtonColor:'#0d6efd'}); return; }

    const type      = $('#adjustment_type').val();
    const available = parseFloat($('#available_stock_display').val()) || 0;
    const formData  = $('#adjustmentForm').serialize();

    if (['adjustment_out','damaged','expired','theft'].includes(type) && qty > available) {
        Swal.fire({
            icon:'warning', title:'Insufficient Stock',
            text:'Cannot remove more than available ('+available+').',
            showCancelButton:true, confirmButtonColor:'#dc3545',
            confirmButtonText:'Proceed Anyway', cancelButtonText:'Adjust Quantity'
        }).then(r => { if (r.isConfirmed) saveAdjustment(formData); });
        return;
    }
    saveAdjustment(formData);
}

function saveAdjustment(formData) {
    const urlParams = new URLSearchParams(window.location.search);
    const isEdit    = urlParams.has('edit');
    const url       = isEdit ? '<?= getUrl("api/update_adjustment.php") ?>' : '<?= getUrl("api/create_stock_adjustment.php") ?>';
    const data      = isEdit ? formData + '&movement_id=' + urlParams.get('edit') : formData;

    Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    $.ajax({
        url: url, type: 'POST', data: data, dataType: 'json',
        success: function(r) {
            if (r.success) {
                logReportAction(isEdit ? 'Updated Stock Adjustment' : 'Created Stock Adjustment', '');
                Swal.fire({
                    icon:'success', title:'Success!', text: r.message || 'Adjustment saved successfully.',
                    confirmButtonColor:'#198754', confirmButtonText:'OK'
                }).then(() => {
                    const pid = new URLSearchParams(window.location.search).get('project_id') || $('#adjustment_project_id').val();
                    if (pid) window.location.href = '<?= getUrl("project_view") ?>?id=' + pid;
                    else { $('#newAdjustmentModal').modal('hide'); location.reload(); }
                });
            } else {
                Swal.fire({icon:'error', title:'Error', text: r.message, confirmButtonText:'OK'});
            }
        },
        error: function() {
            Swal.fire({icon:'error', title:'Error', text:'Server error. Please try again.', confirmButtonText:'OK'});
        }
    });
}

// ── viewAdjustmentDetails ─────────────────────────────────────────────────
function viewAdjustmentDetails(id) {
    $.ajax({
        url: '<?= getUrl("api/get_adjustment.php") ?>', type:'GET', data:{id:id}, dataType:'json',
        success: function(r) {
            if (r.success) {
                const a = r.data;
                Swal.fire({
                    title:'Adjustment Details', width:750,
                    html:`<div class="row text-start">
                        <div class="col-6"><p><b>Product:</b> ${a.product_name}</p><p><b>SKU:</b> ${a.sku||'N/A'}</p><p><b>Warehouse:</b> ${a.warehouse_name}</p><p><b>Project:</b> ${a.project_name||'N/A'}</p></div>
                        <div class="col-6"><p><b>Type:</b> ${a.movement_type}</p><p><b>Qty:</b> ${a.quantity} ${a.unit}</p><p><b>Stock Before:</b> ${a.stock_before}</p><p><b>Stock After:</b> ${a.stock_after}</p></div>
                        ${a.notes?`<div class="col-12"><p><b>Notes:</b> ${a.notes}</p></div>`:''}
                    </div>`,
                    showCloseButton:true, showConfirmButton:false,
                    footer:`<a href="?edit=${id}" class="btn btn-warning btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>`
                });
            }
        }
    });
}

function printAdjustment(id) { window.open('<?= getUrl("adjustment_print") ?>?id='+id,'_blank'); }

function deleteAdjustment(id) {
    Swal.fire({
        title:'Delete Adjustment', text:'This will reverse the stock change. Are you sure?',
        icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545',
        confirmButtonText:'Yes, Delete', cancelButtonText:'Cancel'
    }).then(r => {
        if (r.isConfirmed) {
            $.ajax({
                url:'<?= getUrl("api/delete_adjustment.php") ?>', type:'POST',
                data:{adjustment_id:id}, dataType:'json',
                success: function(r) {
                    if (r.success) {
                        Swal.fire({icon:'success',title:'Deleted!',text:r.message,confirmButtonColor:'#198754',confirmButtonText:'OK'})
                        .then(()=>location.reload());
                    } else {
                        Swal.fire({icon:'error',title:'Error',text:r.message,confirmButtonText:'OK'});
                    }
                }
            });
        }
    });
}

function exportAdjustments() {
    const p = new URLSearchParams(window.location.search);
    p.set('export','excel');
    window.location.href = '<?= getUrl("api/export_adjustments.php") ?>?' + p.toString();
}

function bulkAdjustment() { $('#bulkAdjustmentModal').modal('show'); }

function copyTable() {
    const t = document.getElementById('adjustmentsTable');
    const r = document.createRange();
    r.selectNode(t);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(r);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    Swal.fire({icon:'success',title:'Copied!',text:'Table copied to clipboard',timer:1500,showConfirmButton:false});
}

function downloadBulkTemplate() {
    const csv = ['sku,quantity,movement_type,reason,warehouse_id,unit_cost,notes','PROD001,10,adjustment_in,found,1,1000,Found in storage'].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
    a.download = 'bulk_adjustment_template.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

function previewBulkFile(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.split('\n').filter(l=>l.trim()&&!l.startsWith('#'));
        if (lines.length < 2) { Swal.fire({icon:'warning',title:'Invalid File',text:'CSV must contain data rows.'}); return; }
        const headers = lines[0].split(',');
        const body = $('#bulkPreviewTable tbody').empty();
        for (let i=1;i<Math.min(lines.length,6);i++) {
            const v = lines[i].split(',');
            body.append(`<tr><td>${v[headers.indexOf('sku')]||''}</td><td>${v[headers.indexOf('sku')]||''}</td><td>${v[headers.indexOf('quantity')]||''}</td><td>${v[headers.indexOf('movement_type')]||''}</td><td>${v[headers.indexOf('reason')]||''}</td></tr>`);
        }
        $('#bulkPreview').show();
    };
    reader.readAsText(file);
}

function processBulkAdjustment() {
    const file = $('#bulkFile')[0].files[0];
    if (!file) { Swal.fire({icon:'warning',title:'No File',text:'Please select a CSV file.'}); return; }
    const fd = new FormData();
    fd.append('file',file);
    fd.append('default_type',$('#bulkAdjustmentType').val());
    fd.append('default_reason',$('#bulkReason').val());
    fd.append('default_warehouse',$('#bulkWarehouse').val());
    Swal.fire({title:'Processing...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    $.ajax({
        url:'<?= getUrl("api/process_bulk_adjustment.php") ?>',type:'POST',
        data:fd,processData:false,contentType:false,dataType:'json',
        success:function(r){
            Swal.close();
            if(r.success){
                Swal.fire({icon:'success',title:'Done!',html:`<p>${r.message}</p><p>Processed: ${r.processed} | Success: ${r.success_count} | Failed: ${r.failed_count}</p>`,confirmButtonText:'OK'})
                .then(()=>{$('#bulkAdjustmentModal').modal('hide');location.reload();});
            } else {
                Swal.fire({icon:'error',title:'Error',text:r.message,confirmButtonText:'OK'});
            }
        },
        error:function(){Swal.close();Swal.fire({icon:'error',title:'Error',text:'Failed to process.',confirmButtonText:'OK'});}
    });
}

function updatePerPage(size) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page',size);
    url.searchParams.set('page',1);
    window.location.href = url.toString();
}

$(document).keydown(function(e) {
    if (e.ctrlKey && e.key==='n' && !$(e.target).is('input,textarea,select')) { e.preventDefault(); $('#newAdjustmentModal').modal('show'); }
    if (e.ctrlKey && e.key==='b' && !$(e.target).is('input,textarea,select')) { e.preventDefault(); bulkAdjustment(); }
    if (e.key==='F5') { e.preventDefault(); location.reload(); }
});
</script>

<style>
.stock-adjustments-dashboard { background:#fff; min-height:100vh; }
.custom-stat-card { background-color:#d1e7dd !important; border-color:#badbcc !important; transition:transform .2s; border-radius:12px; }
.custom-stat-card:hover { transform:translateY(-3px); }
.custom-stat-card h4, .custom-stat-card small, .custom-stat-card i { color:#0f5132 !important; font-weight:600; }
.stats-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; background:rgba(15,81,50,.1); color:#0f5132 !important; }
.custom-code { color:#0f5132 !important; background-color:#d1e7dd !important; padding:2px 4px; border-radius:4px; font-family:monospace; }
.table thead th { background-color:#f8f9fa !important; border-bottom:2px solid #dee2e6 !important; color:#333 !important; }
.btn-white { background:#fff; border:1px solid #dee2e6; color:#444; }
.btn-white:hover { background:#f8f9fa; }
.dropdown-menu { padding:.4rem; border:none; box-shadow:0 10px 30px rgba(0,0,0,.12); border-radius:10px; }
.dropdown-item { border-radius:6px; margin-bottom:2px; }

/* Mobile */
@media (max-width:767px) {
    .stock-adjustments-dashboard { padding:.5rem !important; }
    .table thead th, .table tbody td { font-size:.78rem !important; padding:.4rem .3rem !important; }
}

/* Print */
@media print {
  @page { size: A4 landscape; margin: 0.4in 0.4in 2cm 0.4in; }
    body { background:#fff !important; padding:0 0 2cm 0 !important; padding-top: 0 !important; margin:0 !important; }
    .stock-adjustments-dashboard { padding:0 !important; }
    .d-print-none { display:none !important; }
    .card { border:none !important; box-shadow:none !important; }
    .card-body, .table-responsive { overflow:visible !important; }
    #adjustmentsTable { table-layout:fixed !important; width:100% !important; border-collapse:collapse !important; }
   #adjustmentsTable th {
    background-color:#f8f9fa !important; text-align:center !important;
    border:1px solid #999 !important; padding:5px 4px !important;
    font-size:9pt !important; white-space:nowrap !important;
    -webkit-print-color-adjust:exact;
}
#adjustmentsTable td {
    border:1px solid #ccc !important; padding:5px 4px !important;
    font-size:9pt !important; vertical-align:middle !important;
    word-break:break-word; white-space:normal;
}
    #adjustmentsTable td {
        border:1px solid #ccc !important; padding:3px !important;
        font-size:7pt !important; vertical-align:middle !important;
        word-break:break-word; white-space:normal;
    }
    #adjustmentsTable tr { page-break-inside:avoid !important; }
    #adjustmentsTable th:nth-child(1),#adjustmentsTable td:nth-child(1){width:4%;}
    #adjustmentsTable th:nth-child(2),#adjustmentsTable td:nth-child(2){width:10%;}
    #adjustmentsTable th:nth-child(3),#adjustmentsTable td:nth-child(3){width:20%;}
    #adjustmentsTable th:nth-child(4),#adjustmentsTable td:nth-child(4){width:13%;}
    #adjustmentsTable th:nth-child(5),#adjustmentsTable td:nth-child(5){width:11%;}
    #adjustmentsTable th:nth-child(6),#adjustmentsTable td:nth-child(6){width:11%;}
    #adjustmentsTable th:nth-child(7),#adjustmentsTable td:nth-child(7){width:9%;}
    #adjustmentsTable th:nth-child(8),#adjustmentsTable td:nth-child(8){width:12%;}
    .print-footer {
        position:fixed !important; bottom:0 !important; left:0; right:0;
        height:1.4cm; display:flex !important; flex-direction:column;
        justify-content:center; text-align:center;
        background:#fff !important; border-top:1px solid #ccc !important;
        font-size:8px; z-index:999999 !important;
        -webkit-print-color-adjust:exact; pointer-events:none;
    }
    .dataTables_length,.dataTables_info,.dataTables_paginate,.dataTables_filter { display:none !important; }
}
</style>

<?php
includeFooter();
ob_end_flush();
?>