<?php
// File: sales_order_edit.php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Enforce permission
autoEnforcePermission('sales_orders');

// Get parameters
$sales_order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$quote_id = isset($_GET['quote']) ? intval($_GET['quote']) : 0;
$project_id = isset($_GET['project']) ? intval($_GET['project']) : 0;

$is_quote = false;

if ($sales_order_id <= 0) {
    header("Location: " . getUrl('sales_orders') . "?error=Invalid Order ID");
    exit();
}

assertScopeForRecordHtml('sales_orders', 'sales_order_id', $sales_order_id);

// Get current user info
$user_id = $_SESSION['user_id'];

// Fetch existing sales order if ID provided
$sales_order = null;
$existing_items = [];

try {
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE sales_order_id = ?");
    $stmt->execute([$sales_order_id]);
    $sales_order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sales_order) {
        header("Location: " . getUrl('sales_orders') . "?error=Order Not Found");
        exit();
    }

    $is_quote = $sales_order['is_quote'] == 1;
    $customer_id = $sales_order['customer_id'];
    $project_id = $sales_order['project_id'] ?: $project_id;
    
    // Get items
    $stmt = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id = ?");
    $stmt->execute([$sales_order_id]);
    $existing_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    header("Location: " . getUrl('sales_orders') . "?error=Database Error");
    exit();
}
$customer = null;
if ($customer_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ? AND status != 'inactive'");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get quote details if provided (conversion scenario)
$quote = null;
$quote_items = [];
if ($quote_id > 0 && !$sales_order) { // Only load quote if not editing an existing sales order
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE sales_order_id = ? AND is_quote = TRUE");
    $stmt->execute([$quote_id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($quote) {
        // Get quote items
        $stmt = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id = ?");
        $stmt->execute([$quote_id]);
        $quote_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If customer not provided, get from quote
        if (!$customer && $quote['customer_id']) {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
            $stmt->execute([$quote['customer_id']]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            $customer_id = $customer['customer_id'];
        }
        
        // Inherit project
        if (!$project_id) $project_id = $quote['project_id'];
    }
}

// Scope: assigned project IDs for current user
$_soe_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));

// Get customers for dropdown — scoped by project for non-admins
if (isAdmin()) {
    $customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_soe_assigned)) {
    $_soe_cph = implode(',', array_fill(0, count($_soe_assigned), '?'));
    $_soe_cstmt = $pdo->prepare("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_soe_cph)) ORDER BY customer_name");
    $_soe_cstmt->execute($_soe_assigned);
    $customers = $_soe_cstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' AND project_id IS NULL ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get salespeople for dropdown (not project-scoped — role-based only)
$salespeople = $pdo->query("SELECT user_id, username, CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE is_active = '1' AND role IN ('Admin', 'Manager', 'Sales') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Get warehouses — scoped by project for non-admins
if (isAdmin()) {
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, project_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_soe_assigned)) {
    $_soe_wph = implode(',', array_fill(0, count($_soe_assigned), '?'));
    $_soe_wstmt = $pdo->prepare("SELECT warehouse_id, warehouse_name, project_id FROM warehouses WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_soe_wph)) ORDER BY warehouse_name");
    $_soe_wstmt->execute($_soe_assigned);
    $warehouses = $_soe_wstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, project_id FROM warehouses WHERE status = 'active' AND project_id IS NULL ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Check projects setting
$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

$projects = [];
if ($enable_projects) {
    try {
        if (isAdmin()) {
            $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($_soe_assigned)) {
            $_soe_pph = implode(',', array_fill(0, count($_soe_assigned), '?'));
            $_soe_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($_soe_pph) ORDER BY project_name");
            $_soe_pstmt->execute($_soe_assigned);
            $projects = $_soe_pstmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// Get payment terms
$payment_terms = [
    'cod' => 'Cash on Delivery',
    '7_days' => '7 Days',
    '15_days' => '15 Days',
    '30_days' => '30 Days',
    '60_days' => '60 Days',
    '90_days' => '90 Days',
    'cash' => 'Immediate Payment'
];

// Get currency options
$currencies = [
    'TZS' => 'Tanzanian Shilling',
    'USD' => 'US Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'KES' => 'Kenyan Shilling'
];

// Helper functions removed, now in helpers.php

function generate_order_number($is_quote = false) {
    $prefix = $is_quote ? 'QT' : 'SO';
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    $random = mt_rand(100, 999);
    return $prefix . '-' . $year . $month . $day . '-' . $random;
}

// Quote context comes from the saved record (this page always edits an existing record)
$is_quote = ($sales_order['is_quote'] == 1);
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <?php if ($is_quote): ?>
            <li class="breadcrumb-item"><a href="<?= getUrl('quotations') ?>">Quotations</a></li>
            <li class="breadcrumb-item active"><?= $sales_order ? 'Edit Quotation' : 'Create Quotation' ?></li>
            <?php else: ?>
            <li class="breadcrumb-item"><a href="<?= getUrl('sales_orders') ?>">Sales Orders</a></li>
            <li class="breadcrumb-item active"><?= $sales_order ? 'Edit Sales Order' : 'Create Sales Order' ?></li>
            <?php endif; ?>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-pencil-square text-primary"></i> <?= $is_quote ? 'Edit Quotation' : 'Edit Sales Order' ?></h2>
                    <p class="text-muted mb-0">Update existing <?= $is_quote ? 'quotation' : 'sales order' ?> details</p>
                </div>
                <div class="d-flex gap-2">
                    <?php 
                    $back_project_id = $project_id > 0 ? $project_id : ($sales_order['project_id'] ?? 0);
                    if ($enable_projects && $back_project_id > 0): ?>
                    <a href="<?= getUrl('project_view') ?>?id=<?= $back_project_id ?>" class="btn btn-outline-primary">
                        <i class="bi bi-kanban"></i> Back to Project
                    </a>
                    <?php endif; ?>
                    <a href="<?= $is_quote ? getUrl('quotations') : getUrl('sales_orders') ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> <?= $is_quote ? 'Back to Quotations' : 'Back to Orders' ?>
                    </a>
                    <?php if ($is_quote): ?>
                    <a href="<?= getUrl('sales_order_create') ?>" class="btn btn-primary">
                        <i class="bi bi-cart"></i> Switch to Sales Order
                    </a>
                    <?php else: ?>
                    <a href="<?= getUrl('sales_order_create') ?>?quote=1" class="btn btn-primary">
                        <i class="bi bi-file-text"></i> Switch to Quotation
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light border-bottom">
            <h5 class="mb-0 text-dark"><i class="bi bi-file-text"></i> <?= $is_quote ? 'Quotation Details' : 'Order Details' ?></h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <form id="salesOrderForm">
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label for="order_number" class="form-label"><?= $is_quote ? 'Quotation #' : 'Order #' ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="order_number" name="order_number" 
                               value="<?= $sales_order ? safe_output($sales_order['order_number']) : generate_order_number($is_quote) ?>" required readonly>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="order_date" class="form-label"><?= $is_quote ? 'Quotation Date' : 'Order Date' ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="order_date" name="order_date" 
                               value="<?= $sales_order ? $sales_order['order_date'] : date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="delivery_date" class="form-label">Delivery Date</label>
                        <input type="date" class="form-control" id="delivery_date" name="delivery_date" 
                               value="<?= $sales_order ? $sales_order['delivery_date'] : '' ?>">
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="<?= $enable_projects ? 'col-md-4' : 'col-md-6' ?> mb-3">
                        <label for="customer_id" class="form-label">Customer <span class="text-danger">*</span></label>
                        <select class="form-select" id="customer_id" name="customer_id" required onchange="loadCustomerInfo()">
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?= $cust['customer_id'] ?>" 
                                    <?= ($customer_id > 0 && $cust['customer_id'] == $customer_id) ? 'selected' : '' ?>
                                    data-payment-terms="<?= $cust['payment_terms'] ?? '' ?>"
                                    data-currency="<?= $cust['currency'] ?? 'TZS' ?>"
                                    data-credit-limit="<?= $cust['credit_limit'] ?? 0 ?>">
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
                                    <?= ($sales_order && isset($sales_order['salesperson_id']) && $sales_order['salesperson_id'] == $salesperson['user_id']) ? 'selected' : ((!$sales_order && $user_id == $salesperson['user_id']) ? 'selected' : '') ?>>
                                    <?= safe_output($salesperson['username']) ?>
                                    <?php if (!empty($salesperson['full_name'])): ?>
                                        (<?= safe_output($salesperson['full_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($enable_projects): ?>
                    <div class="col-md-4 mb-3">
                        <label for="project_id" class="form-label">Project</label>
                        <select class="form-select" id="project_id" name="project_id" onchange="filterWarehousesByProject()">
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>"
                                    <?= (($sales_order && isset($sales_order['project_id']) && $sales_order['project_id'] == $proj['project_id']) || ($project_id == $proj['project_id'])) ? 'selected' : '' ?>>
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
                    <div class="card-body" id="customerInfoBody">
                        <!-- Customer info will be loaded here -->
                    </div>
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
                    
                    <div class="col-md-3 mb-3">
                        <label for="warehouse_id" class="form-label">Warehouse / Delivery Point <span class="text-danger">*</span></label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                            <option value="">Select Warehouse</option>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= $w['warehouse_id'] ?>" 
                                        data-project-id="<?= $w['project_id'] ?? '' ?>"
                                        <?= ($sales_order && $sales_order['warehouse_id'] == $w['warehouse_id']) ? 'selected' : '' ?>>
                                    <?= safe_output($w['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php
                    // Pre-compute $terms_found BEFORE rendering the HTML so the style attribute can use it
                    $terms_found = false;
                    $saved_payment_terms = $sales_order['payment_terms'] ?? '';
                    foreach ($payment_terms as $pt_value => $pt_label) {
                        if ($sales_order && $sales_order['payment_terms'] == $pt_value) {
                            $terms_found = true;
                            break;
                        }
                    }
                    $show_dropdown  = !($sales_order && !$terms_found && !empty($saved_payment_terms));
                    $show_text_wrap = ($sales_order && !$terms_found && !empty($saved_payment_terms));
                    ?>
                    <div class="col-md-3 mb-3">
                        <label for="payment_terms_select" class="form-label">Payment Terms</label>
                        <!-- Dropdown mode (default) -->
                        <select class="form-select" id="payment_terms_select" name="payment_terms"
                                onchange="toggleCustomPaymentTerms()"
                                style="<?= $show_dropdown ? '' : 'display:none;' ?>">
                            <option value="">Select Terms</option>
                            <?php foreach ($payment_terms as $value => $label):
                                $selected = ($sales_order && $sales_order['payment_terms'] == $value);
                            ?>
                                <option value="<?= $value ?>" <?= $selected ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="other">Other (specify)</option>
                        </select>
                        <!-- Text mode: shown in-place when Other/custom value is saved -->
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

                    <?php if (!$is_quote): /* A quotation is issued before the customer's PO — no PO reference */ ?>
                    <div class="col-md-3 mb-3">
                        <label for="po_no" class="form-label">PO No:</label>
                        <input type="text" class="form-control" id="po_no" name="po_no"
                               value="<?= $sales_order ? safe_output($sales_order['po_no'] ?? '') : '' ?>"
                               placeholder="Enter PO Number"
                               autocomplete="off">
                    </div>
                    <?php endif; ?>

                    <div class="col-md-3 mb-3">
                        <label for="reference" class="form-label">Customer Reference</label>
                        <input type="text" class="form-control" id="reference" name="reference" 
                               value="<?= $sales_order ? safe_output($sales_order['reference']) : '' ?>"
                               placeholder="Customer PO/Reference number">
                    </div>
                    
                    <?php if ($is_quote): ?>
                    <div class="col-md-3 mb-3">
                        <label for="valid_until" class="form-label">Valid Until</label>
                        <input type="date" class="form-control" id="valid_until" name="valid_until" 
                               min="<?= date('Y-m-d') ?>" value="<?= $sales_order ? ($sales_order['quote_valid_until'] ?? '') : date('Y-m-d', strtotime('+30 days')) ?>">
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Order Items -->
                <div class="card mb-4">
                    <div class="card-header bg-light border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 text-dark"><i class="bi bi-list-check"></i> Order Items</h6>
                        <div>
                        
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="itemsTable">
                                <thead class="bg-light">
                                    <tr>
                                        <th width="5%">S/NO</th>
                                        <th width="30%">Product/Item <span class="text-danger">*</span></th>
                                        <th width="10%">SKU</th>
                                        <th width="10%">Quantity <span class="text-danger">*</span></th>
                                        <th width="10%">Unit</th>
                                        <th width="15%">Unit Price <span class="text-danger">*</span></th>
                                        <th width="10%">Tax Rate</th>
                                        <th width="10%">Discount %</th>
                                        <th width="10%">Total</th>
                                        <th width="5%"></th>
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
                                                    <strong>Total Quantity: <span id="totalQuantity">0.000</span></strong>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Order Summary -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-chat-left-text"></i> Notes & Terms</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Order Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4" 
                                              placeholder="Special instructions, delivery notes, or additional information"><?= $sales_order ? safe_output($sales_order['notes']) : '' ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="terms_conditions" class="form-label">Terms & Conditions</label>
                                    <textarea class="form-control" id="terms_conditions" name="terms_conditions" rows="3" 
                                              placeholder="Payment terms, delivery terms, warranty, etc."><?= $sales_order ? safe_output($sales_order['terms_conditions']) : '' ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-calculator"></i> Order Summary</h6>
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
                                    <span>
                                        Discount 
                                        <small>(<span id="discount-percent">0</span>%)</small>:
                                    </span>
                                    <span id="discount-total">0.00</span>
                                    <span class="currency-symbol">TZS</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Shipping:</span>
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <span class="input-group-text currency-symbol">TZS</span>
                                        <input type="number" class="form-control" id="shipping_cost" name="shipping_cost" 
                                               min="0" step="0.01" value="<?= $sales_order ? $sales_order['shipping_cost'] : '0' ?>" onchange="calculateTotals()">
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
                                            <input class="form-check-input" type="checkbox" id="apply_tax" name="apply_tax" <?= ($sales_order && $sales_order['tax_amount'] > 0) || !$sales_order ? 'checked' : '' ?> onchange="calculateTotals()">
                                            <label class="form-check-label" for="apply_tax">
                                                Apply Tax
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="apply_discount" name="apply_discount" <?= ($sales_order && $sales_order['discount_amount'] > 0) ? 'checked' : '' ?> onchange="calculateTotals()">
                                            <label class="form-check-label" for="apply_discount">
                                                Apply Order Discount
                                            </label>
                                        </div>
                                    </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Hidden fields -->
                <input type="hidden" name="sales_order_id" value="<?= $sales_order_id ?>">
                <input type="hidden" name="created_by" value="<?= $user_id ?>">
                <input type="hidden" name="is_quote" value="<?= $is_quote ? '1' : '0' ?>">
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
                        <?php if ($is_quote): ?>
                        <button type="button" class="btn btn-info" onclick="saveAsQuote()">
                            <i class="bi bi-file-text"></i> Save Quotation
                        </button>
                        <?php else: ?>
                        <?php if ($sales_order_id > 0): ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Save Changes
                        </button>
                        <?php else: ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Create Order
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
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
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Stock</th>
                    <th>Price</th>
                    <th>Tax</th>
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
let currentWarehouseStockMap = {}; // product_id -> available qty (warehouse)
let poAllowedProductIds = null; // Set<number> when PO selected
let poAllowedNames = null; // Set<string> (lowercase) when PO items have no product_id
let taxRates = [
    { rate_id: 0, rate_name: 'No Tax', rate_percentage: 0 },
    { rate_id: 1, rate_name: 'VAT 18%', rate_percentage: 18 },
    { rate_id: 2, rate_name: 'Reduced 5%', rate_percentage: 5 }
];

$(document).ready(function() {
    // Log Activity
    const typeLabel = <?= $is_quote ? "'Quotation'" : "'Sales Order'" ?>;
    logReportAction('View ' + typeLabel + ' Edit Page', 'User opened the page to edit ' + typeLabel + ' #' + '<?= $sales_order['order_number'] ?>');
    
    // Add first item row if not editing
    <?php if ($sales_order_id == 0 && !$quote_id): ?>
    addItemRow();
    <?php endif; ?>
    
    // Load customer info if customer is already selected
    if ($('#customer_id').val()) {
        loadCustomerInfo(true);
    }
    
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
    
    // Load existing items if editing
    <?php if ($sales_order_id > 0 && !empty($existing_items)): ?>
    loadQuoteItems(<?= json_encode($existing_items) ?>);
    <?php endif; ?>

    // Load quote items if quote is provided
    <?php if ($quote && count($quote_items) > 0): ?>
    loadQuoteItems(<?= json_encode($quote_items) ?>);
    <?php endif; ?>
    
    // Form submission
    $('#salesOrderForm').on('submit', function(e) {
        e.preventDefault();
        <?php if ($sales_order_id > 0 && $sales_order): ?>
        // When editing, preserve the current status
        createSalesOrder('<?= $sales_order['status'] ?>');
        <?php else: ?>
        createSalesOrder('pending');
        <?php endif; ?>
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
    
    // Update currency symbol
    $('#currency').change(function() {
        updateCurrencySymbol();
    });
    
    // Calculate totals when quantities or prices change
    $(document).on('input', '.item-quantity, .item-price, .item-tax, .item-discount', function() {
        const index = $(this).closest('tr').data('index');
        calculateItemTotal(index);
        calculateTotals();
    });
    
    // Initial warehouse filtering
    filterWarehousesByProject(true);
    
    // Initialize with default tax rates (you can load from API)
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
        }
        // Clear PO field when warehouse changes
        $('#po_no').val('');
        $('#poSearchResults').hide();
        currentWarehouseStockMap = {};
        poAllowedProductIds = null;
        poAllowedNames = null;
    }
}

// --- Payment Terms toggle (in-place swap) ---
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

// --- PO Number search ---
let poSearchTimer;
function searchPOByWarehouse(term) {
    const warehouseId = $('#warehouse_id').val();
    const resultsDiv = $('#poSearchResults');

    if (!warehouseId) {
        resultsDiv.html('<a class="list-group-item list-group-item-action text-muted disabled">Select a warehouse first</a>').show();
        return;
    }

    clearTimeout(poSearchTimer);
    poSearchTimer = setTimeout(() => {
        resultsDiv.html('<a class="list-group-item"><span class="spinner-border spinner-border-sm"></span> Loading...</a>').show();
        $.ajax({
            url: '<?= getUrl('/api/account/get_pos_by_warehouse.php') ?>',
            type: 'GET',
            data: { warehouse_id: warehouseId, search: term },
            dataType: 'json',
            success: function(response) {
                resultsDiv.empty();
                if (response.success && response.data.length > 0) {
                    response.data.forEach(po => {
                        const badge = `<span class="badge bg-secondary ms-1">${po.status}</span>`;
                        resultsDiv.append(
                            $('<a class="list-group-item list-group-item-action py-1"></a>')
                                .html(`<strong>${po.order_number}</strong>${badge}<br><small class="text-muted">${po.supplier_name || ''} &mdash; ${po.order_date}</small>`)
                                .on('click', function() {
                                    $('#po_no').val(po.order_number);
                                    resultsDiv.hide();
                                    loadItemsFromPO(po.order_number);
                                })
                        );
                    });
                } else {
                    resultsDiv.html('<a class="list-group-item text-muted disabled">No POs found for this warehouse</a>');
                }
                resultsDiv.show();
            },
            error: function() {
                resultsDiv.html('<a class="list-group-item text-danger disabled">Error loading POs</a>').show();
            }
        });
    }, 300);
}

function loadItemsFromPO(orderNumber) {
    const warehouseId = $('#warehouse_id').val();
    if (!warehouseId) return;
    if (!orderNumber) return;

    Swal.fire({
        title: 'Loading PO items...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: '<?= getUrl('/api/account/get_po_items.php') ?>',
        type: 'GET',
        data: { warehouse_id: warehouseId, order_number: orderNumber },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load PO items' });
                return;
            }

            const items = (res.data && res.data.items) ? res.data.items : [];
            if (!items.length) {
                Swal.fire({ icon: 'warning', title: 'No Items', text: 'This PO has no items.' });
                return;
            }

            // Reset items table and load PO items.
            $('#itemsBody').empty();
            itemCount = 0;
            currentWarehouseStockMap = {};
            poAllowedProductIds = new Set();
            poAllowedNames = new Set();

            items.forEach(it => {
                const product = {
                    product_id: it.product_id,
                    product_name: it.product_name,
                    sku: it.sku,
                    unit: it.unit,
                    quantity: parseFloat(it.po_quantity || 1),
                    unit_price: parseFloat(it.selling_price || 0),
                    selling_price: parseFloat(it.selling_price || 0),
                    tax_rate: parseFloat(it.tax_rate || 0)
                };
                if (product.product_id) {
                    currentWarehouseStockMap[String(product.product_id)] = parseFloat(it.current_stock || 0);
                    poAllowedProductIds.add(parseInt(product.product_id, 10));
                } else if (product.product_name) {
                    poAllowedNames.add(String(product.product_name).toLowerCase().trim());
                }
                addItemRow(product);
            });

            calculateTotals();
            updateSerialNumbers();
            Swal.fire({
                icon: 'success',
                title: 'PO Items Loaded',
                text: items.length + ' item(s) loaded. You can adjust quantities before saving.',
                confirmButtonText: 'OK'
            });
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load PO items (network/server error).' });
        }
    });
}

// Hide PO results on outside click
$(document).on('click', function(e) {
    if (!$(e.target).closest('#po_no, #poSearchResults, .btn').length) {
        $('#poSearchResults').hide();
    }
});

function loadProductsCache() {
    $.ajax({
        url: '<?= getUrl('/api/account/get_products.php') ?>',
        type: 'GET',
        data: { active_only: true, with_stock: true },
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

function loadTaxRates() {
    // Load tax rates from API or use defaults
    $.ajax({
        url: '<?= getUrl('/api/account/get_tax_rates.php') ?>',
        type: 'GET',
        success: function(response) {
            if (response.success) {
                taxRates = response.data;
            }
        },
        error: function(error) {
            console.error('Error loading tax rates:', error);
            // Use default tax rates
        }
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
                           placeholder="Type to search product..." required
                           oninput="openProductSearch(${index}, this.value)"
                           onclick="openProductSearch(${index}, this.value)"
                           style="cursor: text; background-color: #fff;"
                           autocomplete="off">
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="openProductSearch(${index})">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <input type="hidden" class="item-product-id"
                       name="items[${index}][product_id]">
            </td>
            <td>
                <input type="text" class="form-control item-sku"
                       name="items[${index}][sku]"
                       placeholder="SKU" readonly>
            </td>
            <td>
                <input type="number" class="form-control item-quantity"
                       name="items[${index}][quantity]"
                       min="0.001" step="0.001" value="1" required>
            </td>
            <td>
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
                    <input type="number" class="form-control item-price"
                           name="items[${index}][unit_price]"
                           min="0" step="0.01" value="0" required>
                </div>
            </td>
            <td>
                <select class="form-select item-tax" name="items[${index}][tax_rate]">
                    ${taxRates.map(rate => `
                        <option value="${rate.rate_percentage}">
                            ${rate.rate_name} (${rate.rate_percentage}%)
                        </option>
                    `).join('')}
                </select>
            </td>
            <td>
                <div class="input-group">
                    <input type="number" class="form-control item-discount"
                           name="items[${index}][discount_percent]"
                           min="0" max="100" step="0.01" value="0">
                    <span class="input-group-text">%</span>
                </div>
            </td>
            <td>
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
        $row.find('.item-name').val(product.product_name || '');
        $row.find('.item-product-id').val(product.product_id != null ? product.product_id : '');
        $row.find('.item-sku').val(product.sku || '');
        $row.find('.item-quantity').val(product.quantity != null ? product.quantity : 1);
        $row.find('.item-price').val(product.unit_price != null ? product.unit_price : (product.selling_price || 0));
        $row.find('.item-discount').val(product.discount_percent || 0);
        $row.find('.item-tax').val(product.tax_rate != null ? product.tax_rate : 0);
        const unitVal = product.unit || 'pcs';
        if (!$row.find(`.item-unit option[value="${unitVal}"]`).length) {
            $row.find('.item-unit').append(`<option value="${unitVal}">${unitVal}</option>`);
        }
        $row.find('.item-unit').val(unitVal);
    }

    calculateItemTotal(index);
    calculateTotals();
    updateSerialNumbers();

    return index;
}

function updateSerialNumbers() {
    $('#itemsBody tr').each(function(i) {
        $(this).find('.item-serial').text(i + 1);
    });
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
        results = productsCache.filter(p =>
            (p.product_name && p.product_name.toLowerCase().includes(searchTerm)) ||
            (p.sku && p.sku.toLowerCase().includes(searchTerm)) ||
            (p.barcode && p.barcode.toLowerCase().includes(searchTerm))
        );
    }

    // If a PO is selected, only show products/items that were created under that PO.
    const poSelected = String($('#po_no').val() || '').trim() !== '';
    if (poSelected && (poAllowedProductIds instanceof Set || poAllowedNames instanceof Set)) {
        results = results.filter(p => {
            const pidOk = (poAllowedProductIds instanceof Set) && p.product_id && poAllowedProductIds.has(parseInt(p.product_id, 10));
            const nameOk = (poAllowedNames instanceof Set) && p.product_name && poAllowedNames.has(String(p.product_name).toLowerCase().trim());
            return pidOk || nameOk;
        });
    }

    if (results.length === 0) {
        tbody.append(`<tr><td colspan="5" class="text-center text-danger p-3">${poSelected ? 'No products available for the selected PO' : 'No products found'}</td></tr>`);
        return;
    }

    results.slice(0, 50).forEach(product => {
        tbody.append(`
            <tr onclick="selectProduct(${product.product_id})">
                <td>
                    <strong>${product.product_name}</strong><br>
                    <small class="text-muted">${product.sku || 'No SKU'}</small>
                </td>
                <td>${product.sku || 'N/A'}</td>
                <td>${product.current_stock || 0}</td>
                <td>${product.selling_price || 0}</td>
                <td>${product.tax_rate || 0}%</td>
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
        row.find('.item-unit').val(product.unit || 'pcs');
        row.find('.item-price').val(product.selling_price || 0);
        row.find('.item-tax').val(product.tax_rate || 0);
        
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
    const taxRate = parseFloat(row.find('.item-tax').val()) || 0;
    const discountPercent = parseFloat(row.find('.item-discount').val()) || 0;
    
    const subtotal = quantity * price;
    const discountAmount = subtotal * (discountPercent / 100);
    const taxableAmount = subtotal - discountAmount;
    const taxAmount = taxableAmount * (taxRate / 100);
    const total = taxableAmount + taxAmount;
    
    row.find('.item-total').text(total.toFixed(2));
}

function calculateTotals() {
    let subtotal = 0;
    let taxTotal = 0;
    let discountTotal = 0;
    let totalItems = 0;
    let totalQuantity = 0;
    let applyTax = $('#apply_tax').is(':checked');
    let applyDiscount = $('#apply_discount').is(':checked');
    
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
    
    // Update display
    $('#subtotal').text(subtotal.toFixed(2));
    $('#tax-total').text(taxTotal.toFixed(2));
    $('#discount-total').text(discountTotal.toFixed(2));
    $('#grand-total').text(grandTotal.toFixed(2));
    $('#totalItems').text(totalItems);
    $('#totalQuantity').text(totalQuantity.toFixed(3));
    
    // Calculate average discount percentage
    const discountPercent = subtotal > 0 ? (discountTotal / subtotal * 100).toFixed(1) : 0;
    $('#discount-percent').text(discountPercent);
    
    // Update hidden fields
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
            updateSerialNumbers();
        }
    });
}

function updateCurrencySymbol() {
    const currency = $('#currency').val();
    $('.currency-symbol').text(currency);
}

function loadCustomerInfo(isInitialLoad = false) {
    const customerId = $('#customer_id').val();
    if (!customerId) {
        $('#customerInfoCard').hide();
        return;
    }
    
    const selectedOption = $('#customer_id option:selected');
    const paymentTerms = selectedOption.data('payment-terms');
    const currency = selectedOption.data('currency');
    const creditLimit = selectedOption.data('credit-limit');
    
    // Update form fields only if not the initial load in edit mode
    if (!isInitialLoad) {
        if (paymentTerms) {
            $('#payment_terms').val(paymentTerms);
            // If the value wasn't set (not in dropdown), use 'other'
            if ($('#payment_terms').val() !== paymentTerms && paymentTerms !== "") {
                $('#payment_terms').val('other');
                $('#custom_payment_terms').val(paymentTerms);
            }
            toggleCustomPaymentTerms();
        }
        if (currency) {
            $('#currency').val(currency);
            updateCurrencySymbol();
        }
    }
    
    $.ajax({
        url: '<?= getUrl('/api/account/get_customer.php') ?>',
        type: 'GET',
        data: { id: customerId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const customer = response.data;
                let html = `
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
                            <p>Credit Limit: ${formatCurrency(customer.credit_limit || 0)}</p>
                            <p>Current Balance: ${formatCurrency(customer.current_balance || 0)}</p>
                        </div>
                    </div>
                `;
                $('#customerInfoBody').html(html);
                $('#customerInfoCard').show();
                
                // Check credit limit
                const currentBalance = parseFloat(customer.current_balance) || 0;
                const creditLimit = parseFloat(customer.credit_limit) || 0;
                const grandTotal = parseFloat($('#grand_total_hidden').val()) || 0;
                
                if (creditLimit > 0 && (currentBalance + grandTotal) > creditLimit) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Credit Limit Warning',
                        text: `Order amount exceeds customer's credit limit. Credit Limit: ${formatCurrency(creditLimit)}, Current Balance: ${formatCurrency(currentBalance)}, Order Total: ${formatCurrency(grandTotal)}`,
                        confirmButtonText: 'Continue Anyway',
                        showCancelButton: true
                    });
                }
            }
        },
        error: function(error) {
            console.error('Error loading customer info:', error);
        }
    });
}

function formatCurrency(amount) {
    const currency = $('#currency').val();
    const number = parseFloat(amount);

    return currency + ' ' + (isNaN(number) ? '0.00' : number.toFixed(2));
}


function loadQuoteItems(quoteItems) {
    // Clear existing items
    $('#itemsBody').empty();
    itemCount = 0;
    
    // Add quote items
    quoteItems.forEach(item => {
        addItemRow(item);
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
                <strong>Product Found:</strong> ${product.product_name}<br>
                <small>SKU: ${product.sku || 'N/A'} | Price: ${formatCurrency(product.selling_price || 0)}</small>
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

function createSalesOrder(status = 'pending') {
    // Validate form
    // 'draft' was removed from sales_orders; always run full validation.
    if (!validateForm(false)) {
        return;
    }

    const formData = new FormData($('#salesOrderForm')[0]);
    formData.append('status', status);
    
    // Get items data
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
        
        if (item.product_name && item.quantity) {
            items.push(item);
        }
    });
    
    // Add items to form data
    formData.append('items', JSON.stringify(items));
    
    // Before saving, warn if any quantity exceeds available warehouse stock
    const warehouseId = $('#warehouse_id').val();

    const proceedSave = () => {
        // Show loading state on ALL action buttons
        const submitBtn = $('button[type="submit"], .btn-primary, .btn-outline-primary').filter(':visible');
        const clickedBtn = $('button[type="submit"]');
        const originalText = clickedBtn.html();
        submitBtn.prop('disabled', true);
        clickedBtn.html('<span class="spinner-border spinner-border-sm"></span> Saving...');

        $.ajax({
            url: '<?= getUrl('/api/account/save_sales_order.php') ?>',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 30000, // 30 second timeout
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: response.message,
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        <?php if ($is_quote): ?>
                        window.location.href = '<?= getUrl('quotations') ?>';
                        <?php else: ?>
                        window.location.href = '<?= getUrl('sales_orders') ?>';
                        <?php endif; ?>
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to save order.'
                    });
                    submitBtn.prop('disabled', false);
                    clickedBtn.html(originalText);
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = 'An error occurred. Please try again.';
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please check your connection and try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (xhr.responseText) {
                    try {
                        const resp = JSON.parse(xhr.responseText);
                        if (resp.message) errorMsg = resp.message;
                    } catch(e) {
                        console.error('Response:', xhr.responseText);
                    }
                }
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: errorMsg
                });
                submitBtn.prop('disabled', false);
                clickedBtn.html(originalText);
                console.error('Save error:', status, error);
            }
        });
    };

    if (warehouseId) {
        $.ajax({
            url: '<?= getUrl('/api/account/check_stock.php') ?>',
            type: 'POST',
            data: { warehouse_id: warehouseId, items: JSON.stringify(items) },
            dataType: 'json',
            success: function(res) {
                if (!res.success) {
                    Swal.fire({ icon: 'error', title: 'Stock Check Failed', text: res.message || 'Unable to verify stock.' });
                    return;
                }
                if (res.ok) {
                    proceedSave();
                    return;
                }

                const list = (res.shortages || []).map(s =>
                    `<li><b>${escapeHtml(s.product_name)}</b> (available: ${s.available}, requested: ${s.requested})</li>`
                ).join('');

                Swal.fire({
                    icon: 'warning',
                    title: 'Insufficient Stock',
                    html: `<p>Please reduce quantities before continuing:</p><ul style="text-align:left;">${list}</ul>`,
                    confirmButtonText: 'OK'
                });
            },
            error: function() {
                Swal.fire({ icon: 'error', title: 'Stock Check Failed', text: 'Unable to verify stock (network/server error).' });
            }
        });
    } else {
        proceedSave();
    }
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, function(m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
}

function saveAsQuote() {
    if (!validateForm()) {
        return;
    }
    // 'draft' was removed from the sales_orders enum; quote flow now saves as 'pending'.
    createSalesOrder('pending');
}

function validateForm(isDraft = false) {
    // Check if at least one item is added
    if ($('[id^="item-row-"]').length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Items',
            text: 'Please add at least one item to the order.'
        });
        return false;
    }
    
    // Check if all items have valid data
    let hasValidItems = false;
    $('[id^="item-row-"]').each(function() {
        const productName = $(this).find('.item-name').val();
        const quantity = $(this).find('.item-quantity').val();
        const price = $(this).find('.item-price').val();
        
        if (productName && quantity > 0 && price >= 0) {
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Items',
            text: 'Please ensure all items have a name, quantity, and price.'
        });
        return false;
    }
    
    // Check required fields
    const requiredFields = ['order_number', 'order_date', 'customer_id'];
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
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    background-color: white !important;
    border: 1px solid #dee2e6 !important;
}

.card-header.bg-light {
    background-color: #f8f9fa !important;
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

.currency-symbol {
    font-weight: bold;
    color: #495057;
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

/* Order summary */
#grand-total {
    font-size: 1.5rem;
    color: #198754;
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
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>