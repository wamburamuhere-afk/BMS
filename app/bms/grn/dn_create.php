<?php
// File: app/bms/grn/dn_create.php
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('dn');
includeHeader();

global $pdo;

$project_id  = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$edit_id     = isset($_GET['edit'])       ? intval($_GET['edit'])       : 0;
$po_id       = isset($_GET['po_id'])      ? intval($_GET['po_id'])      : 0;
$is_edit     = $edit_id > 0;
$is_from_po  = $po_id > 0;

// ── 1. LOAD PRIMARY DATA (IF EDIT) ───────────────────────────
$dn = null;
$dn_items = [];
$dn_attachments = [];
if ($is_edit) {
    // Load DN first to get its project context
    $stmt = $pdo->prepare("SELECT d.*, s.supplier_name, w.warehouse_name FROM deliveries d LEFT JOIN suppliers s ON d.supplier_id = s.supplier_id LEFT JOIN warehouses w ON d.warehouse_id = w.warehouse_id WHERE d.delivery_id = ?");
    $stmt->execute([$edit_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dn) {
        // OVERRIDE project_id from the record
        $project_id = intval($dn['project_id'] ?? 0);
        
        // Load existing items
        $stmt2 = $pdo->prepare("SELECT di.*, p.product_name, p.sku, p.unit FROM delivery_items di LEFT JOIN products p ON di.product_id = p.product_id WHERE di.delivery_id = ? ORDER BY di.delivery_item_id");
        $stmt2->execute([$edit_id]);
        $dn_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Load existing attachments
        $stmt3 = $pdo->prepare("SELECT * FROM delivery_attachments WHERE delivery_id = ? ORDER BY attachment_id");
        $stmt3->execute([$edit_id]);
        $dn_attachments = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── 2. LOAD PO DATA (IF FROM PO) ─────────────────────────────
$po_data = null;
$po_items = [];
if ($is_from_po) {
    $stmt = $pdo->prepare("SELECT po.*, s.supplier_name, w.warehouse_name 
                           FROM purchase_orders po 
                           LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id 
                           LEFT JOIN warehouses w ON po.warehouse_id = w.warehouse_id 
                           WHERE po.purchase_order_id = ?");
    $stmt->execute([$po_id]);
    $po_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($po_data) {
        if ($po_data['project_id'] > 0) $project_id = $po_data['project_id'];
        
        // Load PO items with remaining quantity calculation
        $stmt2 = $pdo->prepare("
            SELECT 
                poi.*, 
                p.product_name, 
                p.sku, 
                p.unit,
                (poi.quantity - COALESCE((
                    SELECT SUM(di.quantity_delivered) 
                    FROM delivery_items di 
                    JOIN deliveries d ON di.delivery_id = d.delivery_id 
                    WHERE d.purchase_order_id = poi.purchase_order_id 
                    AND di.product_id = poi.product_id
                    AND d.status != 'cancelled'
                ), 0)) as quantity_remaining
            FROM purchase_order_items poi 
            LEFT JOIN products p ON poi.product_id = p.product_id 
            WHERE poi.purchase_order_id = ?
        ");
        $stmt2->execute([$po_id]);
        $po_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}

$has_project = $project_id > 0;

// ── 3. LOAD SYSTEM LISTS ─────────────────────────────────────
// Get project info
$project = null;
if ($has_project) {
    $stmt = $pdo->prepare("SELECT project_id, project_name, contract_number as contract_no FROM projects WHERE project_id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) {
        echo '<div class="alert alert-danger m-4">Project not found.</div>';
        includeFooter(); exit;
    }
}

// Get all projects
$all_projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);

// Get ALL active warehouses
$all_warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, location, IFNULL(project_id, 0) as project_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

// Filter warehouses for the initial dropdown view
$warehouses = [];
foreach ($all_warehouses as $wh) {
    if ($has_project) {
        if ($wh['project_id'] == $project_id) $warehouses[] = $wh;
    } else {
        if ($wh['project_id'] == 0) $warehouses[] = $wh;
    }
}

// Get eligible POs & Suppliers
$po_list = $pdo->query("
    SELECT po.purchase_order_id, po.order_number, po.supplier_id, IFNULL(po.warehouse_id, 0) as warehouse_id, IFNULL(po.project_id, 0) as project_id, s.supplier_name 
    FROM purchase_orders po 
    JOIN suppliers s ON po.supplier_id = s.supplier_id
    WHERE po.status IN ('approved', 'ordered', 'partially_received', 'received', 'completed')
    ORDER BY po.order_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$po_suppliers = $pdo->query("
    SELECT DISTINCT s.supplier_id, s.supplier_name, s.company_name 
    FROM suppliers s
    JOIN purchase_orders po ON s.supplier_id = po.supplier_id
    WHERE po.status IN ('approved', 'ordered', 'partially_received', 'received', 'completed')
    AND s.status = 'active'
    ORDER BY s.supplier_name
")->fetchAll(PDO::FETCH_ASSOC);

$project_suppliers = $po_suppliers;

// Get Delivery Orders for project (DO must exist before DN)
$project_dos = [];
if ($has_project) {
    $dos_stmt = $pdo->prepare("SELECT do_id, do_number, do_date FROM delivery_orders WHERE project_id = ? ORDER BY do_id DESC");
    $dos_stmt->execute([$project_id]);
    $project_dos = $dos_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Company info
$company_name = getSetting('company_name', 'BMS');
$company_logo = getSetting('company_logo', '');
$print_user   = ucwords(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
$print_role   = ucwords($_SESSION['user_role'] ?? 'Staff');
$print_date   = date('d M, Y \a\t h:i A');

$return_url = $has_project
    ? getUrl('project_view') . '?id=' . $project_id . '&tab=procurement'
    : getUrl('delivery_notes');
?>

<div class="container-fluid mt-3">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <?php if ($has_project && $project): ?>
            <li class="breadcrumb-item"><a href="<?= getUrl('project_view') ?>?id=<?= $project_id ?>">Projects</a></li>
            <li class="breadcrumb-item"><a href="<?= $return_url ?>">Procurement</a></li>
            <?php else: ?>
            <li class="breadcrumb-item"><a href="<?= $return_url ?>">Delivery Notes</a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active"><?= $is_edit ? 'Edit DN' : 'New Delivery Note' ?></li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4 d-print-none">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-truck-flatbed text-primary me-2"></i><?= $is_edit ? 'Edit Delivery Note' : 'New Delivery Note' ?></h4>
            <?php if ($has_project && $project): ?>
            <p class="text-muted small mb-0">Project: <strong><?= safe_output($project['project_name']) ?></strong>
                <?php if (!empty($project['contract_no'])): ?>
                — Contract: <strong><?= safe_output($project['contract_no']) ?></strong>
                <?php endif; ?>
            </p>
            <?php else: ?>
            <p class="text-muted small mb-0">General Delivery Note — <span class="text-info">No project selected</span></p>
            <?php endif; ?>
        </div>
        <a href="<?= $return_url ?>" class="btn btn-outline-secondary btn-sm flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> <?= $has_project ? 'Back to Project' : 'Back to Delivery Notes' ?>
        </a>
    </div>

    <?php if (empty($warehouses)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?php if ($has_project): ?>
            No warehouses linked to this project. Please add a warehouse to the project first.
        <?php else: ?>
            No general warehouses found. All warehouses are currently assigned to specific projects.
            <?php if ($has_project === false): ?>
            <a href="<?= getUrl('project_view') ?>" class="alert-link ms-1">Select a project</a> to see its warehouses.
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php elseif (empty($project_suppliers)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= $has_project ? 'No suppliers linked to this project. Please add suppliers to the project first.' : 'No active suppliers found. Please add suppliers first.' ?>
    </div>
    <?php else: ?>

    <form id="dnForm">
        <?php if ($is_edit): ?>
        <input type="hidden" name="delivery_id" value="<?= $edit_id ?>">
        <?php endif; ?>

        <div class="row g-4">
            <!-- LEFT: Main Info -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i>Delivery Note Details</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <!-- Project Selection -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Select Project <span class="text-muted small">(Optional)</span></label>
                                <select class="form-select border-primary border-opacity-25" name="project_id" id="dn_project_id" onchange="filterWarehouses(this.value)">
                                    <option value="0">-- No Project (General) --</option>
                                    <?php foreach ($all_projects as $p): ?>
                                    <option value="<?= $p['project_id'] ?>" <?= ($project_id == $p['project_id']) ? 'selected' : '' ?>>
                                        <?= safe_output($p['project_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Warehouse Selection -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Warehouse <span class="text-danger">*</span></label>
                                <select class="form-select shadow-sm border-primary border-opacity-25 fw-bold" name="warehouse_id" id="dn_warehouse_id" required onchange="filterSuppliers(this.value)">
                                    <option value="">-- Select Warehouse --</option>
                                    <?php foreach ($all_warehouses as $wh): ?>
                                    <option value="<?= $wh['warehouse_id'] ?>" 
                                            data-project="<?= $wh['project_id'] ?>"
                                            <?= (($dn && $dn['warehouse_id'] == $wh['warehouse_id']) || ($po_data && $po_data['warehouse_id'] == $wh['warehouse_id'])) ? 'selected' : '' ?>>
                                        <?= safe_output($wh['warehouse_name']) ?>
                                        <?php if (!empty($wh['location'])): ?> — <?= safe_output($wh['location']) ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Supplier Selection -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
                                <select class="form-select shadow-sm border-secondary border-opacity-25" name="supplier_id" id="dn_supplier_id" required onchange="filterPOs(this.value)">
                                    <option value="">-- Select Supplier --</option>
                                    <?php foreach ($po_suppliers as $s): ?>
                                    <option value="<?= $s['supplier_id'] ?>" 
                                            <?= (($dn && $dn['supplier_id'] == $s['supplier_id']) || ($po_data && $po_data['supplier_id'] == $s['supplier_id'])) ? 'selected' : '' ?>>
                                        <?= safe_output($s['supplier_name']) ?>
                                        <?php if (!empty($s['company_name'])): ?> (<?= safe_output($s['company_name']) ?>)<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Purchase Order Selection -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Purchase Order Reference <span class="text-muted small">(Approved/Partial)</span></label>
                                <select class="form-select border-primary border-opacity-25" name="purchase_order_id" id="dn_purchase_order_id" onchange="handlePOSelection(this)">
                                    <option value="">-- Select PO (Optional) --</option>
                                    <?php foreach ($po_list as $po): ?>
                                    <option value="<?= $po['purchase_order_id'] ?>" 
                                            data-supplier="<?= $po['supplier_id'] ?>" 
                                            data-warehouse="<?= $po['warehouse_id'] ?>"
                                            data-project="<?= $po['project_id'] ?>"
                                            <?= (($po_id == $po['purchase_order_id']) || ($dn && $dn['purchase_order_id'] == $po['purchase_order_id'])) ? 'selected' : '' ?>>
                                        <?= safe_output($po['order_number']) ?> (<?= safe_output($po['supplier_name']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">DN Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="delivery_date"
                                    value="<?= $dn ? $dn['delivery_date'] : date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contact Person</label>
                                <input type="text" class="form-control" name="contact_person"
                                    value="<?= $dn ? safe_output($dn['contact_person']) : '' ?>"
                                    placeholder="Person to receive delivery">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contact Phone</label>
                                <input type="text" class="form-control" name="contact_phone"
                                    value="<?= $dn ? safe_output($dn['contact_phone']) : '' ?>"
                                    placeholder="+255...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Delivery Address</label>
                                <input type="text" class="form-control" name="delivery_address"
                                    value="<?= $dn ? safe_output($dn['delivery_address']) : '' ?>"
                                    placeholder="Delivery site address">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Notes</label>
                                <textarea class="form-control" name="notes" rows="2"
                                    placeholder="Additional notes..."><?= $dn ? safe_output($dn['notes']) : '' ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Section -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i>Materials to Deliver</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="dnItemsTable">
                                <thead class="bg-light text-uppercase small fw-bold">
                                    <tr>
                                        <th class="ps-3" style="width:50px;">S/NO</th>
                                        <th>Product</th>
                                        <th style="width:120px;">Available</th>
                                        <th style="width:130px;">Qty to Issue</th>
                                        <th style="width:80px;">Unit</th>
                                        <th style="width:55px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="dnItemsBody">
                                    <!-- rows added by JS -->
                                </tbody>
                            </table>
                        </div>
                        <div id="dnItemsEmpty" class="text-center py-4 text-muted">
                            <i class="bi bi-box-seam fs-3 d-block mb-2 opacity-25"></i>
                            <p class="small">Select a warehouse first, then click "Add Item"</p>
                        </div>
                        <!-- Add Item button — bottom left -->
                        <div class="p-3 border-top">
                            <button type="button" class="btn btn-primary btn-sm" onclick="addDNItem()">
                                <i class="bi bi-plus-circle me-1"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Summary -->
            <div class="col-lg-4">
                <!-- Attachments Card -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold small"><i class="bi bi-paperclip me-2"></i>Attachments & Documents</h6>
                        <?php if ($is_edit): ?><span class="badge bg-secondary smallest"><?= count($dn_attachments) ?> Saved</span><?php endif; ?>
                    </div>
                    <div class="card-body p-3">
                        <div id="attachmentList">
                            <!-- Existing Attachments (Edit Mode) -->
                            <?php if ($is_edit && !empty($dn_attachments)): ?>
                                <?php foreach ($dn_attachments as $att): ?>
                                <div class="attachment-row mb-3 pb-3 border-bottom border-light existing-attachment" data-id="<?= $att['attachment_id'] ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="small fw-bold text-primary">
                                            <i class="bi bi-file-earmark-check me-1"></i> Existing Document
                                        </div>
                                        <button type="button" class="btn-close smallest" onclick="removeAttachmentRow(this, <?= $att['attachment_id'] ?>)"></button>
                                    </div>
                                    <input type="hidden" name="existing_attachment_ids[]" value="<?= $att['attachment_id'] ?>">
                                    <input type="text" name="existing_attachment_names[]" class="form-control form-control-sm mb-2" 
                                           value="<?= safe_output($att['file_name']) ?>" placeholder="Document Name">
                                    
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <a href="<?= getUrl($att['file_path']) ?>" target="_blank" class="btn btn-light btn-sm py-0 smallest border">
                                            <i class="bi bi-eye me-1"></i> View Current
                                        </a>
                                        <span class="text-muted smallest truncate" style="max-width:150px;"><?= basename($att['file_path']) ?></span>
                                    </div>

                                    <div class="bg-light p-2 rounded border border-dashed">
                                        <label class="smallest text-muted d-block mb-1 fw-bold text-uppercase">Replace File (Optional)</label>
                                        <input type="file" name="replace_attachments[<?= $att['attachment_id'] ?>]" class="form-control form-control-sm">
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Initial blank row for new DN -->
                            <?php if (!$is_edit): ?>
                            <div class="attachment-row mb-3 pb-3 border-bottom border-light">
                                <input type="text" name="attachment_names[]" class="form-control form-control-sm mb-2" placeholder="Document Name (e.g. Invoice)">
                                <input type="file" name="attachments[]" class="form-control form-control-sm">
                            </div>
                            <?php endif; ?>
                        </div>

                        <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-2" onclick="addAttachmentRow()">
                            <i class="bi bi-plus-circle me-1"></i> Add More Files
                        </button>
                        <p class="text-muted smallest mt-2 mb-0"><i class="bi bi-info-circle me-1"></i> Max 10MB per file. PDF, Image, Doc.</p>
                    </div>
                </div>

                <div class="card shadow-sm border-0 border-primary mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-clipboard-check me-2"></i>DN Summary</h6>
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
                        <div class="alert alert-info small py-2 mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            No prices are shown — this is a materials issue document only.
                        </div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary shadow-sm px-4" onclick="submitDN('draft')">
                                <i class="bi bi-save me-2"></i> Save as Draft
                            </button>
                            <a href="<?= $return_url ?>" class="btn btn-link text-muted text-decoration-none small">Cancel</a>
                        </div>
                    </div>
                </div>

                <!-- Warehouse Stock Info -->
                <div class="card shadow-sm border-0" id="warehouseStockCard" style="display:none;">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0 fw-bold small"><i class="bi bi-building me-2"></i>Available Stock</h6>
                    </div>
                    <div class="card-body p-2" id="warehouseStockList">
                        <div class="text-center text-muted py-3 small">Loading...</div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?php endif; ?>
</div>

<!-- Product data from warehouse (loaded via JS) -->
<script>
let PROJECT_ID = <?= $project_id ?>;
// APP_URL already declared in header.php — do not redeclare
let warehouseStock = []; // products available in selected warehouse

$(document).ready(function() {
    // If we have initial values (e.g. from URL or edit mode), trigger filters
    const initialProj = $('#dn_project_id').val();
    const initialWh   = $('#dn_warehouse_id').val();
    const initialSupp = $('#dn_supplier_id').val();
    const initialPoId = '<?= $po_id ?: ($dn['purchase_order_id'] ?? 0) ?>';
    
    // Always trigger warehouse filtering on load to show correct initial list
    filterWarehousesManual(initialProj || 0, false); 

    if (initialWh) {
        filterSuppliersManual(initialWh, false);
    }
    
    // Always ensure the initial supplier is visible if it was pre-selected
    if (initialSupp) {
        $(`#dn_supplier_id option[value="${initialSupp}"]`).show();
        $('#dn_supplier_id').val(initialSupp);
    }

    if (initialSupp) {
        filterPOsManual(initialSupp, false);
    }

    // Always ensure the initial PO is visible if it was pre-selected
    if (initialPoId && initialPoId != '0') {
        $(`#dn_purchase_order_id option[value="${initialPoId}"]`).show();
        $('#dn_purchase_order_id').val(initialPoId);
    }

    // Auto-load items if coming from a specific PO (only for NEW records)
    if (!<?= $is_edit ? 'true' : 'false' ?> && initialPoId && initialPoId != '0') {
        loadPOItemsForDN(initialPoId);
    }

    // If edit mode and has items, load saved items
    if (<?= $is_edit ? 'true' : 'false' ?>) {
        <?php foreach ($dn_items as $item): ?>
        addDNItem('<?= $item['product_id'] ?>', '<?= addslashes($item['product_name']) ?>', '<?= $item['quantity_delivered'] ?>', '<?= $item['unit'] ?>', 0);
        <?php endforeach; ?>
    }
});

// Manual filtering functions that don't reset children (for initial load)
function filterWarehousesManual(projectId, reset = true) {
    PROJECT_ID = projectId;
    const currentVal = $('#dn_warehouse_id').val();
    $('#dn_warehouse_id option').each(function() {
        const whProjId = $(this).data('project');
        if (!$(this).val()) return; // Skip placeholder
        
        // Strictly show only warehouses belonging to the selected project
        // (If projectId is 0, show only General warehouses where whProjId is also 0)
        if (whProjId == projectId || $(this).val() == currentVal) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
    if (reset) {
        $('#dn_warehouse_id').val('');
        $('#dn_supplier_id').val('').trigger('change');
    }
}

function filterSuppliersManual(warehouseId, reset = true) {
    if (!warehouseId) {
        $('#dn_supplier_id option').hide();
        $(`#dn_supplier_id option[value=""]`).show();
        if (reset) $('#dn_supplier_id').val('').trigger('change');
        return;
    }
    
    loadWarehouseStock();
    const projectId = $('#dn_project_id').val();
    const availableSuppliers = new Set();
    const currentVal = $('#dn_supplier_id').val();
    
    // Identify suppliers who have POs matching BOTH Warehouse and Project
    $('#dn_purchase_order_id option').each(function() {
        const poProj = $(this).data('project');
        const poWh   = $(this).data('warehouse');
        const poSupp = $(this).data('supplier');
        
        if (poSupp && poWh == warehouseId && poProj == projectId) {
            availableSuppliers.add(poSupp.toString());
        }
    });

    // Strictly filter the supplier list based on available POs
    $('#dn_supplier_id option').each(function() {
        const suppId = $(this).val();
        if (!suppId) return; // Skip placeholder
        
        if (availableSuppliers.has(suppId.toString()) || suppId == currentVal) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });

    if (reset) $('#dn_supplier_id').val('').trigger('change');
}

function filterPOsManual(supplierId, reset = true) {
    if (!supplierId) {
        $('#dn_purchase_order_id option').hide();
        $(`#dn_purchase_order_id option[value=""]`).show();
        if (reset) $('#dn_purchase_order_id').val('').trigger('change');
        return;
    }
    
    const projectId = $('#dn_project_id').val();
    const warehouseId = $('#dn_warehouse_id').val();
    const currentVal = $('#dn_purchase_order_id').val();

    // Strictly show POs matching Project + Warehouse + Supplier
    $('#dn_purchase_order_id option').each(function() {
        const poProj = $(this).data('project');
        const poWh   = $(this).data('warehouse');
        const poSupp = $(this).data('supplier');
        const poId   = $(this).val();

        if (!poId) return;

        if (poProj == projectId && poWh == warehouseId && poSupp == supplierId || poId == currentVal) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
    if (reset) $('#dn_purchase_order_id').val('').trigger('change');
}

// Map the original functions to the manual ones with reset=true
function filterWarehouses(val) { filterWarehousesManual(val, true); }
function filterSuppliers(val)  { filterSuppliersManual(val, true); }
function filterPOs(val)        { filterPOsManual(val, true); }

// ── Load stock when warehouse selected ──────────────────────
function loadWarehouseStock(callback) {
    const warehouseId = document.getElementById('dn_warehouse_id').value;
    if (!warehouseId) {
        document.getElementById('warehouseStockCard').style.display = 'none';
        warehouseStock = [];
        return;
    }

    document.getElementById('warehouseStockCard').style.display = '';
    document.getElementById('warehouseStockList').innerHTML = '<div class="text-center text-muted py-2 small"><i class="bi bi-hourglass-split me-1"></i>Loading stock...</div>';

    $.getJSON(APP_URL + '/api/get_project_warehouse_stock', {
        warehouse_id: warehouseId,
        project_id: PROJECT_ID
    }, function(res) {
        if (res.success && res.data && res.data.length > 0) {
            warehouseStock = res.data;
            let html = '';
            res.data.forEach(p => {
                // Filter out service products (should not appear in DN)
                if (p.is_service) return;
                html += `<div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                    <div>
                        <div class="fw-bold">${p.product_name}</div>
                        <small class="text-muted">${p.sku || ''}</small>
                    </div>
                    <span class="badge bg-${p.available_quantity > 0 ? 'success' : 'danger'} bg-opacity-10 text-${p.available_quantity > 0 ? 'success' : 'danger'} border">
                        ${p.available_quantity} ${p.unit}
                    </span>
                </div>`;
            });
            document.getElementById('warehouseStockList').innerHTML = html;
        } else {
            warehouseStock = [];
            document.getElementById('warehouseStockList').innerHTML = '<div class="text-center text-muted py-2 small">No stock in this warehouse.</div>';
        }
        if (typeof callback === 'function') callback();
    }).fail(function(xhr) {
        document.getElementById('warehouseStockList').innerHTML = '<div class="text-center text-danger py-2 small">Failed to load: ' + (xhr.responseText || 'Server error') + '</div>';
        if (typeof callback === 'function') callback();
    });
}

// ── Close all open dropdowns ──────────────────────────────────
function closeAllDropdowns() {
    document.querySelectorAll('.dn-product-dropdown').forEach(d => d.remove());
}

// ── Show autocomplete dropdown for product input ───────────────
function showProductDropdown(rowId, inputEl) {
    closeAllDropdowns();

    // If no warehouse selected — show warning inside dropdown
    if (!document.getElementById('dn_warehouse_id').value) {
        const dropdown = document.createElement('div');
        dropdown.className = 'dn-product-dropdown';
        const rect = inputEl.getBoundingClientRect();
        const scrollY = window.pageYOffset || document.documentElement.scrollTop;
        dropdown.style.cssText = `position:absolute;top:${rect.bottom+scrollY+2}px;left:${rect.left}px;width:${Math.max(rect.width,260)}px;background:#fff8f0;border:1px solid #f0ad4e;border-radius:6px;padding:10px 14px;font-size:.85rem;color:#664d03;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.1);`;
        dropdown.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Please select a warehouse first';
        document.body.appendChild(dropdown);
        setTimeout(() => closeAllDropdowns(), 2500);
        return;
    }

    // If warehouse selected but stock not loaded yet — reload
    if (warehouseStock.length === 0) {
        loadWarehouseStock(function() {
            showProductDropdown(rowId, inputEl);
        });
        return;
    }

    const q = inputEl.value.trim().toLowerCase();

    // Filter warehouseStock
    // Filter: exclude services, apply search query
    const filtered = warehouseStock.filter(p =>
        !p.is_service && (
            !q ||
            p.product_name.toLowerCase().includes(q) ||
            (p.sku || '').toLowerCase().includes(q)
        )
    );

    if (filtered.length === 0) {
        const dropdown = document.createElement('div');
        dropdown.className = 'dn-product-dropdown';
        const rect = inputEl.getBoundingClientRect();
        const scrollY = window.pageYOffset || document.documentElement.scrollTop;
        dropdown.style.cssText = `position:absolute;top:${rect.bottom+scrollY+2}px;left:${rect.left}px;width:${Math.max(rect.width,260)}px;background:#fff;border:1px solid #ced4da;border-radius:6px;padding:10px 14px;font-size:.85rem;color:#888;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.1);`;
        dropdown.innerHTML = '<i class="bi bi-search me-2"></i>No products found';
        document.body.appendChild(dropdown);
        setTimeout(() => closeAllDropdowns(), 2000);
        return;
    }

    // Build dropdown
    const rect   = inputEl.getBoundingClientRect();
    const scrollY = window.pageYOffset || document.documentElement.scrollTop;
    const scrollX = window.pageXOffset || document.documentElement.scrollLeft;

    const dropdown = document.createElement('div');
    dropdown.className = 'dn-product-dropdown';
    dropdown.style.cssText = `
        position:absolute;
        top:${rect.bottom + scrollY + 2}px;
        left:${rect.left + scrollX}px;
        width:${Math.max(rect.width, 280)}px;
        max-height:220px;
        overflow-y:auto;
        background:#fff;
        border:1px solid #ced4da;
        border-radius:6px;
        box-shadow:0 4px 16px rgba(0,0,0,.12);
        z-index:99999;
    `;

    filtered.forEach(p => {
        const item = document.createElement('div');
        item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;';
        const isTracked    = p.track_inventory == 1 || p.track_inventory === true;
        const availText    = isTracked ? `${p.available_quantity} ${p.unit}` : 'Non-tracked';
        const availBg      = isTracked ? (p.available_quantity > 0 ? '#d1e7dd' : '#f8d7da') : '#fff3cd';
        const availColor   = isTracked ? (p.available_quantity > 0 ? '#0f5132' : '#842029') : '#664d03';
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div style="font-weight:600;font-size:.85rem;">${p.product_name}</div>
                    <small style="color:#888;">${p.sku || ''}</small>
                </div>
                <span style="font-size:.75rem;padding:2px 8px;border-radius:20px;font-weight:600;
                    background:${availBg};color:${availColor};">
                    ${availText}
                </span>
            </div>`;
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            selectProduct(rowId, p);
            closeAllDropdowns();
        });
        item.addEventListener('mouseover', function() { this.style.background = '#f0f4ff'; });
        item.addEventListener('mouseout',  function() { this.style.background = '#fff'; });
        dropdown.appendChild(item);
    });

    document.body.appendChild(dropdown);
}

// ── Product selected from dropdown ────────────────────────────
function selectProduct(rowId, p) {
    // Set hidden product_id
    document.getElementById('pid_'     + rowId).value       = p.product_id;
    // Set visible input text
    document.getElementById('pname_'   + rowId).value       = p.product_name;
    // Update unit
    document.getElementById('unit_'    + rowId).textContent = p.unit;
    document.getElementById('unitval_' + rowId).value       = p.unit;
    // Update available badge
    const avail  = parseFloat(p.available_quantity) || 0;
    const cls    = avail > 0 ? 'success' : 'danger';
    const availEl = document.getElementById('avail_' + rowId);
    if (availEl) {
        availEl.textContent = avail > 0 ? avail + ' ' + p.unit : '—';
        availEl.className   = `badge bg-${cls} bg-opacity-10 text-${cls} border small`;
    }
    // Focus qty
    const qtyEl = document.getElementById('qty_' + rowId);
    if (qtyEl) qtyEl.focus();
    updateDNSummary();
}

// ── Add item row ───────────────────────────────────────────────
function addDNItem(productId, productName, qty, unit, available) {
    productId   = productId   || '';
    productName = productName || '';
    qty         = qty         || '';
    unit        = unit        || 'pcs';
    available   = available   || 0;

    const rowId = 'dnrow_' + Date.now();

    const avail    = parseFloat(available) || 0;
    const availCls = avail > 0 ? 'success' : 'danger';
    const availTxt = (productId && avail > 0) ? avail + ' ' + unit : '—';

    const html = `
    <tr id="${rowId}">
        <td class="text-center text-muted fw-bold serial-num ps-2" style="width:50px;"></td>
        <td style="min-width:180px;">
            <div style="position:relative;">
                <input type="text"
                    id="pname_${rowId}"
                    class="form-control form-control-sm"
                    placeholder="Type or click to search..."
                    value="${productName}"
                    autocomplete="off"
                    oninput="showProductDropdown('${rowId}', this)"
                    onfocus="showProductDropdown('${rowId}', this)"
                    onblur="setTimeout(() => closeAllDropdowns(), 200)">
                <input type="hidden" name="product_id[]" id="pid_${rowId}" value="${productId}">
            </div>
        </td>
        <td class="text-center" style="width:110px;">
            <span class="badge bg-${availCls} bg-opacity-10 text-${availCls} border small"
                id="avail_${rowId}">${availTxt}</span>
        </td>
        <td style="width:130px;">
            <input type="number"
                id="qty_${rowId}"
                class="form-control form-control-sm qty-input"
                name="quantity[]"
                value="${qty}"
                min="0.001" step="0.001"
                placeholder="Qty"
                oninput="updateDNSummary()">
        </td>
        <td style="width:75px;">
            <span class="text-muted small fw-semibold" id="unit_${rowId}">${unit}</span>
            <input type="hidden" name="unit[]" value="${unit}" id="unitval_${rowId}">
        </td>
        <td class="text-center pe-2" style="width:48px;">
            <button type="button" class="btn btn-danger btn-sm"
                style="width:30px;height:30px;padding:0;"
                onclick="removeRow('${rowId}')">
                <i class="bi bi-trash" style="font-size:.75rem;"></i>
            </button>
        </td>
    </tr>`;

    $('#dnItemsBody').append(html);
    updateSerials();
    updateDNSummary();

    // Auto-select if single product in warehouse
    if (warehouseStock.length === 1 && !productId) {
        selectProduct(rowId, warehouseStock[0]);
    }
}

function removeRow(rowId) {
    $('#' + rowId).remove();
    updateSerials();
    updateDNSummary();
}

function updateSerials() {
    const rows = $('#dnItemsBody tr');
    rows.each(function(i) {
        $(this).find('.serial-num').text(i + 1);
    });
    if (rows.length === 0) {
        $('#dnItemsEmpty').show();
    } else {
        $('#dnItemsEmpty').hide();
    }
}

function updateDNSummary() {
    let totalItems = 0, totalQty = 0;
    $('#dnItemsBody tr').each(function() {
        const qty = parseFloat($(this).find('.qty-input').val()) || 0;
        if ($(this).find('input[name="product_id[]"]').val()) {
            totalItems++;
            totalQty += qty;
        }
    });
    $('#dnTotalItems').text(totalItems);
    $('#dnTotalQty').text(totalQty.toFixed(3));
}

function handlePOSelection(select) {
    const poId = $(select).val();
    if (poId) {
        loadPOItemsForDN(poId);
    }
}

function addAttachmentRow() {
    const html = `
    <div class="attachment-row mb-3 pb-3 border-bottom border-light position-relative">
        <button type="button" class="btn-close position-absolute top-0 end-0 smallest" onclick="removeAttachmentRow(this)"></button>
        <input type="text" name="attachment_names[]" class="form-control form-control-sm mb-2" placeholder="Document Name (e.g. Invoice)">
        <input type="file" name="attachments[]" class="form-control form-control-sm">
    </div>`;
    $('#attachmentList').append(html);
}

function removeAttachmentRow(btn, existingId = null) {
    if (existingId) {
        // Track deleted attachments if needed
        $('<input>').attr({type: 'hidden', name: 'delete_attachment_ids[]', value: existingId}).appendTo('#dnForm');
    }
    $(btn).closest('.attachment-row').remove();
}

function loadPOItemsForDN(poId) {
    const warehouseId = $('#dn_warehouse_id').val();
    Swal.fire({ title: 'Loading PO Items...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.get('<?= getUrl("api/get_po_items") ?>', { id: poId }, function(res) {
        Swal.close();
        if (res.success && res.data && res.data.items) {
            $('#dnItemsBody').empty();
            res.data.items.forEach(item => {
                // Only add items that have a remaining quantity
                if (parseFloat(item.quantity_remaining) <= 0) return;

                // Find available qty in warehouseStock if we have it
                let available = 0;
                if (warehouseStock.length > 0) {
                    const stock = warehouseStock.find(s => s.product_id == item.product_id);
                    if (stock) available = stock.available_quantity;
                }
                addDNItem(item.product_id, item.product_name, item.quantity_remaining, item.unit, available);
            });
            updateDNSummary();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load PO items' });
        }
    }, 'json').fail(function(xhr) {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Server Error', text: 'Status: ' + xhr.status + ' - ' + (xhr.responseText || 'Could not reach server') });
    });
}

// ── Submit DN ─────────────────────────────────────────────────
function submitDN(status = 'draft') {
    const warehouseId = $('#dn_warehouse_id').val();
    const supplierId  = $('#dn_supplier_id').val();
    const date        = $('[name="delivery_date"]').val();
    const doId        = $('#dn_do_id').length ? $('#dn_do_id').val() : null;

    if (!warehouseId || !supplierId || !date) {
        Swal.fire({icon:'warning', title:'Required Fields', text:'Please select warehouse, supplier and date.', confirmButtonColor:'#0d6efd'});
        return;
    }

    const items = [];
    $('#dnItemsBody tr').each(function() {
        const productId = $(this).find('input[name="product_id[]"]').val();
        const qty       = parseFloat($(this).find('input[name="quantity[]"]').val()) || 0;
        const unit      = $(this).find('input[name="unit[]"]').val() || 'pcs';
        if (productId && qty > 0) {
            items.push({ product_id: productId, quantity: qty, unit: unit });
        }
    });

    if (items.length === 0) {
        Swal.fire({icon:'warning', title:'No Valid Items', text:'Please add at least one item, select a product, and enter quantity greater than 0.', confirmButtonColor:'#0d6efd'});
        return;
    }

    // Build FormData for file support
    const formData = new FormData();
    formData.append('project_id',       PROJECT_ID);
    formData.append('warehouse_id',     warehouseId);
    formData.append('supplier_id',      supplierId);
    formData.append('delivery_date',    date);
    formData.append('contact_person',   $('[name="contact_person"]').val());
    formData.append('contact_phone',    $('[name="contact_phone"]').val());
    formData.append('delivery_address', $('[name="delivery_address"]').val());
    formData.append('notes',            $('[name="notes"]').val());
    formData.append('do_id',            doId || '');
    formData.append('items',            JSON.stringify(items));
    formData.append('status',           status);
    formData.append('purchase_order_id', $('#dn_purchase_order_id').val() || '');
    
    <?php if ($is_edit): ?>
    formData.append('delivery_id', '<?= $edit_id ?>');
    <?php endif; ?>

    // New Attachments
    $('.attachment-row:not(.existing-attachment)').each(function() {
        const nameInput = $(this).find('input[name="attachment_names[]"]');
        const fileInput = $(this).find('input[name="attachments[]"]');
        if (fileInput[0].files.length > 0) {
            formData.append('attachment_names[]', nameInput.val() || 'Unnamed Document');
            formData.append('attachments[]', fileInput[0].files[0]);
        }
    });

    // Existing Attachments (Metadata + Potential File Replacement)
    $('.existing-attachment').each(function() {
        const id = $(this).data('id');
        const name = $(this).find('input[name="existing_attachment_names[]"]').val();
        const fileInput = $(this).find(`input[name="replace_attachments[${id}]"]`);
        
        formData.append('existing_attachment_ids[]', id);
        formData.append('existing_attachment_names[]', name);
        if (fileInput[0] && fileInput[0].files.length > 0) {
            formData.append(`replace_attachments_${id}`, fileInput[0].files[0]);
        }
    });

    // Deleted Attachments
    $('input[name="delete_attachment_ids[]"]').each(function() {
        formData.append('delete_attachment_ids[]', $(this).val());
    });

    Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    const url = '<?= $is_edit ? getUrl("api/update_dn") : getUrl("api/create_dn") ?>';
    $.ajax({
        url: url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Saved!',
                    text: res.message,
                    confirmButtonColor: '#0d6efd'
                }).then(() => { window.location.href = '<?= $return_url ?>'; });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' });
        }
    });
}

// ── Init ─────────────────────────────────────────────────────
// Close dropdown on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dn-product-dropdown') && !e.target.closest('input[id^="pname_"]')) {
        closeAllDropdowns();
    }
});

$(document).ready(function() {
    <?php if ($is_edit && $dn): ?>
    // Edit mode — load stock then populate existing items
    loadWarehouseStock();
    setTimeout(function() {
        <?php foreach ($dn_items as $item): ?>
        addDNItem(
            <?= $item['product_id'] ?>,
            '<?= addslashes($item['product_name'] ?? '') ?>',
            <?= $item['quantity_delivered'] ?>,
            '<?= addslashes($item['unit'] ?? 'pcs') ?>',
            0
        );
        <?php endforeach; ?>
    }, 900);
    <?php elseif ($is_from_po && !empty($po_items)): ?>
    // PO mode — load stock then populate items from PO (Remaining Balance)
    loadWarehouseStock();
    setTimeout(function() {
        <?php foreach ($po_items as $item): ?>
        // Only add items that have a remaining balance
        if (<?= floatval($item['quantity_remaining']) ?> > 0) {
            addDNItem(
                <?= $item['product_id'] ?>,
                '<?= addslashes($item['product_name'] ?? '') ?>',
                <?= $item['quantity_remaining'] ?>,
                '<?= addslashes($item['unit'] ?? 'pcs') ?>',
                0
            );
        }
        <?php endforeach; ?>
    }, 900);
    <?php else: ?>
    // New DN — add one blank row immediately so user sees the table
    addDNItem();
    <?php endif; ?>
});
</script>

<?php includeFooter(); ?>
