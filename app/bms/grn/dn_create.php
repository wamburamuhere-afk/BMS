<?php
// File: app/bms/grn/dn_create.php
// scope-audit: skip — DN create form; no prior record to scope; scope enforced via api/approve_dn.php + api/delete_dn.php
// RECORD a Delivery Note — INBOUND: goods received FROM a Supplier / Sub-Contractor.
// The DN number is typed by hand (the number on the supplier's physical note) and one
// or more named scans of that note must be attached.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('dn');

global $pdo;

$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
// An outbound DN must be edited on the outbound form — redirect before any output.
if ($edit_id > 0) {
    $peek = $pdo->prepare("SELECT dn_type FROM deliveries WHERE delivery_id = ?");
    $peek->execute([$edit_id]);
    if ($peek->fetchColumn() === 'outbound') {
        header('Location: ' . getUrl('dn_outbound') . '?edit=' . $edit_id);
        exit;
    }
}

includeHeader();

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
// Origin context (URL only): where the user came FROM. Drives the post-save redirect so
// editing a project-linked DN from the general area does NOT jump into the project.
$origin_project_id = $project_id;
$po_id      = isset($_GET['po_id'])      ? intval($_GET['po_id'])      : 0;
$is_edit    = $edit_id > 0;
$is_from_po = $po_id > 0;

// ── 1. LOAD DN (edit mode) ───────────────────────────────────
$dn = null; $dn_items = []; $dn_attachments = [];
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
    $stmt->execute([$edit_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dn) {
        $project_id = intval($dn['project_id'] ?? 0);
        $stmt2 = $pdo->prepare("SELECT di.*, p.product_name, p.sku, p.unit FROM delivery_items di LEFT JOIN products p ON di.product_id = p.product_id WHERE di.delivery_id = ? ORDER BY di.delivery_item_id");
        $stmt2->execute([$edit_id]);
        $dn_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $att = $pdo->prepare("SELECT * FROM delivery_attachments WHERE delivery_id = ? ORDER BY attachment_id");
        $att->execute([$edit_id]);
        $dn_attachments = $att->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── 2. LOAD PO (from-PO mode) ────────────────────────────────
$po_data = null;
if ($is_from_po) {
    $stmt = $pdo->prepare("SELECT po.*, s.supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id WHERE po.purchase_order_id = ?");
    $stmt->execute([$po_id]);
    $po_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($po_data && intval($po_data['project_id']) > 0) $project_id = intval($po_data['project_id']);
}

$has_project = $project_id > 0;

// ── 3. LISTS — scoped by project for non-admins ───────────────
$_dnc_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));

// Warehouses come from the shared helper (core/warehouse_scope.php);
// the JS cascade filters them further by the selected project.
require_once ROOT_DIR . '/core/warehouse_scope.php';
$all_warehouses = warehousesForSelect($pdo);

if (isAdmin()) {
    $all_projects   = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
    $all_suppliers  = $pdo->query("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    if ($project_id > 0) {
        $_dnc_admpstmt = $pdo->prepare("
            SELECT po.purchase_order_id, po.order_number, po.supplier_id, IFNULL(po.warehouse_id,0) AS warehouse_id, IFNULL(po.project_id,0) AS project_id, s.supplier_name
            FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.supplier_id
            WHERE po.status = 'approved' AND po.project_id = ?
            ORDER BY po.order_date DESC
        ");
        $_dnc_admpstmt->execute([$project_id]);
        $po_list = $_dnc_admpstmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $po_list = $pdo->query("
            SELECT po.purchase_order_id, po.order_number, po.supplier_id, IFNULL(po.warehouse_id,0) AS warehouse_id, IFNULL(po.project_id,0) AS project_id, s.supplier_name
            FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.supplier_id
            WHERE po.status = 'approved'
            ORDER BY po.order_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif (!empty($_dnc_assigned)) {
    $_dnc_ph = implode(',', array_fill(0, count($_dnc_assigned), '?'));
    $_dnc_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($_dnc_ph) ORDER BY project_name");
    $_dnc_pstmt->execute($_dnc_assigned);
    $all_projects = $_dnc_pstmt->fetchAll(PDO::FETCH_ASSOC);
    $_dnc_sstmt = $pdo->prepare("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_dnc_ph)) ORDER BY supplier_name");
    $_dnc_sstmt->execute($_dnc_assigned);
    $all_suppliers = $_dnc_sstmt->fetchAll(PDO::FETCH_ASSOC);
    if ($project_id > 0) {
        $_dnc_postmt = $pdo->prepare("
            SELECT po.purchase_order_id, po.order_number, po.supplier_id, IFNULL(po.warehouse_id,0) AS warehouse_id, IFNULL(po.project_id,0) AS project_id, s.supplier_name
            FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.supplier_id
            WHERE po.status = 'approved' AND po.project_id = ?
            ORDER BY po.order_date DESC
        ");
        $_dnc_postmt->execute([$project_id]);
    } else {
        $_dnc_postmt = $pdo->prepare("
            SELECT po.purchase_order_id, po.order_number, po.supplier_id, IFNULL(po.warehouse_id,0) AS warehouse_id, IFNULL(po.project_id,0) AS project_id, s.supplier_name
            FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.supplier_id
            WHERE po.status = 'approved'
            AND (po.project_id IS NULL OR po.project_id IN ($_dnc_ph))
            ORDER BY po.order_date DESC
        ");
        $_dnc_postmt->execute($_dnc_assigned);
    }
    $po_list = $_dnc_postmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $all_projects   = [];
    $all_suppliers  = $pdo->query("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' AND project_id IS NULL ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    if ($project_id > 0) {
        $_dnc_nppstmt = $pdo->prepare("
            SELECT po.purchase_order_id, po.order_number, po.supplier_id, IFNULL(po.warehouse_id,0) AS warehouse_id, IFNULL(po.project_id,0) AS project_id, s.supplier_name
            FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.supplier_id
            WHERE po.status = 'approved' AND po.project_id = ?
            ORDER BY po.order_date DESC
        ");
        $_dnc_nppstmt->execute([$project_id]);
        $po_list = $_dnc_nppstmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $po_list = $pdo->query("
            SELECT po.purchase_order_id, po.order_number, po.supplier_id, IFNULL(po.warehouse_id,0) AS warehouse_id, IFNULL(po.project_id,0) AS project_id, s.supplier_name
            FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.supplier_id
            WHERE po.status = 'approved' AND po.project_id IS NULL
            ORDER BY po.order_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}
$all_subs = $pdo->query("SELECT supplier_id, supplier_name, company_name FROM sub_contractors WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

// Project context
$project = null;
if ($has_project) {
    $stmt = $pdo->prepare("SELECT project_id, project_name, contract_number AS contract_no FROM projects WHERE project_id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Resolve the currently-selected party (edit / from-PO)
$cur_party_type = 'supplier';
$cur_party_id   = 0;
if ($dn) {
    $cur_party_type = ($dn['party_type'] === 'subcontractor') ? 'subcontractor' : 'supplier';
    $cur_party_id   = ($cur_party_type === 'subcontractor') ? intval($dn['subcontractor_id'] ?? 0) : intval($dn['supplier_id'] ?? 0);
} elseif ($po_data) {
    $cur_party_id = intval($po_data['supplier_id']);
}

$return_url = $is_from_po
    ? getUrl('purchase_order_details') . '?id=' . $po_id
    : ($origin_project_id > 0 ? getUrl('project_view') . '?id=' . $origin_project_id . '&tab=procurement' : getUrl('delivery_notes'));
?>

<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>

<div class="container-fluid mt-3">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none dn-sticky-nav">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $return_url ?>">Delivery Notes</a></li>
            <li class="breadcrumb-item active"><?= $is_edit ? 'Edit Record DN' : 'Record Delivery Note' ?></li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-start gap-3 mb-4 d-print-none">
        <div>
            <h4 class="fw-bold mb-1">
                <i class="bi bi-box-arrow-in-down text-primary me-2"></i>
                <?= $is_edit ? 'Edit Record Delivery Note' : 'Record Delivery Note' ?>
                <span class="badge bg-primary-subtle text-primary border border-primary ms-1" style="font-size:.65rem;">INBOUND</span>
            </h4>
            <p class="text-muted small mb-0">Goods <strong>received from</strong> a supplier or sub-contractor — recorded against their physical delivery note.</p>
        </div>
        <a href="<?= $return_url ?>" class="btn btn-outline-secondary btn-sm flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>

    <form id="dnForm" enctype="multipart/form-data">
        <?php if ($is_edit): ?><input type="hidden" name="delivery_id" value="<?= $edit_id ?>"><?php endif; ?>

        <div class="row g-4">
            <!-- LEFT -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i>Delivery Note Details</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <!-- 1. DN NUMBER (typed by hand) -->
                            <div class="col-12">
                                <label class="form-label fw-semibold">Supplier's DN Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-lg fw-bold border-primary border-opacity-50"
                                       name="dn_number" id="dn_number"
                                       value="<?= $dn ? safe_output($dn['dn_number']) : '' ?>"
                                       placeholder="Type the number written on the supplier's delivery note" required>
                                <small class="text-muted"><i class="bi bi-pencil me-1"></i>Enter the number exactly as it appears on the physical note the supplier brought.</small>
                            </div>

                            <hr class="my-1">

                            <!-- 2. PARTY TYPE (dropdown) -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Received From <span class="text-danger">*</span></label>
                                <select class="form-select" name="party_type" id="dn_party_type">
                                    <option value="supplier" <?= $cur_party_type === 'supplier' ? 'selected' : '' ?>>Supplier</option>
                                    <option value="subcontractor" <?= $cur_party_type === 'subcontractor' ? 'selected' : '' ?>>Sub-Contractor</option>
                                </select>
                            </div>

                            <!-- 3. SPECIFIC PARTY -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" id="partyLabel">Select Supplier <span class="text-danger">*</span></label>
                                <select class="form-select" name="party_id" id="dn_party_id" required></select>
                            </div>

                            <!-- Project -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Project <span class="text-muted small">(Optional)</span></label>
                                <select class="form-select select2-static" name="project_id" id="dn_project_id">
                                    <option value="0">-- No Project (General) --</option>
                                    <?php foreach ($all_projects as $p): ?>
                                    <option value="<?= $p['project_id'] ?>" <?= ($project_id == $p['project_id']) ? 'selected' : '' ?>><?= safe_output($p['project_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Warehouse -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Warehouse <span class="text-danger">*</span></label>
                                <select class="form-select" name="warehouse_id" id="dn_warehouse_id" required>
                                    <option value="">-- Select Warehouse --</option>
                                </select>
                            </div>

                            <!-- PO reference -->
                            <div class="col-md-6" id="poFieldWrap">
                                <label class="form-label fw-semibold">Purchase Order Reference <span class="text-muted small">(Optional)</span></label>
                                <select class="form-select" name="purchase_order_id" id="dn_purchase_order_id">
                                    <option value="">-- Select PO (Optional) --</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">DN Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="delivery_date" value="<?= $dn ? $dn['delivery_date'] : date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contact Person</label>
                                <input type="text" class="form-control" name="contact_person" value="<?= $dn ? safe_output($dn['contact_person']) : '' ?>" placeholder="Person who received delivery">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contact Phone</label>
                                <input type="text" class="form-control" name="contact_phone" value="<?= $dn ? safe_output($dn['contact_phone']) : '' ?>" placeholder="+255...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Delivery Address</label>
                                <input type="text" class="form-control" name="delivery_address" value="<?= $dn ? safe_output($dn['delivery_address']) : '' ?>" placeholder="Delivery site address">
                            </div>

                            <hr class="col-12 my-1">

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Vehicle / Truck No. <span class="text-muted small fw-normal">(Optional)</span></label>
                                <input type="text" class="form-control" name="vehicle_number" value="<?= $dn ? safe_output($dn['vehicle_number'] ?? '') : '' ?>" placeholder="e.g. T 123 ABC">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Driver Name <span class="text-muted small fw-normal">(Optional)</span></label>
                                <input type="text" class="form-control" name="driver_name" value="<?= $dn ? safe_output($dn['driver_name'] ?? '') : '' ?>" placeholder="Full name of driver">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Shipping Method <span class="text-muted small fw-normal">(Optional)</span></label>
                                <input type="text" class="form-control" name="shipping_method" value="<?= $dn ? safe_output($dn['shipping_method'] ?? '') : '' ?>" placeholder="e.g. Road, Air, Sea">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Notes</label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."><?= $dn ? safe_output($dn['notes']) : '' ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-light py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i>Materials Received</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="dnItemsTable" style="min-width:560px;">
                                <thead class="bg-light text-uppercase small fw-bold">
                                    <tr>
                                        <th class="ps-3" style="width:50px;">S/NO</th>
                                        <th>Product</th>
                                        <th style="width:120px;">In Stock</th>
                                        <th style="width:130px;">Qty Received</th>
                                        <th style="width:80px;">Unit</th>
                                        <th style="width:110px;">Condition</th>
                                        <th style="width:55px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="dnItemsBody"></tbody>
                            </table>
                        </div>
                        <div id="dnItemsEmpty" class="text-center py-4 text-muted">
                            <i class="bi bi-box-seam fs-3 d-block mb-2 opacity-25"></i>
                            <p class="small">Select a warehouse first, then click "Add Item"</p>
                        </div>
                        <div class="p-3 border-top">
                            <button type="button" class="btn btn-primary btn-sm" onclick="addDNItem()">
                                <i class="bi bi-plus-circle me-1"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Supplier's DN scans (required, named, multiple) -->
                <div class="card shadow-sm border-0 border-start border-primary border-3">
                    <div class="card-header bg-light py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2 text-primary"></i>Supplier's Delivery Note — Attachments <span class="text-danger">*</span></h6>
                    </div>
                    <div class="card-body p-3">
                        <?php if ($is_edit && $dn_attachments): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Existing Attachments</label>
                            <?php foreach ($dn_attachments as $a): ?>
                            <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-1" id="att-<?= $a['attachment_id'] ?>">
                                <a href="<?= getUrl($a['file_path']) ?>" target="_blank" class="text-decoration-none small text-truncate">
                                    <i class="bi bi-file-earmark-text text-primary me-1"></i><?= safe_output($a['file_name']) ?>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteAttachment(<?= $a['attachment_id'] ?>)"><i class="bi bi-trash"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <label class="form-label fw-semibold">
                            Upload named scans of the supplier's physical DN
                            <?php if (!$is_edit): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <div id="attachmentRows"></div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="addAttachmentRow()">
                            <i class="bi bi-plus-circle me-1"></i> Add Attachment
                        </button>
                        <div><small class="text-muted">Each attachment needs a name and a file. PDF, image or Word. Max 10MB each.
                            <?= $is_edit ? 'Existing files above are kept; rows added here are appended.' : 'At least one attachment is required.' ?>
                        </small></div>
                    </div>
                </div>
            </div>

            <!-- RIGHT -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 border-primary mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-clipboard-check me-2"></i>Record Summary</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Items:</span>
                            <span class="fw-bold" id="dnTotalItems">0</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Qty:</span>
                            <span class="fw-bold text-primary" id="dnTotalQty">0</span>
                        </div>
                        <hr>
                        <div class="alert alert-primary small py-2 mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            This records goods <strong>received from</strong> the supplier. The DN number and attachments come from their physical note.
                        </div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary shadow-sm px-4" onclick="submitDN('draft')">
                                <i class="bi bi-save me-2"></i> <?= $is_edit ? 'Update Record' : 'Save Record DN' ?>
                            </button>
                            <a href="<?= $return_url ?>" class="btn btn-link text-muted text-decoration-none small">Cancel</a>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0" id="warehouseStockCard" style="display:none;">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0 fw-bold small"><i class="bi bi-building me-2"></i>Current Warehouse Stock</h6>
                    </div>
                    <div class="card-body p-2" id="warehouseStockList">
                        <div class="text-center text-muted py-3 small">Loading...</div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="<?= getUrl('assets/js/warehouse-project-filter.js') ?>"></script>
<script>
let PROJECT_ID = <?= $project_id ?>;
let warehouseStock = [];
let isInitialLoad = true;
let attachmentRowSeq = 0;

const ALL_WAREHOUSES = <?= json_encode(array_values(array_map(fn($w) => [
    'id' => (int)$w['warehouse_id'],
    'text' => $w['warehouse_name'] . (!empty($w['location']) ? ' — ' . $w['location'] : ''),
    'project_id' => (int)$w['project_id'],
], $all_warehouses))) ?>;
const ALL_SUPPLIERS = <?= json_encode(array_values(array_map(fn($s) => [
    'id' => (int)$s['supplier_id'],
    'text' => $s['supplier_name'] . (!empty($s['company_name']) ? ' (' . $s['company_name'] . ')' : ''),
], $all_suppliers))) ?>;
const ALL_SUBCONTRACTORS = <?= json_encode(array_values(array_map(fn($s) => [
    'id' => (int)$s['supplier_id'],
    'text' => $s['supplier_name'] . (!empty($s['company_name']) ? ' (' . $s['company_name'] . ')' : ''),
], $all_subs))) ?>;
const ALL_POS = <?= json_encode(array_values(array_map(fn($p) => [
    'id' => (int)$p['purchase_order_id'],
    'text' => $p['order_number'] . ' (' . $p['supplier_name'] . ')',
    'supplier_id' => (int)$p['supplier_id'],
], $po_list))) ?>;

const CUR_PARTY_TYPE = '<?= $cur_party_type ?>';
const CUR_PARTY_ID   = <?= (int)$cur_party_id ?>;
const IS_EDIT        = <?= $is_edit ? 'true' : 'false' ?>;
const IS_FROM_PO     = <?= $is_from_po ? 'true' : 'false' ?>;
const PRESET_WH      = <?= (int)($dn['warehouse_id'] ?? $po_data['warehouse_id'] ?? 0) ?>;
const PRESET_PO      = <?= (int)($dn['purchase_order_id'] ?? $po_id ?? 0) ?>;

function initS2($el, placeholder) {
    if ($el.data('select2')) $el.select2('destroy');
    $el.select2({ theme: 'bootstrap-5', placeholder: placeholder, allowClear: true, width: '100%' });
}

// ── Attachment rows (name + file) ────────────────────────────
function addAttachmentRow(name) {
    attachmentRowSeq++;
    const id = 'attrow_' + attachmentRowSeq;
    const html = `
    <div class="row g-2 mb-2 attachment-row align-items-center" id="${id}">
        <div class="col-12 col-md-5">
            <input type="text" class="form-control form-control-sm att-name" name="attachment_name[]"
                placeholder="Attachment name (e.g. Supplier DN page 1)" value="${name || ''}">
        </div>
        <div class="col-9 col-md-6">
            <input type="file" class="form-control form-control-sm att-file" name="attachment_file[]"
                accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx">
        </div>
        <div class="col-3 col-md-1 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeAttachmentRow('${id}')"><i class="bi bi-trash"></i></button>
        </div>
    </div>`;
    $('#attachmentRows').append(html);
}
function removeAttachmentRow(id) {
    $('#' + id).remove();
    if ($('#attachmentRows .attachment-row').length === 0) addAttachmentRow();
}

// ── Warehouse list (filtered by project) ─────────────────────
function rebuildWarehouses() {
    const $sel = $('#dn_warehouse_id');
    const current = $sel.val() || PRESET_WH;
    initS2($sel, '-- Select Warehouse --');
    $sel.empty().append($('<option>').val('').text('-- Select Warehouse --'));
    // Shared Project → Warehouse rule (assets/js/warehouse-project-filter.js):
    // no project -> only unassigned warehouses; project -> only its warehouses.
    filterWarehousesForProject(ALL_WAREHOUSES, PROJECT_ID)
        .forEach(w => $sel.append($('<option>').val(w.id).text(w.text).prop('selected', w.id == current)));
    $sel.trigger('change.select2');
}

// ── Party list (suppliers OR sub-contractors) ────────────────
function rebuildParty(preserve) {
    const type = $('#dn_party_type').val();
    const list = type === 'subcontractor' ? ALL_SUBCONTRACTORS : ALL_SUPPLIERS;
    $('#partyLabel').html((type === 'subcontractor' ? 'Select Sub-Contractor' : 'Select Supplier') + ' <span class="text-danger">*</span>');
    const $sel = $('#dn_party_id');
    const current = preserve ? $sel.val() : null;
    initS2($sel, type === 'subcontractor' ? '-- Select Sub-Contractor --' : '-- Select Supplier --');
    $sel.empty().append($('<option>').val('').text(type === 'subcontractor' ? '-- Select Sub-Contractor --' : '-- Select Supplier --'));
    list.forEach(s => $sel.append($('<option>').val(s.id).text(s.text).prop('selected', s.id == current)));
    $sel.trigger('change.select2');
    $('#poFieldWrap').toggle(type === 'supplier');
    rebuildPOs();
}

// ── PO list (filtered by selected supplier) ──────────────────
function rebuildPOs() {
    const $sel = $('#dn_purchase_order_id');
    const type = $('#dn_party_type').val();
    const supplierId = parseInt($('#dn_party_id').val()) || 0;
    const current = $sel.val() || PRESET_PO;
    initS2($sel, '-- Select PO (Optional) --');
    $sel.empty().append($('<option>').val('').text('-- Select PO (Optional) --'));
    if (type === 'supplier') {
        ALL_POS.filter(p => !supplierId || p.supplier_id === supplierId)
            .forEach(p => $sel.append($('<option>').val(p.id).text(p.text).prop('selected', p.id == current)));
    }
    $sel.trigger('change.select2');
}

$(document).ready(function () {
    $('#dn_project_id').select2({ theme: 'bootstrap-5', placeholder: '-- No Project (General) --', allowClear: true, width: '100%' });

    rebuildWarehouses();
    $('#dn_party_type').val(CUR_PARTY_TYPE);
    rebuildParty(false);
    if (CUR_PARTY_ID > 0) { $('#dn_party_id').val(CUR_PARTY_ID).trigger('change.select2'); rebuildPOs(); }

    addAttachmentRow(); // start with one empty attachment row

    $('#dn_project_id').on('change', function () { PROJECT_ID = parseInt($(this).val()) || 0; rebuildWarehouses(); });
    $('#dn_warehouse_id').on('change', function () { if (!isInitialLoad) loadWarehouseStock(); });
    $('#dn_party_type').on('change', function () { rebuildParty(false); });
    $('#dn_party_id').on('change', function () { if (!isInitialLoad) rebuildPOs(); });
    $('#dn_purchase_order_id').on('change', function () { if (!isInitialLoad) handlePOSelection(this); });

    if (PRESET_WH > 0) { $('#dn_warehouse_id').val(PRESET_WH).trigger('change.select2'); loadWarehouseStock(); }

    if (IS_EDIT) {
        setTimeout(function () {
            <?php foreach ($dn_items as $item): ?>
            addDNItem('<?= $item['product_id'] ?>', '<?= addslashes($item['product_name']) ?>', '<?= $item['quantity_delivered'] ?>', '<?= $item['unit'] ?>', 0, '<?= $item['condition'] ?? 'good' ?>');
            <?php endforeach; ?>
        }, 800);
    } else if (IS_FROM_PO && PRESET_PO > 0) {
        loadPOItemsForDN(PRESET_PO);
    } else {
        addDNItem();
    }

    setTimeout(() => { isInitialLoad = false; }, 1000);
});

// ── Warehouse stock ──────────────────────────────────────────
function loadWarehouseStock(callback) {
    const warehouseId = document.getElementById('dn_warehouse_id').value;
    if (!warehouseId) {
        document.getElementById('warehouseStockCard').style.display = 'none';
        warehouseStock = [];
        return;
    }
    document.getElementById('warehouseStockCard').style.display = '';
    document.getElementById('warehouseStockList').innerHTML = '<div class="text-center text-muted py-2 small"><i class="bi bi-hourglass-split me-1"></i>Loading stock...</div>';
    $.getJSON(APP_URL + '/api/get_project_warehouse_stock', { warehouse_id: warehouseId, project_id: PROJECT_ID }, function (res) {
        if (res.success && res.data && res.data.length > 0) {
            warehouseStock = res.data;
            let html = '';
            res.data.forEach(p => {
                if (p.is_service) return;
                html += `<div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                    <div><div class="fw-bold">${p.product_name}</div><small class="text-muted">${p.sku || ''}</small></div>
                    <span class="badge bg-primary bg-opacity-10 text-primary border">${p.available_quantity} ${p.unit}</span>
                </div>`;
            });
            document.getElementById('warehouseStockList').innerHTML = html;
        } else {
            warehouseStock = [];
            document.getElementById('warehouseStockList').innerHTML = '<div class="text-center text-muted py-2 small">No stock recorded in this warehouse yet.</div>';
        }
        if (typeof callback === 'function') callback();
    }).fail(function () {
        document.getElementById('warehouseStockList').innerHTML = '<div class="text-center text-danger py-2 small">Failed to load stock.</div>';
        if (typeof callback === 'function') callback();
    });
}

// ── Product autocomplete dropdown ────────────────────────────
function closeAllDropdowns() { document.querySelectorAll('.dn-product-dropdown').forEach(d => d.remove()); }

function showProductDropdown(rowId, inputEl) {
    closeAllDropdowns();
    if (!document.getElementById('dn_warehouse_id').value) {
        const d = document.createElement('div');
        d.className = 'dn-product-dropdown';
        const r = inputEl.getBoundingClientRect(), sY = window.pageYOffset || document.documentElement.scrollTop;
        d.style.cssText = `position:absolute;top:${r.bottom+sY+2}px;left:${r.left}px;width:${Math.max(r.width,260)}px;background:#fff8f0;border:1px solid #f0ad4e;border-radius:6px;padding:10px 14px;font-size:.85rem;color:#664d03;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.1);`;
        d.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Please select a warehouse first';
        document.body.appendChild(d);
        setTimeout(closeAllDropdowns, 2500);
        return;
    }
    if (warehouseStock.length === 0) { loadWarehouseStock(() => showProductDropdown(rowId, inputEl)); return; }

    const q = inputEl.value.trim().toLowerCase();
    const filtered = warehouseStock.filter(p => !p.is_service && (!q || p.product_name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q)));
    const r = inputEl.getBoundingClientRect();
    const sY = window.pageYOffset || document.documentElement.scrollTop;
    const sX = window.pageXOffset || document.documentElement.scrollLeft;
    const dropdown = document.createElement('div');
    dropdown.className = 'dn-product-dropdown';

    if (filtered.length === 0) {
        dropdown.style.cssText = `position:absolute;top:${r.bottom+sY+2}px;left:${r.left}px;width:${Math.max(r.width,260)}px;background:#fff;border:1px solid #ced4da;border-radius:6px;padding:10px 14px;font-size:.85rem;color:#888;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.1);`;
        dropdown.innerHTML = '<i class="bi bi-search me-2"></i>No products found';
        document.body.appendChild(dropdown);
        setTimeout(closeAllDropdowns, 2000);
        return;
    }
    dropdown.style.cssText = `position:absolute;top:${r.bottom+sY+2}px;left:${r.left+sX}px;width:${Math.max(r.width,280)}px;max-height:220px;overflow-y:auto;background:#fff;border:1px solid #ced4da;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:99999;`;
    filtered.forEach(p => {
        const item = document.createElement('div');
        item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;';
        const tracked = p.track_inventory == 1 || p.track_inventory === true;
        const txt = tracked ? `${p.available_quantity} ${p.unit}` : 'Non-tracked';
        const bg = tracked ? (p.available_quantity > 0 ? '#cfe2ff' : '#f8d7da') : '#fff3cd';
        const col = tracked ? (p.available_quantity > 0 ? '#084298' : '#842029') : '#664d03';
        item.innerHTML = `<div class="d-flex justify-content-between align-items-center">
            <div><div style="font-weight:600;font-size:.85rem;">${p.product_name}</div><small style="color:#888;">${p.sku || ''}</small></div>
            <span style="font-size:.75rem;padding:2px 8px;border-radius:20px;font-weight:600;background:${bg};color:${col};">${txt}</span></div>`;
        item.addEventListener('mousedown', function (e) { e.preventDefault(); selectProduct(rowId, p); closeAllDropdowns(); });
        item.addEventListener('mouseover', function () { this.style.background = '#f0f4ff'; });
        item.addEventListener('mouseout', function () { this.style.background = '#fff'; });
        dropdown.appendChild(item);
    });
    document.body.appendChild(dropdown);
}

function selectProduct(rowId, p) {
    document.getElementById('pid_' + rowId).value = p.product_id;
    document.getElementById('pname_' + rowId).value = p.product_name;
    document.getElementById('unit_' + rowId).textContent = p.unit;
    document.getElementById('unitval_' + rowId).value = p.unit;
    const avail = parseFloat(p.available_quantity) || 0;
    const availEl = document.getElementById('avail_' + rowId);
    if (availEl) { availEl.textContent = avail > 0 ? avail + ' ' + p.unit : '—'; }
    const qtyEl = document.getElementById('qty_' + rowId);
    if (qtyEl) qtyEl.focus();
    updateDNSummary();
}

function addDNItem(productId, productName, qty, unit, available, condition) {
    productId = productId || ''; productName = productName || ''; qty = qty || '';
    unit = unit || 'pcs'; available = available || 0; condition = condition || 'good';
    if (productId && (available == 0 || available == '0')) {
        const s = warehouseStock.find(s => s.product_id == productId);
        if (s) available = s.available_quantity;
    }
    const rowId = 'dnrow_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const avail = parseFloat(available) || 0;
    const availTxt = (productId && avail > 0) ? avail + ' ' + unit : '—';
    const html = `
    <tr id="${rowId}">
        <td class="text-center text-muted fw-bold serial-num ps-2" style="width:50px;"></td>
        <td style="min-width:180px;">
            <div style="position:relative;">
                <input type="text" id="pname_${rowId}" class="form-control form-control-sm" placeholder="Type or click to search..."
                    value="${productName}" autocomplete="off"
                    oninput="showProductDropdown('${rowId}', this)" onfocus="showProductDropdown('${rowId}', this)"
                    onblur="setTimeout(closeAllDropdowns, 200)">
                <input type="hidden" name="product_id[]" id="pid_${rowId}" value="${productId}">
            </div>
        </td>
        <td class="text-center" style="width:110px;">
            <span class="badge bg-primary bg-opacity-10 text-primary border small" id="avail_${rowId}">${availTxt}</span>
        </td>
        <td style="width:130px;">
            <input type="number" id="qty_${rowId}" class="form-control form-control-sm qty-input" name="quantity[]"
                value="${qty}" min="0.001" step="0.001" placeholder="Qty" oninput="updateDNSummary()">
        </td>
        <td style="width:75px;">
            <span class="text-muted small fw-semibold" id="unit_${rowId}">${unit}</span>
            <input type="hidden" name="unit[]" value="${unit}" id="unitval_${rowId}">
        </td>
        <td style="width:110px;">
            <select id="cond_${rowId}" name="condition[]" class="form-select form-select-sm cond-select">
                <option value="good" ${condition === 'good' ? 'selected' : ''}>Good</option>
                <option value="damaged" ${condition === 'damaged' ? 'selected' : ''}>Damaged</option>
                <option value="expired" ${condition === 'expired' ? 'selected' : ''}>Expired</option>
            </select>
        </td>
        <td class="text-center pe-2" style="width:48px;">
            <button type="button" class="btn btn-danger btn-sm" style="width:30px;height:30px;padding:0;" onclick="removeRow('${rowId}')">
                <i class="bi bi-trash" style="font-size:.75rem;"></i>
            </button>
        </td>
    </tr>`;
    $('#dnItemsBody').append(html);
    updateSerials();
    updateDNSummary();
}

function removeRow(rowId) { $('#' + rowId).remove(); updateSerials(); updateDNSummary(); }

function updateSerials() {
    const rows = $('#dnItemsBody tr');
    rows.each(function (i) { $(this).find('.serial-num').text(i + 1); });
    $('#dnItemsEmpty').toggle(rows.length === 0);
}

function updateDNSummary() {
    let totalItems = 0, totalQty = 0;
    $('#dnItemsBody tr').each(function () {
        const qty = parseFloat($(this).find('.qty-input').val()) || 0;
        if ($(this).find('input[name="product_id[]"]').val()) { totalItems++; totalQty += qty; }
    });
    $('#dnTotalItems').text(totalItems);
    $('#dnTotalQty').text(totalQty.toFixed(3));
}

function handlePOSelection(select) {
    const poId = $(select).val();
    if (poId) loadPOItemsForDN(poId);
}

function loadPOItemsForDN(poId) {
    Swal.fire({ title: 'Loading PO Items...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.get('<?= getUrl("api/get_po_items") ?>', { id: poId }, function (res) {
        Swal.close();
        if (res.success && res.data && res.data.items) {
            $('#dnItemsBody').empty();
            res.data.items.forEach(item => {
                if (parseFloat(item.quantity_remaining) <= 0) return;
                let available = 0;
                if (warehouseStock.length > 0) {
                    const s = warehouseStock.find(s => s.product_id == item.product_id);
                    if (s) available = s.available_quantity;
                }
                addDNItem(item.product_id, item.product_name, item.quantity_remaining, item.unit, available);
            });
            updateDNSummary();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load PO items' });
        }
    }, 'json').fail(function () {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Server Error', text: 'Could not reach server.' });
    });
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.dn-product-dropdown') && !e.target.closest('input[id^="pname_"]')) closeAllDropdowns();
});

function deleteAttachment(id) {
    Swal.fire({
        title: 'Remove attachment?', icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, remove', cancelButtonText: 'Cancel'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl("api/delete_dn_attachment.php") ?>', { attachment_id: id }, function (res) {
            if (res.success) {
                $('#att-' + id).remove();
                Swal.fire({ icon: 'success', title: 'Removed', timer: 1200, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Network error.' }));
    });
}

// ── Submit ───────────────────────────────────────────────────
function submitDN(status) {
    status = status || 'draft';
    const dnNumber  = $('#dn_number').val().trim();
    const partyType = $('#dn_party_type').val();
    const partyId   = $('#dn_party_id').val();
    const warehouse = $('#dn_warehouse_id').val();
    const date      = $('[name="delivery_date"]').val();

    if (!dnNumber)  { Swal.fire({ icon: 'warning', title: "DN Number Required", text: "Enter the supplier's DN number." }); return; }
    if (!partyId)   { Swal.fire({ icon: 'warning', title: 'Required', text: 'Select the ' + (partyType === 'subcontractor' ? 'sub-contractor' : 'supplier') + '.' }); return; }
    if (!warehouse) { Swal.fire({ icon: 'warning', title: 'Required', text: 'Select a warehouse.' }); return; }
    if (!date)      { Swal.fire({ icon: 'warning', title: 'Required', text: 'Enter the DN date.' }); return; }

    const items = [];
    $('#dnItemsBody tr').each(function () {
        const productId = $(this).find('input[name="product_id[]"]').val();
        const qty = parseFloat($(this).find('input[name="quantity[]"]').val()) || 0;
        const unit = $(this).find('input[name="unit[]"]').val() || 'pcs';
        const condition = $(this).find('select[name="condition[]"]').val() || 'good';
        if (productId && qty > 0) items.push({ product_id: productId, quantity: qty, unit: unit, condition: condition });
    });
    if (items.length === 0) { Swal.fire({ icon: 'warning', title: 'No Valid Items', text: 'Add at least one item with a product and quantity.' }); return; }

    const fd = new FormData();
    fd.append('dn_type', 'inbound');
    fd.append('dn_number', dnNumber);
    fd.append('party_type', partyType);
    fd.append('party_id', partyId);
    fd.append('project_id', PROJECT_ID);
    fd.append('warehouse_id', warehouse);
    fd.append('delivery_date', date);
    fd.append('contact_person', $('[name="contact_person"]').val());
    fd.append('contact_phone', $('[name="contact_phone"]').val());
    fd.append('delivery_address', $('[name="delivery_address"]').val());
    fd.append('notes', $('[name="notes"]').val());
    fd.append('purchase_order_id', $('#dn_purchase_order_id').val() || '');
    fd.append('vehicle_number', $('[name="vehicle_number"]').val() || '');
    fd.append('driver_name', $('[name="driver_name"]').val() || '');
    fd.append('shipping_method', $('[name="shipping_method"]').val() || '');
    fd.append('items', JSON.stringify(items));
    fd.append('status', status);
    <?php if ($is_edit): ?>fd.append('delivery_id', '<?= $edit_id ?>');<?php endif; ?>

    // Named attachment rows — append file + name in parallel
    let fileCount = 0;
    $('#attachmentRows .attachment-row').each(function () {
        const fileInput = $(this).find('.att-file')[0];
        const nameVal = $(this).find('.att-name').val().trim();
        if (fileInput && fileInput.files.length > 0) {
            fd.append('attachment_file[]', fileInput.files[0]);
            fd.append('attachment_name[]', nameVal);
            fileCount++;
        }
    });
    if (!IS_EDIT && fileCount === 0) {
        Swal.fire({ icon: 'warning', title: 'Attachment Required', text: "Attach at least one scan of the supplier's delivery note." });
        return;
    }

    Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.ajax({
        url: '<?= $is_edit ? buildUrl("api/update_dn.php") : buildUrl("api/create_dn.php") ?>',
        type: 'POST', data: fd, processData: false, contentType: false,
        success: function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, confirmButtonColor: '#0d6efd' })
                    .then(() => { window.location.href = '<?= $return_url ?>'; });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' }); }
    });
}
</script>

<style>
@media (max-width: 767px) {
    .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
    .dn-sticky-nav { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 6px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.06); }
    #dnItemsTable { min-width: 560px; }
}
</style>

<?php includeFooter(); ?>
