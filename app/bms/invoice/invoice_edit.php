<?php
// File: invoice_edit.php
require_once __DIR__ . '/../../../roots.php';
// Enforce permission
autoEnforcePermission('invoices');

// Get Invoice ID
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($invoice_id <= 0) {
    header("Location: " . getUrl('invoices') . "?error=Invalid Invoice ID");
    exit();
}

// Fetch Invoice Details
global $pdo;
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

assertScopeForRecordHtml('invoices', 'invoice_id', $invoice_id);

if (!$invoice) {
    header("Location: " . getUrl('invoices') . "?error=Invoice Not Found");
    exit();
}

// Check status - Allow editing for all but provide a warning/check if needed
// For now, moving this above includeHeader to prevent "headers already sent"
if (!in_array($invoice['status'], ['draft', 'pending', 'sent', 'partial', 'paid', 'overdue'])) {
    header("Location: " . getUrl('invoice_view') . "?id=$invoice_id&error=Cannot edit invoice with status " . $invoice['status']);
    exit();
}

includeHeader();

// Fetch Invoice Items
$stmtItems = $pdo->prepare("SELECT ii.*, COALESCE(p.is_service, 0) AS is_service
                              FROM invoice_items ii LEFT JOIN products p ON p.product_id = ii.product_id
                             WHERE ii.invoice_id = ? ORDER BY ii.invoice_item_id");
$stmtItems->execute([$invoice_id]);
$invoiceItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
// Each line keeps its discount (% or a fixed amount) and, for a sales-order line, the order's
// discount still open on it — core/sales_line_discount.php, core/sales_invoicing.php.
require_once ROOT_DIR . '/core/sales_line_discount.php';
require_once ROOT_DIR . '/core/sales_invoicing.php';
foreach ($invoiceItems as &$ii) {
    $ii['discount_value'] = salesDiscountValueOf($ii);
    if (!empty($ii['order_item_id'])) {
        $so = soLineDiscountFor($pdo, (int)$ii['order_item_id'], INF, (int)$invoice_id);
        if ($so && $so['discount_type'] === 'amount') {
            $ii['so_disc_left'] = $so['discount_value'];
            $ii['so_open_qty']  = $so['open_qty'];
        }
    }
}
unset($ii);

// Get customers for dropdown
// Get customers for dropdown
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// Check projects setting — $enable_projects reflects BOTH the superadmin's
// platform grant AND the tenant's own "Enable Projects Module" setting; see
// projectsModuleActive() (core/project_scope.php).
$enable_projects = projectsModuleActive() ? 1 : 0;

$projects = [];
if ($enable_projects) {
    try {
        $_ie_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
        if (isAdmin()) {
            $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($_ie_assigned)) {
            $_ie_pph = implode(',', array_fill(0, count($_ie_assigned), '?'));
            $_ie_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($_ie_pph) ORDER BY project_name");
            $_ie_pstmt->execute($_ie_assigned);
            $projects = $_ie_pstmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// Warehouses for dropdown — scoped by project for non-admins; JS filters further
// by the selected project (project's warehouses only, or unassigned-only if none).
require_once ROOT_DIR . '/core/warehouse_scope.php';
$warehouses = warehousesForSelect($pdo);

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
            <li class="breadcrumb-item active">Edit Invoice</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-pencil-square text-primary"></i> Edit Invoice</h2>
                    <p class="text-muted mb-0">Modify invoice #<?= caseFormat($invoice['invoice_number']) ?></p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($enable_projects && !empty($invoice['project_id'])): ?>
                    <a href="<?= getUrl('project_view') ?>?id=<?= $invoice['project_id'] ?>" class="btn btn-outline-primary btn-sm shadow-sm">
                        <i class="bi bi-kanban"></i> Back to Project
                    </a>
                    <?php endif; ?>
                    <a href="<?= getUrl('invoice_view') ?>?id=<?= $invoice_id ?>" class="btn btn-outline-secondary btn-sm shadow-sm">
                        <i class="bi bi-arrow-left"></i> Back to Details
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-file-text"></i> Invoice Details</h5>
        </div>
        <div class="card-body">
            <form id="invoiceForm">
                <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
                
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Invoice #</label>
                        <input type="text" class="form-control" name="invoice_number" value="<?= safe_output($invoice['invoice_number']) ?>" required readonly>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Invoice Date</label>
                        <input type="date" class="form-control" id="invoice_date" name="invoice_date" value="<?= $invoice['invoice_date'] ?>" required onchange="updateDueDate()">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Due Date</label>
                        <input type="date" class="form-control" id="due_date" name="due_date" value="<?= $invoice['due_date'] ?>" required>
                    </div>
                </div>
                
                <!-- Customer Information -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Customer <span class="text-danger">*</span></label>
                        <select class="form-select select2" id="customer_id" name="customer_id" required>
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?= $cust['customer_id'] ?>" <?= ($cust['customer_id'] == $invoice['customer_id']) ? 'selected' : '' ?>>
                                    <?= caseFormat($cust['customer_name']) ?> <?= !empty($cust['company_name']) ? '('.caseFormat($cust['company_name']).')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($enable_projects): ?>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Project</label>
                        <select class="form-select select2" name="project_id" id="project_id">
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>" <?= (isset($invoice['project_id']) && $invoice['project_id'] == $proj['project_id']) ? 'selected' : '' ?>>
                                    <?= caseFormat($proj['project_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Warehouse</label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id">
                            <option value="">Select Warehouse</option>
                            <?= renderWarehouseOptions($warehouses, $invoice['warehouse_id'] ?? 0) ?>
                        </select>
                        <div class="form-text">Stock for inventory items is checked against this warehouse.</div>
                    </div>
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
                                        <th width="35%" class="ps-3">Product/Item</th>
                                        <th width="12%">Quantity</th>
                                        <th width="8%">Unit</th>
                                        <th width="15%">Unit Price</th>
                                        <th width="12%">Discount</th>
                                        <th width="10%">Tax</th>
                                        <th width="10%" class="text-end">Total</th>
                                        <th width="5%" class="text-center"></th>
                                    </tr>
                                </thead>
                                
                                <tbody id="itemsBody">
                                    <!-- Items added via JS -->
                                </tbody>
                                
                            </table>
                             <button type="button" class="btn btn-sm btn-success" onclick="addItemRow()">
                            <i class="bi bi-plus-circle"></i> Add Item
                        </button>
                        </div>
                    </div>
                </div>

                
                <!-- Summary Section -->
                <div class="row">
                    <div class="col-md-7">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Additional information..."><?= safe_output($invoice['notes']) ?></textarea>
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
                                    <span>Discount:</span>
                                    <span id="discount-total" class="fw-bold text-danger">0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>VAT (18%):</span>
                                    <span id="tax-total" class="fw-bold">0.00</span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between mb-0 fs-5">
                                    <span class="fw-bold">Grand Total:</span>
                                    <span id="grand-total" class="fw-bold text-success">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="submit" class="btn btn-primary px-4 shadow-sm">
                        <i class="bi bi-save me-1"></i> Save Changes
                    </button>
                    <a href="<?= getUrl('invoice_view') ?>?id=<?= $invoice_id ?>" class="btn btn-outline-secondary px-4">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

        </div>
    </div>
</div>

<!-- Floating Product Search Results moved to body level -->
<div id="productSearchResults" class="product-search-results shadow-lg border" style="display: none; position: fixed; width: 500px; z-index: 99999;">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="bg-light sticky-top">
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Stock</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody id="productsSearchBody">
                <!-- Products will be loaded here -->
            </tbody>
        </table>
    </div>
</div>

<script src="<?= getUrl('assets/js/warehouse-project-filter.js') ?>"></script>
<?php require ROOT_DIR . '/includes/line_price_terms_js.php'; ?>
<?php require ROOT_DIR . '/includes/sales_line_extras_js.php'; ?>
<script>
let itemCount = 0;
let productsCache = [];
let currentItemIndex = null;

$(document).ready(function() {
    logReportAction('Viewed Invoice Edit Page', 'User viewed the edit invoice page for invoice #<?= $invoice['invoice_number'] ?>');

    // Determine existing items
    const existingItems = <?= json_encode($invoiceItems) ?>;
    
    if (existingItems && existingItems.length > 0) {
        existingItems.forEach(item => {
            addItemRow(item);
        });
    } else {
        addItemRow();
    }
    
    // Shared Project → Warehouse cascade (assets/js/warehouse-project-filter.js)
    bindWarehouseToProject({
        onFiltered: function () { loadProductsCache(); }
    });
    loadProductsCache();

    $('#warehouse_id').on('change', function() { loadProductsCache(); });

    $('#invoiceForm').on('submit', function(e) {
        e.preventDefault();
        saveInvoice('<?= safe_output($invoice['status']) ?>');
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

function escAttr(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

function addItemRow(item = null) {
    const idx = itemCount++;
    const fromOrder = !!(item && item.order_item_id);
    const lock = fromOrder ? 'readonly' : '';
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
                    <input type="hidden" class="item-order-item-id" name="items[${idx}][order_item_id]" value="${fromOrder ? item.order_item_id : ''}">
                    <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch(${idx})">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                ${fromOrder ? `<div class="form-text small text-primary"><i class="bi bi-link-45deg"></i> From sales order</div>` : ''}
                <textarea class="form-control form-control-sm item-description mt-1 d-none" name="items[${idx}][description]" rows="3"
                          placeholder="Description — printed on the invoice">${item ? escAttr(item.description) : ''}</textarea>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm item-qty" name="items[${idx}][quantity]" 
                       value="${item ? item.quantity : 1}" step="0.01" required onchange="calculateTotals()">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm item-unit" name="items[${idx}][unit]" 
                       value="${item ? item.unit : 'pcs'}" readonly>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm item-price" name="items[${idx}][unit_price]"
                       value="${item ? item.unit_price : 0}" step="0.01" required ${lock} onchange="calculateTotals()">
            </td>
            <td>
                <input type="number" class="form-control form-control-sm item-discount" name="items[${idx}][discount_percent]"
                       value="${item ? (parseFloat(item.discount_value ?? item.discount_percent) || 0) : 0}" step="0.01" min="0" max="100" ${lock} oninput="calculateTotals()" onchange="calculateTotals()">
            </td>
            <td>
                <select class="form-select form-select-sm item-tax" name="items[${idx}][tax_rate]" onchange="calculateTotals()">
                    <option value="0" ${item && item.tax_rate == 0 ? 'selected' : ''}>0%</option>
                    <option value="18" ${item && item.tax_rate == 18 ? 'selected' : ''}>18%</option>
                </select>
            </td>
            <td class="text-end fw-bold item-total">0.00</td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger" onclick="removeItemRow(${idx})"><i class="bi bi-trash"></i></button>
            </td>
        </tr>
    `;
    $('#itemsBody').append(html);
    const $newRow = $(`#item-row-${idx}`);
    // Discount as % or a fixed amount (a saved line keeps its own; a sales-order line keeps the order's).
    BmsLineDiscount.enhance($newRow, { type: item ? item.discount_type : null,
        typeName: `items[${idx}][discount_type]`, valueName: `items[${idx}][discount_value]` });
    $newRow.find('.item-discount-type').on('change', () => calculateTotals());
    if (item && item.so_disc_left != null) {
        $newRow.data('soDiscLeft', parseFloat(item.so_disc_left) || 0).data('soOpenQty', parseFloat(item.so_open_qty) || 0);
    }
    BmsLineDesc.show($newRow, !!(item && parseInt(item.is_service || 0, 10) === 1));
    if (item) {
        BmsLineTerms.attach($(`#item-row-${idx}`), item, {
            savedTermId: item.price_term_id || null, savedTermLabel: item.price_term_label || '',
            keepPrice: !!item.price_term_id, selectName: `items[${idx}][price_term_id]`, onChange: () => calculateTotals()
        });
    }
    calculateTotals();
}

function removeItemRow(idx) {
    if ($('#itemsBody tr').length > 1) {
        $(`#item-row-${idx}`).remove();
        calculateTotals();
    }
}

// A sales-order line's fixed discount on the quantity billed — the same rule as
// soLineDiscountFor() in core/sales_invoicing.php: all that is open, or its share.
function soDiscountShare($row, qty) {
    const left = $row.data('soDiscLeft'), open = $row.data('soOpenQty');
    if (left === undefined) return null;
    if (qty >= open - 0.0005) return left;
    return open > 0 ? BmsLineDiscount.round2(left * qty / open) : 0;
}

// Same arithmetic as api/account/save_invoice.php: VAT on the line after discount.
function calculateTotals() {
    let subtotal = 0;
    let discountTotal = 0;
    let taxTotal = 0;

    $('#itemsBody tr').each(function() {
        const qty = parseFloat($(this).find('.item-qty').val()) || 0;
        const price = parseFloat($(this).find('.item-price').val()) || 0;
        const taxRate = parseFloat($(this).find('.item-tax').val()) || 0;

        const gross = qty * price;
        const disc = soDiscountShare($(this), qty) ?? BmsLineDiscount.amount($(this), gross);
        const lineTax = (gross - disc) * (taxRate / 100);

        subtotal += gross;
        discountTotal += disc;
        taxTotal += lineTax;

        $(this).find('.item-total').text((gross - disc + lineTax).toFixed(2));
    });

    $('#subtotal').text(subtotal.toFixed(2));
    $('#discount-total').text(discountTotal.toFixed(2));
    $('#tax-total').text(taxTotal.toFixed(2));
    $('#grand-total').text((subtotal - discountTotal + taxTotal).toFixed(2));
}

function saveInvoice(status) {
    if (BmsLineTerms.missing($('#itemsBody')).length) {
        Swal.fire({ icon: 'warning', title: <?= json_encode(t('Choose a term')) ?>, text: <?= json_encode(t('A service on this invoice is priced by term. Choose its term before saving.')) ?> });
        return;
    }
    let overDiscount = '';
    $('#itemsBody tr').each(function () {
        if ($(this).data('soDiscLeft') !== undefined) return;   // the order's own discount
        const gross = (parseFloat($(this).find('.item-qty').val()) || 0) * (parseFloat($(this).find('.item-price').val()) || 0);
        if (!overDiscount && BmsLineDiscount.overLimit($(this), gross)) overDiscount = $(this).find('.item-name').val() || 'a line';
    });
    if (overDiscount) {
        Swal.fire({ icon: 'warning', title: 'Discount too large', text: `The discount on "${overDiscount}" is more than the line amount.` });
        return;
    }
    const data = $('#invoiceForm').serialize() + '&status=' + status;
    // Uses the same save_invoice endpoint - it checks for invoice_id to update
    $.post('<?= buildUrl('/api/account/save_invoice.php') ?>', data, function(res) {
        if (res.success) {
            logReportAction('Updated Invoice', 'User updated invoice #<?= $invoice['invoice_number'] ?>');
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: res.message || 'Invoice updated successfully.',
                confirmButtonColor: '#198754',
                confirmButtonText: 'OK',
                timer: 2000,
                showConfirmButton: true
            }).then(() => {
                window.location.href = '<?= getUrl('invoice_view') ?>?id=<?= $invoice_id ?>';
            });
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json').fail(function(xhr) {
        Swal.fire('Error', 'Request failed', 'error');
    });
}

function loadProductsCache(callback = null) {
    const whId = $('#warehouse_id').val() || '';
    $.get('<?= getUrl('/api/account/get_products.php') ?>', { active_only: true, limit: 1000, warehouse_id: whId }, function(res) {
        if (res.success) {
            productsCache = res.data;
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
        tbody.append(`<tr><td colspan="4" class="text-center text-danger p-3">No products found</td></tr>`);
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
                <td>${parseFloat(product.selling_price || 0).toLocaleString()}</td>
            </tr>
        `);
    });
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
        row.find('.item-description').val(p.description || '');
        BmsLineDesc.show(row, parseInt(p.is_service || 0, 10) === 1);
        // A different product is no longer the sales-order line: unlink it and free its price / discount.
        row.find('.item-order-item-id').val('');
        row.removeData('soDiscLeft').removeData('soOpenQty');
        row.find('.item-price, .item-discount').prop('readonly', false);
        if ((window.BMS_SALES_DISCOUNT || {}).allowSwitch !== false) row.find('.item-discount-type').removeClass('pe-none bg-light').removeAttr('tabindex');
        row.find('.form-text.text-primary').remove();
        BmsLineTerms.attach(row, p, { selectName: `items[${currentItemIndex}][price_term_id]`, onChange: () => calculateTotals() });
        
        $('#productSearchResults').hide();
        calculateTotals();
        row.find('.item-qty').focus();
    }
}

function updateDueDate() {
    const invoiceDate = $('#invoice_date').val();
    if (!invoiceDate) return;
    
    // Simplistic +30 days logic, could be refined
    const date = new Date(invoiceDate);
    date.setDate(date.getDate() + 30);
    
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    
    $('#due_date').val(`${year}-${month}-${day}`);
}
</script>

<style>
.custom-header { background-color: #0f5132 !important; border-radius: 0.75rem 0.75rem 0 0; }
.card { border-radius: 0.75rem; }
</style>

<?php includeFooter(); ?>
