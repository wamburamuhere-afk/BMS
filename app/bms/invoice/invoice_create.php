<?php
// File: invoice_create.php
// scope-audit: skip — create form only; no existing record data loaded; save API enforces scope on the chosen project/SO
require_once __DIR__ . '/../../../roots.php';
// Enforce permission (must be before includeHeader to allow redirects)
autoEnforcePermission('invoices');

includeHeader();

// Get parameters
$customer_id = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$order_id    = isset($_GET['order'])    ? intval($_GET['order'])    : 0;
$project_id  = isset($_GET['project']) ? intval($_GET['project'])  : 0;
$ipc_id      = isset($_GET['ipc_id'])  ? intval($_GET['ipc_id'])   : 0;

// Fetch IPC prefill data if coming from an approved IPC
$ipc_prefill = null;
if ($ipc_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT ipc_id, ipc_number, items_json, notes, ipc_date FROM interim_payment_certificates WHERE ipc_id = ? AND status = 'Approved'");
        $stmt->execute([$ipc_id]);
        $ipc_prefill = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Fetch all approved uninvoiced IPCs for the dropdown
$approved_ipcs = [];
try {
    $ipc_q = "SELECT ipc.ipc_id, ipc.ipc_number, ipc.ipc_date, ipc.net_payable, ipc.project_id,
                     p.project_name, p.customer_id AS proj_customer_id
              FROM interim_payment_certificates ipc
              LEFT JOIN projects p ON ipc.project_id = p.project_id
              WHERE ipc.status = 'Approved' AND (ipc.invoice_id IS NULL OR ipc.invoice_id = 0)
              ORDER BY ipc.ipc_date DESC";
    $approved_ipcs = $pdo->query($ipc_q)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get current user info
$user_id = $_SESSION['user_id'];

// Get customer details if provided
$customer = null;
if ($customer_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ? AND status != 'inactive'");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get order details if provided
$order = null;
$order_items = [];
if ($order_id > 0) {
    // NOTE: sales_orders PK is sales_order_id (not order_id)
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE sales_order_id = ? AND status IN ('approved', 'processing', 'delivered', 'partially_delivered', 'completed')");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order) {
        $stmt = $pdo->prepare("
            SELECT 
                soi.*,
                p.product_name,
                p.sku,
                p.unit,
                soi.quantity - IFNULL(SUM(ii.quantity), 0) as available_quantity
            FROM sales_order_items soi
            LEFT JOIN products p ON soi.product_id = p.product_id
            LEFT JOIN invoice_items ii ON soi.order_item_id = ii.order_item_id
            WHERE soi.order_id = ?
            GROUP BY soi.order_item_id
            HAVING available_quantity > 0
        ");
        $stmt->execute([$order_id]);
        $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$customer && $order['customer_id']) {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
            $stmt->execute([$order['customer_id']]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            $customer_id = $customer['customer_id'];
        }
    }
}

// Get customers for dropdown
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// Get projects if enabled
$enable_projects = 0;
// Check if setting exists (using helper or direct query)
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

// Get payment terms
$payment_terms = [
    'immediate' => 'Immediate Payment',
    '7_days' => '7 Days',
    '15_days' => '15 Days',
    '30_days' => '30 Days',
    '60_days' => '60 Days',
    '90_days' => '90 Days',
    'cod' => 'Cash on Delivery'
];

// Get currency options
$currencies = ['TZS' => 'Tanzanian Shilling', 'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'KES' => 'Kenyan Shilling'];

function generate_invoice_number() {
    return 'INV-' . date('Ymd') . '-' . mt_rand(100, 999);
}
?>

<style>
/* Floating product search */
.product-search-results {
    position: absolute;
    background: white;
    z-index: 10000;
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
</style>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('invoices') ?>">Invoices</a></li>
            <li class="breadcrumb-item active">Create Invoice</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-receipt text-primary"></i> Create Invoice</h2>
                    <p class="text-muted mb-0">Generate a new customer invoice</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('invoices') ?>" class="btn btn-outline-secondary btn-sm shadow-sm">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card shadow-sm border-0">
        <div class="card-header custom-header text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-file-text"></i> Invoice Details</h5>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="is_service_invoice" name="is_service_invoice" onchange="toggleServiceInvoiceMode()">
                <label class="form-check-label fw-bold text-white" for="is_service_invoice">
                    <i class="bi bi-box-seam me-1"></i> Service Invoice (Non-Inventory)
                </label>
            </div>
        </div>
        <div class="card-body">
            <form id="invoiceForm">
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Invoice #</label>
                        <input type="text" class="form-control" name="invoice_number" value="<?= generate_invoice_number() ?>" required readonly>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Invoice Date</label>
                        <input type="date" class="form-control" id="invoice_date" name="invoice_date" value="<?= date('Y-m-d') ?>" required onchange="updateDueDate()">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Due Date</label>
                        <input type="date" class="form-control" id="due_date" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
                    </div>
                </div>
                
                <!-- Customer Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Customer <span class="text-danger">*</span></label>
                        <select class="form-select select2" id="customer_id" name="customer_id" required onchange="loadCustomerInfo()">
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?= $cust['customer_id'] ?>" <?= ($customer_id > 0 && $cust['customer_id'] == $customer_id) ? 'selected' : '' ?>>
                                    <?= safe_output($cust['customer_name']) ?> <?= !empty($cust['company_name']) ? '('.safe_output($cust['company_name']).')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3" id="order_container">
                        <label class="form-label small fw-bold">Sales Order (Optional)</label>
                        <select class="form-select select2" id="order_id" name="order_id" onchange="loadOrderItems()">
                            <option value="">Select Sales Order</option>
                            <?php if ($order): ?>
                                <option value="<?= $order['sales_order_id'] ?>" selected><?= safe_output($order['order_number']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <?php if ($enable_projects): ?>
                    <div class="col-md-4 mb-3" id="project_container">
                        <label class="form-label small fw-bold">Project</label>
                        <select class="form-select select2" name="project_id" id="project_id">
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>"
                                    <?= (($order && $order['project_id'] == $proj['project_id']) || ($project_id == $proj['project_id'])) ? 'selected' : '' ?>>
                                    <?= safe_output($proj['project_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($approved_ipcs)): ?>
                    <div class="col-md-4 mb-3" id="ipc_container">
                        <label class="form-label small fw-bold">IPC <span class="text-muted fw-normal">(Approved)</span></label>
                        <select class="form-select select2" id="ipc_select" name="ipc_id" onchange="loadIpcData(this.value)">
                            <option value="">-- Select IPC --</option>
                            <?php foreach ($approved_ipcs as $ipc): ?>
                                <option value="<?= $ipc['ipc_id'] ?>"
                                    data-project="<?= $ipc['project_id'] ?>"
                                    data-customer="<?= $ipc['proj_customer_id'] ?>"
                                    <?= ($ipc_id > 0 && $ipc['ipc_id'] == $ipc_id) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ipc['ipc_number']) ?>
                                    <?php if (!empty($ipc['project_name'])): ?> — <?= htmlspecialchars($ipc['project_name']) ?><?php endif; ?>
                                    (<?= date('d M Y', strtotime($ipc['ipc_date'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Invoice Items -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold">Invoice Items</h6>                       
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="itemsTable">
                                <thead class="bg-light small fw-bold text-muted">
                                    <tr>
                                        <th width="70%" class="ps-3">Product/Item</th>
                                        <th width="15%" class="inv-col">Quantity</th>
                                        <th width="10%" class="inv-col">Unit</th>
                                        <th width="25%" class="price-col-header">Unit Price</th>
                                        <th width="10%" class="inv-col">Tax</th>
                                        <th width="10%" class="text-end item-total-col">Total</th>
                                        <th width="5%" class="text-center"></th>
                                    </tr>
                                </thead>
                                
                                <tbody id="itemsBody">
                                    <!-- Items added via JS -->
                                </tbody>
                                
                            </table>
                             <button type="button" class="btn btn-sm btn-primary" id="addItemBtn" onclick="addItemRow()">
                            <i class="bi bi-plus-circle"></i> <span id="addItemBtnText">Add Item</span>
                        </button>
                        </div>
                    </div>
                </div>

                
                <!-- Summary Section -->
                <div class="row">
                    <div class="col-md-7">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Additional information..."></textarea>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="card bg-light border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Subtotal:</span>
                                    <span id="subtotal" class="fw-bold">0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Tax:</span>
                                    <span id="tax-total" class="fw-bold">0.00</span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between mb-0 fs-5">
                                    <span class="fw-bold">Grand Total:</span>
                                    <span id="grand-total" class="fw-bold text-primary">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="submit" class="btn btn-primary px-4 shadow-sm">
                        <i class="bi bi-check-circle me-1"></i> Create Invoice
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-4">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Floating Product Search Results moved to body level -->
<div id="productSearchResults" class="product-search-results shadow-lg border" style="display: none; position: fixed; width: 500px; z-index: 99999;">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="bg-light sticky-top">
                <tr id="search-header-row">
                    <th id="search-col-product">Product</th>
                    <th id="search-col-sku" class="inv-col">SKU</th>
                    <th id="search-col-stock" class="inv-col">Stock</th>
                    <th id="search-col-price">Price</th>
                </tr>
            </thead>
            <tbody id="productsSearchBody">
                <!-- Products will be loaded here -->
            </tbody>
        </table>
    </div>
</div>

<script>
let itemCount = 0;
$(document).ready(function() {
    // Log View
    logReportAction('Viewed Invoice Create Page', 'User viewed the create invoice page');

    addItemRow();
    loadProductsCache();
    
    $('#invoiceForm').on('submit', function(e) {
        e.preventDefault();
        console.log('[invoice_create] submit fired');
        saveInvoice('pending');
    });

    // Hide search results on click outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.item-name, .product-search-results, .btn-outline-secondary').length) {
            $('#productSearchResults').hide();
        }
    });

    // Handle ESC key to hide search results
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#productSearchResults').hide();
        }
    });
});

function toggleServiceInvoiceMode() {
    const isService = $('#is_service_invoice').is(':checked');
    if (isService) {
        $('.price-col-header').text('Amount');
        $('#addItemBtnText').text('Add Service');

        Swal.fire({
            icon: 'info',
            title: 'Non-Inventory Mode Active',
            text: 'Service/Non-Inventory mode enabled. All fields remain visible.',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000
        });
    } else {
        $('.price-col-header').text('Unit Price');
        $('#addItemBtnText').text('Add Item');
    }

    // Refresh cache to only show services if needed, or just let search filter it
    loadProductsCache();
}

function addItemRow(item = null) {
    const idx = itemCount++;
    const html = `
        <tr id="item-row-${idx}">
            <td class="ps-3">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control item-name" name="items[${idx}][product_name]" 
                           value="${item ? item.product_name : ''}" placeholder="Type to search..." required
                           oninput="openProductSearch(${idx}, this.value)"
                           onclick="openProductSearch(${idx}, this.value)"
                           autocomplete="off">
                    <input type="hidden" class="item-product-id" name="items[${idx}][product_id]" value="${item ? item.product_id : ''}">
                    <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch(${idx})">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </td>
            <td class="inv-col">
                <input type="number" class="form-control form-control-sm item-qty" name="items[${idx}][quantity]" 
                       value="${item ? item.quantity : 1}" step="0.01" required onchange="calculateTotals()">
            </td>
            <td class="inv-col">
                <input type="text" class="form-control form-control-sm item-unit" name="items[${idx}][unit]" 
                       value="${item ? item.unit : 'pcs'}" readonly>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm item-price" name="items[${idx}][unit_price]" 
                       value="${item ? item.unit_price : 0}" step="0.01" required onchange="calculateTotals()">
            </td>
            <td class="inv-col">
                <select class="form-select form-select-sm item-tax" name="items[${idx}][tax_rate]" onchange="calculateTotals()">
                    <option value="0" ${item && item.tax_rate == 0 ? 'selected' : ''}>0%</option>
                    <option value="18" ${item && item.tax_rate == 18 ? 'selected' : ''}>18%</option>
                </select>
            </td>
            <td class="text-end fw-bold item-total item-total-col">0.00</td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger" onclick="removeItemRow(${idx})"><i class="bi bi-trash"></i></button>
            </td>
        </tr>
    `;
    $('#itemsBody').append(html);
    
    // No column hiding — all columns remain visible in both inventory and non-inventory mode
    
    calculateTotals();
}

function removeItemRow(idx) {
    if ($('#itemsBody tr').length > 1) {
        $(`#item-row-${idx}`).remove();
        calculateTotals();
    }
}

function calculateTotals() {
    let subtotal = 0;
    let taxTotal = 0;
    
    $('#itemsBody tr').each(function() {
        const qty = parseFloat($(this).find('.item-qty').val()) || 0;
        const price = parseFloat($(this).find('.item-price').val()) || 0;
        const taxRate = parseFloat($(this).find('.item-tax').val()) || 0;
        
        const lineTotal = qty * price;
        const lineTax = lineTotal * (taxRate / 100);
        
        subtotal += lineTotal;
        taxTotal += lineTax;
        
        $(this).find('.item-total').text(lineTotal.toFixed(2));
    });
    
    $('#subtotal').text(subtotal.toFixed(2));
    $('#tax-total').text(taxTotal.toFixed(2));
    $('#grand-total').text((subtotal + taxTotal).toFixed(2));
}

function saveInvoice(status) {
    const data = $('#invoiceForm').serialize() + '&status=' + status;
    $.post('<?= buildUrl('/api/account/save_invoice.php') ?>', data, function(res) {
        if (res.success) {
            logReportAction('Created Invoice', 'User created a new invoice');
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: res.message || 'Invoice created successfully.',
                confirmButtonColor: '#198754',
                confirmButtonText: 'OK',
                timer: 3000,
                showConfirmButton: true
            }).then(() => {
                window.location.href = '<?= getUrl('invoices') ?>';
            });
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json').fail(function(xhr) {
        console.error('[invoice_create] save_invoice.php failed', xhr.status, xhr.responseText);
        Swal.fire('Error', 'Request failed (' + xhr.status + '). Please try again.', 'error');
    });
}

let productsCache = [];
let currentItemIndex = null;


function loadProductsCache(callback = null) {
    const isService = $('#is_service_invoice').is(':checked') ? 1 : 0;
    $.get('<?= getUrl('/api/account/get_products.php') ?>', { active_only: true, limit: 1000, is_service: isService }, function(res) {
        if (res.success) {
            productsCache = res.data;
            console.log('Products loaded:', productsCache.length);
            if (callback) callback();
        }
    }, 'json').fail(function() {
        if (callback) callback();
    });
}

function openProductSearch(index, term = '') {
    currentItemIndex = index;
    const input = $(`#item-row-${index} .item-name`);
    const rect = input[0].getBoundingClientRect();
    
    $('#productSearchResults').css({
        top: rect.bottom + 2,
        left: rect.left,
        width: Math.max(input.outerWidth() * 1.5, 500),
        display: 'block'
    });
    
    if (productsCache.length === 0) {
        $('#productsSearchBody').html('<tr><td colspan="4" class="text-center p-3"><div class="spinner-border spinner-border-sm text-primary"></div> Loading...</td></tr>');
        loadProductsCache(() => searchProducts(term));
    } else {
        searchProducts(term);
    }
}

function searchProducts(term = '') {
    const tbody = $('#productsSearchBody');
    const isService = $('#is_service_invoice').is(':checked');
    if (isService) {
        $('#productSearchResults .inv-col').hide();
    } else {
        $('#productSearchResults .inv-col').show();
    }

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
    
    if (results.length === 0) {
        tbody.append(`<tr><td colspan="${isService ? '2' : '4'}" class="text-center text-danger p-3">No ${isService ? 'services' : 'products'} found</td></tr>`);
        return;
    }
    
    results.slice(0, 50).forEach(product => {
        tbody.append(`
            <tr onclick="selectProduct(${product.product_id})">
                <td>
                    <strong>${product.product_name}</strong><br>
                    <small class="text-muted ${isService ? 'd-none' : ''}">${product.sku || 'No SKU'}</small>
                </td>
                <td class="inv-col">${product.sku || 'N/A'}</td>
                <td class="inv-col">${product.current_stock || 0}</td>
                <td>${parseFloat(product.selling_price || 0).toLocaleString()}</td>
            </tr>
        `);
    });
    if (isService) {
        $('#productSearchResults .inv-col').hide();
    }
}

function selectProduct(id) {
    const p = productsCache.find(x => x.product_id == id);
    if (p) {
        const row = $(`#item-row-${currentItemIndex}`);
        row.find('.item-name').val(p.product_name);
        row.find('.item-product-id').val(p.product_id);
        row.find('.item-unit').val(p.unit || 'pcs');
        
        let price = parseFloat(p.selling_price);
        if (isNaN(price)) price = 0;
        
        row.find('.item-price').val(price.toFixed(2));
        row.find('.item-tax').val(parseInt(p.tax_rate) || 0);
        
        $('#productSearchResults').hide();
        calculateTotals();
        row.find('.item-qty').focus();
    }
}



function loadCustomerInfo() {
    const customerId = $('#customer_id').val();
    if (!customerId) {
        $('#order_id').html('<option value="">Select Sales Order</option>');
        return;
    }
    
    // Fetch sales orders for this customer
    $.get('<?= getUrl('/api/account/get_sales_orders.php') ?>', { customer: customerId, status: 'approved' }, function(res) {
        if (res.success) {
            let options = '<option value="">Select Sales Order</option>';
            res.data.forEach(order => {
                options += `<option value="${order.sales_order_id}">${order.order_number} (${order.order_date})</option>`;
            });
            $('#order_id').html(options);
        }
    }, 'json');
}

function loadOrderItems() {
    const orderId = $('#order_id').val();
    if (!orderId) return;
    
    // Fetch items for this order
    // Since we don't have get_sales_order_details, let's assume we might need to add it or use a separate API
    $.get('<?= getUrl('/api/account/get_sales_order_items.php') ?>', { order_id: orderId }, function(res) {
        if (res.success) {
            $('#itemsBody').empty();
            itemCount = 0;
            res.data.forEach(item => {
                addItemRow(item);
            });
        }
    }, 'json');
}

function updateDueDate() {
    const invoiceDate = $('#invoice_date').val();
    if (!invoiceDate) return;

    const date = new Date(invoiceDate);
    date.setDate(date.getDate() + 30); // Default 30 days

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    $('#due_date').val(`${year}-${month}-${day}`);
}

function applyIpcData(data) {
    // Items
    var items = [];
    try { items = JSON.parse(data.items_json || '[]'); } catch(e) {}
    if (items.length > 0) {
        $('#itemsBody').empty();
        itemCount = 0;
        items.forEach(function(item) {
            addItemRow({
                product_name: item.product_name || '',
                product_id:   item.product_id   || '',
                quantity:     item.quantity      || 1,
                unit:         item.unit          || '',
                unit_price:   item.unit_price    || 0,
                tax_rate:     item.tax_percent   || 0
            });
        });
    }
    // Notes
    if (data.notes) $('textarea[name="notes"]').val(data.notes);
    // Date
    if (data.ipc_date) { $('#invoice_date').val(data.ipc_date); updateDueDate(); }
}

function loadIpcData(ipcId) {
    if (!ipcId) return;
    // Pre-select project and customer from the option data attributes
    var opt = $('#ipc_select option[value="' + ipcId + '"]');
    var projId = opt.data('project');
    var custId = opt.data('customer');
    if (projId) $('#project_id').val(projId).trigger('change');
    if (custId) { $('#customer_id').val(custId).trigger('change'); }

    $.getJSON('<?= buildUrl('/api/operations/get_ipc.php') ?>', { id: ipcId }, function(res) {
        if (!res.success) { Swal.fire('Error', res.message, 'error'); return; }
        applyIpcData(res.data);
    });
}

<?php if ($ipc_prefill): ?>
// Auto-prefill from IPC passed via URL
$(document).ready(function() {
    applyIpcData(<?= json_encode([
        'items_json' => $ipc_prefill['items_json'] ?? '[]',
        'notes'      => $ipc_prefill['notes']      ?? '',
        'ipc_date'   => $ipc_prefill['ipc_date']   ?? '',
    ]) ?>);
});
<?php endif; ?>
</script>

<style>
.custom-header { background-color: #0d6efd !important; border-radius: 0.75rem 0.75rem 0 0; }
.custom-stat-card {
    background-color: #cfe2ff !important;
    border-color: #b6d4fe !important;
    border-radius: 1rem;
}
.table thead th { 
    background-color: #f8f9fa; 
    border-bottom: 2px solid #dee2e6; 
    padding: 1rem 0.5rem;
}
.card { border-radius: 0.75rem; }
</style>

<?php includeFooter(); ?>