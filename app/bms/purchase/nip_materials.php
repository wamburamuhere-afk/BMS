<?php
// scope-audit: skip — NIP material lists are scoped by project_id on nip_material_lists table; scope filtering pending Phase G-2
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';
autoEnforcePermission('nip_materials');
logActivity($pdo, $_SESSION['user_id'], 'VIEW', '[NIP Materials] Page viewed');
includeHeader();
$can_create = canCreate('nip_materials');
$can_edit   = canEdit('nip_materials');
$can_delete = hasPermission('delete_nip_materials');
$c_name = getSetting('company_name', 'BMS');
$c_logo = getSetting('company_logo', '');

// Auto-migrate: add warehouse_id and list_no columns if not present
try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN warehouse_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN list_no VARCHAR(50) NULL DEFAULT NULL"); } catch (Exception $e) {}

// Back-fill list_no for existing records that have none
try {
    $pdo->exec("
        UPDATE nip_material_lists
        SET list_no = CONCAT('ML-', DATE_FORMAT(created_at,'%Y%m%d'), '-', LPAD(id,4,'0'))
        WHERE list_no IS NULL OR list_no = ''
    ");
} catch (Exception $e) {}

// Main query: material LISTS (not individual components)
$lists_data = [];
try {
    $lists_data = $pdo->query("
        SELECT
            ml.id,
            ml.name,
            COALESCE(ml.list_no,
                CONCAT('ML-', DATE_FORMAT(ml.created_at,'%Y%m%d'), '-', LPAD(ml.id,4,'0'))
            ) AS list_no,
            ml.project_id,
            COALESCE(p.project_name,'') AS project_name,
            ml.warehouse_id,
            COALESCE(w.warehouse_name,'') AS warehouse_name,
            COUNT(mln.id) AS nip_count,
            ml.created_at
        FROM nip_material_lists ml
        LEFT JOIN projects   p ON ml.project_id   = p.project_id
        LEFT JOIN warehouses w ON ml.warehouse_id = w.warehouse_id
        LEFT JOIN nip_material_list_nips mln ON mln.material_list_id = ml.id
        WHERE 1=1" . scopeFilterSqlNullable('project', 'ml') . scopeFilterSqlNullable('warehouse', 'ml') . "
        GROUP BY ml.id, ml.name, ml.list_no, ml.project_id, p.project_name,
                 ml.warehouse_id, w.warehouse_name, ml.created_at
        ORDER BY ml.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $lists_data = []; }

// Stats
$stat_total_lists  = count($lists_data);
$stat_total_nips   = 0;
$stat_total_comps  = 0;
try {
    $stat_total_nips  = (int)$pdo->query("SELECT COUNT(DISTINCT nip_product_id) FROM nip_material_list_nips")->fetchColumn();
    $stat_total_comps = (int)$pdo->query("
        SELECT COUNT(DISTINCT pac.component_product_id)
        FROM product_assembly_components pac
        WHERE EXISTS (
            SELECT 1 FROM nip_material_list_nips mln WHERE mln.nip_product_id = pac.parent_product_id
        )
    ")->fetchColumn();
} catch (Exception $e) {}

// Form data — shared helper, also respects the user's direct warehouse grant
// (Phase 6, pos_upgrade_plan.md).
$warehouses_all = warehousesForSelect($pdo);

$_nip_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
if (isAdmin()) {
    $projects_list = $pdo->query("
        SELECT project_id, project_name FROM projects WHERE status='active' ORDER BY project_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_nip_assigned)) {
    $_nip_pph = implode(',', array_fill(0, count($_nip_assigned), '?'));
    $_nip_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status='active' AND project_id IN ($_nip_pph) ORDER BY project_name");
    $_nip_pstmt->execute($_nip_assigned);
    $projects_list = $_nip_pstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $projects_list = [];
}

$nip_for_form = $pdo->query("
    SELECT p.product_id, p.product_name,
           COALESCE(p.warehouse_id, 0) AS warehouse_id,
           COALESCE(p.project_id, w.project_id, 0) AS effective_project_id
    FROM products p
    LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
    WHERE p.is_service = 1 AND p.status = 'active'
    ORDER BY p.product_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
.nip-stat-card {
    background-color:#d1e7dd!important; border-color:#badbcc!important;
    border-radius:0; transition:transform .18s;
}
.nip-stat-card:hover { transform:translateY(-3px); }
.nip-stat-card h4, .nip-stat-card p, .nip-stat-card i { color:#0f5132!important; font-weight:600; }
#mlMainTable thead th {
    background:#fff; color:#333; border-bottom:2px solid #dee2e6;
    font-size:.78rem; text-transform:uppercase;
}
.ml-action-btn { font-size:.75rem; padding:.2rem .5rem; }
@page {
    margin: 0.5cm 1cm 1.5cm 1cm;
}
@media print {
    .d-print-none { display:none!important; }
    .card { border:none!important; box-shadow:none!important; }
    .table { font-size:9px; }
    th { white-space:nowrap!important; font-size:9px!important; }
    td { font-size:9px!important; }
    thead { display:table-header-group; }
    .container-fluid { padding:0!important; }
    .print-footer {
        display:flex!important; flex-direction:column; justify-content:center;
        align-items:center; text-align:center;
        position:fixed; bottom:0; left:0; right:0;
        height:1cm; background:#fff;
        border-top:1px solid #ccc; font-size:8px; z-index:9999;
        -webkit-print-color-adjust:exact; print-color-adjust:exact;
    }
    body { padding-bottom:1.2cm; }
}
</style>

<div class="container-fluid mt-4">

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('purchases') ?>">Procurement</a></li>
            <li class="breadcrumb-item active">Materials</li>
        </ol>
    </nav>

    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4">
        
        <h4 class="fw-bold text-dark text-uppercase">Materials List</h4>
        <p class="text-muted small">Date: <?= date('d M, Y') ?></p>
        <hr>
    </div>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-boxes text-primary me-2"></i>Materials</h2>
            <p class="text-muted mb-0 small">Manage material lists for Non-Inventory Products</p>
        </div>
        <?php if ($can_create): ?>
        <button class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addMaterialListModal">
            <i class="bi bi-plus-circle me-1"></i> Add Materials
        </button>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-6 col-md-4">
            <div class="card nip-stat-card p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h4 class="mb-0"><?= $stat_total_lists ?></h4><p class="mb-0 small">Material Lists</p></div>
                    <i class="bi bi-list-ul fs-1 opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card nip-stat-card p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h4 class="mb-0"><?= $stat_total_nips ?></h4><p class="mb-0 small">NIPs Used</p></div>
                    <i class="bi bi-boxes fs-1 opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card nip-stat-card p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h4 class="mb-0"><?= $stat_total_comps ?></h4><p class="mb-0 small">Components</p></div>
                    <i class="bi bi-box-seam fs-1 opacity-40"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card shadow-sm mb-3 border-0 d-print-none">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <input type="text" class="form-control form-control-sm" id="mlSearch"
                        placeholder="Search list name or number…">
                </div>
                <div class="col-md-4">
                    <select class="form-select form-select-sm" id="mlFilterProject">
                        <option value="">All Projects</option>
                        <?php foreach ($projects_list as $pr): ?>
                        <option value="<?= $pr['project_id'] ?>"><?= htmlspecialchars($pr['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 text-end">
                    <span class="text-muted small" id="mlCountLabel"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Export / Print / Show bar -->
    <div class="d-flex gap-2 mb-3 d-print-none">
        <button onclick="window.print()" style="background:#fff;border:1px solid #dee2e6;border-radius:3px;font-size:.78rem;padding:.22rem .55rem;cursor:pointer;">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <select id="mlPerPage" style="background:#fff;border:1px solid #dee2e6;border-radius:3px;font-size:.78rem;padding:.22rem .4rem;cursor:pointer;">
            <option value="5">Show 5</option>
            <option value="10">Show 10</option>
            <option value="25" selected>Show 25</option>
            <option value="50">Show 50</option>
            <option value="100">Show 100</option>
            <option value="-1">Show All</option>
        </select>
    </div>

    <!-- Main Table -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center d-print-none">
            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-list-ul me-2 text-primary"></i>Materials Records</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="mlMainTable">
                    <thead>
                        <tr>
                            <th class="text-center ps-3" style="width:5%">S/NO</th>
                            <th style="width:30%">Materials List Name</th>
                            <th class="text-center" style="width:14%">Materials List No</th>
                            <th class="text-center" style="width:16%">Project</th>
                            <th class="text-center" style="width:16%">Warehouse</th>
                            <th class="text-center d-print-none" style="width:19%">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="mlTableBody">
                    <?php foreach ($lists_data as $i => $row): ?>
                    <tr data-id="<?= $row['id'] ?>"
                        data-project="<?= $row['project_id'] ?? 0 ?>"
                        data-name="<?= strtolower(htmlspecialchars($row['name'] . ' ' . $row['list_no'])) ?>">
                        <td class="text-center ps-3 text-muted fw-bold ml-row-no" style="white-space:nowrap"><?= $i+1 ?></td>
                        <td>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($row['name']) ?></div>
                            <small class="text-muted"><?= $row['nip_count'] ?> NIP<?= $row['nip_count'] != 1 ? 's' : '' ?></small>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border" style="font-size:.78rem;letter-spacing:.5px;">
                                <?= htmlspecialchars($row['list_no']) ?>
                            </span>
                        </td>
                        <td class="text-center text-muted small"><?= htmlspecialchars($row['project_name']) ?></td>
                        <td class="text-center text-muted small"><?= htmlspecialchars($row['warehouse_name']) ?></td>
                        <td class="text-center d-print-none">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light border dropdown-toggle px-2" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-gear"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                    <li>
                                        <a class="dropdown-item py-2"
                                           href="<?= getUrl('view_material_list') ?>?id=<?= $row['id'] ?>">
                                            <i class="bi bi-eye text-primary me-2"></i> View
                                        </a>
                                    </li>
                                    <?php if ($can_edit): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item py-2" href="javascript:void(0)"
                                           onclick="mlOpenEdit(<?= $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['name'])) ?>')">
                                            <i class="bi bi-pencil text-primary me-2"></i> Edit
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item py-2 text-danger" href="javascript:void(0)"
                                           onclick="mlDelete(<?= $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['name'])) ?>')">
                                            <i class="bi bi-trash me-2"></i> Delete
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($lists_data)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-list-ul" style="font-size:3rem;opacity:.25;"></i>
                        <p class="mt-3">No material lists yet. Click <strong>Add Materials</strong> to create one.</p>
                    </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Print footer — hidden on screen, shown only when printing -->
    <div class="print-footer d-none">
        <p style="margin:0 0 1px;color:#888;font-size:8px;">
            Printed by <strong style="color:#212529;"><?= htmlspecialchars(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))) ?></strong>
            on <strong style="color:#212529;"><?= date('d M, Y \a\t H:i') ?></strong>
        </p>
        <p style="margin:0;font-weight:bold;color:#0d6efd;font-size:8px;">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</p>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     ADD MATERIALS MODAL
══════════════════════════════════════════════════════ -->
<div class="modal fade" id="addMaterialListModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white py-3" style="background:#0d6efd;">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>CREATE MATERIALS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addMaterialListForm" method="post" style="display:flex;flex-direction:column;overflow:hidden;flex:1 1 auto;min-height:0;">
                <div class="modal-body p-4" style="overflow-y:auto;flex:1 1 auto;">
                    <div id="mlAddMsg" class="mb-3"></div>

                    <!-- Name -->
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Material List Name <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="name" id="mlAddName" rows="2" required
                            placeholder="e.g. Foundation Materials for Block A"
                            style="resize:vertical;"></textarea>
                    </div>

                    <!-- List No (read-only) -->
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Materials List No</label>
                        <input type="text" class="form-control bg-light text-muted" id="mlAddListNo"
                               value="" readonly placeholder="Auto-generated on save">
                    </div>

                    <!-- Project + Warehouse (same row) -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Select Project <span class="text-muted fw-normal">(optional)</span></label>
                            <select class="form-select" name="project_id" id="mlAddProjectId" onchange="mlAddProjectChanged()">
                                <option value="">No Project (General)</option>
                                <?php foreach ($projects_list as $pr): ?>
                                <option value="<?= $pr['project_id'] ?>"><?= htmlspecialchars($pr['project_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Select Warehouse</label>
                            <select class="form-select" name="warehouse_id" id="mlAddWarehouseId" onchange="mlAddWarehouseChanged()">
                                <option value="">Select Warehouse</option>
                            </select>
                            <div class="form-text" id="mlAddWhHelp">Select a project first, or all general warehouses will appear.</div>
                        </div>
                    </div>

                    <!-- NIP rows -->
                    <h6 class="fw-bold small text-uppercase text-muted mb-2"><i class="bi bi-list-ul me-1"></i>Non-Inventory Products</h6>
                    <div class="table-responsive rounded-3 border" style="overflow-x:auto;overflow-y:visible;">
                        <table class="table table-hover align-middle mb-0" id="mlAddTable">
                            <thead class="text-white text-center" style="background:#0d6efd;">
                                <tr class="small">
                                    <th style="width:50px;">S/NO</th>
                                    <th class="text-start ps-3">Non-Inventory Product</th>
                                    <th style="width:20%;">Quantity</th>
                                    <th style="width:50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="mlAddTbody"></tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="4" class="ps-3 py-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="mlAddRow('add')">
                                            <i class="bi bi-plus-circle me-1"></i> Add NIP
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" id="mlAddSaveBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Materials
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     EDIT MATERIALS MODAL
══════════════════════════════════════════════════════ -->
<div class="modal fade" id="editMaterialListModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white py-3" style="background:#0d6efd;">
                <h5 class="modal-title fw-bold" id="mlEditTitle"><i class="bi bi-pencil me-2"></i>EDIT MATERIALS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editMaterialListForm" method="post" style="display:flex;flex-direction:column;overflow:hidden;flex:1 1 auto;min-height:0;">
                <div class="modal-body p-4" style="overflow-y:auto;flex:1 1 auto;" id="mlEditBody">
                    <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
                </div>
                <div class="modal-footer bg-white border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" id="mlEditSaveBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const NIP_URL         = '<?= rtrim(getUrl(''), '/') ?>';
const ALL_WH          = <?= json_encode(array_values($warehouses_all)) ?>;
const ML_ALL_NIPS     = <?= json_encode(array_values($nip_for_form)) ?>;
const ALL_PROJECTS    = <?= json_encode(array_values($projects_list)) ?>;
const ML_COMPANY_NAME = '<?= addslashes($c_name) ?>';
const ML_COMPANY_LOGO = '<?= !empty($c_logo) ? addslashes(getUrl($c_logo)) : '' ?>';
const ML_EXPORT_USER  = '<?= addslashes(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))) ?>';

var mlAddRowIdx  = 0;
var mlEditRowIdx = 0;
var mlTable;

// ── Warehouse + NIP helpers ───────────────────────────────────────────────────
function mlGetWarehouses(projId) {
    if (projId) return ALL_WH.filter(function(w) { return parseInt(w.project_id) === projId; });
    return ALL_WH.filter(function(w) { return !w.project_id || parseInt(w.project_id) === 0; });
}

function mlBuildWarehouseOptions(selectedId, projId) {
    var html = '<option value="">— Select Warehouse —</option>';
    mlGetWarehouses(projId).forEach(function(w) {
        var sel = String(w.warehouse_id) === String(selectedId) ? 'selected' : '';
        html += '<option value="' + w.warehouse_id + '" ' + sel + '>' + w.warehouse_name + '</option>';
    });
    return html;
}

function mlGetNips(warehouseId, projId) {
    if (warehouseId) {
        var wh = ALL_WH.find(function(w) { return parseInt(w.warehouse_id) === warehouseId; });
        var whProjId = (wh && wh.project_id) ? parseInt(wh.project_id) : 0;
        return ML_ALL_NIPS.filter(function(n) {
            if ((parseInt(n.warehouse_id) || 0) === warehouseId) return true;
            if (whProjId && (parseInt(n.effective_project_id) || 0) === whProjId) return true;
            return false;
        });
    }
    if (projId) return ML_ALL_NIPS.filter(function(n) { return (parseInt(n.effective_project_id) || 0) === projId; });
    return ML_ALL_NIPS;
}

function mlBuildNipOptions(selectedId, warehouseId, projId) {
    var html = '<option value="">— Select NIP Product —</option>';
    mlGetNips(warehouseId, projId).forEach(function(n) {
        var sel = String(n.product_id) === String(selectedId) ? 'selected' : '';
        html += '<option value="' + n.product_id + '" ' + sel + '>' + n.product_name.replace(/[<>"]/g, function(c) { return {'<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }) + '</option>';
    });
    return html;
}

function mlAddProjectChanged() {
    var projId = parseInt($('#mlAddProjectId').val()) || 0;
    $('#mlAddWarehouseId').html(mlBuildWarehouseOptions('', projId));
    mlAddWarehouseChanged();
}

function mlAddWarehouseChanged() {
    var whId   = parseInt($('#mlAddWarehouseId').val()) || 0;
    var projId = parseInt($('#mlAddProjectId').val())   || 0;
    $('#mlAddTbody .ml-nip-select-add').each(function() {
        $(this).html(mlBuildNipOptions($(this).val(), whId, projId));
    });
}

function mlEditProjectChanged() {
    var projId = parseInt($('#mlEditProjectId').val()) || 0;
    $('#mlEditWarehouseId').html(mlBuildWarehouseOptions('', projId));
    mlEditWarehouseChanged();
}

function mlEditWarehouseChanged() {
    var whId   = parseInt($('#mlEditWarehouseId').val()) || 0;
    var projId = parseInt($('#mlEditProjectId').val())   || 0;
    $('#mlEditTbody .ml-nip-select-edit').each(function() {
        $(this).html(mlBuildNipOptions($(this).val(), whId, projId));
    });
}

// ── Add / remove NIP rows ─────────────────────────────────────────────────────
function mlAddRow(mode, product_id, quantity) {
    var idx     = (mode === 'edit') ? mlEditRowIdx++ : mlAddRowIdx++;
    var tbodyId = (mode === 'edit') ? '#mlEditTbody' : '#mlAddTbody';
    var whId    = parseInt(((mode === 'edit') ? $('#mlEditWarehouseId') : $('#mlAddWarehouseId')).val()) || 0;
    var projId  = parseInt(((mode === 'edit') ? $('#mlEditProjectId')   : $('#mlAddProjectId')).val())   || 0;
    var html = '<tr id="ml-' + mode + '-row-' + idx + '">'
        + '<td class="text-center fw-bold text-muted ml-sno-' + mode + '"></td>'
        + '<td class="ps-3"><select name="nips[' + idx + '][product_id]" class="form-select form-select-sm ml-nip-select-' + mode + '">'
        + mlBuildNipOptions(product_id || '', whId, projId) + '</select></td>'
        + '<td><input type="number" name="nips[' + idx + '][quantity]" class="form-control form-control-sm text-end fw-bold"'
        + ' min="0.001" step="any" value="' + (quantity || 1) + '" required placeholder="Qty"></td>'
        + '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"'
        + ' onclick="$(\'#ml-' + mode + '-row-' + idx + '\').remove(); mlRenumber(\'' + mode + '\');"><i class="bi bi-trash"></i></button></td>'
        + '</tr>';
    $(tbodyId).append(html);
    mlRenumber(mode);
}

function mlRenumber(mode) {
    var tbodyId = (mode === 'edit') ? '#mlEditTbody' : '#mlAddTbody';
    $(tbodyId + ' tr').each(function(i) { $(this).find('.ml-sno-' + mode).text(i + 1); });
}

// ── Edit modal ────────────────────────────────────────────────────────────────
function mlOpenEdit(id, name) {
    mlEditRowIdx = 0;
    $('#mlEditTitle').html('<i class="bi bi-pencil me-2"></i>EDIT: ' + name);
    $('#mlEditBody').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>');
    new bootstrap.Modal(document.getElementById('editMaterialListModal')).show();

    $.getJSON(NIP_URL + '/api/get_material_list_for_edit.php?id=' + id, function(res) {
        if (!res.success) { $('#mlEditBody').html('<div class="alert alert-danger">' + res.message + '</div>'); return; }
        var l      = res.list;
        var projId = parseInt(l.project_id)   || 0;
        var whId   = parseInt(l.warehouse_id) || 0;

        var projOpts = '<option value="">No Project (General)</option>';
        ALL_PROJECTS.forEach(function(pr) {
            projOpts += '<option value="' + pr.project_id + '"' + (parseInt(pr.project_id) === projId ? ' selected' : '') + '>' + pr.project_name + '</option>';
        });

        var bodyHtml = '<div id="mlEditMsg" class="mb-3"></div>'
            + '<input type="hidden" name="id" value="' + l.id + '">'
            + '<div class="mb-3"><label class="form-label fw-bold small">Material List Name <span class="text-danger">*</span></label>'
            + '<textarea class="form-control" name="name" id="mlEditName" rows="2" required style="resize:vertical;">' + l.name.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</textarea></div>'
            + '<div class="mb-3"><label class="form-label fw-bold small">Materials List No</label>'
            + '<input type="text" class="form-control bg-light text-muted" value="' + l.list_no + '" readonly></div>'
            + '<div class="row g-3 mb-4">'
            + '<div class="col-md-6"><label class="form-label fw-bold small">Select Project <span class="text-muted fw-normal">(optional)</span></label>'
            + '<select class="form-select" name="project_id" id="mlEditProjectId" onchange="mlEditProjectChanged()">' + projOpts + '</select></div>'
            + '<div class="col-md-6"><label class="form-label fw-bold small">Select Warehouse</label>'
            + '<select class="form-select" name="warehouse_id" id="mlEditWarehouseId" onchange="mlEditWarehouseChanged()">'
            + mlBuildWarehouseOptions(whId, projId) + '</select></div></div>'
            + '<h6 class="fw-bold small text-uppercase text-muted mb-2"><i class="bi bi-list-ul me-1"></i>Non-Inventory Products</h6>'
            + '<div class="table-responsive rounded-3 border"><table class="table table-hover align-middle mb-0">'
            + '<thead class="text-white text-center" style="background:#0d6efd;"><tr class="small">'
            + '<th style="width:50px;">S/NO</th><th class="text-start ps-3">Non-Inventory Product</th>'
            + '<th style="width:20%;">Quantity</th><th style="width:50px;"></th></tr></thead>'
            + '<tbody id="mlEditTbody"></tbody>'
            + '<tfoot class="bg-light"><tr><td colspan="4" class="ps-3 py-3">'
            + '<button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="mlAddRow(\'edit\')">'
            + '<i class="bi bi-plus-circle me-1"></i> Add NIP</button></td></tr></tfoot></table></div>';

        $('#mlEditBody').html(bodyHtml);
        (l.nips || []).forEach(function(n) { mlAddRow('edit', n.nip_product_id, n.quantity); });
        if (!l.nips || l.nips.length === 0) mlAddRow('edit');
    }).fail(function() { $('#mlEditBody').html('<div class="alert alert-danger">Failed to load. Try again.</div>'); });
}

// ── Delete ────────────────────────────────────────────────────────────────────
function mlDelete(id, name) {
    Swal.fire({
        title: 'Delete Material List?',
        html: '<p>Delete <strong>' + name + '</strong>?</p><p class="text-danger small fw-bold">All NIP assignments will be removed. This cannot be undone.</p>',
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post(NIP_URL + '/api/delete_material_list.php', { id: id }, function(res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, timer: 1800, showConfirmButton: false });
                setTimeout(function() { location.reload(); }, 1900);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Server error.' });
            }
        }, 'json').fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); });
    });
}

// ── All DOM-dependent code inside $(function(){}) ─────────────────────────────
$(function() {
    // DataTables custom project filter
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (settings.nTable.id !== 'mlMainTable') return true;
        var row = settings.aoData[dataIndex].nTr;
        if (!row) return true;
        var proj = $('#mlFilterProject').val();
        return !proj || String($(row).data('project') || '') === proj;
    });

    // DataTables init — skip when only the empty-state colspan row is present
    if ($('#mlMainTable tbody tr td[colspan]').length > 0) {
        $('#mlCountLabel').text('0 lists');
    } else {
    mlTable = $('#mlMainTable').DataTable({
        pageLength: 25,
        lengthMenu: [[5,10,25,50,100,-1],['5','10','25','50','100','All']],
        dom: 'rtip',
        order: [[0,'asc']],
        columnDefs: [{ orderable: false, targets: [0, 5] }],
        language: { paginate: { previous: 'Prev', next: 'Next' }, info: 'Showing _START_–_END_ of _TOTAL_' },
        drawCallback: function() {
            var info = this.api().page.info();
            $('#mlCountLabel').text(info.recordsDisplay + ' lists');
            this.api().rows({ page: 'current' }).every(function(rowIdx) {
                $(this.node()).find('.ml-row-no').text(info.start + rowIdx + 1);
            });
        }
    });
    } // end DataTables guard

    $('#mlSearch').on('input', function() { mlTable.search(this.value).draw(); });
    $('#mlFilterProject').on('change', function() { mlTable.draw(); });
    $('#mlPerPage').on('change', function() { mlTable.page.len(parseInt(this.value)).draw(); });

    // Add modal: reset + preview list_no on open
    document.getElementById('addMaterialListModal').addEventListener('show.bs.modal', function() {
        mlAddRowIdx = 0;
        $('#mlAddTbody').empty();
        $('#mlAddMsg').html('');
        document.getElementById('addMaterialListForm').reset();
        $('#mlAddListNo').val('');
        mlAddProjectChanged();
        mlAddRow('add');
        $.getJSON(NIP_URL + '/api/get_material_lists.php', function(res) {
            if (res.success) {
                var d = new Date();
                var ds = d.getFullYear() + '' + String(d.getMonth()+1).padStart(2,'0') + String(d.getDate()).padStart(2,'0');
                $('#mlAddListNo').val('ML-' + ds + '-' + String(res.lists.length + 1).padStart(4,'0'));
            }
        });
    });

    // Add form submit
    $('#addMaterialListForm').on('submit', function(e) {
        e.preventDefault();
        var name = $('#mlAddName').val().trim();
        if (!name) { $('#mlAddMsg').html('<div class="alert alert-danger py-2">Material List Name is required.</div>'); return; }
        var hasNip = false;
        $('#mlAddTbody .ml-nip-select-add').each(function() { if ($(this).val()) hasNip = true; });
        if (!hasNip) { $('#mlAddMsg').html('<div class="alert alert-danger py-2">Select at least one Non-Inventory Product.</div>'); return; }
        var $btn = $('#mlAddSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
        fetch(NIP_URL + '/api/create_material_list.php', { method: 'POST', body: new FormData(this) })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Materials');
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addMaterialListModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Created!', text: res.message, timer: 2000, showConfirmButton: false });
                    setTimeout(function() { location.reload(); }, 2100);
                } else {
                    $('#mlAddMsg').html('<div class="alert alert-danger py-2">' + res.message + '</div>');
                }
            }).catch(function() {
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Materials');
                $('#mlAddMsg').html('<div class="alert alert-danger py-2">Server error. Try again.</div>');
            });
    });

    // Edit form submit
    $('#editMaterialListForm').on('submit', function(e) {
        e.preventDefault();
        var name = $('#mlEditName').val().trim();
        if (!name) { $('#mlEditMsg').html('<div class="alert alert-danger py-2">Material List Name is required.</div>'); return; }
        var hasNip = false;
        $('#mlEditTbody .ml-nip-select-edit').each(function() { if ($(this).val()) hasNip = true; });
        if (!hasNip) { $('#mlEditMsg').html('<div class="alert alert-danger py-2">Select at least one Non-Inventory Product.</div>'); return; }
        var $btn = $('#mlEditSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
        fetch(NIP_URL + '/api/update_material_list.php', { method: 'POST', body: new FormData(this) })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('editMaterialListModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Updated!', text: res.message, timer: 2000, showConfirmButton: false });
                    setTimeout(function() { location.reload(); }, 2100);
                } else {
                    $('#mlEditMsg').html('<div class="alert alert-danger py-2">' + res.message + '</div>');
                }
            }).catch(function() {
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
                $('#mlEditMsg').html('<div class="alert alert-danger py-2">Server error. Try again.</div>');
            });
    });
});

</script>
<?php includeFooter(); ?>
