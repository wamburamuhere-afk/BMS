<?php
// File: grn_create.php
// scope-audit: skip — GRN create/edit form; new GRN has no prior record; scope enforced at save level via api GRN save endpoint
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('grn');

// Include the header
includeHeader();

// Permission flags
$can_create_grn = isAdmin() || canCreate('grn');


// Get parameters
$supplier_id = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
$warehouse_id = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;
$po_id = isset($_GET['po']) ? intval($_GET['po']) : 0;
$project_id_param = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$return_tab = isset($_GET['tab']) ? htmlspecialchars($_GET['tab']) : 'proc-grn';
$type = isset($_GET['type']) ? htmlspecialchars($_GET['type']) : 'grn';
$is_dn = ($type === 'delivery_note');
$doc_label = $is_dn ? 'Received Note' : 'Goods Received Note';
$doc_short = $is_dn ? 'DN' : 'GRN';
$doc_icon = $is_dn ? 'bi-file-earmark-check' : 'bi-clipboard-plus';

// Build return URL for project context
$project_return_url = $project_id_param > 0 
    ? getUrl('project_view') . '?id=' . $project_id_param . '&tab=procurement'
    : null;

// Get current user info
$user_id = $_SESSION['user_id'];
global $username;
if (!isset($username) || empty($username)) {
    $username = $_SESSION['username'] ?? '';
    if (empty($username)) {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $username = $stmt->fetchColumn();
    }
}

// Get supplier details if provided
$supplier = null;
if ($supplier_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get warehouse details if provided
$warehouse = null;
if ($warehouse_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
    $stmt->execute([$warehouse_id]);
    $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get purchase order details if provided
$purchase_order = null;
$po_items = [];
if ($po_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE purchase_order_id = ?");
    $stmt->execute([$po_id]);
    $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($purchase_order) {
        // Get PO items
        $stmt = $pdo->prepare("
            SELECT poi.*, p.product_name, p.sku, p.unit, p.barcode
            FROM purchase_order_items poi
            LEFT JOIN products p ON poi.product_id = p.product_id
            WHERE poi.purchase_order_id = ?
        ");
        $stmt->execute([$po_id]);
        $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If supplier not provided, get from PO
        if (!$supplier && $purchase_order['supplier_id']) {
            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
            $stmt->execute([$purchase_order['supplier_id']]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            $supplier_id = $supplier['supplier_id'];
        }
        
        // If warehouse not provided, get from PO
        if (!$warehouse && $purchase_order['warehouse_id']) {
            $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE warehouse_id = ?");
            $stmt->execute([$purchase_order['warehouse_id']]);
            $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
            $warehouse_id = $warehouse['warehouse_id'];
        }
    }
}

// Get suppliers for dropdown - ONLY those with pending, ordered or partially delivered purchase orders
$suppliers_query = "
    SELECT DISTINCT s.supplier_id, s.supplier_name, s.company_name 
    FROM suppliers s
    JOIN purchase_orders po ON s.supplier_id = po.supplier_id
    LEFT JOIN purchase_order_items poi ON po.purchase_order_id = poi.purchase_order_id
    LEFT JOIN (
        SELECT purchase_order_item_id, SUM(quantity_received) as received_qty
        FROM receipt_items
        GROUP BY purchase_order_item_id
    ) pri ON poi.order_item_id = pri.purchase_order_item_id
    WHERE s.status = 'active' 
    AND po.status IN ('pending', 'ordered', 'partially_received')
";

$supp_params = [];
if ($project_id_param > 0) {
    $suppliers_query .= " AND po.project_id = ? ";
    $supp_params[] = $project_id_param;
}

$suppliers_query .= "
    GROUP BY po.purchase_order_id, s.supplier_id
    HAVING SUM(poi.quantity - IFNULL(pri.received_qty, 0)) > 0
    ORDER BY s.supplier_name
";

$stmt = $pdo->prepare($suppliers_query);
$stmt->execute($supp_params);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Scope: assigned project IDs for current user
$_grnc_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));

// Get warehouses for dropdown — scoped by project for non-admins
if (isAdmin()) {
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, location, IFNULL(project_id,0) as project_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_grnc_assigned)) {
    $_grnc_wph = implode(',', array_fill(0, count($_grnc_assigned), '?'));
    $_grnc_wstmt = $pdo->prepare("SELECT warehouse_id, warehouse_name, location, IFNULL(project_id,0) as project_id FROM warehouses WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_grnc_wph)) ORDER BY warehouse_name");
    $_grnc_wstmt->execute($_grnc_assigned);
    $warehouses = $_grnc_wstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, location, IFNULL(project_id,0) as project_id FROM warehouses WHERE status = 'active' AND project_id IS NULL ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get projects for dropdown — scoped to assigned projects for non-admins
if (isAdmin()) {
    $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_grnc_assigned)) {
    $_grnc_pph = implode(',', array_fill(0, count($_grnc_assigned), '?'));
    $_grnc_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($_grnc_pph) ORDER BY project_name");
    $_grnc_pstmt->execute($_grnc_assigned);
    $projects = $_grnc_pstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $projects = [];
}

// Get pending purchase orders
$po_query = "
    SELECT po.purchase_order_id, po.order_number, po.order_date, s.supplier_name, s.supplier_id,
           COUNT(poi.order_item_id) as total_items,
           SUM(poi.quantity - IFNULL(pri.received_qty, 0)) as pending_qty
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    LEFT JOIN purchase_order_items poi ON po.purchase_order_id = poi.purchase_order_id
    LEFT JOIN (
        SELECT purchase_order_item_id, SUM(quantity_received) as received_qty
        FROM receipt_items
        GROUP BY purchase_order_item_id
    ) pri ON poi.order_item_id = pri.purchase_order_item_id
    WHERE po.status IN ('pending', 'ordered', 'partially_received')
";

$po_params = [];
if ($project_id_param > 0) {
    $po_query .= " AND po.project_id = ? ";
    $po_params[] = $project_id_param;
}

$po_query .= "
    GROUP BY po.purchase_order_id
    HAVING pending_qty > 0
    ORDER BY po.order_date DESC
";

$stmt = $pdo->prepare($po_query);
$stmt->execute($po_params);
$pending_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions removed, now in helpers.php
function generate_grn_number() {
    $prefix = 'GRN';
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    $random = mt_rand(100, 999);
    return $prefix . '-' . $year . $month . $day . '-' . $random;
}
?>

<div class="container-fluid mt-4">

    <!-- PRINT FOOTER (fixed - matches tenders) -->
    <div class="print-footer d-none d-print-block">
        <p class="mb-1 text-muted" style="font-size:8pt;">
            This document was Printed by
            <span class="fw-bold text-dark"><?= ucwords(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))) ?> - <?= ucwords($_SESSION['user_role'] ?? 'Staff') ?></span>
            on <span class="fw-bold text-dark"><?= date('d M, Y \\a\\t h:i A') ?></span>
        </p>
        <p class="mb-0 fw-bold text-primary" style="font-size:10pt;letter-spacing:0.5px;">
            Powered By BJP Technologies &copy; 2026
        </p>
    </div>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 class="mb-1"><i class="bi <?= $doc_icon ?>"></i> Create <?= $doc_label ?> (<?= $doc_short ?>)</h2>
                    <p class="text-muted mb-0">Record receipt of goods from suppliers</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= getUrl($is_dn ? 'delivery_notes' : 'grn') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to <?= $doc_short ?>s
                    </a>
                    <?php if ($project_id_param > 0): ?>
                    <a href="<?= $project_return_url ?>" class="btn btn-outline-primary">
                        <i class="bi bi-kanban"></i> Back to Project
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> <?= $doc_short ?> Details</h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <form id="grnForm" enctype="multipart/form-data">
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label for="receipt_number" class="form-label"><?= $doc_short ?> Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="receipt_number" name="receipt_number" 
                               value="<?= generate_grn_number() ?>" required readonly>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="receipt_date" class="form-label">Receipt Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="receipt_date" name="receipt_date" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="received_by" class="form-label">Received By <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="received_by" name="received_by" 
                               value="<?= safe_output($username) ?>" required>
                    </div>
                </div>
                
                <!-- Supplier, Warehouse and Project -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label for="supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" id="supplier_id" name="supplier_id" required onchange="loadSupplierInfo()">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supp): ?>
                                <option value="<?= $supp['supplier_id'] ?>" 
                                    <?= ($supplier_id > 0 && $supp['supplier_id'] == $supplier_id) ? 'selected' : '' ?>>
                                    <?= safe_output($supp['supplier_name']) ?>
                                    <?php if (!empty($supp['company_name'])): ?>
                                        (<?= safe_output($supp['company_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="project_id" class="form-label">Project <span class="text-muted small">(Optional)</span></label>
                        <select class="form-select select2-static" id="project_id" name="project_id"
                            onchange="filterGrnWarehouses(this.value); $('#projectIdHidden').val(this.value)">
                            <option value="">No Project</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>"
                                    <?= ($project_id_param > 0 && $proj['project_id'] == $project_id_param) ? 'selected' : '' ?>>
                                    <?= safe_output($proj['project_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="grnWarehouseHint">Select project to filter warehouses.</small>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="warehouse_id" class="form-label">Warehouse <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" id="warehouse_id" name="warehouse_id" required>
                            <option value="">Select Warehouse</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['warehouse_id'] ?>"
                                    data-project="<?= $wh['project_id'] ?>"
                                    <?= ($warehouse_id > 0 && $wh['warehouse_id'] == $warehouse_id) ? 'selected' : '' ?>>
                                    <?= safe_output($wh['warehouse_name']) ?>
                                    <?php if (!empty($wh['location'])): ?>- <?= safe_output($wh['location']) ?><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Purchase Order Selection -->
                <div class="row mb-4" id="poSelectionDiv" style="display: none;">
                    <div class="col-md-6 mb-3">
                        <label for="purchase_order_id" class="form-label">Purchase Order (Optional)</label>
                        <div class="input-group">
                            <select class="form-select select2-static" id="purchase_order_id" name="purchase_order_id" onchange="loadPurchaseOrderItems()">
                                <option value="">Select Purchase Order</option>
                                <?php foreach ($pending_pos as $po): ?>
                                    <option value="<?= $po['purchase_order_id'] ?>" 
                                        <?= ($po_id > 0 && $po['purchase_order_id'] == $po_id) ? 'selected' : '' ?>
                                        data-supplier-id="<?= $po['supplier_id'] ?? 0 ?>">
                                        <?= safe_output($po['order_number']) ?> - 
                                        <?= safe_output($po['supplier_name']) ?> 
                                        (<?= $po['pending_qty'] ?> items pending)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearPOSelection()">
                                <i class="bi bi-x"></i> Clear
                            </button>
                        </div>
                        <small class="text-muted">Select a purchase order to auto-populate items</small>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="delivery_note_id" class="form-label">Delivery Note <span class="text-muted small">(Optional)</span></label>
                        <div class="input-group">
                            <select class="form-select" id="delivery_note_id" onchange="loadDNItems()">
                                <option value="">— Select recorded DN —</option>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearDNSelection()">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                        <input type="hidden" id="delivery_id" name="delivery_id" value="">
                        <input type="hidden" id="delivery_note" name="delivery_note" value="">
                        <small class="text-muted">Select supplier + warehouse first, then choose a DN</small>
                    </div>
                </div>
                
                <!-- Supplier Information Card -->
                <div class="card mb-4" id="supplierInfoCard" style="display: none;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-truck"></i> Supplier Information</h6>
                    </div>
                    <div class="card-body" id="supplierInfoBody">
                        <!-- Supplier info will be loaded here -->
                    </div>
                </div>
                
                <!-- Received Items -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-list-check"></i> Received Items</h6>
                        <div>
                            <button type="button" class="btn btn-sm btn-light" onclick="addItemRow()">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-light ms-2" onclick="clearAllItems()">
                                <i class="bi bi-trash"></i> Clear All
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">S/NO</th>
                                        <th width="30%">Product/Item <span class="text-danger">*</span></th>
                                        <th width="10%">SKU/Barcode</th>
                                        <th width="10%">Quantity <span class="text-danger">*</span></th>
                                        <th width="10%">Unit</th>
                                        <?php if (!$is_dn): ?>
                                        <th width="12%">Unit Price</th>
                                        <?php endif; ?>
                                        <th width="10%">Batch No.</th>
                                        <th width="10%">Expiry Date</th>
                                        <?php if (!$is_dn): ?>
                                        <th width="5%">Total</th>
                                        <?php endif; ?>
                                        <th width="3%"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <!-- Items will be added here -->
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="10">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <button type="button" class="btn btn-sm btn-primary" onclick="addItemRow()">
                                                        <i class="bi bi-plus-circle"></i> Add Item
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="scanBarcode()">
                                                        <i class="bi bi-upc-scan"></i> Scan Barcode
                                                    </button>
                                                </div>
                                                <div class="text-end">
                                                    <strong>Total Items: <span id="totalItems">0</span></strong><br>
                                                    <?php if (!$is_dn): ?>
                                                    <strong>Total Value: <span id="totalValue">0.00</span> TZS</strong>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Attachments Section -->
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-light border-bottom py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2 text-primary"></i> Documents & Attachments</h6>
                    </div>
                    <div class="card-body">
                        <div id="attachments-container" class="border rounded p-3 bg-light">
                            <div id="attachment-fields">
                                <div class="row g-2 attachment-row mb-2">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Document Name (e.g. Delivery Note Copy, Quality Cert)">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="file" class="form-control form-control-sm" name="attachments[]">
                                    </div>
                                    <div class="col-md-1 text-end">
                                        <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeAttachmentRow(this)" title="Remove">
                                            <i class="bi bi-trash fs-5"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="addAttachmentRow()">
                                <i class="bi bi-plus-circle me-1"></i> Add Attachment
                            </button>
                        </div>
                        <div class="form-text text-muted mt-2">Accepted: PDF, DOC, DOCX, JPG, PNG (max 10MB each).</div>
                    </div>
                </div>
                
                <!-- Quality Check & Notes -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-clipboard-check"></i> Quality Check</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Overall Condition</label>
                                    <select class="form-select" name="quality_condition">
                                        <option value="excellent">Excellent</option>
                                        <option value="good" selected>Good</option>
                                        <option value="fair">Fair</option>
                                        <option value="poor">Poor</option>
                                    </select>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="packaging_ok" id="packaging_ok" checked>
                                    <label class="form-check-label" for="packaging_ok">Packaging OK</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="quantity_ok" id="quantity_ok" checked>
                                    <label class="form-check-label" for="quantity_ok">Quantity OK</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="damage_check" id="damage_check">
                                    <label class="form-check-label" for="damage_check">Damage Checked</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="expiry_check" id="expiry_check">
                                    <label class="form-check-label" for="expiry_check">Expiry Dates Checked</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-chat-left-text"></i> Notes & Remarks</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4" 
                                              placeholder="Any special notes, remarks, or observations about the received goods"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="inspected_by" class="form-label">Inspected By</label>
                                    <input type="text" class="form-control" id="inspected_by" name="inspected_by" 
                                           value="<?= safe_output($username) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Hidden fields -->
                <input type="hidden" name="created_by" value="<?= $user_id ?>">
                <input type="hidden" name="total_received" id="totalReceivedHidden" value="0">
                <input type="hidden" name="status" value="draft">
                <input type="hidden" name="project_id" id="projectIdHidden" value="<?= $project_id_param ?>">
                <input type="hidden" name="return_url" id="returnUrlHidden" value="<?= htmlspecialchars($project_return_url ?? '') ?>">
                
                <!-- Form Actions -->
                <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3" style="min-width: 120px;" onclick="window.history.back()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary px-3" style="min-width: 120px;" onclick="saveAsDraft()">
                        <i class="bi bi-save"></i> Save as Draft
                    </button>
                    <button type="submit" class="btn btn-sm btn-success px-3" style="min-width: 120px;">
                        <i class="bi bi-check-circle"></i> Create <?= $doc_short ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Floating Product Search Results -->
<div id="productSearchResults" class="product-search-results shadow-lg border">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="bg-light sticky-top">
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Stock</th>
                    <th>Cost Price</th>
                </tr>
            </thead>
            <tbody id="productsSearchBody">
                <!-- Products will be loaded here -->
            </tbody>
        </table>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="barcodeScannerModalLabel">
                    <i class="bi bi-upc-scan"></i> Barcode Scanner
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="bi bi-upc" style="font-size: 3rem;"></i>
                    <p class="mt-2">Scan barcode or enter manually</p>
                </div>
                <div class="mb-3">
                    <label for="barcodeInput" class="form-label">Barcode</label>
                    <input type="text" class="form-control" id="barcodeInput" placeholder="Scan or enter barcode" autofocus>
                    <small class="text-muted">Press Enter after scanning or typing</small>
                </div>
                <div id="barcodeResult" class="d-none">
                    <!-- Barcode scan result will be shown here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" onclick="addScannedItem()">Add Item</button>
            </div>
        </div>
    </div>
</div>


<script>
let currentItemIndex = null;
let itemCount = 0;
let productsCache = [];

$(document).ready(function() {
    // Select2 on DB-backed selects
    $('#supplier_id').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'Select Supplier' });
    $('#project_id').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'No Project' });
    $('#purchase_order_id').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'Select Purchase Order' });

    // Add first item row
    addItemRow();
    
    // Load supplier info if supplier is already selected
    if ($('#supplier_id').val()) {
        loadSupplierInfo();
    }
    
    // Load PO items if PO is already selected
    if ($('#purchase_order_id').val()) {
        loadPurchaseOrderItems();
    }
    
    // Form submission
    $('#grnForm').on('submit', function(e) {
        e.preventDefault();
        createGRN('completed');
    });
    
    // Load products cache
    loadProductsCache();
    
    // Auto-focus barcode input when modal opens
    $('#barcodeScannerModal').on('shown.bs.modal', function() {
        $('#barcodeInput').focus();
    });
    
    // Handle barcode input
    $('#barcodeInput').on('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleBarcodeInput($(this).val());
        }
    });
    
    // Hide search results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.item-name, #productSearchResults').length) {
            $('#productSearchResults').hide();
        }
    });

    // Handle ESC key to hide search results
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#productSearchResults').hide();
        }
    });

    // Calculate totals when quantities or prices change
    $(document).on('input', '.item-quantity, .item-price', function() {
        const index = $(this).closest('tr').data('index');
        calculateItemTotal(index);
        calculateTotals();
    });

    // Smart filtering: When supplier changes, filter Purchase Orders
    $('#supplier_id').on('change', function() {
        const supplierId = $(this).val();
        
        // Clear PO selection first
        $('#purchase_order_id').val('');
        $('#itemsBody').empty();
        itemCount = 0;
        addItemRow();
        
        if (!supplierId) {
            $('#poSelectionDiv').hide();
            return;
        }
        
        let poCount = 0;
        $('#purchase_order_id option').each(function() {
            // Use jQuery's data() or get the attribute directly
            const poSupplierId = $(this).attr('data-supplier-id');
            // Hide POs that don't belong to this supplier
            if (poSupplierId && poSupplierId != supplierId) {
                $(this).hide();
            } else {
                $(this).show();
                if (poSupplierId) poCount++;
            }
        });
        
        // Show PO selection if there are POs for this supplier
        if (poCount > 0) {
            $('#poSelectionDiv').fadeIn();
        } else {
            $('#poSelectionDiv').hide();
        }
    });
});

function loadProductsCache() {
    $.ajax({
        url: '<?= getUrl('api/pos/get_products') ?>',
        type: 'GET',
        data: { type: 'inventory' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                productsCache = response.data;
            }
        },
        error: function(error) {
            console.error('Error loading products:', error);
        }
    });
}

function updateSerialNumbers() {
    $('#itemsBody tr').each(function(index) {
        $(this).find('.row-sn').text(index + 1);
    });
}

function addItemRow(product = null) {
    const index = itemCount++;
    const html = `
        <tr id="item-row-${index}" data-index="${index}">
            <td class="row-sn text-center fw-bold text-muted">${$('#itemsBody tr').length + 1}</td>
            <td>
                <div class="input-group">
                    <input type="text" class="form-control item-name" 
                           name="items[${index}][product_name]" 
                           placeholder="Type to search product..." required
                           oninput="openProductSearch(${index}, this.value)"
                           onclick="openProductSearch(${index}, this.value)"
                           style="cursor: text; background-color: #fff;"
                           autocomplete="off"
                           value="${product && product.product_name ? product.product_name : ''}">
                    <button type="button" class="btn btn-outline-secondary" 
                            onclick="openProductSearch(${index})">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <input type="hidden" class="item-product-id" 
                       name="items[${index}][product_id]" 
                       value="${product && product.product_id ? product.product_id : ''}">
                <input type="hidden" class="item-po-item-id" 
                       name="items[${index}][purchase_order_item_id]" 
                       value="${product && (product.item_id || product.order_item_id) ? (product.item_id || product.order_item_id) : ''}">
            </td>
            <td>
                <input type="text" class="form-control item-sku" 
                       name="items[${index}][sku]" 
                       placeholder="SKU" 
                       value="${product && product.sku ? product.sku : ''}">
            </td>
            <td>
                <input type="number" class="form-control item-quantity" 
                       name="items[${index}][quantity_received]" 
                       min="0.001" step="0.001" value="${product ? product.quantity || 1 : 1}" required>
            </td>
            <td>
                <select class="form-select item-unit" name="items[${index}][unit]">
                    <option value="pcs" ${product && product.unit == 'pcs' ? 'selected' : ''}>pcs</option>
                    <option value="kg" ${product && product.unit == 'kg' ? 'selected' : ''}>kg</option>
                    <option value="g" ${product && product.unit == 'g' ? 'selected' : ''}>g</option>
                    <option value="l" ${product && product.unit == 'l' ? 'selected' : ''}>l</option>
                    <option value="ml" ${product && product.unit == 'ml' ? 'selected' : ''}>ml</option>
                    <option value="m" ${product && product.unit == 'm' ? 'selected' : ''}>m</option>
                    <option value="box" ${product && product.unit == 'box' ? 'selected' : ''}>box</option>
                    <option value="carton" ${product && product.unit == 'carton' ? 'selected' : ''}>carton</option>
                </select>
            </td>
            <td class="<?= $is_dn ? 'd-none' : '' ?>">
                <div class="input-group">
                    <span class="input-group-text">TZS</span>
                    <input type="number" class="form-control item-price" 
                           name="items[${index}][unit_price]" 
                           min="0" step="0.01" value="${product ? product.unit_price || 0 : 0}">
                </div>
            </td>
            <td>
                <input type="text" class="form-control item-batch" 
                       name="items[${index}][batch_number]" 
                       placeholder="Batch No.">
            </td>
            <td>
                <input type="date" class="form-control item-expiry" 
                       name="items[${index}][expiry_date]">
            </td>
            <td class="<?= $is_dn ? 'd-none' : '' ?>">
                <span class="item-total">0.00</span>
                <span class="ms-1">TZS</span>
            </td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeItemRow(${index})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
    
    $('#itemsBody').append(html);
    updateSerialNumbers();
    
    // Calculate initial total
    calculateItemTotal(index);
    calculateTotals();
    
    return index;
}

function openProductSearch(index, term) {
    currentItemIndex = index;
    const input = $(`#item-row-${index} .item-name`);
    const offset = input.offset();
    
    // Position the results container
    $('#productSearchResults').css({
        top: offset.top + input.outerHeight() + 2,
        left: offset.left,
        width: Math.max(input.outerWidth() * 1.5, 600),
        display: 'block'
    });
    
    searchProducts(term);
}

function searchProducts(term = '') {
    const tbody = $('#productsSearchBody');
    tbody.empty();
    
    const searchTerm = term.toLowerCase().trim();
    let results = productsCache;
    
    if (searchTerm.length > 0) {
        results = productsCache.filter(product => {
            return (product.product_name && product.product_name.toLowerCase().includes(searchTerm)) ||
                   (product.sku && product.sku.toLowerCase().includes(searchTerm)) ||
                   (product.barcode && product.barcode.toLowerCase().includes(searchTerm));
        });
    }
    
    if (results.length === 0) {
        tbody.html('<tr><td colspan="4" class="text-center text-danger p-3">No products found</td></tr>');
        return;
    }
    
    results.slice(0, 50).forEach(product => {
        const costPrice = parseFloat(product.cost_price) || parseFloat(product.purchase_price) || 0;
        tbody.append(`
            <tr onclick="selectProduct(${product.product_id})">
                <td>
                    <strong>${product.product_name}</strong><br>
                    <small class="text-muted">${product.sku || 'No SKU'}</small>
                </td>
                <td>${product.sku || 'N/A'}</td>
                <td>${product.current_stock || 0}</td>
                <td>${costPrice.toLocaleString()}</td>
            </tr>
        `);
    });
}

function selectProduct(productId) {
    const product = productsCache.find(p => p.product_id == productId);
    if (product) {
        const row = $(`#item-row-${currentItemIndex}`);
        row.find('.item-name').val(product.product_name);
        row.find('.item-product-id').val(product.product_id);
        row.find('.item-sku').val(product.sku || '');
        // Auto-fill unit from product registration
        const unit = product.unit || 'pcs';
        row.find('.item-unit').val(unit);
        // Auto-fill unit price from cost_price saved at registration, fallback to selling_price
        const unitPrice = parseFloat(product.cost_price) > 0 
            ? parseFloat(product.cost_price) 
            : parseFloat(product.selling_price) || 0;
        row.find('.item-price').val(unitPrice.toFixed(2));
        
        $('#productSearchResults').hide();
        
        calculateItemTotal(currentItemIndex);
        calculateTotals();
        
        // Focus quantity field
        row.find('.item-quantity').focus();
    }
}

function calculateItemTotal(index) {
    const row = $(`#item-row-${index}`);
    const quantity = parseFloat(row.find('.item-quantity').val()) || 0;
    const price = parseFloat(row.find('.item-price').val()) || 0;
    const total = quantity * price;
    row.find('.item-total').text(total.toFixed(2));
}

function calculateTotals() {
    let totalItems = 0;
    let totalValue = 0;
    
    $('[id^="item-row-"]').each(function() {
        const quantity = parseFloat($(this).find('.item-quantity').val()) || 0;
        const price = parseFloat($(this).find('.item-price').val()) || 0;
        totalItems += quantity;
        totalValue += quantity * price;
    });
    
    $('#totalItems').text(totalItems.toFixed(3));
    $('#totalValue').text(totalValue.toFixed(2));
    $('#totalReceivedHidden').val(totalValue.toFixed(2));
}

function removeItemRow(index) {
    $(`#item-row-${index}`).remove();
    updateSerialNumbers();
    calculateTotals();
}

function clearAllItems() {
    Swal.fire({
        title: 'Clear All Items?',
        text: 'Are you sure you want to remove all items?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Clear All',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#itemsBody').empty();
            itemCount = 0;
            calculateTotals();
            addItemRow();
        }
    });
}

// GRN/DN - Warehouse filter by project
const grnAllWarehouses = <?= json_encode(array_values(array_map(function($w){
    return ['warehouse_id'=>(int)$w['warehouse_id'],'warehouse_name'=>$w['warehouse_name'],'location'=>$w['location']??'','project_id'=>(int)$w['project_id']];
},$warehouses))) ?>;

function filterGrnWarehouses(projectId) {
    const $sel = $('#warehouse_id');
    if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
    const sel   = document.getElementById('warehouse_id');
    const hint  = document.getElementById('grnWarehouseHint');
    const curVal = parseInt(sel.value) || 0;
    sel.innerHTML = '<option value="">Select Warehouse</option>';
    let filtered;
    if (!projectId || projectId === '' || projectId === '0') {
        filtered = grnAllWarehouses.filter(w => w.project_id === 0);
        if (hint) hint.textContent = 'Showing warehouses not linked to any project.';
    } else {
        filtered = grnAllWarehouses.filter(w => w.project_id === parseInt(projectId));
        if (hint) hint.textContent = filtered.length === 0
            ? 'No warehouses found for this project.'
            : 'Showing ' + filtered.length + ' warehouse(s) for selected project.';
    }
    filtered.forEach(w => {
        const opt = document.createElement('option');
        opt.value = w.warehouse_id;
        opt.setAttribute('data-project', w.project_id);
        opt.textContent = w.warehouse_name + (w.location ? ' - ' + w.location : '');
        if (w.warehouse_id === curVal) opt.selected = true;
        sel.appendChild(opt);
    });
    if (filtered.length === 1) sel.value = filtered[0].warehouse_id;
    $sel.select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'Select Warehouse' });
}

// Run warehouse filter on page load
$(document).ready(function() {
    const initProject = $('#project_id').val();
    const initWarehouse = <?= $warehouse_id ?: 0 ?>;
    filterGrnWarehouses(initProject);
    if (initWarehouse) $('#warehouse_id').val(initWarehouse);
});

function loadSupplierInfo() {
    const supplierId = $('#supplier_id').val();
    if (!supplierId) {
        $('#supplierInfoCard').hide();
        return;
    }
    
    $.ajax({
        url: 'api/get_supplier.php',
        type: 'GET',
        data: { id: supplierId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const supplier = response.data;
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>${supplier.supplier_name}</strong></p>
                            ${supplier.contact_person ? `<p>Contact: ${supplier.contact_person}</p>` : ''}
                            ${supplier.phone ? `<p>Phone: ${supplier.phone}</p>` : ''}
                            ${supplier.email ? `<p>Email: ${supplier.email}</p>` : ''}
                        </div>
                        <div class="col-md-6">
                            ${supplier.address ? `<p>Address: ${supplier.address}</p>` : ''}
                            ${supplier.city ? `<p>City: ${supplier.city}</p>` : ''}
                            ${supplier.country ? `<p>Country: ${supplier.country}</p>` : ''}
                        </div>
                    </div>
                `;
                $('#supplierInfoBody').html(html);
                $('#supplierInfoCard').show();
            }
        },
        error: function(error) {
            console.error('Error loading supplier info:', error);
        },
        complete: function() {
            loadDNsForSupplier();
        }
    });
}

function loadPurchaseOrderItems() {
    const poId = $('#purchase_order_id').val();
    if (!poId) {
        return;
    }
    
    // Clear existing items
    $('#itemsBody').empty();
    itemCount = 0;
    
    $.ajax({
        url: '<?= getUrl('api/operations/get_po_items') ?>',
        type: 'GET',
        data: { po_id: poId },
        dataType: 'json',
        success: function(response) {
            if (response && response.success) {
                // Update supplier and warehouse from PO
                if (response.data.supplier_id) {
                    $('#supplier_id').val(response.data.supplier_id).trigger('change.select2');
                    loadSupplierInfo();
                }

                // Set project if present in PO
                if (response.data.project_id) {
                    $('#project_id').val(response.data.project_id).trigger('change.select2');
                    $('#projectIdHidden').val(response.data.project_id);
                    // MUST filter warehouses first before setting the value
                    filterGrnWarehouses(response.data.project_id);
                } else {
                    $('#project_id').val('');
                    $('#projectIdHidden').val('');
                    filterGrnWarehouses(0);
                }
                
                // Set warehouse if present in PO
                if (response.data.warehouse_id) {
                    $('#warehouse_id').val(response.data.warehouse_id);
                }
                
                // Add PO items
                if (response.data.items && response.data.items.length > 0) {
                    response.data.items.forEach(item => {
                        addItemRow(item);
                    });
                }
                
                Swal.fire({
                    icon: 'success',
                    title: 'Items Loaded',
                    text: `${response.data.items.length} items loaded from purchase order.`,
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        },
        error: function(error) {
            console.error('Error loading PO items:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to load purchase order items.'
            });
        }
    });
}

function clearPOSelection() {
    $('#purchase_order_id').val('');
    $('#project_id').val('');
    $('#projectIdHidden').val('');
    Swal.fire({
        icon: 'info',
        title: 'PO Cleared',
        text: 'Purchase order selection cleared.',
        timer: 1500,
        showConfirmButton: false
    });
}

// ── Delivery Note helpers ─────────────────────────────────────────────────────

function loadDNsForSupplier() {
    const supplierId  = $('#supplier_id').val();
    const warehouseId = $('#warehouse_id').val();
    const poId        = $('#purchase_order_id').val();
    const $sel        = $('#delivery_note_id');

    $sel.html('<option value="">— Select recorded DN —</option>');
    $('#delivery_id').val('');
    $('#delivery_note').val('');

    if (!supplierId || !warehouseId) return;

    $.getJSON('<?= getUrl('api/get_dns_for_grn.php') ?>', {
        supplier_id: supplierId,
        po_id: poId || ''
    }, function (res) {
        if (res.success && res.data.length) {
            res.data.forEach(function (dn) {
                const label = dn.dn_number + ' — ' + dn.delivery_date
                    + (dn.order_number ? ' (PO: ' + dn.order_number + ')' : '');
                $sel.append($('<option>', {
                    value: dn.delivery_id,
                    text:  label,
                    'data-po-id': dn.purchase_order_id || ''
                }));
            });
        }
    });
}

function loadDNItems() {
    const deliveryId = $('#delivery_note_id').val();
    if (!deliveryId) {
        $('#delivery_id').val('');
        $('#delivery_note').val('');
        return;
    }

    // Store delivery_id and dn_number for the API
    const $opt = $('#delivery_note_id option:selected');
    $('#delivery_id').val(deliveryId);
    $('#delivery_note').val($opt.text().split(' — ')[0]); // store dn_number

    // Auto-fill PO if the DN has one
    const poId = $opt.data('po-id');
    if (poId && !$('#purchase_order_id').val()) {
        $('#purchase_order_id').val(poId).trigger('change.select2');
    }

    // Load items from DN
    $('#itemsBody').empty();
    itemCount = 0;

    $.getJSON('<?= getUrl('api/get_dn_items_for_grn.php') ?>', { delivery_id: deliveryId }, function (res) {
        if (res.success) {
            if (res.data.warehouse_id && !$('#warehouse_id').val()) {
                $('#warehouse_id').val(res.data.warehouse_id);
            }
            if (res.data.project_id && !$('#project_id').val()) {
                $('#project_id').val(res.data.project_id).trigger('change.select2');
                $('#projectIdHidden').val(res.data.project_id);
                filterGrnWarehouses(res.data.project_id);
            }
            if (res.data.items && res.data.items.length) {
                res.data.items.forEach(function (item) { addItemRow(item); });
                Swal.fire({
                    icon: 'success', title: 'Items Loaded',
                    text: res.data.items.length + ' items loaded from Delivery Note.',
                    timer: 1500, showConfirmButton: false
                });
            }
        }
    });
}

function clearDNSelection() {
    $('#delivery_note_id').val('');
    $('#delivery_id').val('');
    $('#delivery_note').val('');
}

// Reload DN list whenever warehouse changes (supplier already triggers it via loadSupplierInfo)
$('#warehouse_id').on('change', function () { loadDNsForSupplier(); });

// Also reload DN list when PO changes (to narrow the list)
const _origPOChange = window.loadPurchaseOrderItems || function(){};
$('#purchase_order_id').on('change', function () { loadDNsForSupplier(); });

// ─────────────────────────────────────────────────────────────────────────────

function scanBarcode() {
    $('#barcodeScannerModal').modal('show');
}

function handleBarcodeInput(barcode) {
    if (!barcode.trim()) return;
    
    // Search for product by barcode
    const product = productsCache.find(p => p.barcode && p.barcode === barcode);
    
    if (product) {
        // Show product found
        $('#barcodeResult').removeClass('d-none').html(`
            <div class="alert alert-success">
                <strong>Product Found:</strong> ${product.product_name}<br>
                <small>SKU: ${product.sku || 'N/A'} | Unit: ${product.unit || 'pcs'}</small>
            </div>
        `);
        
        // Add item with this product
        const index = addItemRow(product);
        $('#barcodeScannerModal').modal('hide');
        
        // Focus on quantity field of new item
        setTimeout(() => {
            $(`#item-row-${index} .item-quantity`).focus();
        }, 100);
        
    } else {
        $('#barcodeResult').removeClass('d-none').html(`
            <div class="alert alert-warning">
                <strong>Product Not Found</strong><br>
                <small>Barcode "${barcode}" not found in database.</small>
            </div>
        `);
    }
    
    $('#barcodeInput').val('');
}

function addScannedItem() {
    const barcode = $('#barcodeInput').val();
    if (barcode) {
        handleBarcodeInput(barcode);
    }
}

function createGRN(status = 'completed') {
    // Validate form
    if (!validateForm(status === 'draft')) {
        return;
    }
    
    const formData = new FormData($('#grnForm')[0]);
    formData.append('status', status);
    
    // Get items data
    const items = [];
    $('[id^="item-row-"]').each(function() {
        const item = {
            product_id: $(this).find('.item-product-id').val(),
            purchase_order_item_id: $(this).find('.item-po-item-id').val(),
            product_name: $(this).find('.item-name').val(),
            sku: $(this).find('.item-sku').val(),
            quantity_received: $(this).find('.item-quantity').val(),
            unit: $(this).find('.item-unit').val(),
            unit_price: $(this).find('.item-price').val(),
            batch_number: $(this).find('.item-batch').val(),
            expiry_date: $(this).find('.item-expiry').val()
        };
        
        if (item.product_name && item.quantity_received) {
            items.push(item);
        }
    });
    
    // Add items to form data
    formData.append('items', JSON.stringify(items));
    
    // Show loading state
    const submitBtn = $('#grnForm [type="submit"]');
    const originalText = submitBtn.html();
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');
    
    $.ajax({
        url: 'api/create_grn.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message,
                    showConfirmButton: false,
                    timer: 1500
                }).then(() => {
                    // Return to project if came from project context
                    const returnUrl = $('#returnUrlHidden').val();
                    if (returnUrl && returnUrl.length > 5) {
                        window.location.href = returnUrl;
                    } else if (response.receipt_id) {
                        window.location.href = '<?= getUrl('grn_view') ?>?id=' + response.receipt_id;
                    } else {
                        window.location.href = '<?= getUrl('grn') ?>';
                    }
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message
                });
                submitBtn.prop('disabled', false).html(originalText);
            }
        },
        error: function(xhr, status, error) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred. Please try again.'
            });
            submitBtn.prop('disabled', false).html(originalText);
            console.error('Error:', error);
        }
    });
}

function saveAsDraft() {
    if (!validateForm(true)) {
        return;
    }
    createGRN('draft');
}

function validateForm(isDraft = false) {
    // Check if at least one item is added
    if ($('[id^="item-row-"]').length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Items',
            text: 'Please add at least one received item.'
        });
        return false;
    }
    
    // Check if all items have valid data
    let hasValidItems = false;
    $('[id^="item-row-"]').each(function() {
        const productName = $(this).find('.item-name').val();
        const quantity = $(this).find('.item-quantity').val();
        
        if (productName && quantity > 0) {
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Items',
            text: 'Please ensure all items have a name and quantity.'
        });
        return false;
    }
    
    // Check required fields
    const requiredFields = ['receipt_number', 'receipt_date', 'received_by', 'supplier_id', 'warehouse_id'];
    for (const field of requiredFields) {
        const value = $(`#${field}`).val();
        if (!value && !isDraft) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Information',
                text: `Please fill in the ${field.replace('_', ' ')} field.`
            });
            $(`#${field}`).focus();
            return false;
        }
    }
    
    return true;
}

function addAttachmentRow() {
    const html = `
        <div class="row g-2 attachment-row mb-2">
            <div class="col-md-5">
                <input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Document Name">
            </div>
            <div class="col-md-6">
                <input type="file" class="form-control form-control-sm" name="attachments[]">
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeAttachmentRow(this)" title="Remove">
                    <i class="bi bi-trash fs-5"></i>
                </button>
            </div>
        </div>
    `;
    $('#attachment-fields').append(html);
}

function removeAttachmentRow(btn) {
    if ($('.attachment-row').length > 1) {
        $(btn).closest('.attachment-row').remove();
    } else {
        $(btn).closest('.attachment-row').find('input').val('');
    }
}

function printGRN() {
    // Validate form first
    if (!validateForm(true)) {
        return;
    }
    
    // Save as draft and print
    createGRN('draft');
}
</script>

<style>
@media (max-width: 767px) {
    .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
}

.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.table th {
    font-weight: 600;
    font-size: 0.9rem;
}

#itemsTable input, #itemsTable select {
    font-size: 0.85rem;
}

#itemsTable .form-control {
    padding: 0.25rem 0.5rem;
}

.item-total {
    font-weight: bold;
    color: #198754;
}

/* Quality check checkboxes */
.form-check-input:checked {
    background-color: #198754;
    border-color: #198754;
}

/* Barcode scanner modal */
#barcodeScannerModal .modal-body {
    min-height: 200px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding: 0.5rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .table-responsive {
        font-size: 0.85rem;
    }
    
    #itemsTable th, #itemsTable td {
        padding: 0.5rem;
    }
    
    #itemsTable th:nth-child(1),
    #itemsTable td:nth-child(1) {
        min-width: 150px;
    }
    
    #itemsTable th:nth-child(2),
    #itemsTable th:nth-child(6),
    #itemsTable th:nth-child(7),
    #itemsTable td:nth-child(2),
    #itemsTable td:nth-child(6),
    #itemsTable td:nth-child(7) {
        display: none;
    }
}

@media print {
    .navbar, .card-header .btn, .dropdown, 
    .modal, .fixed-bottom, .d-print-none {
        display: none !important;
    }
    
    .card {
        border: none;
        box-shadow: none;
    }
    
    .card-body {
        padding: 0;
    }
    
    table {
        width: 100% !important;
        font-size: 12px !important;
    }
}

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

/* Floating product search */
.product-search-results {
    position: absolute;
    background: white;
    z-index: 9999;
    max-height: 400px;
    overflow-y: auto;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
}

.product-search-results table thead th {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    z-index: 10;
}

.product-search-results tr {
    cursor: pointer;
    transition: all 0.2s;
}

.product-search-results tr:hover {
    background-color: #e9ecef !important;
}
@media print{
    .d-print-none,.btn,.card-header,.dropdown{display:none!important;}
    body{background:#fff!important;padding:0!important;}
    .container-fluid{padding-bottom:6cm!important;}
    @page{size:auto;margin:0.5in 0.5in 75mm 0.5in!important;}
    .card{border:none!important;box-shadow:none!important;}
    table{width:100%!important;border-collapse:collapse!important;}
    th,td{border:1px solid #dee2e6!important;padding:6px 4px!important;font-size:8.5pt!important;}
    thead th{background:#f8f9fa!important;-webkit-print-color-adjust:exact;}
    .print-footer{position:fixed!important;bottom:0!important;left:0;right:0;
        height:1.5cm;display:flex;flex-direction:column;justify-content:center;
        text-align:center;background:#fff!important;padding:0;
        border-top:1px solid #ddd!important;font-size:10px;
        z-index:999999!important;-webkit-print-color-adjust:exact;pointer-events:none;}
}
</style>

<?php
// Include the footer
includeFooter();
?>

