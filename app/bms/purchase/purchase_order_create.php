<?php
// File: purchase_order_create.php
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('purchase_orders');

includeHeader();

// Check if supplier or project is provided (optional deep link)
$supplier_id = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
// Handle both 'project' and 'project_id' for better compatibility
$project_id = isset($_GET['project']) ? intval($_GET['project']) : (isset($_GET['project_id']) ? intval($_GET['project_id']) : 0);

// Dependencies from PDO
global $pdo;

// Get warehouse locations
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, IFNULL(project_id,0) as project_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for dropdown (initial load, though search is preferred for large lists)
$suppliers = $pdo->query("SELECT supplier_id, supplier_name, company_name, currency, payment_terms FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

// Get tax rates
$tax_rates = $pdo->query("SELECT * FROM tax_rates WHERE status = 'active' ORDER BY rate_percentage")->fetchAll(PDO::FETCH_ASSOC);

// Get shipping methods
$shipping_methods = $pdo->query("SELECT * FROM shipping_methods WHERE status = 'active' ORDER BY method_name")->fetchAll(PDO::FETCH_ASSOC);

$currencies = [
    'TZS' => 'Tanzanian Shilling',
    'USD' => 'US Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'KES' => 'Kenyan Shilling'
];

// Get projects if enabled
$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

$projects = [];
if ($enable_projects) {
    try {
        $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Check for edit mode
$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$is_edit = $edit_id > 0;
$po_data = null;
$po_items = [];
$po_attachments = [];

if ($is_edit) {
    // Fetch PO main data
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE purchase_order_id = ?");
    $stmt->execute([$edit_id]);
    $po_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($po_data) {
        // Override initial IDs for dropdown selections
        $supplier_id = $po_data['supplier_id'];
        $project_id  = $po_data['project_id'];
        
        // Fetch Items with Tax IDs (matching by percentage since table doesn't store rate_id)
        $stmt = $pdo->prepare("
            SELECT poi.*, tr.rate_id as tax_rate_id 
            FROM purchase_order_items poi 
            LEFT JOIN tax_rates tr ON poi.tax_rate = tr.rate_percentage AND tr.status = 'active'
            WHERE poi.purchase_order_id = ? 
            ORDER BY poi.item_id
        ");
        $stmt->execute([$edit_id]);
        $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Attachments
        $stmt = $pdo->prepare("SELECT * FROM purchase_order_attachments WHERE purchase_order_id = ?");
        $stmt->execute([$edit_id]);
        $po_attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $is_edit = false;
        $edit_id = 0;
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs & Header -->
    <nav aria-label="breadcrumb" class="mb-3 po-create-sticky-nav">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('purchase_orders') ?>">Purchase Orders</a></li>
            <li class="breadcrumb-item active"><?= $is_edit ? 'Edit Order' : 'New Order' ?></li>
        </ol>
    </nav>

    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-2">
                <div>
                    <h2 class="fw-bold">
                        <i class="bi <?= $is_edit ? 'bi-pencil-square' : 'bi-cart-plus' ?> text-primary"></i>
                        <?= $is_edit ? 'Edit Purchase Order' : 'Create Purchase Order' ?>
                    </h2>
                    <p class="text-muted mb-0"><?= $is_edit ? 'Update existing purchase order details' : 'Issue a new purchase request to a supplier' ?></p>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <?php if ($project_id > 0 && $enable_projects): ?>
                    <a href="<?= getUrl('project_view') ?>?id=<?= $project_id ?>" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left"></i> Back to Project
                    </a>
                    <?php endif; ?>
                    <a href="<?= getUrl('purchase_orders') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Creation Form -->
    <form id="purchaseOrderForm">
        <input type="hidden" name="purchase_order_id" value="<?= $edit_id ?>">
            <!-- Left Column: Order Details -->
            <div class="col-lg-12">
                <div class="card mb-4 shadow-sm">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i> Basic Information</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <!-- 1. Supplier -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
                                <select class="form-select select2-static" id="supplier_id" name="supplier_id" required>
                                    <option value="">Select a supplier</option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?= $s['supplier_id'] ?>"
                                            <?= $supplier_id == $s['supplier_id'] ? 'selected' : '' ?>
                                            data-currency="<?= $s['currency'] ?>"
                                            data-terms="<?= $s['payment_terms'] ?>">
                                            <?= htmlspecialchars($s['supplier_name']) ?> (<?= htmlspecialchars($s['company_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 2. Project (Optional) -->
                            <?php if ($enable_projects): ?>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Project <span class="text-muted small fw-normal">(Optional)</span></label>
                                <select class="form-select select2-static" id="project_id" name="project_id">
                                    <option value="">No Project</option>
                                    <?php foreach ($projects as $proj): ?>
                                        <option value="<?= $proj['project_id'] ?>"
                                            <?= $project_id == $proj['project_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($proj['project_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <!-- 3. Warehouse -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Warehouse / Delivery Point <span class="text-danger">*</span></label>
                                <select class="form-select select2-static" id="warehouse_id" name="warehouse_id" required>
                                    <option value="">Select Warehouse</option>
                                    <?php foreach ($warehouses as $w): ?>
                                        <option value="<?= $w['warehouse_id'] ?>" 
                                            data-project="<?= $w['project_id'] ?>"
                                            <?= ($is_edit && $po_data['warehouse_id'] == $w['warehouse_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($w['warehouse_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Row 2: RFQ, Order Date, Expected Delivery -->
                            <div class="col-md-4" id="rfq_ref_div">
                                <label class="form-label fw-semibold">RFQ Reference <span class="text-success small fw-normal">(Optional — leave blank to skip)</span></label>
                                <select class="form-select select2-static" id="rfq_reference" name="rfq_reference">
                                    <option value="">Select RFQ (Optional)</option>
                                    <?php if ($is_edit && $po_data['rfq_id']): ?>
                                        <?php 
                                            $rfq_stmt = $pdo->prepare("SELECT rfq_number, rfq_date FROM rfq WHERE rfq_id = ?");
                                            $rfq_stmt->execute([$po_data['rfq_id']]);
                                            $rfq_info = $rfq_stmt->fetch();
                                        ?>
                                        <option value="<?= $po_data['rfq_id'] ?>" selected>
                                            <?= htmlspecialchars($rfq_info['rfq_number'] ?? '') ?> (<?= htmlspecialchars($rfq_info['rfq_date'] ?? '') ?>)
                                        </option>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text text-muted">RFQs matching selection</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Order Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="order_date" value="<?= $is_edit ? $po_data['order_date'] : date('Y-m-d') ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Expected Delivery</label>
                                <input type="date" class="form-control" name="expected_delivery_date" value="<?= ($is_edit && isset($po_data['expected_date'])) ? $po_data['expected_date'] : '' ?>">
                            </div>

                            <!-- Row 3: Currency & Payment Terms -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Currency <span class="text-danger">*</span></label>
                                <select class="form-select select2-static" id="currency" name="currency" required>
                                    <?php foreach ($currencies as $code => $name): ?>
                                        <option value="<?= $code ?>" <?= ($is_edit && $po_data['currency'] == $code) ? 'selected' : '' ?>>
                                            <?= $code ?> - <?= $name ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Payment Terms</label>
                                <select class="form-select" id="payment_terms" name="payment_terms" onchange="togglePaymentTermsOther(this.value)">
                                    <option value="immediate" <?= ($is_edit && $po_data['payment_terms'] == 'immediate') ? 'selected' : '' ?>>Immediate</option>
                                    <option value="net_15" <?= ($is_edit && $po_data['payment_terms'] == 'net_15') ? 'selected' : '' ?>>Net 15 Days</option>
                                    <option value="net_30" <?= ($is_edit && $po_data['payment_terms'] == 'net_30') ? 'selected' : '' ?>>Net 30 Days</option>
                                    <option value="net_60" <?= ($is_edit && $po_data['payment_terms'] == 'net_60') ? 'selected' : '' ?>>Net 60 Days</option>
                                    <option value="other" <?= ($is_edit && !in_array($po_data['payment_terms'] ?? '', ['immediate','net_15','net_30','net_60'])) ? 'selected' : '' ?>>Other (Specify)</option>
                                </select>
                                <div id="payment_terms_custom_div" class="input-group mt-1 <?= ($is_edit && !in_array($po_data['payment_terms'] ?? '', ['immediate','net_15','net_30','net_60'])) ? '' : 'd-none' ?>">
                                    <input type="text" class="form-control" id="payment_terms_input" name="payment_terms_custom" 
                                           value="<?= $is_edit ? htmlspecialchars($po_data['payment_terms'] ?? '') : '' ?>" placeholder="e.g. Net 45 Days, 50% Advance">
                                    <button type="button" class="btn btn-outline-secondary" onclick="resetPaymentTerms()" title="Back to list"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Section -->
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-light border-bottom py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i> Order Items</h5>
                            
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="itemsTable">
                                <thead class="bg-light text-uppercase small fw-bold">
                                    <tr>
                                        <th style="width: 50px;">S/NO</th>
                                        <th style="min-width: 300px;">Product / Service</th>
                                        <th style="width: 150px;">Quantity</th>
                                        <th style="width: 200px;">Unit Price</th>
                                        <th style="width: 200px;">Tax Rate</th>                                        
                                        <th class="text-end" style="width: 150px;">Total</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <?php if ($is_edit && count($po_items) > 0): ?>
                                        <?php foreach ($po_items as $index => $item): 
                                            $rowId = 'row_edit_' . $index;
                                        ?>
                                            <tr id="<?= $rowId ?>">
                                                <td class="serial-number text-center fw-bold text-muted"><?= $index + 1 ?></td>
                                                <td>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control product-selector" required
                                                               oninput="openProductSearch('<?= $rowId ?>', this.value)"
                                                               onclick="openProductSearch('<?= $rowId ?>', this.value)"
                                                               style="cursor:text;background:#fff;" autocomplete="off"
                                                               value="<?= htmlspecialchars($item['item_name']) ?>">
                                                        <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch('<?= $rowId ?>')">
                                                            <i class="bi bi-search"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" class="item-product-id" name="productId" value="<?= $item['product_id'] ?>">
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control qty-input" name="qty" 
                                                           value="<?= $item['quantity'] ?>" min="0.001" step="0.001" 
                                                           oninput="calculateRowTotal('<?= $rowId ?>')" required>
                                                </td>
                                                <td>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light cur-symbol">TSh </span>
                                                        <input type="number" class="form-control price-input" name="price" value="<?= $item['unit_price'] ?>" min="0" step="0.01" oninput="calculateRowTotal('<?= $rowId ?>')" required>
                                                    </div>
                                                </td>
                                                <td>
                                                    <select class="form-select tax-selector" name="taxId" onchange="calculateRowTotal('<?= $rowId ?>')">
                                                        <option value="0" data-rate="0">No Tax (0%)</option>
                                                        <?php foreach ($tax_rates as $tr): ?>
                                                            <option value="<?= $tr['rate_id'] ?>" data-rate="<?= $tr['rate_percentage'] ?>" 
                                                                <?= ($item['tax_rate_id'] == $tr['rate_id']) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($tr['rate_name']) ?> (<?= $tr['rate_percentage'] ?>%)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="text-end fw-bold"><span class="row-total"><?= number_format($item['line_total'], 2, '.', '') ?></span></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-outline-danger btn-sm border-0"
                                                        onclick="$('#<?= $rowId ?>').remove(); updateSerialNumbers(); calculateGrandTotal();">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-light">
                                        <td colspan="5" class="text-end fw-bold">Subtotal:</td>
                                        <td class="text-end fw-bold pt-3" id="subtotal_display">TSh 0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                 <button type="button" class="btn btn-primary btn-sm" onclick="addItemRow()">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>

                <!-- Shipping & Notes Section -->
                <div class="row">
                    <div class="col-md-7">
                        <div class="card mb-4 shadow-sm">
                            <div class="card-header bg-light py-3">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2"></i> Notes & Terms</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Internal Notes</label>
                                    <textarea class="form-control" name="notes" rows="3" placeholder="Notes for internal team..."><?= $is_edit ? htmlspecialchars($po_data['notes'] ?? '') : '' ?></textarea>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label fw-semibold">Terms & Conditions</label>
                                    <textarea class="form-control" name="terms_conditions" rows="3" placeholder="Standard PO terms..."><?= $is_edit ? htmlspecialchars($po_data['terms_conditions'] ?? '') : '' ?></textarea>
                                </div>
                                <div class="mb-0 mt-3">
                                    <label class="form-label fw-semibold mb-2">Order Attachments <span class="text-muted small fw-normal">(Optional)</span></label>
                                    
                                    <div id="attachments-container" class="border rounded p-3 bg-light">
                                        <div id="attachment-fields">
                                            <?php if ($is_edit && count($po_attachments) > 0): ?>
                                                <?php foreach ($po_attachments as $att): ?>
                                                    <div class="row g-2 attachment-row mb-2 align-items-center">
                                                        <div class="col-md-5">
                                                            <input type="text" class="form-control form-control-sm" name="attachment_names[]" value="<?= htmlspecialchars($att['file_name']) ?>" placeholder="Document Name">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="custom-file-input-wrapper">
                                                                <label class="input-group input-group-sm mb-0 cursor-pointer">
                                                                    <span class="input-group-text bg-light border-end-0">Choose File</span>
                                                                    <div class="form-control form-control-sm file-display-name text-truncate small text-muted bg-white border-start-0">
                                                                        <i class="bi bi-file-earmark-check text-success me-1"></i> <?= htmlspecialchars(basename($att['file_path'])) ?>
                                                                    </div>
                                                                    <input type="file" class="d-none actual-file-input" name="attachments[]" onchange="handleFileSelect(this)">
                                                                </label>
                                                                <input type="hidden" name="existing_attachments[]" value="<?= $att['attachment_id'] ?>">
                                                            </div>
                                                        </div>
                                                    <div class="col-md-1 text-end">
                                                        <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeAttachmentRow(this)" title="Remove">
                                                            <i class="bi bi-trash fs-5"></i>
                                                        </button>
                                                    </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>

                                            <!-- Always show at least one blank row if no attachments exist -->
                                            <?php if (!$is_edit || count($po_attachments) == 0): ?>
                                            <div class="row g-2 attachment-row mb-2">
                                                <div class="col-md-5">
                                                    <input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Document Name (e.g. Contract, Specs)">
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
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="addAttachmentRow()">
                                            <i class="bi bi-plus-circle me-1"></i> Add Attachment
                                        </button>
                                    </div>
                                    <div class="form-text text-muted mt-2">Accepted: PDF, DOC, DOCX, JPG, PNG (max 10MB each). Saved to Document Library.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="card shadow-sm border-primary">
                            <div class="card-header bg-primary text-white py-3">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-calculator me-2"></i> Order Summary</h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between mb-3 text-muted">
                                    <span>Items Subtotal</span>
                                    <span id="summary-subtotal">0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-3 text-muted">
                                    <span>Total Tax</span>
                                    <span id="summary-tax">0.00</span>
                                </div>
                                <div class="row mb-3 align-items-center">
                                    <div class="col-6 text-muted">Shipping Cost</div>
                                    <div class="col-6">
                                        <input type="number" step="0.01" class="form-control form-control-sm text-end" 
                                               id="shipping_cost" name="shipping_cost" value="<?= $is_edit ? $po_data['shipping_cost'] : '0.00' ?>" oninput="calculateGrandTotal()">
                                    </div>
                                </div>
                                <hr class="my-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="mb-0 fw-bold text-dark">Total Value</h4>
                                    <h4 class="mb-0 fw-bold text-primary" id="summary-grand-total">TSh 0.00</h4>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-success btn-lg shadow-sm">
                                <i class="bi bi-check2-all me-2"></i> <?= $is_edit ? 'Update Purchase Order' : 'Create Purchase Order' ?>
                            </button>
                            <button type="button" class="btn btn-outline-primary" onclick="window.saveDraft()">
                                <i class="bi bi-save me-2"></i> Save as Draft
                            </button>
                            <a href="<?= getUrl('purchase_orders') ?>" class="btn btn-link text-decoration-none text-muted">
                                Cancel and return
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
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

<!-- Scripts Section -->
<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let productsList = [];
const editId = <?= json_encode($edit_id) ?>;
const isEdit = <?= json_encode($is_edit) ?>;

// ── Global Functions ──────────────────────────────────────────────

function handleFileSelect(input) {
    validateFileSize(input);
    if (input.files && input.files[0]) {
        const fileName = input.files[0].name;
        // Use a more robust selector to find the display name in the same row
        $(input).closest('.attachment-row, .custom-file-input-wrapper').find('.file-display-name').html('<i class="bi bi-file-earmark-plus text-primary me-1"></i> ' + fileName);
    }
}

function addAttachmentRow() {
    const rowId = 'attach_' + Date.now();
    const html = `
        <div class="attachment-row mb-2" id="${rowId}">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Document Name (e.g. Contract, Specs)">
                </div>
                <div class="col-md-6">
                    <div class="custom-file-input-wrapper">
                        <label class="input-group input-group-sm mb-0 cursor-pointer">
                            <span class="input-group-text bg-light border-end-0">Choose File</span>
                            <div class="form-control form-control-sm file-display-name text-truncate small text-muted bg-white border-start-0">No file chosen</div>
                            <input type="file" class="d-none actual-file-input" name="attachments[]" onchange="handleFileSelect(this)">
                        </label>
                        <input type="hidden" name="existing_attachments[]" value="">
                    </div>
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="$('#${rowId}').remove()" title="Remove">
                        <i class="bi bi-trash fs-5"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    $('#attachment-fields').append(html);
}

function removeAttachmentRow(btn) {
    $(btn).closest('.attachment-row').remove();
}

// ── Select2 helpers ───────────────────────────────────────────────
function initSelect2Fields() {
    $('.select2-static').each(function() {
        const $el = $(this);
        if ($el.data('select2')) return;
        $el.select2({
            theme: 'bootstrap-5',
            placeholder: $el.find('option:first').text() || 'Select...',
            allowClear: true,
            width: '100%'
        });
    });
}

function initTaxSelect2(rowId) {
    const $sel = rowId ? $(`#${rowId} .tax-selector`) : $('.tax-selector');
    $sel.each(function() {
        if ($(this).data('select2')) return;
        $(this).select2({
            theme: 'bootstrap-5',
            dropdownParent: $('body'),
            allowClear: false,
            width: '100%',
            minimumResultsForSearch: Infinity
        });
    });
}

function reinitRfqSelect2() {
    const $rfq = $('#rfq_reference');
    if ($rfq.data('select2')) $rfq.select2('destroy');
    $rfq.select2({
        theme: 'bootstrap-5',
        placeholder: 'Select RFQ (Optional)',
        allowClear: true,
        width: '100%'
    });
}

$(document).ready(function() {
    // RFQ Reference is always optional — remove any required attribute that may have been set
    $('#rfq_reference').removeAttr('required');

    // Initialise Select2 on all static dropdowns
    initSelect2Fields();
    // Initialise Select2 on any pre-rendered edit row tax selectors
    initTaxSelect2();

    // Move inline onchange handlers to jQuery so Select2 fires them
    $('#project_id').on('change', function() {
        filterWarehousesByProject($(this).val());
        loadRFQs();
    });

    if (isEdit) {
        $('h2').html('<i class="bi bi-pencil-square text-primary"></i> Edit Purchase Order');
        $('button[type="submit"]').html('<i class="bi bi-save me-2"></i> Update Order');
    }

    // Initial load
    fetchProducts().then(() => {
        if (!isEdit) {
            logReportAction('Viewed Purchase Order Create Page', 'User opened the create purchase order page');
            addItemRow();
        } else {
            calculateGrandTotal();
        }
    });

    // Auto-update currency when supplier changes + reload RFQs
    $('#supplier_id').on('change', function() {
        const option = $(this).find('option:selected');
        if (option.val()) {
            $('#currency').val(option.data('currency') || 'TZS');

            // Set payment terms from supplier
            const terms = option.data('terms');
            const standardTerms = ['immediate', 'net_15', 'net_30', 'net_60'];
            if (terms && !standardTerms.includes(terms)) {
                applyCustomPaymentTerms(terms);
            } else {
                resetPaymentTerms();
                if (terms) $('#payment_terms').val(terms);
            }
            updateCurSymbols();
        }
        loadRFQs();
    });

    // Reload RFQs when warehouse or project changes
    $('#warehouse_id').on('change', loadRFQs);
    $('#project_id').on('change', loadRFQs);

    // Re-fetch products filtered to selected warehouse
    $('#warehouse_id').on('change', function() {
        productsList = [];
        fetchProducts();
    });

    $('#currency').on('change', updateCurSymbols);

    // Hide search results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.product-selector, #productSearchResults').length) {
            $('#productSearchResults').hide();
        }
    });

    // Handle ESC key to hide search results
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#productSearchResults').hide();
        }
    });

    // Form submission
    $('#purchaseOrderForm').on('submit', function(e) {
        e.preventDefault();
        saveOrder('pending');
    });
});

// ── RFQ Reference helpers ──────────────────────────────────────────────
function loadRFQs() {
    const supplierId  = $('#supplier_id').val()  || '';
    const warehouseId = $('#warehouse_id').val()  || '';
    const projectId   = $('#project_id').val()    || '';

    // Need at least one filter to avoid loading all RFQs unfiltered
    if (!supplierId && !warehouseId && !projectId) {
        $('#rfq_reference').html('<option value="">Select RFQ (Optional)</option>');
        reinitRfqSelect2();
        return;
    }

    const params = { status_group: 'po_ready' };
    if (supplierId)  params.supplier  = supplierId;
    if (warehouseId) params.warehouse = warehouseId;
    if (projectId)   params.project   = projectId;

    $.getJSON('<?= getUrl("api/get_rfqs") ?>', params, function(res) {
        const data = res.data || [];
        if (!data.length) {
            $('#rfq_reference').html('<option value="">Select RFQ (Optional)</option>');
            reinitRfqSelect2();
            return;
        }
        let opts = '<option value="">Select RFQ (Optional)</option>';
        data.forEach(r => {
            const parts = [r.rfq_number, r.rfq_date];
            if (!supplierId && r.supplier_name) parts.push(r.supplier_name);
            if (!warehouseId && r.warehouse_name) parts.push(r.warehouse_name);
            const statusBadge = r.status ? ' [' + r.status + ']' : '';
            opts += `<option value="${r.rfq_id}"
                data-project="${r.project_id || ''}"
                data-warehouse="${r.warehouse_id || ''}"
                data-warehouse-name="${r.warehouse_name || ''}"
                data-project-name="${r.project_name || ''}"
                data-supplier="${r.supplier_id || ''}">${parts.join(' \u2014 ')}${statusBadge}</option>`;
        });
        $('#rfq_reference').html(opts);
        reinitRfqSelect2();
    }).fail(function() {
        $('#rfq_reference').html('<option value="">Select RFQ (Optional)</option>');
        reinitRfqSelect2();
    });
}

$(document).on('change', '#rfq_reference', function() {
    const rfqId = $(this).val();
    if (!rfqId) {
        $('#itemsBody').empty();
        addItemRow();
        return;
    }

    Swal.fire({
        title: 'Loading RFQ Items...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.getJSON('<?= getUrl("api/get_rfq_items") ?>', { rfq_id: rfqId }, function(res) {
        Swal.close();
        if (!res.success) {
            Swal.fire('Error', res.message || 'Failed to load items', 'error');
            return;
        }
        if (!res.items || res.items.length === 0) {
            Swal.fire('Info', 'All items in this RFQ have already been fully ordered or no items were found.', 'info');
            return;
        }
        $('#itemsBody').empty();
            res.items.forEach(function(item) {
                const matched     = productsList.find(p =>
                    p.product_name &&
                    p.product_name.toLowerCase().trim() === (item.description || '').toLowerCase().trim()
                );
                const rowId       = 'row_' + Date.now() + Math.random().toString(36).substr(2, 5);
                const productName = matched ? matched.product_name : item.description;
                const unitSuffix  = item.unit ? ` (${item.unit})` : '';
                const displayDesc = productName + unitSuffix;
                const productId   = matched ? matched.product_id   : '';
                const price       = matched
                    ? (parseFloat(matched.cost_price) || parseFloat(matched.purchase_price) || 0).toFixed(2)
                    : '0.00';
                const taxOpts = `<option value="0" data-rate="0">No Tax (0%)</option><?php foreach ($tax_rates as $tr): ?><option value="<?= $tr['rate_id'] ?>" data-rate="<?= $tr['rate_percentage'] ?>"><?= htmlspecialchars($tr['rate_name']) ?> (<?= $tr['rate_percentage'] ?>%)</option><?php endforeach; ?>`;
                $('#itemsBody').append(`
                <tr id="${rowId}">
                    <td class="serial-number text-center fw-bold text-muted"></td>
                    <td>
                        <div class="input-group">
                            <input type="text" class="form-control product-selector" placeholder="Type to search product..." required
                                   oninput="openProductSearch('${rowId}', this.value)"
                                   onclick="openProductSearch('${rowId}', this.value)"
                                   style="cursor:text;background:#fff;" autocomplete="off"
                                   value="${displayDesc}">
                            <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch('${rowId}')">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <input type="hidden" class="item-product-id" name="productId" value="${productId}">
                    </td>
                    <td>
                        <input type="number" class="form-control qty-input" name="qty" 
                               value="${item.remaining_qty || item.requested_qty || 1}" 
                               min="0.001" max="${item.remaining_qty || ''}" step="0.001" 
                               oninput="validateRfqQty(this); calculateRowTotal('${rowId}')" required>
                        <div class="form-text text-muted small mt-1">
                            Req: ${item.requested_qty} | <span class="text-primary fw-bold">Rem: ${item.remaining_qty}</span>
                        </div>
                    </td>
                    <td>
                        <div class="input-group">
                            <span class="input-group-text bg-light cur-symbol">TSh </span>
                            <input type="number" class="form-control price-input" name="price" value="${price}" min="0" step="0.01" oninput="calculateRowTotal('${rowId}')" required>
                        </div>
                    </td>
                    <td>
                        <select class="form-select tax-selector" name="taxId" onchange="calculateRowTotal('${rowId}')">
                            <option value="0" data-rate="0">No Tax (0%)</option>${taxOpts}
                        </select>
                    </td>
                    <td class="text-end fw-bold"><span class="row-total">0.00</span></td>
                    <td class="text-center">
                        <button type="button" class="btn btn-outline-danger btn-sm border-0"
                            onclick="$('#${rowId}').remove(); updateSerialNumbers(); calculateGrandTotal();">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>`);
                calculateRowTotal(rowId);
            });
            updateSerialNumbers();
            calculateGrandTotal();
            initTaxSelect2();
            Toast.fire({
                icon: 'success',
                title: res.items.length + ' item(s) loaded from RFQ'
            });
        }).fail(function() { 
            Swal.close();
            Swal.fire('Error', 'Could not reach the server.', 'error');
        });
});

// Warehouse filter by project
const allWarehouses = <?= json_encode(array_map(fn($w)=>['warehouse_id'=>$w['warehouse_id'],'warehouse_name'=>$w['warehouse_name'],'project_id'=>(int)$w['project_id']],$warehouses)) ?>;

function filterWarehousesByProject(projectId) {
    const $wSel = $('#warehouse_id');
    if ($wSel.data('select2')) $wSel.select2('destroy');

    const sel = document.getElementById('warehouse_id');
    const currentVal = sel.value;
    sel.innerHTML = '<option value="">Select Warehouse</option>';
    let filtered;
    if (!projectId || projectId === '') {
        filtered = allWarehouses.filter(w => !w.project_id || w.project_id === 0);
    } else {
        filtered = allWarehouses.filter(w => w.project_id == projectId);
    }
    filtered.forEach(w => {
        const opt = document.createElement('option');
        opt.value = w.warehouse_id;
        opt.setAttribute('data-project', w.project_id);
        opt.textContent = w.warehouse_name;
        if (w.warehouse_id == currentVal) opt.selected = true;
        sel.appendChild(opt);
    });

    $wSel.select2({
        theme: 'bootstrap-5',
        placeholder: 'Select Warehouse',
        allowClear: true,
        width: '100%'
    });
}

// Run on page load - filter warehouses based on pre-selected project
$(document).ready(function(){
    <?php if ($enable_projects): ?>
    filterWarehousesByProject($('#project_id').val());
    <?php else: ?>
    filterWarehousesByProject('');
    <?php endif; ?>
});



function validateRfqQty(input) {
    const max = parseFloat(input.getAttribute('max'));
    const val = parseFloat(input.value);
    if (max > 0 && val > max) {
        Swal.fire({
            icon: 'warning',
            title: 'Limit Exceeded',
            text: `This item has only ${max} units remaining in the RFQ. You cannot order ${val}.`,
            confirmButtonColor: '#0d6efd'
        });
    }
}
function validateFileSize(input) {
    if (input.files && input.files[0]) {
        const f = input.files[0];
        if (f.size > 10 * 1024 * 1024) {
            Swal.fire({icon:'warning',title:'File Too Large',text:'Maximum file size is 10MB.',confirmButtonColor:'#0d6efd',confirmButtonText:'OK'});
            input.value = '';
            return;
        }
    }
}

async function fetchProducts() {
    const warehouseId = $('#warehouse_id').val();
    if (!warehouseId) {
        productsList = [];
        return;
    }
    try {
        const response = await fetch('<?= buildUrl('api/account/get_products.php') ?>?limit=1000&is_service=0&warehouse_id=' + warehouseId);
        const result = await response.json();
        if (result.success) {
            productsList = result.data;
        }
    } catch (error) {
        console.error('Failed to fetch products:', error);
    }
}

window.togglePaymentTermsOther = function(val) {
    if (val === 'other') {
        $('#payment_terms').prop('disabled', true).addClass('d-none');
        $('#payment_terms_custom_div').removeClass('d-none');
        $('#payment_terms_input').focus();
    }
};

window.resetPaymentTerms = function() {
    $('#payment_terms_custom_div').addClass('d-none');
    $('#payment_terms_input').val('');
    $('#payment_terms').prop('disabled', false).removeClass('d-none').val('net_30');
};

function applyCustomPaymentTerms(value) {
    $('#payment_terms').prop('disabled', true).addClass('d-none');
    $('#payment_terms_custom_div').removeClass('d-none');
    $('#payment_terms_input').val(value);
};

function updateCurSymbols() {
    const sym = $('#currency').val();
    $('.cur-symbol').text(sym + ' ');
    calculateGrandTotal();
}

function updateSerialNumbers() {
    $('#itemsBody tr').each(function(index) {
        $(this).find('.serial-number').text(index + 1);
    });
}

function addItemRow() {
    const rowId = 'row_' + Date.now();
    const html = `
        <tr id="${rowId}">
            <td class="serial-number text-center fw-bold text-muted"></td>
            <td>
                <div class="input-group">
                    <input type="text" class="form-control product-selector" 
                           placeholder="Type to search product..." required
                           oninput="openProductSearch('${rowId}', this.value)"
                           onclick="openProductSearch('${rowId}', this.value)"
                           style="cursor: text; background-color: #fff;"
                           autocomplete="off">
                    <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch('${rowId}')">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <input type="hidden" class="item-product-id" name="productId">
            </td>
            <td>
                <input type="number" class="form-control qty-input" name="qty" value="1" min="1" step="0.001" oninput="calculateRowTotal('${rowId}')" required>
            </td>
            <td>
                <div class="input-group">
                    <span class="input-group-text bg-light cur-symbol">TSh </span>
                    <input type="number" class="form-control price-input" name="price" value="0.00" min="0" step="0.01" oninput="calculateRowTotal('${rowId}')" required>
                </div>
            </td>
            <td>
                <select class="form-select tax-selector" name="taxId" onchange="calculateRowTotal('${rowId}')">
                    <option value="0" data-rate="0">No Tax (0%)</option>
                    <?php foreach ($tax_rates as $tr): ?>
                        <option value="<?= $tr['rate_id'] ?>" data-rate="<?= $tr['rate_percentage'] ?>">
                            <?= htmlspecialchars($tr['rate_name']) ?> (<?= $tr['rate_percentage'] ?>%)
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="text-end fw-bold">
                <span class="row-total">0.00</span>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="$('#${rowId}').remove(); updateSerialNumbers(); calculateGrandTotal();">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
    $('#itemsBody').append(html);
    updateSerialNumbers();
    initTaxSelect2(rowId);
}

function addEditItemRow(item) {
    const rowId = 'row_' + Date.now() + Math.random().toString(36).substr(2, 9);
    const html = `
        <tr id="${rowId}">
            <td class="serial-number text-center fw-bold text-muted"></td>
            <td>
                <div class="input-group">
                    <input type="text" class="form-control product-selector" 
                           placeholder="Type to search product..." required
                           oninput="openProductSearch('${rowId}', this.value)"
                           onclick="openProductSearch('${rowId}', this.value)"
                           style="cursor: text; background-color: #fff;"
                           autocomplete="off"
                           value="${item.product_name}">
                    <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch('${rowId}')">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <input type="hidden" class="item-product-id" name="productId" value="${item.product_id}">
            </td>
            <td>
                <input type="number" class="form-control qty-input" name="qty" value="${item.quantity}" min="1" step="0.001" oninput="calculateRowTotal('${rowId}')" required>
            </td>
            <td>
                <div class="input-group">
                    <span class="input-group-text bg-light cur-symbol">TSh </span>
                    <input type="number" class="form-control price-input" name="price" value="${item.unit_price}" min="0" step="0.01" oninput="calculateRowTotal('${rowId}')" required>
                </div>
            </td>
            <td>
                <select class="form-select tax-selector" name="taxId" onchange="calculateRowTotal('${rowId}')">
                    <option value="0" data-rate="0">No Tax (0%)</option>
                    <?php foreach ($tax_rates as $tr): ?>
                        <option value="<?= $tr['rate_id'] ?>" data-rate="<?= $tr['rate_percentage'] ?>" ${item.tax_rate_id == <?= $tr['rate_id'] ?> ? 'selected' : ''}>
                            <?= htmlspecialchars($tr['rate_name']) ?> (<?= $tr['rate_percentage'] ?>%)
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="text-end fw-bold">
                <span class="row-total">0.00</span>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="$('#${rowId}').remove(); updateSerialNumbers(); calculateGrandTotal();">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
    $('#itemsBody').append(html);
    updateSerialNumbers();
    calculateRowTotal(rowId);
    initTaxSelect2(rowId);
}

let currentRowId = null;

function openProductSearch(rowId, term = '') {
    // Block search if warehouse not selected
    if (!$('#warehouse_id').val()) {
        Swal.fire({
            icon: 'warning',
            title: 'Select Warehouse First',
            text: 'Please select a Warehouse / Delivery Point before searching for products.',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }
    currentRowId = rowId;
    const input = $(`#${rowId} .product-selector`);
    const offset = input.offset();
    
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
    let results = productsList;

    if (searchTerm.length > 0) {
        results = productsList.filter(p =>
            (p.product_name && p.product_name.toLowerCase().includes(searchTerm)) ||
            (p.sku && p.sku.toLowerCase().includes(searchTerm)) ||
            (p.barcode && p.barcode.toLowerCase().includes(searchTerm))
        );
    }

    if (results.length === 0) {
        tbody.append(`<tr><td colspan="4" class="text-center text-danger p-3">No products found</td></tr>`);
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
    const product = productsList.find(p => p.product_id == productId);
    if (product) {
        const row = $(`#${currentRowId}`);
        row.find('.product-selector').val(product.product_name);
        row.find('.item-product-id').val(product.product_id);
        
        let price = 0;
        if (parseFloat(product.cost_price) > 0) price = parseFloat(product.cost_price);
        else if (parseFloat(product.purchase_price) > 0) price = parseFloat(product.purchase_price);
        else if (parseFloat(product.selling_price) > 0) price = parseFloat(product.selling_price);

        row.find('.price-input').val(price.toFixed(2));
        $('#productSearchResults').hide();
        
        calculateRowTotal(currentRowId);
        row.find('.qty-input').focus();
    }
}

function calculateRowTotal(rowId) {
    const row = $('#' + rowId);
    const qty = parseFloat(row.find('.qty-input').val()) || 0;
    const price = parseFloat(row.find('.price-input').val()) || 0;
    const taxRate = parseFloat(row.find('.tax-selector option:selected').data('rate')) || 0;
    
    const subtotal = qty * price;
    const total = subtotal + (subtotal * (taxRate / 100));
    
    row.find('.row-total').text(total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let subtotal = 0;
    let taxTotal = 0;
    
    $('#itemsBody tr').each(function() {
        const qty = parseFloat($(this).find('.qty-input').val()) || 0;
        const price = parseFloat($(this).find('.price-input').val()) || 0;
        const taxRate = parseFloat($(this).find('.tax-selector option:selected').data('rate')) || 0;
        
        const lineSubtotal = qty * price;
        const lineTax = lineSubtotal * (taxRate / 100);
        
        subtotal += lineSubtotal;
        taxTotal += lineTax;
    });
    
    const shipping = parseFloat($('#shipping_cost').val()) || 0;
    const grand = subtotal + taxTotal + shipping;
    const cur = $('#currency').val();
    
    $('#subtotal_display').text(cur + ' ' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#summary-subtotal').text(subtotal.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#summary-tax').text(taxTotal.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#summary-grand-total').text(cur + ' ' + grand.toLocaleString(undefined, {minimumFractionDigits: 2}));
}

window.saveDraft = function() {
    saveOrder('draft');
}

function saveOrder(status) {
    const form = $('#purchaseOrderForm');
    
    // Simple validation
    if (!form[0].checkValidity()) {
        form[0].reportValidity();
        return;
    }

    const items = [];
    $('#itemsBody tr').each(function() {
        const row = $(this);
        const productId = row.find('.item-product-id').val();
        const productName = row.find('.product-selector').val();
        
        if (productName) {
            items.push({
                product_id: productId || null,
                product_name: productName,
                quantity: row.find('.qty-input').val(),
                unit_price: row.find('.price-input').val(),
                tax_rate_id: row.find('.tax-selector').val()
            });
        }
    });

    if (items.length === 0) {
        Swal.fire('Error', 'Please add at least one item', 'error');
        return;
    }

    const formData = new FormData(form[0]);
    formData.append('status', status);
    formData.append('items', JSON.stringify(items));

    Swal.fire({
        title: 'Saving Purchase Order...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    // Attach proforma file if selected
    const proformaInput = document.getElementById('proforma_file');
    if (proformaInput && proformaInput.files[0]) {
        formData.append('proforma_file', proformaInput.files[0]);
    }

    $.ajax({
        url: '<?= buildUrl('api/account/save_purchase_order.php') ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                const editId = $('#purchaseOrderForm').data('edit-id');
                const action = editId ? 'Updated Purchase Order' : 'Created Purchase Order';
                const description = editId ? 'User updated purchase order' : 'User created a new purchase order';
                logReportAction(action, description);
                
                Swal.fire({
                    icon: 'success',
                    title: editId ? 'Purchase Order Updated!' : 'Purchase Order Created!',
                    text: editId ? 'The purchase order has been successfully updated.' : 'The purchase order has been successfully created.',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'OK',
                    timer: 3000
                }).then(() => {
                    const projectId = <?= (int)($project_id ?? 0) ?>;
                    if (projectId > 0) {
                        window.location.href = '<?= getUrl('project_view') ?>?id=' + projectId;
                    } else {
                        window.location.href = '<?= getUrl('purchase_orders') ?>';
                    }
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Creation Failed',
                    text: response.message || 'An unknown error occurred while creating the purchase order.',
                    confirmButtonText: 'OK'
                });
            }
        },
        error: function() {
            Swal.fire({
                icon: 'error',
                title: 'System Error',
                text: 'A network or server error occurred. Please check your connection and try again.',
                confirmButtonText: 'OK'
            });
        }
    });
}


</script>

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

.table thead th {
    background-color: #f8f9fa !important;
}

/* Mobile: sticky breadcrumb */
@media (max-width: 767px) {
    .po-create-sticky-nav {
        position: sticky;
        top: 0;
        z-index: 1020;
        background: #fff;
        padding: 8px 0 4px;
        margin-bottom: 0.5rem !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.06);
    }
    /* Keep items table scrollable horizontally */
    #itemsTable {
        min-width: 700px;
    }
    /* Product search popup: full width on mobile */
    .product-search-results {
        left: 0 !important;
        right: 0 !important;
        width: auto !important;
        margin: 0 8px;
    }
    /* Summary card always full width on mobile */
    .col-md-5 {
        width: 100%;
    }
}
</style>

<?php 
includeFooter(); 
ob_end_flush();
?>