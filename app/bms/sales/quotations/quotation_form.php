<?php
// File: app/bms/sales/quotations/quotation_form.php
// Shared quotation create/edit form body. Reads/writes the `quotations` table.
// Included by quotation_create.php (create mode) and quotation_edit.php (edit mode).
// A quotation is the first document issued to a customer: no PO reference,
// no stock blocking, no "switch to sales order".
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('sales_orders');

global $pdo;

$user_id      = $_SESSION['user_id'];
$quotation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit      = ($quotation_id > 0);

$customer_id = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$project_id  = isset($_GET['project'])  ? intval($_GET['project'])  : 0;

// Load existing quotation when editing.
$quotation      = null;
$existing_items = [];
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM quotations WHERE sales_order_id = ?");
    $stmt->execute([$quotation_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quotation) {
        header("Location: " . getUrl('quotations') . "?error=Quotation Not Found");
        exit();
    }
    // An approved quotation is locked — it can no longer be edited.
    if (($quotation['status'] ?? '') === 'approved') {
        header("Location: " . getUrl('quotation_view') . "?id=" . $quotation_id);
        exit();
    }
    $customer_id = $quotation['customer_id'];
    $project_id  = $quotation['project_id'] ?: $project_id;

    $stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE order_id = ?");
    $stmt->execute([$quotation_id]);
    $existing_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$customer = null;
if ($customer_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ? AND status != 'inactive'");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Dropdown data.
$customers   = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
$salespeople = $pdo->query("SELECT user_id, username, CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE is_active = '1' AND role IN ('Admin', 'Manager', 'Sales') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$warehouses  = $pdo->query("SELECT warehouse_id, warehouse_name, project_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

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

$payment_terms = [
    'cod'     => 'Cash on Delivery',
    '7_days'  => '7 Days',
    '15_days' => '15 Days',
    '30_days' => '30 Days',
    '60_days' => '60 Days',
    '90_days' => '90 Days',
    'cash'    => 'Immediate Payment',
];

$currencies = [
    'TZS' => 'Tanzanian Shilling',
    'USD' => 'US Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'KES' => 'Kenyan Shilling',
];

if (!function_exists('generate_quotation_number')) {
    function generate_quotation_number() {
        return 'QT-' . date('Ymd') . '-' . mt_rand(100, 999);
    }
}

$back_project_id = $project_id > 0 ? $project_id : ($quotation['project_id'] ?? 0);

// Pre-compute whether the saved payment term is one of the presets.
$terms_found = false;
$saved_payment_terms = $quotation['payment_terms'] ?? '';
foreach ($payment_terms as $pt_value => $pt_label) {
    if ($quotation && $quotation['payment_terms'] == $pt_value) { $terms_found = true; break; }
}
$show_dropdown  = !($quotation && !$terms_found && !empty($saved_payment_terms));
$show_text_wrap = ($quotation && !$terms_found && !empty($saved_payment_terms));

$page_title = $is_edit ? ('Edit Quotation #' . $quotation['order_number']) : 'Create Quotation';
includeHeader();
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('quotations') ?>">Quotations</a></li>
            <li class="breadcrumb-item active"><?= $is_edit ? 'Edit Quotation' : 'Create Quotation' ?></li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-file-earmark-text text-primary"></i> <?= $is_edit ? 'Edit Quotation' : 'Create Quotation' ?></h2>
                    <p class="text-muted mb-0"><?= $is_edit ? 'Update existing quotation details' : 'Create a quotation for customer approval' ?></p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($enable_projects && $back_project_id > 0): ?>
                    <a href="<?= getUrl('project_view') ?>?id=<?= $back_project_id ?>" class="btn btn-outline-primary">
                        <i class="bi bi-kanban"></i> Back to Project
                    </a>
                    <?php endif; ?>
                    <a href="<?= getUrl('quotations') ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> Back to Quotations
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-dark"><i class="bi bi-file-text"></i> Quotation Details</h5>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="is_service_order" name="is_service_order" onchange="toggleServiceOrderMode()">
                <label class="form-check-label fw-bold text-primary" for="is_service_order">
                    <i class="bi bi-box-seam me-1"></i> Service Quotation (Non-Inventory)
                </label>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>

            <form id="quotationForm">
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label for="order_number" class="form-label">Quotation # <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="order_number" name="order_number"
                               value="<?= $quotation ? safe_output($quotation['order_number']) : generate_quotation_number() ?>" required readonly>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="order_date" class="form-label">Quotation Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="order_date" name="order_date"
                               value="<?= $quotation ? $quotation['order_date'] : date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="delivery_date" class="form-label">Delivery Date</label>
                        <input type="date" class="form-control" id="delivery_date" name="delivery_date"
                               value="<?= $quotation ? $quotation['delivery_date'] : '' ?>">
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="<?= $enable_projects ? 'col-md-4' : 'col-md-6' ?> mb-3">
                        <label for="customer_id" class="form-label">Customer <span class="text-danger">*</span></label>
                        <select class="form-select" id="customer_id" name="customer_id" required onchange="loadCustomerInfo()">
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?= $cust['customer_id'] ?>"
                                    <?= ($customer_id > 0 && $cust['customer_id'] == $customer_id) ? 'selected' : '' ?>>
                                    <?= safe_output($cust['customer_name']) ?>
                                    <?php if (!empty($cust['company_name'])): ?>
                                        (<?= safe_output($cust['company_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="<?= $enable_projects ? 'col-md-4' : 'col-md-6' ?> mb-3">
                        <label for="salesperson_id" class="form-label">Salesperson</label>
                        <select class="form-select" id="salesperson_id" name="salesperson_id">
                            <option value="">Select Salesperson</option>
                            <?php foreach ($salespeople as $salesperson): ?>
                                <option value="<?= $salesperson['user_id'] ?>"
                                    <?= ($quotation && isset($quotation['salesperson_id']) && $quotation['salesperson_id'] == $salesperson['user_id']) ? 'selected' : ((!$quotation && $user_id == $salesperson['user_id']) ? 'selected' : '') ?>>
                                    <?= safe_output($salesperson['username']) ?>
                                    <?php if (!empty($salesperson['full_name'])): ?>
                                        (<?= safe_output($salesperson['full_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($enable_projects): ?>
                    <div class="col-md-4 mb-3" id="project_container">
                        <label for="project_id" class="form-label">Project</label>
                        <select class="form-select" id="project_id" name="project_id" onchange="filterWarehousesByProject()">
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>"
                                    <?= (($quotation && isset($quotation['project_id']) && $quotation['project_id'] == $proj['project_id']) || ($project_id == $proj['project_id'])) ? 'selected' : '' ?>>
                                    <?= safe_output($proj['project_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Customer Information Card -->
                <div class="card mb-4" id="customerInfoCard" style="display: none;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-person-circle"></i> Customer Information</h6>
                    </div>
                    <div class="card-body" id="customerInfoBody"></div>
                </div>

                <!-- Financial Information -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <label for="currency" class="form-label">Currency <span class="text-danger">*</span></label>
                        <select class="form-select" id="currency" name="currency" required>
                            <?php foreach ($currencies as $code => $name): ?>
                                <option value="<?= $code ?>"
                                    <?= ($customer && isset($customer['currency']) && $customer['currency'] == $code) ? 'selected' : '' ?>
                                    <?= (!$customer && $code == 'TZS') ? 'selected' : '' ?>>
                                    <?= $code ?> - <?= $name ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3 mb-3" id="warehouse_container">
                        <label for="warehouse_id" class="form-label">Warehouse / Delivery Point <span class="text-danger">*</span></label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                            <option value="">Select Warehouse</option>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= $w['warehouse_id'] ?>"
                                        data-project-id="<?= $w['project_id'] ?? '' ?>"
                                        <?= ($quotation && $quotation['warehouse_id'] == $w['warehouse_id']) ? 'selected' : '' ?>>
                                    <?= safe_output($w['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label for="payment_terms_select" class="form-label">Payment Terms</label>
                        <select class="form-select" id="payment_terms_select" name="payment_terms"
                                onchange="toggleCustomPaymentTerms()"
                                style="<?= $show_dropdown ? '' : 'display:none;' ?>">
                            <option value="">Select Terms</option>
                            <?php foreach ($payment_terms as $value => $label):
                                $selected = ($quotation && $quotation['payment_terms'] == $value);
                            ?>
                                <option value="<?= $value ?>" <?= $selected ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                            <option value="other">Other (specify)</option>
                        </select>
                        <div id="payment_terms_text_wrap" class="input-group"
                             style="<?= $show_text_wrap ? '' : 'display:none;' ?>">
                            <input type="text" class="form-control" id="payment_terms_text" name="payment_terms"
                                   value="<?= $show_text_wrap ? safe_output($saved_payment_terms) : '' ?>"
                                   placeholder="e.g. 45 Days, End of Month...">
                            <button type="button" class="btn btn-outline-secondary" title="Back to dropdown"
                                    onclick="revertPaymentTermsToDropdown()">
                                <i class="bi bi-arrow-left-circle"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label for="reference" class="form-label">Customer Reference</label>
                        <input type="text" class="form-control" id="reference" name="reference"
                               value="<?= $quotation ? safe_output($quotation['reference']) : '' ?>"
                               placeholder="Customer enquiry / reference">
                    </div>

                    <div class="col-md-3 mb-3">
                        <label for="valid_until" class="form-label">Valid Until</label>
                        <input type="date" class="form-control" id="valid_until" name="valid_until"
                               min="<?= date('Y-m-d') ?>"
                               value="<?= $quotation ? ($quotation['quote_valid_until'] ?? '') : date('Y-m-d', strtotime('+30 days')) ?>">
                    </div>
                </div>

                <!-- Quotation Items -->
                <div class="card mb-4">
                    <div class="card-header bg-light border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 text-dark"><i class="bi bi-list-check"></i> Quotation Items</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="itemsTable">
                                <thead class="bg-light">
                                    <tr>
                                        <th width="5%">S/NO</th>
                                        <th width="30%" id="col-product">Product / Service <span class="text-danger">*</span></th>
                                        <th width="10%" id="col-sku" class="inv-col">SKU</th>
                                        <th width="10%" id="col-qty" class="inv-col">Quantity <span class="text-danger">*</span></th>
                                        <th width="10%" id="col-unit" class="inv-col">Unit</th>
                                        <th width="15%" id="col-price" class="price-col-header">Unit Price <span class="text-danger">*</span></th>
                                        <th width="10%" id="col-tax" class="inv-col">Tax Rate</th>
                                        <th width="10%" id="col-discount" class="inv-col">Discount %</th>
                                        <th width="10%" id="col-total" class="item-total-col">Total</th>
                                        <th width="5%"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody"></tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="10">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <button type="button" class="btn btn-sm btn-primary" id="addItemBtn" onclick="addItemRow()">
                                                        <i class="bi bi-plus-circle"></i> <span id="addItemBtnText">Add Item</span>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2 inv-col" onclick="scanBarcode()">
                                                        <i class="bi bi-upc-scan"></i> Scan Barcode
                                                    </button>
                                                </div>
                                                <div class="text-end">
                                                    <strong>Total Items: <span id="totalItems">0</span></strong><br>
                                                    <strong id="totalSummaryLabel">Total Quantity: <span id="totalQuantity">0.000</span></strong>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Notes & Summary -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-chat-left-text"></i> Notes & Terms</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Quotation Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4"
                                              placeholder="Special instructions or additional information"><?= $quotation ? safe_output($quotation['notes']) : '' ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="terms_conditions" class="form-label">Terms & Conditions</label>
                                    <textarea class="form-control" id="terms_conditions" name="terms_conditions" rows="3"
                                              placeholder="Payment terms, delivery terms, warranty, etc."><?= $quotation ? safe_output($quotation['terms_conditions']) : '' ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-calculator"></i> Quotation Summary</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Subtotal:</span>
                                    <span id="subtotal">0.00</span>
                                    <span class="currency-symbol">TZS</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Tax:</span>
                                    <span id="tax-total">0.00</span>
                                    <span class="currency-symbol">TZS</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Discount <small>(<span id="discount-percent">0</span>%)</small>:</span>
                                    <span id="discount-total">0.00</span>
                                    <span class="currency-symbol">TZS</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Shipping:</span>
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <span class="input-group-text currency-symbol">TZS</span>
                                        <input type="number" class="form-control" id="shipping_cost" name="shipping_cost"
                                               min="0" step="0.01" value="<?= $quotation ? $quotation['shipping_cost'] : '0' ?>" onchange="calculateTotals()">
                                    </div>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between fw-bold fs-5">
                                    <span>Grand Total:</span>
                                    <span id="grand-total">0.00</span>
                                    <span class="currency-symbol">TZS</span>
                                </div>
                                <div class="mt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="apply_tax" name="apply_tax" <?= ($quotation && $quotation['tax_amount'] > 0) || !$quotation ? 'checked' : '' ?> onchange="calculateTotals()">
                                        <label class="form-check-label" for="apply_tax">Apply Tax</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="apply_discount" name="apply_discount" <?= ($quotation && $quotation['discount_amount'] > 0) ? 'checked' : '' ?> onchange="calculateTotals()">
                                        <label class="form-check-label" for="apply_discount">Apply Order Discount</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hidden fields -->
                <input type="hidden" name="quotation_id" value="<?= $quotation_id ?>">
                <input type="hidden" name="created_by" value="<?= $user_id ?>">
                <input type="hidden" id="subtotal_hidden" name="subtotal" value="0">
                <input type="hidden" id="tax_hidden" name="tax_amount" value="0">
                <input type="hidden" id="discount_hidden" name="discount_amount" value="0">
                <input type="hidden" id="grand_total_hidden" name="grand_total" value="0">

                <!-- Form Actions -->
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-info text-white" onclick="saveQuotationFinal()">
                            <i class="bi bi-file-text"></i> Save Quotation
                        </button>
                    </div>
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
                <tr id="search-header-row">
                    <th id="search-col-product">Product</th>
                    <th id="search-col-sku" class="inv-col">SKU</th>
                    <th id="search-col-stock" class="inv-col">Stock</th>
                    <th id="search-col-price">Price</th>
                    <th id="search-col-tax">Tax</th>
                </tr>
            </thead>
            <tbody id="productsSearchBody"></tbody>
        </table>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-upc-scan"></i> Barcode Scanner</h5>
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
                <div id="barcodeResult" class="d-none"></div>
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
let taxRates = [
    { rate_id: 0, rate_name: 'No Tax', rate_percentage: 0 },
    { rate_id: 1, rate_name: 'VAT 18%', rate_percentage: 18 },
    { rate_id: 2, rate_name: 'Reduced 5%', rate_percentage: 5 }
];
const QUOTATION_IS_EDIT = <?= $is_edit ? 'true' : 'false' ?>;
const QUOTATION_STATUS  = '<?= $is_edit ? ($quotation['status'] ?? 'draft') : 'pending' ?>';

$(document).ready(function() {
    logReportAction('View Quotation Form', 'User opened the quotation <?= $is_edit ? 'edit' : 'create' ?> page');

    <?php if (!$is_edit): ?>
    addItemRow();
    <?php endif; ?>

    if ($('#customer_id').val()) {
        loadCustomerInfo(true);
    }

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.item-name, #productSearchResults').length) {
            $('#productSearchResults').hide();
        }
    });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') { $('#productSearchResults').hide(); }
    });

    <?php if ($is_edit && !empty($existing_items)): ?>
    loadQuoteItems(<?= json_encode($existing_items) ?>);
    <?php endif; ?>

    $('#quotationForm').on('submit', function(e) {
        e.preventDefault();
        saveQuotationFinal();
    });

    $('#barcodeScannerModal').on('shown.bs.modal', function() { $('#barcodeInput').focus(); });
    $('#barcodeInput').on('keypress', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); handleBarcodeInput($(this).val()); }
    });

    $('#currency').change(function() { updateCurrencySymbol(); });

    $(document).on('input', '.item-quantity, .item-price, .item-tax, .item-discount', function() {
        const index = $(this).closest('tr').data('index');
        calculateItemTotal(index);
        calculateTotals();
    });

    $('#warehouse_id').on('change', function() {
        toggleProductInputs($(this).val());
    });

    filterWarehousesByProject(true);
    loadTaxRates();
});

function filterWarehousesByProject(isInitial = false) {
    const projectId = $('#project_id').val();
    const warehouseSelect = $('#warehouse_id');

    warehouseSelect.find('option').each(function() {
        const optionProjectId = $(this).data('project-id');
        if ($(this).val() === '') { $(this).show(); return; }
        if (projectId) {
            (String(optionProjectId) === String(projectId)) ? $(this).show() : $(this).hide();
        } else {
            (!optionProjectId) ? $(this).show() : $(this).hide();
        }
    });

    if (!isInitial) {
        const sel = warehouseSelect.find('option:selected');
        if (sel.css('display') === 'none') {
            warehouseSelect.val('');
            toggleProductInputs('');
        }
    }
}

function toggleCustomPaymentTerms() {
    const val = $('#payment_terms_select').val();
    if (val === 'other') {
        $('#payment_terms_select').removeAttr('name').hide();
        $('#payment_terms_text_wrap').show();
        $('#payment_terms_text').val('').focus();
    } else {
        $('#payment_terms_select').attr('name', 'payment_terms').show();
        $('#payment_terms_text_wrap').hide();
        $('#payment_terms_text').val('');
    }
}

function revertPaymentTermsToDropdown() {
    $('#payment_terms_select').val('').attr('name', 'payment_terms').show();
    $('#payment_terms_text_wrap').hide();
    $('#payment_terms_text').val('');
}

function loadTaxRates() {
    $.ajax({
        url: '<?= getUrl('/api/account/get_tax_rates.php') ?>',
        type: 'GET',
        success: function(response) {
            if (response.success) { taxRates = response.data; }
        },
        error: function() { /* keep defaults */ }
    });
}

function addItemRow(product = null) {
    const index = itemCount++;
    const currency = $('#currency').val();

    const html = `
        <tr id="item-row-${index}" data-index="${index}">
            <td class="item-serial text-center fw-bold text-muted"></td>
            <td>
                <div class="input-group">
                    <input type="text" class="form-control item-name"
                           name="items[${index}][product_name]"
                           placeholder="Type to search product/service..." required
                           oninput="openProductSearch(${index}, this.value)"
                           onclick="openProductSearch(${index}, this.value)"
                           style="cursor: text; background-color: #fff;"
                           autocomplete="off"
                           value="${product ? (product.product_name || '') : ''}">
                    <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch(${index})">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <input type="hidden" class="item-product-id" name="items[${index}][product_id]"
                       value="${product && product.product_id != null ? product.product_id : ''}">
            </td>
            <td class="inv-col">
                <input type="text" class="form-control item-sku" name="items[${index}][sku]"
                       placeholder="SKU" readonly value="${product ? (product.sku || '') : ''}">
            </td>
            <td class="inv-col">
                <input type="number" class="form-control item-quantity" name="items[${index}][quantity]"
                       min="0.001" step="0.001" value="${product && product.quantity != null ? product.quantity : 1}" required>
            </td>
            <td class="inv-col">
                <select class="form-select item-unit" name="items[${index}][unit]">
                    <option value="pcs">pcs</option>
                    <option value="kg">kg</option>
                    <option value="g">g</option>
                    <option value="l">l</option>
                    <option value="ml">ml</option>
                    <option value="m">m</option>
                    <option value="box">box</option>
                    <option value="carton">carton</option>
                </select>
            </td>
            <td>
                <div class="input-group">
                    <span class="input-group-text currency-symbol">${currency}</span>
                    <input type="number" class="form-control item-price" name="items[${index}][unit_price]"
                           min="0" step="0.01" value="${product ? (product.unit_price != null ? product.unit_price : (product.selling_price || 0)) : 0}" required>
                </div>
            </td>
            <td class="inv-col">
                <select class="form-select item-tax" name="items[${index}][tax_rate]">
                    ${taxRates.map(rate => `<option value="${rate.rate_percentage}">${rate.rate_name} (${rate.rate_percentage}%)</option>`).join('')}
                </select>
            </td>
            <td class="inv-col">
                <div class="input-group">
                    <input type="number" class="form-control item-discount" name="items[${index}][discount_percent]"
                           min="0" max="100" step="0.01" value="${product ? (product.discount_percent || 0) : 0}">
                    <span class="input-group-text">%</span>
                </div>
            </td>
            <td class="item-total-col">
                <span class="item-total">0.00</span>
                <span class="ms-1 currency-symbol">${currency}</span>
            </td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeItemRow(${index})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;

    const $row = $(html);
    $('#itemsBody').append($row);

    if (product) {
        const unitVal = product.unit || 'pcs';
        if (!$row.find(`.item-unit option[value="${unitVal}"]`).length) {
            $row.find('.item-unit').append(`<option value="${unitVal}">${unitVal}</option>`);
        }
        $row.find('.item-unit').val(unitVal);
        if (product.tax_rate != null) { $row.find('.item-tax').val(product.tax_rate); }
    }

    calculateItemTotal(index);
    calculateTotals();
    updateSerialNumbers();
    toggleProductInputs($('#warehouse_id').val());

    if ($('#is_service_order').is(':checked')) {
        $(`#item-row-${index} .inv-col`).hide();
        $(`#item-row-${index} .item-total-col`).hide();
    }
    return index;
}

function updateSerialNumbers() {
    $('#itemsBody tr').each(function(i) {
        $(this).find('.item-serial').text(i + 1);
    });
}

function toggleServiceOrderMode() {
    const isService = $('#is_service_order').is(':checked');
    if (isService) {
        $('#warehouse_container').hide();
        $('#warehouse_id').prop('required', false).val('');
        $('.price-col-header').text('Amount');
        $('#addItemBtnText').text('Add Service');
        $('#totalSummaryLabel').html('Total Amount: <span id="totalAmountSummary">0.00</span> <span class="currency-symbol">' + $('#currency').val() + '</span>');
    } else {
        $('#warehouse_container').show();
        $('#warehouse_id').prop('required', true);
        $('.price-col-header').text('Unit Price');
        $('#addItemBtnText').text('Add Item');
        $('#totalSummaryLabel').html('Total Quantity: <span id="totalQuantity">0.000</span>');
    }
    toggleProductInputs($('#warehouse_id').val() || (isService ? 'SERVICE' : ''));
}

function toggleProductInputs(warehouseId) {
    const isService = $('#is_service_order').is(':checked');
    const isEnabled = (warehouseId && warehouseId !== "") || isService;
    $('.item-name').prop('disabled', !isEnabled);
    $('.item-name').attr('placeholder', isEnabled ? 'Type to search product/service...' : 'Select warehouse first...');
}

let searchTimer;
function openProductSearch(index, term) {
    const isService = $('#is_service_order').is(':checked');
    const warehouseId = $('#warehouse_id').val();

    if (!warehouseId && !isService) {
        Swal.fire({ icon: 'warning', title: 'Select Warehouse', text: 'Please select a warehouse / delivery point before searching for products.' });
        return;
    }

    currentItemIndex = index;
    const input = $(`#item-row-${index} .item-name`);
    const offset = input.offset();
    $('#productSearchResults').css({
        top: offset.top + input.outerHeight() + 2,
        left: offset.left,
        width: Math.max(input.outerWidth() * 1.5, 600)
    });

    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { searchProducts(term); }, 300);
}

function searchProducts(term = '') {
    const tbody = $('#productsSearchBody');
    const warehouseId = $('#warehouse_id').val();
    const isService = $('#is_service_order').is(':checked');

    $('#productSearchResults').show();
    isService ? $('#productSearchResults .inv-col').hide() : $('#productSearchResults .inv-col').show();

    tbody.html('<tr><td colspan="' + (isService ? '3' : '5') + '" class="text-center p-3"><div class="spinner-border spinner-border-sm text-primary"></div> Searching...</td></tr>');

    $.ajax({
        url: '<?= getUrl('/api/account/get_products.php') ?>',
        type: 'GET',
        data: {
            active_only: true,
            warehouse_id: isService ? '' : warehouseId,
            project_id: isService ? ($('#project_id').val() || '0') : '',
            is_service: isService ? 1 : 0,
            search: term,
            is_selling: true
        },
        dataType: 'json',
        success: function(response) {
            tbody.empty();
            if (response.success && response.data && response.data.length > 0) {
                productsCache = response.data;
                response.data.forEach(product => {
                    tbody.append(`
                        <tr onclick="selectProduct(${product.product_id})" style="cursor: pointer;">
                            <td>
                                <strong>${product.product_name}</strong><br>
                                <small class="text-muted ${isService ? 'd-none' : ''}">${product.sku || 'No SKU'}</small>
                            </td>
                            <td class="inv-col">${product.sku || 'N/A'}</td>
                            <td class="inv-col"><span class="badge ${parseFloat(product.current_stock) > 0 ? 'bg-success' : 'bg-danger'}">${product.current_stock || 0}</span></td>
                            <td>${formatCurrency(product.selling_price || 0)}</td>
                            <td>${product.tax_rate || 0}%</td>
                        </tr>
                    `);
                });
                if (isService) { $('#productSearchResults .inv-col').hide(); }
            } else {
                tbody.append(`<tr><td colspan="${isService ? '3' : '5'}" class="text-center text-danger p-3">No ${isService ? 'services' : 'products'} matching "${term}"</td></tr>`);
            }
        },
        error: function() {
            tbody.html('<tr><td colspan="5" class="text-center text-danger p-3">Error fetching products</td></tr>');
        }
    });
}

function selectProduct(productId) {
    const product = productsCache.find(p => p.product_id == productId);
    if (product) {
        const row = $(`#item-row-${currentItemIndex}`);
        row.find('.item-name').val(product.product_name);
        row.find('.item-product-id').val(product.product_id);
        row.find('.item-sku').val(product.sku || '');
        row.find('.item-unit').val(product.unit || 'pcs');
        row.find('.item-price').val(product.selling_price || 0);
        row.find('.item-tax').val(product.tax_rate || 0);
        $('#productSearchResults').hide();
        calculateItemTotal(currentItemIndex);
        calculateTotals();
        row.find('.item-quantity').focus();
    }
}

function calculateItemTotal(index) {
    const row = $(`#item-row-${index}`);
    const quantity = parseFloat(row.find('.item-quantity').val()) || 0;
    const price = parseFloat(row.find('.item-price').val()) || 0;
    const taxRate = parseFloat(row.find('.item-tax').val()) || 0;
    const discountPercent = parseFloat(row.find('.item-discount').val()) || 0;

    const subtotal = quantity * price;
    const discountAmount = subtotal * (discountPercent / 100);
    const taxableAmount = subtotal - discountAmount;
    const taxAmount = taxableAmount * (taxRate / 100);
    row.find('.item-total').text((taxableAmount + taxAmount).toFixed(2));
}

function calculateTotals() {
    let subtotal = 0, taxTotal = 0, discountTotal = 0, totalItems = 0, totalQuantity = 0;
    const applyTax = $('#apply_tax').is(':checked');
    const applyDiscount = $('#apply_discount').is(':checked');

    $('[id^="item-row-"]').each(function() {
        const quantity = parseFloat($(this).find('.item-quantity').val()) || 0;
        const price = parseFloat($(this).find('.item-price').val()) || 0;
        const taxRate = applyTax ? parseFloat($(this).find('.item-tax').val()) || 0 : 0;
        const discountPercent = applyDiscount ? parseFloat($(this).find('.item-discount').val()) || 0 : 0;

        const itemSubtotal = quantity * price;
        const itemDiscount = itemSubtotal * (discountPercent / 100);
        const taxableAmount = itemSubtotal - itemDiscount;
        const itemTax = taxableAmount * (taxRate / 100);

        subtotal += itemSubtotal;
        discountTotal += itemDiscount;
        taxTotal += itemTax;
        totalItems++;
        totalQuantity += quantity;
    });

    const shippingCost = parseFloat($('#shipping_cost').val()) || 0;
    const grandTotal = subtotal - discountTotal + taxTotal + shippingCost;

    $('#subtotal').text(subtotal.toFixed(2));
    $('#tax-total').text(taxTotal.toFixed(2));
    $('#discount-total').text(discountTotal.toFixed(2));
    $('#grand-total').text(grandTotal.toFixed(2));
    $('#totalItems').text(totalItems);
    $('#totalQuantity').text(totalQuantity.toFixed(3));
    if ($('#is_service_order').is(':checked')) { $('#totalAmountSummary').text(subtotal.toFixed(2)); }

    $('#discount-percent').text(subtotal > 0 ? (discountTotal / subtotal * 100).toFixed(1) : 0);

    $('#subtotal_hidden').val(subtotal.toFixed(2));
    $('#tax_hidden').val(taxTotal.toFixed(2));
    $('#discount_hidden').val(discountTotal.toFixed(2));
    $('#grand_total_hidden').val(grandTotal.toFixed(2));
}

function removeItemRow(index) {
    $(`#item-row-${index}`).remove();
    calculateTotals();
    updateSerialNumbers();
}

function updateCurrencySymbol() {
    $('.currency-symbol').text($('#currency').val());
}

function loadCustomerInfo(isInitialLoad = false) {
    const customerId = $('#customer_id').val();
    if (!customerId) { $('#customerInfoCard').hide(); return; }

    $.ajax({
        url: '<?= getUrl('/api/account/get_customer.php') ?>',
        type: 'GET',
        data: { id: customerId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const customer = response.data;
                $('#customerInfoBody').html(`
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>${customer.customer_name}</strong></p>
                            ${customer.company_name ? `<p>Company: ${customer.company_name}</p>` : ''}
                            ${customer.phone ? `<p>Phone: ${customer.phone}</p>` : ''}
                            ${customer.email ? `<p>Email: ${customer.email}</p>` : ''}
                        </div>
                        <div class="col-md-6">
                            ${customer.address ? `<p>Address: ${customer.address}</p>` : ''}
                            ${customer.city ? `<p>City: ${customer.city}</p>` : ''}
                            ${customer.country ? `<p>Country: ${customer.country}</p>` : ''}
                        </div>
                    </div>
                `);
                $('#customerInfoCard').show();
            }
        },
        error: function() { /* non-fatal */ }
    });
}

function formatCurrency(amount) {
    const currency = $('#currency').val();
    const number = parseFloat(amount);
    return currency + ' ' + (isNaN(number) ? '0.00' : number.toFixed(2));
}

function loadQuoteItems(quoteItems) {
    $('#itemsBody').empty();
    itemCount = 0;
    quoteItems.forEach(item => { addItemRow(item); });
}

function scanBarcode() {
    $('#barcodeScannerModal').modal('show');
}

function handleBarcodeInput(barcode) {
    if (!barcode.trim()) return;
    const product = productsCache.find(p => p.barcode && p.barcode === barcode);
    if (product) {
        $('#barcodeResult').removeClass('d-none').html(`
            <div class="alert alert-success">
                <strong>Product Found:</strong> ${product.product_name}<br>
                <small>SKU: ${product.sku || 'N/A'} | Price: ${formatCurrency(product.selling_price || 0)}</small>
            </div>`);
        const index = addItemRow(product);
        $('#barcodeScannerModal').modal('hide');
        setTimeout(() => { $(`#item-row-${index} .item-quantity`).focus(); }, 100);
    } else {
        $('#barcodeResult').removeClass('d-none').html(`
            <div class="alert alert-warning">
                <strong>Product Not Found</strong><br>
                <small>Barcode "${barcode}" not found in database.</small>
            </div>`);
    }
    $('#barcodeInput').val('');
}

function addScannedItem() {
    const barcode = $('#barcodeInput').val();
    if (barcode) { handleBarcodeInput(barcode); }
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, function(m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
}

function collectItems() {
    const items = [];
    $('[id^="item-row-"]').each(function() {
        const item = {
            product_id: $(this).find('.item-product-id').val(),
            product_name: $(this).find('.item-name').val(),
            sku: $(this).find('.item-sku').val(),
            quantity: $(this).find('.item-quantity').val(),
            unit: $(this).find('.item-unit').val(),
            unit_price: $(this).find('.item-price').val(),
            tax_rate: $(this).find('.item-tax').val(),
            discount_percent: $(this).find('.item-discount').val()
        };
        if (item.product_name && item.quantity) { items.push(item); }
    });
    return items;
}

function saveQuotation(status) {
    if (!validateForm(status === 'draft')) { return; }

    const formData = new FormData($('#quotationForm')[0]);
    formData.append('status', status);
    formData.append('items', JSON.stringify(collectItems()));

    const submitBtns = $('#quotationForm button');
    submitBtns.prop('disabled', true);

    $.ajax({
        url: '<?= getUrl('/api/account/save_quotation.php') ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        timeout: 30000,
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: response.message,
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'OK'
                }).then(() => {
                    window.location.href = '<?= getUrl('quotations') ?>';
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to save quotation.' });
                submitBtns.prop('disabled', false);
            }
        },
        error: function(xhr, status) {
            let errorMsg = 'An error occurred. Please try again.';
            if (status === 'timeout') {
                errorMsg = 'Request timed out. Please check your connection and try again.';
            } else if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message;
            } else if (xhr.responseText) {
                try { const r = JSON.parse(xhr.responseText); if (r.message) errorMsg = r.message; } catch (e) {}
            }
            Swal.fire({ icon: 'error', title: 'Error', text: errorMsg });
            submitBtns.prop('disabled', false);
        }
    });
}

function saveQuotationFinal() {
    // Status is decided server-side: a new quotation enters at 'pending';
    // editing an existing one never changes its workflow status.
    saveQuotation('pending');
}

function validateForm(isDraft = false) {
    if ($('[id^="item-row-"]').length === 0) {
        Swal.fire({ icon: 'warning', title: 'No Items', text: 'Please add at least one item to the quotation.' });
        return false;
    }

    let hasValidItems = false;
    $('[id^="item-row-"]').each(function() {
        const productName = $(this).find('.item-name').val();
        const quantity = $(this).find('.item-quantity').val();
        const price = $(this).find('.item-price').val();
        if (productName && quantity > 0 && price >= 0) { hasValidItems = true; }
    });
    if (!hasValidItems) {
        Swal.fire({ icon: 'warning', title: 'Invalid Items', text: 'Please ensure all items have a name, quantity, and price.' });
        return false;
    }

    const requiredFields = ['order_number', 'order_date', 'customer_id'];
    for (const field of requiredFields) {
        if (!$(`#${field}`).val() && !isDraft) {
            Swal.fire({ icon: 'warning', title: 'Missing Information', text: `Please fill in the ${field.replace('_', ' ')} field.` });
            $(`#${field}`).focus();
            return false;
        }
    }
    return true;
}
</script>

<style>
.card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); background-color: white !important; border: 1px solid #dee2e6 !important; }
.card-header.bg-light { background-color: #f8f9fa !important; }
.table th { font-weight: 600; font-size: 0.9rem; }
#itemsTable input, #itemsTable select { font-size: 0.85rem; }
#itemsTable .form-control { padding: 0.25rem 0.5rem; }
.item-total { font-weight: bold; color: #198754; }
.currency-symbol { font-weight: bold; color: #495057; }
.product-search-results {
    position: absolute; background: white; z-index: 9999; max-height: 400px;
    overflow-y: auto; border-radius: 8px; display: none;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
}
.product-search-results table thead th { position: sticky; top: 0; background: #f8f9fa; z-index: 10; }
.product-search-results tr { cursor: pointer; transition: all 0.2s; }
.product-search-results tr:hover { background-color: #e9ecef !important; }
#grand-total { font-size: 1.5rem; color: #198754; }
#barcodeScannerModal .modal-body { min-height: 200px; }
@media (max-width: 768px) {
    .container-fluid { padding: 0.5rem; }
    .card-body { padding: 1rem; }
    .table-responsive { font-size: 0.85rem; }
    #itemsTable th, #itemsTable td { padding: 0.5rem; }
}
</style>

<?php includeFooter(); ?>
