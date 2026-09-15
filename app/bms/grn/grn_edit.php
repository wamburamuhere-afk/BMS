<?php
// File: grn_create.php
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('grn');

// Include the header
includeHeader();

// Permission flags
$can_create_grn = isAdmin() || canCreate('grn');


// Get parameters
$receipt_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($receipt_id <= 0) {
    header("Location: grn.php?error=" . urlencode(t('Invalid GRN ID')));
    exit();
}

// Fetch GRN Details
global $pdo;
$stmt = $pdo->prepare("SELECT * FROM purchase_receipts WHERE receipt_id = ?");
$stmt->execute([$receipt_id]);
$grn = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$grn) {
    header("Location: grn.php?error=" . urlencode(t('GRN Not Found')));
    exit();
}

$supplier_id = $grn['supplier_id'];
$warehouse_id = $grn['warehouse_id'];
$po_id = $grn['purchase_order_id'];
$project_id_param = $grn['project_id'];
$type = isset($_GET['type']) ? htmlspecialchars($_GET['type']) : 'grn';
$is_dn = ($type === 'delivery_note');
$doc_label = $is_dn ? t('Received Note') : t('Goods Received Note');
$doc_short = $is_dn ? 'DN' : 'GRN';
$doc_icon = 'bi-pencil-square';

// Fetch GRN Items
$stmtItems = $pdo->prepare("
    SELECT ri.*, p.product_name, p.sku, p.unit 
    FROM receipt_items ri
    LEFT JOIN products p ON ri.product_id = p.product_id
    WHERE ri.receipt_id = ?
");
$stmtItems->execute([$receipt_id]);
$grn_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Fetch GRN Attachments
$stmtAtt = $pdo->prepare("SELECT * FROM purchase_receipt_attachments WHERE receipt_id = ?");
$stmtAtt->execute([$receipt_id]);
$attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

// Build return URL for project context
$project_return_url = $project_id_param > 0
    ? getUrl('project_view') . '?id=' . $project_id_param . '&tab=grn'
    : null;

// Origin context (URL only): where the user came FROM. Drives the post-save redirect so
// editing a project-linked GRN from the general area does NOT jump into the project.
// (The GRN keeps its own project link via projectIdHidden below — unchanged.)
$origin_project_id = (projectsModuleActive() && isset($_GET['project_id'])) ? intval($_GET['project_id']) : 0;
$origin_return_url = $origin_project_id > 0
    ? getUrl('project_view') . '?id=' . $origin_project_id . '&tab=grn'
    : '';

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

// Get warehouses for dropdown — scoped by project for non-admins (shared helper)
require_once ROOT_DIR . '/core/warehouse_scope.php';
require_once ROOT_DIR . '/core/project_scope.php';
$warehouses = warehousesForSelect($pdo);

// Get projects for dropdown
$projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);

// Get pending purchase orders — scoped to the current user's project +
// warehouse access (found 2026-07-18: this picker had NO scoping at all,
// showing every approved PO in the company regardless of warehouse/project).
$_grne_po_scope = scopeFilterSqlNullable('project', 'po') . scopeFilterSqlNullable('warehouse', 'po');
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
    $_grne_po_scope
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
            <?php
            $printed_by_html = '<span class="fw-bold text-dark">' . ucwords(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))) . ' - ' . ucwords($_SESSION['user_role'] ?? t('Staff')) . '</span>';
            $printed_at_html = '<span class="fw-bold text-dark">' . date('d M, Y \\a\\t h:i A') . '</span>';
            echo sprintf(t('This document was Printed by %s on %s'), $printed_by_html, $printed_at_html);
            ?>
        </p>
        <p class="mb-0 fw-bold text-primary" style="font-size:10pt;letter-spacing:0.5px;">
            <?= t('Powered By BJP Technologies') ?> &copy; 2026
        </p>
    </div>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 class="mb-1"><i class="bi <?= $doc_icon ?>"></i> <?= t('Edit') ?> <?= $doc_label ?> (<?= $doc_short ?>)</h2>
                    <p class="text-muted mb-0"><?= sprintf(t('Modify Goods Received Note #%s'), safe_output($grn['receipt_number'])) ?></p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= getUrl($is_dn ? 'delivery_notes' : 'grn') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> <?= sprintf(t('Back to %ss'), $doc_short) ?>
                    </a>
                    <?php if ($project_id_param > 0): ?>
                    <a href="<?= $project_return_url ?>" class="btn btn-outline-primary">
                        <i class="bi bi-kanban"></i> <?= t('Back to Project') ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> <?= sprintf(t('%s Details'), $doc_short) ?></h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <form id="grnForm" enctype="multipart/form-data">
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label for="receipt_number" class="form-label"><?= sprintf(t('%s Number'), $doc_short) ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="receipt_number" name="receipt_number"
                               value="<?= safe_output($grn['receipt_number']) ?>" required readonly>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="receipt_date" class="form-label"><?= t('Receipt Date') ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="receipt_date" name="receipt_date"
                               value="<?= $grn['receipt_date'] ?>" required>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="received_by" class="form-label"><?= t('Received By') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="received_by" name="received_by" 
                               value="<?= safe_output($grn['received_by']) ?>" required>
                    </div>
                </div>
                
                <!-- Supplier, Warehouse and Project -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label for="supplier_id" class="form-label"><?= t('Supplier') ?> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" id="supplier_id" name="supplier_id" required onchange="loadSupplierInfo()">
                            <option value=""><?= t('Select Supplier') ?></option>
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
                    
                    <?php if (projectsModuleActive()): ?>
                    <div class="col-md-4 mb-3">
                        <label for="project_id" class="form-label"><?= t('Project') ?> <span class="text-muted small">(<?= t('Optional') ?>)</span></label>
                        <select class="form-select select2-static" id="project_id" name="project_id"
                            onchange="filterGrnWarehouses(this.value); $('#projectIdHidden').val(this.value)">
                            <option value=""><?= t('No Project') ?></option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>"
                                    <?= ($project_id_param > 0 && $proj['project_id'] == $project_id_param) ? 'selected' : '' ?>>
                                    <?= safe_output($proj['project_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="grnWarehouseHint"><?= wLabel('Select project to filter warehouses.', 'Select project to filter shops.') ?></small>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-4 mb-3">
                        <label for="warehouse_id" class="form-label"><?= wLabel('Warehouse', 'Shop') ?> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" id="warehouse_id" name="warehouse_id" required>
                            <option value=""><?= wLabel('Select Warehouse', 'Select Shop') ?></option>
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
                        <label for="purchase_order_id" class="form-label"><?= t('Purchase Order') ?> (<?= t('Optional') ?>)</label>
                        <div class="input-group">
                            <select class="form-select select2-static" id="purchase_order_id" name="purchase_order_id" onchange="loadPurchaseOrderItems()">
                                <option value=""><?= t('Select Purchase Order') ?></option>
                                <?php foreach ($pending_pos as $po): ?>
                                    <option value="<?= $po['purchase_order_id'] ?>" 
                                        <?= ($po_id > 0 && $po['purchase_order_id'] == $po_id) ? 'selected' : '' ?>
                                        data-supplier-id="<?= $po['supplier_id'] ?? 0 ?>">
                                        <?= safe_output($po['order_number']) ?> -
                                        <?= safe_output($po['supplier_name']) ?>
                                        (<?= sprintf(t('%s items pending'), $po['pending_qty']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearPOSelection()">
                                <i class="bi bi-x"></i> <?= t('Clear') ?>
                            </button>
                        </div>
                        <small class="text-muted"><?= t('Select a purchase order to auto-populate items') ?></small>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="delivery_note" class="form-label"><?= t('Delivery Note Number') ?></label>
                        <input type="text" class="form-control" id="delivery_note" name="delivery_note"
                               value="<?= safe_output($grn['delivery_note']) ?>"
                               placeholder="<?= t("Supplier's delivery note number") ?>">
                    </div>
                </div>
                
                <!-- Supplier Information Card -->
                <div class="card mb-4" id="supplierInfoCard" style="display: none;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-truck"></i> <?= t('Supplier Information') ?></h6>
                    </div>
                    <div class="card-body" id="supplierInfoBody">
                        <!-- Supplier info will be loaded here -->
                    </div>
                </div>
                
                <!-- Received Items -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-list-check"></i> <?= t('Received Items') ?></h6>
                        <div>
                            <button type="button" class="btn btn-sm btn-light" onclick="addItemRow()">
                                <i class="bi bi-plus-circle"></i> <?= t('Add Item') ?>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-light ms-2" onclick="clearAllItems()">
                                <i class="bi bi-trash"></i> <?= t('Clear All') ?>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;"><?= t('S/NO') ?></th>
                                        <th width="30%"><?= t('Product/Item') ?> <span class="text-danger">*</span></th>
                                        <th width="10%"><?= t('SKU/Barcode') ?></th>
                                        <th width="10%"><?= t('Quantity') ?> <span class="text-danger">*</span></th>
                                        <th width="10%"><?= t('Unit') ?></th>
                                        <?php if (!$is_dn): ?>
                                        <th width="12%"><?= t('Unit Price') ?></th>
                                        <?php endif; ?>
                                        <th width="10%"><?= t('Batch No.') ?></th>
                                        <th width="10%"><?= t('Expiry Date') ?></th>
                                        <?php if (!$is_dn): ?>
                                        <th width="5%"><?= t('Total') ?></th>
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
                                                        <i class="bi bi-plus-circle"></i> <?= t('Add Item') ?>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="scanBarcode()">
                                                        <i class="bi bi-upc-scan"></i> <?= t('Scan Barcode') ?>
                                                    </button>
                                                </div>
                                                <div class="text-end">
                                                    <strong><?= t('Total Items:') ?> <span id="totalItems">0</span></strong><br>
                                                    <?php if (!$is_dn): ?>
                                                    <strong><?= t('Total Value:') ?> <span id="totalValue">0.00</span> TZS</strong>
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
                        <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2 text-primary"></i> <?= t('Documents & Attachments') ?></h6>
                    </div>
                    <div class="card-body">
                        <div id="attachments-container" class="border rounded p-3 bg-light">
                            <div id="attachment-fields">
                                <!-- Existing Attachments as Editable Rows -->
                                <?php foreach ($attachments as $index => $att): ?>
                                <div class="row g-2 attachment-row mb-2" data-type="existing">
                                    <input type="hidden" name="attachment_ids[]" value="<?= $att['attachment_id'] ?>">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control form-control-sm" name="attachment_names[]"
                                               value="<?= safe_output($att['file_name']) ?>" placeholder="<?= t('Document Name') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <div class="input-group input-group-sm">
                                            <label class="input-group-text mb-0 cursor-pointer" for="file_<?= $att['attachment_id'] ?>"><?= t('Choose File') ?></label>
                                            <input type="text" class="form-control bg-white cursor-pointer" readonly 
                                                   value="<?= basename($att['file_path']) ?>" 
                                                   onclick="document.getElementById('file_<?= $att['attachment_id'] ?>').click()">
                                            <input type="file" class="d-none" id="file_<?= $att['attachment_id'] ?>" name="attachments[]" 
                                                   onchange="this.previousElementSibling.value = this.files[0].name">
                                        </div>
                                    </div>
                                    <div class="col-md-1 text-end">
                                        <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeAttachmentRow(this)" title="<?= t('Remove') ?>">
                                            <i class="bi bi-trash fs-5"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <!-- If no existing, add one empty row -->
                                <?php if (empty($attachments)): ?>
                                <div class="row g-2 attachment-row mb-2" data-type="new">
                                    <input type="hidden" name="attachment_ids[]" value="0">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="<?= t('Document Name') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="file" class="form-control form-control-sm" name="attachments[]">
                                    </div>
                                    <div class="col-md-1 text-end">
                                        <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeAttachmentRow(this)" title="<?= t('Remove') ?>">
                                            <i class="bi bi-trash fs-5"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="addAttachmentRow()">
                                <i class="bi bi-plus-circle me-1"></i> <?= t('Add Attachment') ?>
                            </button>
                        </div>
                        <div class="form-text text-muted mt-2"><?= t('Accepted: PDF, DOC, DOCX, JPG, PNG (max 10MB each).') ?></div>
                    </div>
                </div>
                
                <!-- Quality Check & Notes -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-clipboard-check"></i> <?= t('Quality Check') ?></h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label"><?= t('Overall Condition') ?></label>
                                    <select class="form-select" name="quality_condition">
                                        <option value="excellent"><?= t('Excellent') ?></option>
                                        <option value="good" selected><?= t('Good') ?></option>
                                        <option value="fair"><?= t('Fair') ?></option>
                                        <option value="poor"><?= t('Poor') ?></option>
                                    </select>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="packaging_ok" id="packaging_ok" checked>
                                    <label class="form-check-label" for="packaging_ok"><?= t('Packaging OK') ?></label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="quantity_ok" id="quantity_ok" checked>
                                    <label class="form-check-label" for="quantity_ok"><?= t('Quantity OK') ?></label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="damage_check" id="damage_check">
                                    <label class="form-check-label" for="damage_check"><?= t('Damage Checked') ?></label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="expiry_check" id="expiry_check">
                                    <label class="form-check-label" for="expiry_check"><?= t('Expiry Dates Checked') ?></label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-chat-left-text"></i> <?= t('Notes & Remarks') ?></h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="notes" class="form-label"><?= t('Notes') ?></label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4"
                                              placeholder="<?= t('Any special notes, remarks, or observations about the received goods') ?>"><?= safe_output($grn['notes']) ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="inspected_by" class="form-label"><?= t('Inspected By') ?></label>
                                    <input type="text" class="form-control" id="inspected_by" name="inspected_by" 
                                           value="<?= safe_output($username) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Hidden fields -->
                <input type="hidden" name="receipt_id" value="<?= $receipt_id ?>">
                <input type="hidden" name="created_by" value="<?= $grn['created_by'] ?>">
                <input type="hidden" name="total_received" id="totalReceivedHidden" value="0">
                <input type="hidden" name="status" value="<?= $grn['status'] ?>">
                <input type="hidden" name="project_id" id="projectIdHidden" value="<?= $project_id_param ?>">
                <input type="hidden" name="return_url" id="returnUrlHidden" value="<?= htmlspecialchars($origin_return_url) ?>">
                
                <!-- Form Actions -->
                <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3" style="min-width: 120px;" onclick="window.history.back()">
                        <i class="bi bi-x-circle"></i> <?= t('Cancel') ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary px-3" style="min-width: 120px;" onclick="saveAsDraft()">
                        <i class="bi bi-save"></i> <?= t('Save as Draft') ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary px-3" style="min-width: 120px;">
                        <i class="bi bi-check-circle"></i> <?= sprintf(t('Update %s'), $doc_short) ?>
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
                    <th><?= t('Product') ?></th>
                    <th><?= t('SKU') ?></th>
                    <th><?= t('Stock') ?></th>
                    <th><?= t('Cost Price') ?></th>
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
                    <i class="bi bi-upc-scan"></i> <?= t('Barcode Scanner') ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="bi bi-upc" style="font-size: 3rem;"></i>
                    <p class="mt-2"><?= t('Scan barcode or enter manually') ?></p>
                </div>
                <div class="mb-3">
                    <label for="barcodeInput" class="form-label"><?= t('Barcode') ?></label>
                    <input type="text" class="form-control" id="barcodeInput" placeholder="<?= t('Scan or enter barcode') ?>" autofocus>
                    <small class="text-muted"><?= t('Press Enter after scanning or typing') ?></small>
                </div>
                <div id="barcodeResult" class="d-none">
                    <!-- Barcode scan result will be shown here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Close') ?></button>
                <button type="button" class="btn btn-success" onclick="addScannedItem()"><?= t('Add Item') ?></button>
            </div>
        </div>
    </div>
</div>


<script src="<?= getUrl('assets/js/warehouse-project-filter.js') ?>"></script>
<script>
let currentItemIndex = null;
let itemCount = 0;
let productsCache = [];

// Pre-translated strings used inside JS template literals / dynamic UI below.
const GRN_I18N = {
    selectSupplier:        <?= json_encode(t('Select Supplier')) ?>,
    noProject:             <?= json_encode(t('No Project')) ?>,
    selectPurchaseOrder:   <?= json_encode(t('Select Purchase Order')) ?>,
    selectWarehouse:       <?= json_encode(wLabel('Select Warehouse', 'Select Shop')) ?>,
    typeToSearchProduct:   <?= json_encode(t('Type to search product...')) ?>,
    sku:                   <?= json_encode(t('SKU')) ?>,
    batchNo:               <?= json_encode(t('Batch No.')) ?>,
    noSku:                 <?= json_encode(t('No SKU')) ?>,
    na:                    <?= json_encode(t('N/A')) ?>,
    pleaseSelectWarehouseFirst: <?= json_encode(wLabel('Please select a warehouse first', 'Please select a shop first')) ?>,
    noProductsFound:       <?= json_encode(t('No products found')) ?>,
    warehousesNotLinked:   <?= json_encode(wLabel('Showing warehouses not linked to any project.', 'Showing shops not linked to any project.')) ?>,
    noWarehousesForProject: <?= json_encode(wLabel('No warehouses found for this project.', 'No shops found for this project.')) ?>,
    showingNWarehouses:    <?= json_encode(wLabel('Showing {0} warehouse(s) for selected project.', 'Showing {0} shop(s) for selected project.')) ?>,
    clearAllItemsTitle:    <?= json_encode(t('Clear All Items?')) ?>,
    clearAllItemsText:     <?= json_encode(t('Are you sure you want to remove all items?')) ?>,
    yesClearAll:           <?= json_encode(t('Yes, Clear All')) ?>,
    cancel:                <?= json_encode(t('Cancel')) ?>,
    productFound:          <?= json_encode(t('Product Found:')) ?>,
    skuLabel:              <?= json_encode(t('SKU:')) ?>,
    unitLabel:             <?= json_encode(t('Unit:')) ?>,
    productNotFound:       <?= json_encode(t('Product Not Found')) ?>,
    barcodeNotFound:       <?= json_encode(t('Barcode "{0}" not found in database.')) ?>,
    noItems:               <?= json_encode(t('No Items')) ?>,
    pleaseAddAtLeastOneItem: <?= json_encode(t('Please add at least one received item.')) ?>,
    invalidItems:          <?= json_encode(t('Invalid Items')) ?>,
    pleaseEnsureAtLeastOneItem: <?= json_encode(t('Please ensure at least one item has a name and quantity > 0.')) ?>,
    missingInformation:    <?= json_encode(t('Missing Information')) ?>,
    pleaseFillInField:     <?= json_encode(t('Please fill in the {0} field.')) ?>,
    documentName:          <?= json_encode(t('Document Name')) ?>,
    itemsLoaded:           <?= json_encode(t('Items Loaded')) ?>,
    itemsLoadedFromPO:     <?= json_encode(t('{0} items loaded from purchase order.')) ?>,
    error:                 <?= json_encode(t('Error')) ?>,
    failedToLoadPoItems:   <?= json_encode(t('Failed to load purchase order items.')) ?>,
    poCleared:             <?= json_encode(t('PO Cleared')) ?>,
    poSelectionCleared:    <?= json_encode(t('Purchase order selection cleared.')) ?>,
    updatingGrn:           <?= json_encode(t('Updating GRN...')) ?>,
    pleaseWait:            <?= json_encode(t('Please wait')) ?>,
    updated:               <?= json_encode(t('Updated!')) ?>,
    updateFailed:          <?= json_encode(t('Update Failed')) ?>,
    serverError:           <?= json_encode(t('Server Error')) ?>,
    couldNotConnectApi:    <?= json_encode(t('Could not connect to the update API. Check if the file exists on the server.')) ?>,
    remove:                <?= json_encode(t('Remove')) ?>,
    contactLabel:          <?= json_encode(t('Contact:')) ?>,
    phoneLabel:            <?= json_encode(t('Phone:')) ?>,
    emailLabel:            <?= json_encode(t('Email:')) ?>,
    addressLabel:          <?= json_encode(t('Address:')) ?>,
    cityLabel:             <?= json_encode(t('City:')) ?>,
    countryLabel:          <?= json_encode(t('Country:')) ?>
};

// Per-page local convention: a tiny numbered-placeholder formatter so a
// translated sentence stays ONE coherent unit instead of being concatenated
// from English word-order fragments.
function tFormat(str, ...args) {
    return str.replace(/\{(\d+)\}/g, (m, i) => (args[i] !== undefined ? args[i] : m));
}

$(document).ready(function() {
    // Select2 on DB-backed selects
    $('#supplier_id').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: GRN_I18N.selectSupplier });
    $('#project_id').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: GRN_I18N.noProject });
    $('#purchase_order_id').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: GRN_I18N.selectPurchaseOrder });

    // Load existing items
    const existingItems = <?= json_encode($grn_items) ?>;
    if (existingItems.length > 0) {
        $('#itemsBody').empty();
        existingItems.forEach(item => {
            addItemRow({
                product_id: item.product_id,
                product_name: item.product_name,
                sku: item.sku,
                quantity: item.quantity_received,
                unit: item.unit,
                unit_price: item.unit_price,
                purchase_order_item_id: item.purchase_order_item_id
            });
        });
    } else {
        addItemRow();
    }
    
    // Form submission
    $('#grnForm').on('submit', function(e) {
        e.preventDefault();
        updateGRN();
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
    const warehouseId = $('#warehouse_id').val() || 0;
    $.ajax({
        url: '<?= buildUrl('api/account/get_products.php') ?>',
        type: 'GET',
        data: { limit: 1000, is_service: 0, warehouse_id: warehouseId },
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

// Reload the product cache whenever the warehouse changes, so stock levels
// shown in the product search reflect the newly-selected warehouse.
$('#warehouse_id').on('change', loadProductsCache);

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
                           placeholder="${GRN_I18N.typeToSearchProduct}" required
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
                       placeholder="${GRN_I18N.sku}"
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
                       placeholder="${GRN_I18N.batchNo}">
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

    if (!$('#warehouse_id').val()) {
        tbody.html(`<tr><td colspan="4" class="text-center text-warning p-3"><i class="bi bi-exclamation-triangle me-1"></i>${GRN_I18N.pleaseSelectWarehouseFirst}</td></tr>`);
        return;
    }

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
        tbody.html(`<tr><td colspan="4" class="text-center text-danger p-3">${GRN_I18N.noProductsFound}</td></tr>`);
        return;
    }

    results.slice(0, 50).forEach(product => {
        const costPrice = parseFloat(product.cost_price) || parseFloat(product.purchase_price) || 0;
        tbody.append(`
            <tr onclick="selectProduct(${product.product_id})">
                <td>
                    <strong>${product.product_name}</strong><br>
                    <small class="text-muted">${product.sku || GRN_I18N.noSku}</small>
                </td>
                <td>${product.sku || GRN_I18N.na}</td>
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
        title: GRN_I18N.clearAllItemsTitle,
        text: GRN_I18N.clearAllItemsText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: GRN_I18N.yesClearAll,
        cancelButtonText: GRN_I18N.cancel
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
const GRN_PROJECTS_ACTIVE = <?= json_encode(projectsModuleActive()) ?>;

function filterGrnWarehouses(projectId) {
    const $sel = $('#warehouse_id');
    if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
    const sel   = document.getElementById('warehouse_id');
    const hint  = document.getElementById('grnWarehouseHint');
    const curVal = parseInt(sel.value) || 0;
    sel.innerHTML = `<option value="">${GRN_I18N.selectWarehouse}</option>`;
    // The filtering rule lives in the shared assets/js/warehouse-project-filter.js.
    const filtered = filterWarehousesForProject(grnAllWarehouses, projectId);
    if (hint) {
        if (!projectId || projectId === '' || projectId === '0') {
            hint.textContent = GRN_I18N.warehousesNotLinked;
        } else {
            hint.textContent = filtered.length === 0
                ? GRN_I18N.noWarehousesForProject
                : tFormat(GRN_I18N.showingNWarehouses, filtered.length);
        }
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
    $sel.select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: GRN_I18N.selectWarehouse });
    // Whatever warehouse ended up selected (retained, auto-picked, or none),
    // reload the product cache scoped to it — rebuilding the <option> list
    // above doesn't fire a native 'change' event, so this can't rely on the
    // #warehouse_id change handler alone.
    loadProductsCache();
}

// Run warehouse filter on page load
$(document).ready(function() {
    const initProject = $('#project_id').val();
    const initWarehouse = <?= $warehouse_id ?: 0 ?>;
    filterGrnWarehouses(initProject);
    if (initWarehouse) $('#warehouse_id').val(initWarehouse);
    loadProductsCache();
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
                            ${supplier.contact_person ? `<p>${GRN_I18N.contactLabel} ${supplier.contact_person}</p>` : ''}
                            ${supplier.phone ? `<p>${GRN_I18N.phoneLabel} ${supplier.phone}</p>` : ''}
                            ${supplier.email ? `<p>${GRN_I18N.emailLabel} ${supplier.email}</p>` : ''}
                        </div>
                        <div class="col-md-6">
                            ${supplier.address ? `<p>${GRN_I18N.addressLabel} ${supplier.address}</p>` : ''}
                            ${supplier.city ? `<p>${GRN_I18N.cityLabel} ${supplier.city}</p>` : ''}
                            ${supplier.country ? `<p>${GRN_I18N.countryLabel} ${supplier.country}</p>` : ''}
                        </div>
                    </div>
                `;
                $('#supplierInfoBody').html(html);
                $('#supplierInfoCard').show();
            }
        },
        error: function(error) {
            console.error('Error loading supplier info:', error);
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
                    $('#supplier_id').val(response.data.supplier_id);
                    loadSupplierInfo();
                }
                
                // Set project if present in PO
                if (response.data.project_id) {
                    $('#project_id').val(response.data.project_id);
                    $('#projectIdHidden').val(response.data.project_id);
                    // MUST filter warehouses first before setting the value
                    filterGrnWarehouses(response.data.project_id);
                } else {
                    $('#project_id').val('');
                    $('#projectIdHidden').val('');
                    filterGrnWarehouses(GRN_PROJECTS_ACTIVE ? 0 : undefined);
                }
                
                // Set warehouse if present in PO
                if (response.data.warehouse_id) {
                    $('#warehouse_id').val(response.data.warehouse_id);
                    loadProductsCache();
                }
                
                // Add PO items
                if (response.data.items && response.data.items.length > 0) {
                    response.data.items.forEach(item => {
                        addItemRow(item);
                    });
                }
                
                Swal.fire({
                    icon: 'success',
                    title: GRN_I18N.itemsLoaded,
                    text: tFormat(GRN_I18N.itemsLoadedFromPO, response.data.items.length),
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        },
        error: function(error) {
            console.error('Error loading PO items:', error);
            Swal.fire({
                icon: 'error',
                title: GRN_I18N.error,
                text: GRN_I18N.failedToLoadPoItems
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
        title: GRN_I18N.poCleared,
        text: GRN_I18N.poSelectionCleared,
        timer: 1500,
        showConfirmButton: false
    });
}

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
                <strong>${GRN_I18N.productFound}</strong> ${product.product_name}<br>
                <small>${GRN_I18N.skuLabel} ${product.sku || GRN_I18N.na} | ${GRN_I18N.unitLabel} ${product.unit || 'pcs'}</small>
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
                <strong>${GRN_I18N.productNotFound}</strong><br>
                <small>${tFormat(GRN_I18N.barcodeNotFound, barcode)}</small>
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

function validateForm(isDraft = false) {
    if ($('[id^="item-row-"]').length === 0) {
        Swal.fire({ icon: 'warning', title: GRN_I18N.noItems, text: GRN_I18N.pleaseAddAtLeastOneItem });
        return false;
    }

    let hasValidItems = false;
    $('[id^="item-row-"]').each(function() {
        const productName = $(this).find('.item-name').val();
        const quantity = parseFloat($(this).find('.item-quantity').val()) || 0;
        if (productName && quantity > 0) hasValidItems = true;
    });

    if (!hasValidItems) {
        Swal.fire({ icon: 'warning', title: GRN_I18N.invalidItems, text: GRN_I18N.pleaseEnsureAtLeastOneItem });
        return false;
    }

    const requiredFields = ['receipt_number', 'receipt_date', 'received_by', 'supplier_id', 'warehouse_id'];
    for (const field of requiredFields) {
        if (!$(`#${field}`).val() && !isDraft) {
            Swal.fire({ icon: 'warning', title: GRN_I18N.missingInformation, text: tFormat(GRN_I18N.pleaseFillInField, field.replace('_', ' ')) });
            $(`#${field}`).focus();
            return false;
        }
    }
    return true;
}

function addAttachmentRow() {
    const html = `
        <div class="row g-2 attachment-row mb-2" data-type="new">
            <input type="hidden" name="attachment_ids[]" value="0">
            <div class="col-md-5">
                <input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="${GRN_I18N.documentName}">
            </div>
            <div class="col-md-6">
                <input type="file" class="form-control form-control-sm" name="attachments[]">
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeAttachmentRow(this)" title="${GRN_I18N.remove}">
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

function updateGRN() {
    if (!validateForm()) return;
    
    const formData = new FormData($('#grnForm')[0]);
    
    // Add items as JSON
    const items = [];
    $('[id^="item-row-"]').each(function() {
        const index = $(this).data('index');
        const item = {
            product_id: $(this).find('.item-product-id').val(),
            purchase_order_item_id: $(this).find('.item-po-item-id').val(),
            quantity_received: $(this).find('.item-quantity').val(),
            unit_price: $(this).find('.item-price').val(),
            unit: $(this).find('.item-unit').val(),
            batch_number: $(this).find('.item-batch').val(),
            expiry_date: $(this).find('.item-expiry').val()
        };
        if (item.product_id) items.push(item);
    });
    formData.append('items', JSON.stringify(items));

    Swal.fire({
        title: GRN_I18N.updatingGrn,
        text: GRN_I18N.pleaseWait,
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.ajax({
        url: '<?= getUrl('api/update_grn.php') ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: GRN_I18N.updated,
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    const returnUrl = $('#returnUrlHidden').val();
                    window.location.href = (returnUrl && returnUrl.length > 5) ? returnUrl : '<?= getUrl('grn_view') ?>?id=' + response.receipt_id;
                });
            } else {
                Swal.fire({ icon: 'error', title: GRN_I18N.updateFailed, text: response.message });
            }
        },
        error: function(xhr, status, error) {
            console.error('Update Error:', error, xhr.responseText);
            Swal.fire({
                icon: 'error',
                title: GRN_I18N.serverError,
                text: GRN_I18N.couldNotConnectApi
            });
        }
    });
}

function saveAsDraft() {
    if (!validateForm(true)) return;
    updateGRN(); // Same logic, status is handled by form or separate logic if needed
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

