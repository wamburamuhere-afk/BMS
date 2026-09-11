<?php
// File: pos_scripts_new.php - JavaScript for new POS
?>
<script src="<?= getUrl('assets/js/warehouse-project-filter.js') ?>"></script>
<script>
let cart = [];
let saleVatRate = 0; // POS VAT: cashier-selected per sale — 0 = No Tax, 18 = VAT 18% (never auto-applied)
let currentProduct = null;
let categories = [];
let currentReceiptNumber = '<?= generate_receipt_number() ?>';
let products    = [];
let allProducts = [];  // full catalog, never filtered — scanner always searches here
let currentDiscountPercentage = 0;
let currentShiftId = '<?= $shift_id ?>';
let currentShiftActive = <?= $shift_active ? 'true' : 'false' ?>;
let isSplitPayment = false;
let splitAmounts = { cash: 0, mobile: 0, bank: 0, card: 0 };
let posDiscountType = '<?= get_setting('pos_discount_type', 'percentage') ?>'; // 'percentage' or 'fixed'
let posSelectedPriceGroupId = 0; // Phase 14 (pos_upgrade_plan.md §8) — 0 = no group chosen, plain selling_price
const POS_DENOMINATIONS = <?= json_encode($pos_denomination_list) ?>; // Phase 20 (pos_upgrade_plan.md §8)
const POS_AUTO_PRINT_RECEIPT = <?= get_setting('pos_auto_print_receipt', '0') === '1' ? 'true' : 'false' ?>; // Phase 10 (pos_upgrade_plan.md §7)
const POS_CURRENCY = <?= json_encode($currency) ?>; // Phase 11 (pos_upgrade_plan.md §7) — was hardcoded 'TZS' everywhere
const POS_LOYALTY_REDEEM_VALUE = <?= (float)getSetting('pos_loyalty_redeem_value', '50') ?>; // currency value of 1 point — preview only, server re-validates

// ══════════════════════ TRANSLATED STRINGS (t()) ══════════════════════
const PT = {
    service: <?= json_encode(t('Service')) ?>,
    lowStock: <?= json_encode(t('LOW STOCK')) ?>,
    projectStock: <?= json_encode(t('PROJECT STOCK')) ?>,
    qtyLabel: <?= json_encode(t('Qty:')) ?>,
    noProductsFound: <?= json_encode(t('No products found')) ?>,
    tryDifferentSearch: <?= json_encode(t('Try a different search or category')) ?>,
    errorLoadingProducts: <?= json_encode(t('Error loading products')) ?>,
    statusLabel: <?= json_encode(t('Status:')) ?>,
    retry: <?= json_encode(t('Retry')) ?>,
    noSku: <?= json_encode(t('No SKU')) ?>,
    stockLabel: <?= json_encode(t('Stock:')) ?>,
    quantityLabel: <?= json_encode(t('Quantity')) ?>,
    addToCart: <?= json_encode(t('Add to Cart')) ?>,
    cancel: <?= json_encode(t('Cancel')) ?>,
    error: <?= json_encode(t('Error')) ?>,
    serverError: <?= json_encode(t('Server error.')) ?>,
    remove: <?= json_encode(t('Remove')) ?>,
    clearCartTitle: <?= json_encode(t('Clear Cart?')) ?>,
    clearCartText: <?= json_encode(t('Are you sure you want to remove all items?')) ?>,
    yesClearIt: <?= json_encode(t('Yes, clear it!')) ?>,
    loyaltyDiscountLabel: <?= json_encode(t('Loyalty discount:')) ?>,
    serialTrackedLabel: <?= json_encode(t('Serial / IMEI numbers (select one per unit)')) ?>,
    noSerialsAvailable: <?= json_encode(t('No serial numbers available in this warehouse.')) ?>,
    selectAtLeastOneSerial: <?= json_encode(t('Select at least one serial number.')) ?>,
    loadingSerials: <?= json_encode(t('Loading serial numbers...')) ?>,
    emptyCartTitle: <?= json_encode(t('Empty Cart')) ?>,
    emptyCartText: <?= json_encode(t('Add items to cart before processing payment.')) ?>,
    noActiveShiftTitle: <?= json_encode(t('No Active Shift')) ?>,
    noActiveShiftText: <?= json_encode(t('Please start a shift first.')) ?>,
    startShift: <?= json_encode(t('Start Shift')) ?>,
    warehouseRequiredTitle: <?= json_encode(t('Warehouse required')) ?>,
    warehouseRequiredText: <?= json_encode(t('Please select a warehouse before processing the sale.')) ?>,
    insufficientPaymentTitle: <?= json_encode(t('Insufficient Payment')) ?>,
    insufficientPaymentText: <?= json_encode(t('Amount tendered is less than total amount.')) ?>,
    customerRequiredTitle: <?= json_encode(t('Customer required')) ?>,
    customerRequiredText: <?= json_encode(t('Select a customer to record a credit (pay-later) sale.')) ?>,
    processing: <?= json_encode(t('Processing...')) ?>,
    earnedPts: <?= json_encode(t('Earned %d pt(s).')) ?>,
    redeemedPts: <?= json_encode(t('Redeemed %d pt(s).')) ?>,
    saleCompleted: <?= json_encode(t('Sale Completed!')) ?>,
    receiptHash: <?= json_encode(t('Receipt #')) ?>,
    printAgain: <?= json_encode(t('Print Again')) ?>,
    printReceipt: <?= json_encode(t('Print Receipt')) ?>,
    nextCustomer: <?= json_encode(t('Next Customer')) ?>,
    paymentFailed: <?= json_encode(t('Payment Failed')) ?>,
    genericErrorRetry: <?= json_encode(t('An error occurred. Please try again.')) ?>,
    processPaymentBtn: <?= json_encode(t('PROCESS PAYMENT')) ?>,
    addItemsBeforeHold: <?= json_encode(t('Add items to cart before holding sale.')) ?>,
    holdSaleTitle: <?= json_encode(t('Hold Sale')) ?>,
    holdReferenceLabel: <?= json_encode(t('Hold Reference (optional)')) ?>,
    holdReferencePlaceholder: <?= json_encode(t('e.g., Customer name or phone')) ?>,
    saleHeld: <?= json_encode(t('Sale Held')) ?>,
    saleHeldText: <?= json_encode(t('Sale has been held successfully.')) ?>,
    noHeldSalesFound: <?= json_encode(t('No held sales found')) ?>,
    walkIn: <?= json_encode(t('Walk-in')) ?>,
    walkInCustomer: <?= json_encode(t('Walk-in Customer')) ?>,
    load: <?= json_encode(t('Load')) ?>,
    loadHeldSaleTitle: <?= json_encode(t('Load Held Sale?')) ?>,
    loadHeldSaleText: <?= json_encode(t('Current cart will be replaced. Continue?')) ?>,
    yesLoadIt: <?= json_encode(t('Yes, Load it')) ?>,
    loaded: <?= json_encode(t('Loaded')) ?>,
    saleLoadedSuccessfully: <?= json_encode(t('Sale loaded successfully')) ?>,
    failedToLoadSaleData: <?= json_encode(t('Failed to load sale data')) ?>,
    deleteHeldSaleTitle: <?= json_encode(t('Delete Held Sale?')) ?>,
    cannotRevertText: <?= json_encode(t("You won't be able to revert this!")) ?>,
    yesDeleteIt: <?= json_encode(t('Yes, delete it!')) ?>,
    deletedBang: <?= json_encode(t('Deleted!')) ?>,
    heldSaleDeleted: <?= json_encode(t('Held sale has been deleted.')) ?>,
    failedToDeleteSale: <?= json_encode(t('Failed to delete sale')) ?>,
    loadingRegisters: <?= json_encode(t('Loading registers...')) ?>,
    inUseBySince: <?= json_encode(t('in use by %s since %s')) ?>,
    starting: <?= json_encode(t('Starting...')) ?>,
    shiftStarted: <?= json_encode(t('Shift Started')) ?>,
    shiftStartedText: <?= json_encode(t('Shift %s started on %s.')) ?>,
    registerWord: <?= json_encode(t('register')) ?>,
    failedToStartShift: <?= json_encode(t('Failed to start shift:')) ?>,
    closing: <?= json_encode(t('Closing...')) ?>,
    shiftEnded: <?= json_encode(t('Shift Ended')) ?>,
    shiftClosedSuccessfully: <?= json_encode(t('Shift closed successfully!')) ?>,
    expectedLabel: <?= json_encode(t('Expected:')) ?>,
    actualLabel: <?= json_encode(t('Actual:')) ?>,
    differenceLabel: <?= json_encode(t('Difference:')) ?>,
    totalSalesLabel: <?= json_encode(t('Total Sales:')) ?>,
    viewZReport: <?= json_encode(t('View Z-Report')) ?>,
    close: <?= json_encode(t('Close')) ?>,
    endShift: <?= json_encode(t('End Shift')) ?>,
    failedToCloseShift: <?= json_encode(t('Failed to close shift:')) ?>,
    cashDrawerTitle: <?= json_encode(t('Cash Drawer')) ?>,
    cashDrawerHtml1: <?= json_encode(t('A web browser cannot send a direct "open drawer" signal.')) ?>,
    cashDrawerHtml2: <?= json_encode(t("If your cash drawer is wired to your receipt printer's kick port, it opens automatically every time a receipt prints — including just now, if one did.")) ?>,
    discountRange: <?= json_encode(t('Discount must be between 0 and 100%')) ?>,
    discountApplied: <?= json_encode(t('Discount Applied')) ?>,
    discountAppliedText: <?= json_encode(t('%d% discount has been applied to this sale.')) ?>,
    addItemsFirst: <?= json_encode(t('Add items to cart first.')) ?>,
    balanceMismatch: <?= json_encode(t('Balance Mismatch')) ?>,
    splitMustEqualTotal: <?= json_encode(t('Total split amounts must equal the total payable (%s)')) ?>,
    addItemsBeforeDiscount: <?= json_encode(t('Add items to cart before applying discount.')) ?>,
    discountAmountLabel: <?= json_encode(t('Discount Amount (%s)')) ?>,
    discountPercentageLabel: <?= json_encode(t('Discount Percentage (%)')) ?>,
    selectAllProducts: <?= json_encode(t('Select All Products')) ?>,
    minSellingPriceInfo: <?= json_encode(t('Min Selling Price: %s (Max: %s%%)')) ?>,
    flexibleAmount: <?= json_encode(t('Flexible Amount')) ?>,
    invalidDiscount: <?= json_encode(t('Invalid Discount')) ?>,
    discountCannotBeNegative: <?= json_encode(t('Discount cannot be negative.')) ?>,
    percentageCannotExceed100: <?= json_encode(t('Percentage cannot be greater than 100.')) ?>,
    noSelection: <?= json_encode(t('No Selection')) ?>,
    selectAtLeastOneProduct: <?= json_encode(t('Please select at least one product to discount.')) ?>,
    priceBelowMinimum: <?= json_encode(t('%s: Price %s is below minimum %s')) ?>,
    resultingPriceNegative: <?= json_encode(t('%s: Resulting price cannot be negative.')) ?>,
    priceValidationFailed: <?= json_encode(t('Price Validation Failed')) ?>,
    otherItemsUpdatedNote: <?= json_encode(t('Note: Other valid items were updated.')) ?>,
    ok: <?= json_encode(t('OK')) ?>,
    successfullyUpdatedItems: <?= json_encode(t('Successfully updated %d items.')) ?>,
    cartQtyLabel: <?= json_encode(t('cart qty:')) ?>,
    barcodeNotFound: <?= json_encode(t('Barcode not found')) ?>,
    editPriceTitle: <?= json_encode(t('Edit Price')) ?>,
    newPriceLabel: <?= json_encode(t('New unit price')) ?>,
    priceOverrideBelowMin: <?= json_encode(t('%s: price cannot be below the minimum selling price of %s')) ?>,
    priceUpdated: <?= json_encode(t('Price updated')) ?>,
    discountPermissionDenied: <?= json_encode(t('You do not have permission to apply a discount.')) ?>,
    unitLabel: <?= json_encode(t('Unit')) ?>,
    overrideAndProceed: <?= json_encode(t('Override and Proceed')) ?>,
    availableCredit: <?= json_encode(t('Available Credit')) ?>,
    outstandingLabel: <?= json_encode(t('Outstanding')) ?>,
    totalLabel: <?= json_encode(t('Total:')) ?>,
    shareViaWhatsApp: <?= json_encode(t('Share via WhatsApp')) ?>,
    whatsappNumberLabel: <?= json_encode(t('WhatsApp number (with country code)')) ?>,
    invalidWhatsappNumber: <?= json_encode(t('Please enter a valid phone number.')) ?>
};

// Phase 16 (pos_upgrade_plan.md §8) — loss-control permission split: a cashier
// can sell without necessarily being allowed to change a line's price or apply
// a discount. These flags only control client-side affordances (show/hide the
// edit-price pencil, the discount toolbar button already gated server-side in
// pos.php); api/pos/process_sale.php independently re-validates both — the
// real security boundary, never trusts these client flags alone.
const POS_CAN_PRICE_OVERRIDE = <?= json_encode(canEdit('pos_price_override')) ?>;

// Phase 15 (pos_upgrade_plan.md §8) — safeOutput() is a per-page LOCAL JS
// convention in this codebase (each page defines its own copy, never a
// global header.php helper — see tests/test_pos_phase8_registers_cli.php
// §3d, a regression guard added after a real ReferenceError bug). Needed
// here for the unit-conversion cart badge / dropdown labels.
function safeOutput(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

$(document).ready(function() {
    // Phase 10 (pos_upgrade_plan.md §7) — Select2 AJAX customer search, replacing
    // the old plain <select> hard-limited to 50 rows with no search at all.
    $('#customerSelect').select2({
        theme: 'bootstrap-5', width: '100%', placeholder: <?= json_encode(t('Walk-in Customer')) ?>, allowClear: true,
        ajax: {
            url: '<?= buildUrl('/api/pos/search_customers.php') ?>',
            dataType: 'json', delay: 300, cache: true,
            data: p => ({ q: p.term })
        }
    });

    // Phase 11 (pos_upgrade_plan.md §7) — show the selected customer's loyalty
    // balance and cap how many points they can redeem. Walk-in (no selection)
    // hides the section — points can't be earned/redeemed without a customer.
    $('#customerSelect').on('select2:select', function (e) {
        const points = e.params.data.loyalty_points || 0;
        $('#loyaltyAvailablePoints').text(points.toLocaleString());
        $('#redeemPointsInput').attr('max', points).val(0);
        $('#loyaltyPointsSection').removeClass('d-none');
        // Phase 14 (pos_upgrade_plan.md §8) — auto-apply this customer's price
        // tier if they have one set; the cashier can still change it manually.
        if ($('#posPriceGroupId').length && e.params.data.default_price_group_id) {
            $('#posPriceGroupId').val(e.params.data.default_price_group_id).trigger('change');
        }
        // Phase 19 (pos_upgrade_plan.md §8) — show available credit so a
        // cashier can see it before attempting a credit sale, not just after
        // being blocked. Server independently re-validates at checkout regardless.
        const limit = parseFloat(e.params.data.credit_limit) || 0;
        const outstanding = parseFloat(e.params.data.outstanding_balance) || 0;
        const available = limit - outstanding;
        if (limit > 0) {
            $('#customerCreditInfo').removeClass('d-none').html(
                `<i class="bi bi-credit-card"></i> ${PT.availableCredit}: <strong class="${available <= 0 ? 'text-danger' : 'text-success'}">${POS_CURRENCY} ${available.toLocaleString()}</strong>` +
                (outstanding > 0 ? ` <span class="text-muted">(${PT.outstandingLabel}: ${POS_CURRENCY} ${outstanding.toLocaleString()})</span>` : '')
            );
        } else {
            $('#customerCreditInfo').addClass('d-none').html('');
        }
        calculateCartTotal();
    }).on('select2:clear select2:unselect', function () {
        $('#loyaltyPointsSection').addClass('d-none');
        $('#redeemPointsInput').val(0);
        $('#customerCreditInfo').addClass('d-none').html('');
        // Back to the default group (first option, seeded as "Retail") for a
        // walk-in / cleared customer.
        if ($('#posPriceGroupId').length) {
            $('#posPriceGroupId').val($('#posPriceGroupId option:first').val()).trigger('change');
        }
        calculateCartTotal();
    });

    // Phase 10 (pos_upgrade_plan.md §7) — inline "+ New Customer" quick-add.
    $('#btnQuickAddCustomer').on('click', function () {
        $('#qac_name, #qac_phone').val('');
        new bootstrap.Modal(document.getElementById('quickAddCustomerModal')).show();
    });
    $('#btnSaveQuickCustomer').on('click', function () {
        const name = $('#qac_name').val().trim();
        if (!name) { Swal.fire(<?= json_encode(t('Name required')) ?>, <?= json_encode(t("Please enter the customer's name.")) ?>, 'warning'); return; }
        const btn = $(this);
        btn.prop('disabled', true);
        $.post('<?= buildUrl('/api/quick_add_customer.php') ?>', {
            customer_name: name, phone: $('#qac_phone').val().trim()
        }, function (res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('quickAddCustomerModal')).hide();
                setCustomerSelection(res.customer_id, name);
                saveCartToStorage();
                Swal.fire({ icon: 'success', title: <?= json_encode(t('Customer Added')) ?>, text: <?= json_encode(t('%s has been added and selected.')) ?>.replace('%s', name), timer: 1800, showConfirmButton: false });
            } else {
                Swal.fire(<?= json_encode(t('Error')) ?>, res.message, 'error');
            }
        }, 'json').always(() => btn.prop('disabled', false));
    });

    // Load cart from localStorage
    loadCartFromStorage();

    // Phase 6 (pos_upgrade_plan.md): the dropdown's <option> list is already
    // narrowed server-side to this cashier's own warehouse assignment. If
    // that leaves exactly one choice, lock it — no illusion of a choice that
    // isn't really there. More than one (e.g. a supervisor covering several
    // warehouses) stays a real dropdown, just constrained to their set.
    (function () {
        const $realOptions = $('#posWarehouseId option').filter(function () { return $(this).val() !== ''; });
        if ($realOptions.length === 1) {
            $('#posWarehouseId').val($realOptions.first().val()).prop('disabled', true);
        } else if ($realOptions.length === 0) {
            $('#posWarehouseId').after(
                '<div class="text-danger small mt-1" id="posNoWarehouseWarning">' +
                '<i class="bi bi-exclamation-triangle"></i> ' + <?= json_encode(t('No warehouse is assigned to your account — contact an administrator.')) ?> + '</div>'
            );
        }
    })();

    // Phase 14 (pos_upgrade_plan.md §8) — selling price tiers. Only rendered
    // when more than one active group exists (see pos.php); sync the initial
    // selection and reload the product grid (with its resolved
    // effective_price) whenever the cashier changes it. Existing cart lines
    // keep the price they were added at — only new additions use the new
    // group, matching how a real till behaves.
    if ($('#posPriceGroupId').length) {
        posSelectedPriceGroupId = parseInt($('#posPriceGroupId').val()) || 0;
        $('#posPriceGroupId').on('change', function () {
            posSelectedPriceGroupId = parseInt($(this).val()) || 0;
            loadProducts();
        });
    }

    // Shared Project → Warehouse cascade (assets/js/warehouse-project-filter.js):
    // no project -> only warehouses not assigned to any project;
    // project selected -> only that project's warehouses.
    bindWarehouseToProject({
        project:    '#posProjectId',
        warehouse:  '#posWarehouseId',
        onFiltered: function () { loadProducts(); }
    });

    // VAT selector — two options only (No Tax / VAT 18%), cashier-chosen. Sync with
    // any restored cart, then apply the chosen rate to every line on change.
    if (cart.length) {
        saleVatRate = (parseFloat(cart[0].tax_rate) === 18) ? 18 : 0;
        cart.forEach(item => { item.tax_rate = saleVatRate; });
    }
    $('#saleVatSelect').val(String(saleVatRate));
    $('#saleVatSelect').on('change', function() {
        saleVatRate = (parseFloat($(this).val()) === 18) ? 18 : 0;
        cart.forEach(item => { item.tax_rate = saleVatRate; });
        updateCartDisplay();
        saveCartToStorage();
    });

    // Load initial data
    loadCategories();
    loadProducts();
    
    // Live search with debouncing
    let searchTimeout;
    $('#productSearch').on('keyup', function(e) {
        clearTimeout(searchTimeout);
        const searchTerm = $(this).val().trim();
        
        // Search after 500ms of no typing
        searchTimeout = setTimeout(function() {
            console.log('Live search:', searchTerm);
            loadProducts('all', searchTerm);
        }, 500);
        
        // Immediate search on Enter
        if (e.key === 'Enter') {
            clearTimeout(searchTimeout);
            searchProducts();
        }
    });
    
    // Payment method change
    $('input[name="paymentMethod"]').change(function() {
        const method = $(this).val();
        $('#cashPaymentSection').toggle(method === 'cash');
        if (method === 'cash') {
            calculateChange();
        }
    });
    
    // Calculate difference when ending cash changes
    $('#endingCash').on('input', function() {
        const ending = parseFloat($(this).val()) || 0;
        const calculated = <?= $cash_balance ?>;
        const difference = ending - calculated;
        $('#cashDifference').text(POS_CURRENCY + ' ' + difference.toFixed(2));
    });
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        if (e.key === 'F1') {
            e.preventDefault();
            $('#productSearch').focus();
        } else if (e.key === 'F2') {
            e.preventDefault();
            clearCart();
        } else if (e.key === 'F3') {
            e.preventDefault();
            processPayment();
        } else if (e.key === 'F9') {
            e.preventDefault();
            showHeldSales();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            $('#productSearch').val('');
            loadProducts();
        }
    });
});

// Phase 10 (pos_upgrade_plan.md §7) — the customer <select> is now Select2-in-
// AJAX-mode, so it has no static <option> list to pick from any more. Setting
// .val(id) alone can't show the right label for an id Select2 has never seen
// (e.g. restoring from localStorage, or loading a held sale) — this creates a
// real <option> with the correct text first, then selects it and refreshes
// the visible Select2 widget via 'change'.
function setCustomerSelection(id, text) {
    const $sel = $('#customerSelect');
    if (!id) { $sel.val('').trigger('change'); return; }
    if (!$sel.find(`option[value="${id}"]`).length) {
        $sel.append(new Option(text || (<?= json_encode(t('Customer #')) ?> + id), id, true, true));
    } else {
        $sel.val(id);
    }
    $sel.trigger('change');
}

// Save/Load cart from localStorage
function saveCartToStorage() {
    try {
        localStorage.setItem('pos_cart', JSON.stringify(cart));
        localStorage.setItem('pos_customer', $('#customerSelect').val() || '');
        localStorage.setItem('pos_customer_name', $('#customerSelect option:selected').text() || '');
    } catch (e) {
        console.error('Error saving cart:', e);
    }
}

function loadCartFromStorage() {
    try {
        const savedCart = localStorage.getItem('pos_cart');
        const savedCustomer = localStorage.getItem('pos_customer');
        const savedCustomerName = localStorage.getItem('pos_customer_name');

        if (savedCart) {
            cart = JSON.parse(savedCart);
            updateCartDisplay();
        }

        if (savedCustomer) {
            setCustomerSelection(savedCustomer, savedCustomerName);
        }
    } catch (e) {
        console.error('Error loading cart:', e);
        cart = [];
    }
}

function clearCartStorage() {
    try {
        localStorage.removeItem('pos_cart');
        localStorage.removeItem('pos_customer');
        localStorage.removeItem('pos_customer_name');
    } catch (e) {
        console.error('Error clearing cart storage:', e);
    }
}

function loadCategories() {
    $.ajax({
        url: '<?= buildUrl('/api/get_categories.php') ?>',
        type: 'GET',
        data: { type: 'product', status: 'active' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                categories = response.data;
                const container = $('#categoryButtons');
                
                // Add category buttons with data-category attribute
                categories.slice(0, 8).forEach(category => {
                    container.append(`
                        <button type="button" class="btn btn-sm btn-outline-secondary" 
                                data-category="${category.category_id}"
                                onclick="loadProductsByCategory(${category.category_id})">
                            ${category.category_name}
                        </button>
                    `);
                });
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading categories:', error);
        }
    });
}

let loadProductsXhr = null; // To track and abort previous requests

function loadProducts(categoryId = 'all', searchTerm = '') {
    if (loadProductsXhr) loadProductsXhr.abort(); // Abort previous request before starting new one
    
    $('#loadingProducts').show();
    $('#productGrid').empty();
    
    console.log('=== LOADING PRODUCTS ===');
    console.log('Category:', categoryId);
    console.log('Search:', searchTerm);
    
    const apiUrl = '<?= buildUrl('/api/pos/simple_products.php') ?>';
    const warehouseId = $('#posWarehouseId').val();
    const projectId = $('#posProjectId').val();
    
    loadProductsXhr = $.ajax({
        url: apiUrl,
        url: apiUrl,
        type: 'GET',
        data: {
            category: categoryId !== 'all' ? categoryId : '',
            search: searchTerm,
            warehouse_id: warehouseId,
            project_id: projectId,
            price_group_id: posSelectedPriceGroupId || ''
        },
        dataType: 'json',
        success: function(response) {
            console.log('=== API RESPONSE ===');
            console.log('Success:', response.success);
            console.log('Data:', response.data);
            console.log('Count:', response.data ? response.data.length : 0);
            
            $('#loadingProducts').hide();
            
            if (response.success && response.data && response.data.length > 0) {
                products = response.data;
                // Keep a full-catalog copy for the barcode scanner so it can find
                // any product even when the grid is filtered to one category.
                if (categoryId === 'all' || categoryId === '' || categoryId === undefined) {
                    allProducts = response.data;
                }
                const grid = $('#productGrid');
                grid.empty();
                
                console.log('Rendering', products.length, 'products...');
                
                response.data.forEach(product => {
                    const isService = product.is_service == 1 || product.is_service == '1';
                    const projectStock = parseFloat(product.project_stock) || 0;
                    
                    // Determine image content
                    let imageContent;
                    if (product.image_url) {
                        let imgPath = product.image_url;
                        if (!imgPath.startsWith('http') && !imgPath.startsWith('/')) {
                             imgPath = '../../../' + product.image_url; 
                        }
                        imageContent = `<img src="${imgPath}" alt="${product.product_name}" style="height: 100%; max-width: 100%; object-fit: contain;">`;
                    } else {
                        imageContent = `<i class="bi ${isService ? 'bi-briefcase' : 'bi-box-seam'}" style="font-size: 3rem; color: #0d6efd;"></i>`;
                    }

                    const card = `
                        <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
                            <div class="card product-card h-100 ${projectStock > 0 ? 'border-info shadow-sm' : ''}" onclick="showProductQuickView(${product.product_id})">
                                <div class="card-body text-center p-2">
                                    <div class="mb-2" style="height: 80px; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative;">
                                        ${imageContent}
                                        ${!isService && product.stock_quantity <= 10 ? '<span class="badge bg-danger position-absolute top-0 end-0" style="font-size: 8px;">' + PT.lowStock + '</span>' : ''}
                                        ${projectStock > 0 ? '<span class="badge bg-info position-absolute top-0 start-0" style="font-size: 8px;"><i class="bi bi-star-fill"></i> ' + PT.projectStock + '</span>' : ''}
                                    </div>
                                    ${isService ? '<span class="badge bg-info text-white mb-1">' + PT.service + '</span>' : ''}
                                    <h6 class="card-title mb-1 small text-truncate fw-bold" title="${product.product_name}">${product.product_name}</h6>
                                    <p class="card-text text-muted small mb-1">${product.sku || ''}</p>
                                    <p class="card-text fw-bold text-primary mb-1">${POS_CURRENCY} ${parseFloat(product.effective_price ?? product.selling_price).toLocaleString()}</p>
                                    ${!isService ? `<p class="card-text small ${product.stock_quantity <= 10 ? 'text-danger fw-bold' : 'text-muted'}">
                                        ${PT.qtyLabel} ${product.stock_quantity}
                                    </p>` : '<p class="card-text small text-muted"><i class="bi bi-infinity"></i> ' + PT.service + '</p>'}
                                </div>
                            </div>
                        </div>
                    `;
                    grid.append(card);
                });
                
                console.log('Products rendered successfully!');
            } else {
                console.warn('No products in response');
                $('#productGrid').html(`
                    <div class="col-12 text-center py-5">
                        <i class="bi bi-search" style="font-size: 3rem; color: #6c757d;"></i>
                        <h5 class="mt-3 text-muted">${PT.noProductsFound}</h5>
                        <p class="text-muted">${PT.tryDifferentSearch}</p>
                    </div>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('=== API ERROR ===');
            console.error('Status:', status);
            console.error('Error:', error);
            console.error('Response:', xhr.responseText);
            console.error('Status Code:', xhr.status);
            
            $('#loadingProducts').hide();
            $('#productGrid').html(`
                <div class="col-12 text-center py-5">
                    <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: #dc3545;"></i>
                    <h5 class="mt-3 text-danger">${PT.errorLoadingProducts}</h5>
                    <p class="text-muted">${PT.statusLabel} ${xhr.status} - ${error}</p>
                    <button class="btn btn-primary" onclick="loadProducts()">${PT.retry}</button>
                </div>
            `);
        }
    });
}

function loadProductsByCategory(categoryId) {
    console.log('Loading category:', categoryId);
    
    // Update active button
    $('#categoryButtons button').removeClass('active btn-primary').addClass('btn-outline-secondary');
    
    // Find and activate the clicked button
    if (categoryId === 'all') {
        $('#categoryButtons button:first').removeClass('btn-outline-secondary').addClass('active btn-primary');
    } else {
        $('#categoryButtons button').each(function() {
            if ($(this).data('category') == categoryId) {
                $(this).removeClass('btn-outline-secondary').addClass('active btn-primary');
            }
        });
    }
    
    // Clear search when changing category
    $('#productSearch').val('');
    
    // Load products for this category
    loadProducts(categoryId, '');
}

function searchProducts() {
    const searchTerm = $('#productSearch').val().trim();
    console.log('Manual search:', searchTerm);
    
    // Reset category to "All" when searching
    $('#categoryButtons button').removeClass('active btn-primary').addClass('btn-outline-secondary');
    $('#categoryButtons button:first').removeClass('btn-outline-secondary').addClass('active btn-primary');
    
    loadProducts('all', searchTerm);
}

let currentProductUnits = []; // Phase 15 (pos_upgrade_plan.md §8) — this product's extra selling units
let currentProductSerials = []; // Phase 26 (pos_upgrade_plan.md §9) — this product's in_stock serials in the current warehouse
let selectedSerials = [];       // the cashier's checked subset for the line about to be added

function showProductQuickView(productId) {
    const product = products.find(p => p.product_id == productId);
    if (!product) return;

    currentProduct = product;
    currentProductUnits = [];
    currentProductSerials = [];
    selectedSerials = [];
    const isSerialTracked = currentProduct.is_service != 1 && currentProduct.track_serials == 1;

    const html = `
        <h6>${currentProduct.product_name}</h6>
        <p class="text-muted small mb-2">${currentProduct.sku || PT.noSku}</p>
        <p class="text-success fw-bold" id="quickViewPrice">${POS_CURRENCY} ${parseFloat(currentProduct.effective_price ?? currentProduct.selling_price).toLocaleString()}</p>
        ${currentProduct.is_service != 1 ? `<p class="small ${currentProduct.stock_quantity <= 10 ? 'text-danger' : 'text-muted'}">
            ${PT.stockLabel} ${currentProduct.stock_quantity}
        </p>` : '<p class="small text-muted"><i class="bi bi-infinity"></i> ' + PT.service + '</p>'}

        <div class="mb-3 d-none" id="quickViewUnitWrap">
            <label class="form-label">${PT.unitLabel}</label>
            <select class="form-select" id="quickViewUnit" onchange="updateQuickViewUnitPrice()"></select>
        </div>

        <div class="mb-3 ${isSerialTracked ? 'd-none' : ''}" id="quickViewQtyWrap">
            <label class="form-label">${PT.quantityLabel}</label>
            <div class="input-group">
                <button class="btn btn-outline-secondary" type="button" onclick="adjustQuantity(-1)">-</button>
                <input type="number" class="form-control text-center" id="quickViewQty"
                       value="1" min="1" step="1">
                <button class="btn btn-outline-secondary" type="button" onclick="adjustQuantity(1)">+</button>
            </div>
        </div>

        <div class="mb-3 ${isSerialTracked ? '' : 'd-none'}" id="quickViewSerialWrap">
            <label class="form-label d-flex justify-content-between">
                <span>${PT.serialTrackedLabel}</span>
                <span class="badge bg-primary" id="quickViewSerialCount">0</span>
            </label>
            <div class="border rounded p-2" style="max-height:180px;overflow-y:auto;" id="quickViewSerialList">
                <div class="text-muted small">${PT.loadingSerials}</div>
            </div>
        </div>

        <div class="d-grid gap-2">
            <button class="btn btn-primary" onclick="addToCart()">
                <i class="bi bi-cart-plus"></i> ${PT.addToCart}
            </button>
            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
                ${PT.cancel}
            </button>
        </div>
    `;

    $('#quickViewContent').html(html);

    // Proper way to handle focus in Bootstrap modals to avoid aria-hidden issues
    $('#productQuickView').off('shown.bs.modal').on('shown.bs.modal', function () {
        if (!isSerialTracked) $('#quickViewQty').focus().select();
    });

    $('#productQuickView').modal('show');

    // Phase 26 (pos_upgrade_plan.md §9) — fetch this product's in_stock
    // serials for the currently selected warehouse. Mirrors the unit-fetch
    // pattern immediately below.
    if (isSerialTracked) {
        const warehouseId = $('#posWarehouseId').val();
        $.getJSON('<?= buildUrl('/api/pos/get_available_serials.php') ?>', { product_id: productId, warehouse_id: warehouseId }, function (res) {
            if (currentProduct.product_id != productId) return; // modal moved on already
            currentProductSerials = (res.success && res.data) ? res.data : [];
            renderSerialPicker();
        }).fail(function () {
            if (currentProduct.product_id != productId) return;
            currentProductSerials = [];
            renderSerialPicker();
        });
    }

    // Phase 15 (pos_upgrade_plan.md §8) — fetch this product's extra selling
    // units (base unit is always implicitly available and needs no dropdown
    // entry when it's the only option).
    if (currentProduct.is_service != 1) {
        $.getJSON('<?= buildUrl('/api/pos/get_product_units.php') ?>', { product_id: productId }, function (res) {
            if (currentProduct.product_id != productId) return; // modal moved on already
            currentProductUnits = (res.success && res.data) ? res.data : [];
            if (!currentProductUnits.length) return;

            const sel = $('#quickViewUnit');
            sel.empty();
            sel.append(`<option value="">${safeOutput(currentProduct.unit || '')} (x1)</option>`);
            currentProductUnits.forEach(u => {
                sel.append(`<option value="${safeOutput(u.unit_label)}">${safeOutput(u.unit_label)} (x${u.base_unit_multiplier})</option>`);
            });
            $('#quickViewUnitWrap').removeClass('d-none');
        });
    }
}

// Phase 15 (pos_upgrade_plan.md §8) — live price preview as the cashier
// switches units; the server independently re-resolves this at checkout,
// this is display-only.
function updateQuickViewUnitPrice() {
    const label = $('#quickViewUnit').val();
    const basePrice = parseFloat(currentProduct.effective_price ?? currentProduct.selling_price) || 0;
    if (!label) {
        $('#quickViewPrice').text(POS_CURRENCY + ' ' + basePrice.toLocaleString());
        return;
    }
    const u = currentProductUnits.find(x => x.unit_label === label);
    if (!u) return;
    const perUnitPrice = (u.unit_price_override !== null && u.unit_price_override !== undefined)
        ? parseFloat(u.unit_price_override)
        : basePrice * parseFloat(u.base_unit_multiplier);
    $('#quickViewPrice').text(POS_CURRENCY + ' ' + perUnitPrice.toLocaleString());
}

// Phase 26 (pos_upgrade_plan.md §9) — renders the checkbox list of in_stock
// serials; the running "selected" count IS the line's quantity (a serial is
// qty-always-1), so there is no separate quantity input for these lines.
function renderSerialPicker() {
    const list = $('#quickViewSerialList');
    if (!currentProductSerials.length) {
        list.html(`<div class="text-muted small">${PT.noSerialsAvailable}</div>`);
        $('#quickViewSerialCount').text('0');
        return;
    }
    let html = '';
    currentProductSerials.forEach(sn => {
        const id = 'serial_' + safeOutput(sn).replace(/[^a-zA-Z0-9]/g, '_');
        html += `
            <div class="form-check">
                <input class="form-check-input serial-check" type="checkbox" value="${safeOutput(sn)}" id="${id}" onchange="toggleSerialSelection(this)">
                <label class="form-check-label small" for="${id}">${safeOutput(sn)}</label>
            </div>`;
    });
    list.html(html);
    $('#quickViewSerialCount').text(selectedSerials.length);
}

function toggleSerialSelection(checkbox) {
    const sn = checkbox.value;
    if (checkbox.checked) {
        if (!selectedSerials.includes(sn)) selectedSerials.push(sn);
    } else {
        selectedSerials = selectedSerials.filter(s => s !== sn);
    }
    $('#quickViewSerialCount').text(selectedSerials.length);
}

function adjustQuantity(amount) {
    const input = $('#quickViewQty');
    let current = parseInt(input.val()) || 1;
    const newValue = Math.max(1, current + amount);
    input.val(newValue);
}

function addToCart() {
    if (!currentProduct) return;

    const isSerialTracked = currentProduct.is_service != 1 && currentProduct.track_serials == 1;
    if (isSerialTracked && selectedSerials.length === 0) {
        Swal.fire({ icon: 'warning', title: PT.error, text: PT.selectAtLeastOneSerial });
        return;
    }

    // Phase 26 (pos_upgrade_plan.md §9) — a serial-tracked line's quantity IS
    // the count of serials picked; there is no separate quantity input for it.
    const quantity = isSerialTracked ? selectedSerials.length : (parseInt($('#quickViewQty').val()) || 1);
    const basePrice = parseFloat(currentProduct.effective_price ?? currentProduct.selling_price) || 0;

    // Phase 15 (pos_upgrade_plan.md §8) — unit conversion. An empty
    // selection = base unit, unchanged behaviour. item.price/quantity stay
    // "per whatever unit is on this line" so every existing cart/discount/
    // receipt calculation (price × quantity) keeps working unmodified; the
    // server independently re-resolves the true base-unit price/quantity
    // from unit_label at checkout — never trusts this client-side figure.
    const unitLabel = $('#quickViewUnit').length ? ($('#quickViewUnit').val() || '') : '';
    let linePrice = basePrice;
    if (unitLabel) {
        const u = currentProductUnits.find(x => x.unit_label === unitLabel);
        if (u) {
            linePrice = (u.unit_price_override !== null && u.unit_price_override !== undefined)
                ? parseFloat(u.unit_price_override)
                : basePrice * parseFloat(u.base_unit_multiplier);
        }
    }

    // A different unit of the same product is a DIFFERENT cart line — 2
    // pieces and 3 cartons of the same item can't be merged into one qty.
    // A serial-tracked line is ALSO never merged — each Add to Cart click
    // carries its own distinct serial set, so two additions of the "same"
    // product must stay two separate lines with their own serial_numbers.
    const existingItem = !isSerialTracked
        ? cart.find(item => item.product_id == currentProduct.product_id && (item.unit_label || '') === unitLabel)
        : null;

    if (existingItem) {
        existingItem.quantity += quantity;
    } else {
        cart.push({
            product_id: currentProduct.product_id,
            product_name: currentProduct.product_name,
            sku: currentProduct.sku,
            // Phase 14 (pos_upgrade_plan.md §8) — the chosen price group's
            // override when one exists for this product, else plain
            // selling_price (effective_price === selling_price when no group
            // is active — simple_products.php guarantees this).
            price: linePrice,
            quantity: quantity,
            unit_label: unitLabel || undefined, // Phase 15 — resolved server-side, this is display + payload only
            tax_rate: saleVatRate, // cashier-selected VAT (0 or 18), not auto-applied from the product
            min_selling_price: parseFloat(currentProduct.min_selling_price) || 0,
            discount_type: 'percentage', // Default to percentage
            discount_value: 0,
            discount_percent: 0,
            discounted_price: linePrice,
            // Phase 26 (pos_upgrade_plan.md §9) — the specific serials this
            // line will consume; re-validated server-side at checkout.
            serial_numbers: isSerialTracked ? selectedSerials.slice() : undefined
        });
    }

    updateCartDisplay();
    saveCartToStorage();
    $('#productQuickView').modal('hide');
}

function updateCartDisplay() {
    const cartBody = $('#cartBody');
    const emptyCart = $('#emptyCart');
    const cartTable = $('#cartTable');
    
    if (cart.length === 0) {
        cartBody.empty();
        cartTable.hide();
        emptyCart.show();
        $('#cartItemCount').text('0');
    } else {
        emptyCart.hide();
        cartTable.show();
        cartBody.empty();
        
        cart.forEach((item, index) => {
            const itemTotal = item.discounted_price * item.quantity;
            const originalTotal = item.price * item.quantity;
            
            let priceDisplay = item.price.toLocaleString();
            let discountBadge = '';
            
            // Flexible check for discount existence
            if (item.discounted_price < item.price) {
                priceDisplay = `
                    <span class="text-decoration-line-through text-muted small">${item.price.toLocaleString()}</span><br>
                    <span class="text-danger small">${item.discounted_price.toLocaleString()}</span>
                `;
                
                if (item.discount_type === 'fixed') {
                     // Fixed Amount Logic: Show exact amount off
                     const amount = item.price - item.discounted_price;
                     // Clean up potential float issues for display
                     const cleanAmount = parseFloat(amount.toFixed(2));
                     if (cleanAmount > 0) {
                        discountBadge = `<br><span class="badge bg-danger">-${cleanAmount.toLocaleString()}</span>`;
                     }
                } else {
                     // Percentage Logic (Default): Show percent off
                     if (item.discount_percent > 0) {
                        discountBadge = `<br><span class="badge bg-danger">-${item.discount_percent}%</span>`;
                     }
                }
            }

            const row = `
                <tr>
                    <td>
                        <strong class="small">${item.product_name}</strong>
                        ${item.unit_label ? `<br><span class="badge bg-light text-dark border">${safeOutput(item.unit_label)}</span>` : ''}
                        ${discountBadge}
                    </td>
                    <td class="text-end">
                        <span class="small">${priceDisplay}</span>
                        ${POS_CAN_PRICE_OVERRIDE ? `<br><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" style="font-size:10px;" onclick="editLinePrice(${index})" title="${PT.editPriceTitle}"><i class="bi bi-pencil"></i> ${PT.editPriceTitle}</button>` : ''}
                    </td>
                    <td class="text-center" style="padding: 0.25rem;">
                        ${item.serial_numbers ?
                            // Phase 26 (pos_upgrade_plan.md §9) — a serial-tracked
                            // line's quantity IS its picked serial count; editing it
                            // here would desync from serial_numbers, so it's
                            // read-only (remove and re-add to change the selection).
                            `<span class="badge bg-secondary" title="${safeOutput(item.serial_numbers.join(', '))}">${item.quantity}</span>` :
                            `<div class="d-flex align-items-center justify-content-center" style="gap: 2px;">
                                <button class="btn btn-outline-secondary" onclick="updateCartQuantity(${index}, -1)"
                                        style="padding: 2px 4px; font-size: 10px; line-height: 1; min-width: 18px;">-</button>
                                <input type="number" class="form-control text-center"
                                       style="width: 35px; padding: 2px; font-size: 11px; height: 22px;"
                                       value="${item.quantity}" min="1"
                                       onchange="updateCartQuantityInput(${index}, this.value)">
                                <button class="btn btn-outline-secondary" onclick="updateCartQuantity(${index}, 1)"
                                        style="padding: 2px 4px; font-size: 10px; line-height: 1; min-width: 18px;">+</button>
                            </div>`
                        }
                    </td>
                    <td class="text-end">
                        <strong class="small text-success">${itemTotal.toLocaleString()}</strong>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-link text-danger p-0" onclick="removeFromCart(${index})"
                                style="font-size: 14px;" title="${PT.remove}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            cartBody.append(row);
        });
        
        $('#cartItemCount').text(cart.length);
    }
    
    calculateCartTotal();
}

function updateCartQuantity(index, change) {
    if (cart[index]) {
        const newQuantity = cart[index].quantity + change;
        if (newQuantity >= 1) {
            cart[index].quantity = newQuantity;
            updateCartDisplay();
            saveCartToStorage();
        }
    }
}

function updateCartQuantityInput(index, value) {
    const quantity = parseInt(value) || 1;
    if (quantity >= 1 && cart[index]) {
        cart[index].quantity = quantity;
        updateCartDisplay();
        saveCartToStorage();
    }
}

// Phase 16 (pos_upgrade_plan.md §8) — manual price override, only reachable
// when POS_CAN_PRICE_OVERRIDE is true (button isn't even rendered otherwise).
// Overriding the price clears any discount already applied to this line —
// one deliberate override, not stacked with a percentage/fixed discount.
function editLinePrice(index) {
    const item = cart[index];
    if (!item) return;

    Swal.fire({
        title: PT.editPriceTitle,
        input: 'number',
        inputLabel: PT.newPriceLabel,
        inputValue: item.price,
        inputAttributes: { min: item.min_selling_price, step: '0.01' },
        showCancelButton: true,
        confirmButtonText: PT.ok,
        cancelButtonText: PT.cancel,
        inputValidator: (value) => {
            const v = parseFloat(value);
            if (isNaN(v) || v < item.min_selling_price) {
                return PT.priceOverrideBelowMin.replace('%s', item.product_name).replace('%s', item.min_selling_price.toLocaleString());
            }
        }
    }).then(result => {
        if (!result.isConfirmed) return;
        const newPrice = parseFloat(result.value);
        item.price = newPrice;
        item.discounted_price = newPrice;
        item.discount_percent = 0;
        item.discount_value = 0;
        item.manual_price_override = true;
        updateCartDisplay();
        saveCartToStorage();
        Swal.fire({ icon: 'success', title: PT.priceUpdated, timer: 1200, showConfirmButton: false });
    });
}

function removeFromCart(index) {
    cart.splice(index, 1);
    updateCartDisplay();
    saveCartToStorage();
}

function clearCart() {
    if (cart.length === 0) return;
    
    Swal.fire({
        title: PT.clearCartTitle,
        text: PT.clearCartText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: PT.yesClearIt
    }).then((result) => {
        if (result.isConfirmed) {
            cart = [];
            currentDiscountPercentage = 0; // Reset global discount if any
            updateCartDisplay();
            clearCartStorage();
        }
    });
}

function calculateCartTotal() {
    let subtotal = 0;
    let totalTax = 0;
    
    cart.forEach(item => {
        const itemTotal = item.discounted_price * item.quantity;
        subtotal += itemTotal;
        
        // Per-product tax calculation
        const itemTax = itemTotal * (item.tax_rate / 100);
        totalTax += itemTax;
    });

    let total = subtotal + totalTax;

    // Phase 11 (pos_upgrade_plan.md §7) — loyalty point redemption preview.
    // Client-side only, for display; process_sale.php re-validates the real
    // balance and computes the authoritative discount server-side.
    const redeemPts = parseInt($('#redeemPointsInput').val()) || 0;
    const $preview = $('#loyaltyDiscountPreview');
    if (redeemPts > 0 && total > 0) {
        const loyaltyDiscount = Math.min(redeemPts * POS_LOYALTY_REDEEM_VALUE, total);
        total -= loyaltyDiscount;
        $preview.text(PT.loyaltyDiscountLabel + ' -' + POS_CURRENCY + ' ' + loyaltyDiscount.toLocaleString('en-US', {minimumFractionDigits: 2})).removeClass('d-none');
    } else {
        $preview.addClass('d-none');
    }

    $('#cartSubtotal').text(POS_CURRENCY + ' ' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2}));
    $('#cartTax').text(POS_CURRENCY + ' ' + totalTax.toLocaleString('en-US', {minimumFractionDigits: 2}));
    $('#cartTotal').text(POS_CURRENCY + ' ' + total.toLocaleString('en-US', {minimumFractionDigits: 2}));

    // Hide discount row as we now handle per-item discount
    $('#discountRow').hide();
    
    return total;
}

function calculateChange() {
    const total = parseFloat($('#cartTotal').text().replace(POS_CURRENCY + ' ', '').replace(/,/g, '')) || 0;
    const tendered = parseFloat($('#amountTendered').val()) || 0;
    const change = tendered - total;
    
    if (change >= 0) {
        $('#changeAlert').show();
        $('#changeAmount').text(POS_CURRENCY + ' ' + change.toLocaleString('en-US', {minimumFractionDigits: 2}));
    } else {
        $('#changeAlert').hide();
    }
}

function processPayment() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: PT.emptyCartTitle,
            text: PT.emptyCartText,
            timer: 2000
        });
        return;
    }

    <?php if (!$shift_active): ?>
    Swal.fire({
        icon: 'warning',
        title: PT.noActiveShiftTitle,
        text: PT.noActiveShiftText,
        showConfirmButton: true,
        confirmButtonText: PT.startShift
    }).then((result) => {
        if (result.isConfirmed) {
            startShift();
        }
    });
    return;
    <?php endif; ?>

    // Warehouse is compulsory — a sale must come out of a specific warehouse's stock.
    const warehouseId = $('#posWarehouseId').val();
    if (!warehouseId) {
        Swal.fire({ icon: 'warning', title: PT.warehouseRequiredTitle, text: PT.warehouseRequiredText });
        $('#posWarehouseId').focus();
        return;
    }

    const paymentMethod = $('input[name="paymentMethod"]:checked').val();
    const customerId = $('#customerSelect').val();
    const total = parseFloat($('#cartTotal').text().replace(POS_CURRENCY + ' ', '').replace(/,/g, '')) || 0;
    
    if (paymentMethod === 'cash') {
        const tendered = parseFloat($('#amountTendered').val()) || 0;
        if (tendered < total) {
            Swal.fire({
                icon: 'error',
                title: PT.insufficientPaymentTitle,
                text: PT.insufficientPaymentText,
                timer: 2000
            });
            return;
        }
    }

    // Credit sale — money owed by the customer, settled later. Requires a named
    // customer (cannot put a walk-in on account). Any amount typed in the tendered
    // box is treated as a deposit paid now; the rest becomes the balance due.
    if (paymentMethod === 'credit' && (!customerId || customerId === '')) {
        Swal.fire({ icon: 'warning', title: PT.customerRequiredTitle, text: PT.customerRequiredText });
        return;
    }
    
    // Calculate totals based on per-item data
    let subtotal = 0;
    let totalDiscount = 0;
    let totalTax = 0;
    
    cart.forEach(item => {
        const itemOriginalTotal = item.price * item.quantity;
        const itemDiscountedTotal = item.discounted_price * item.quantity;
        const itemTax = itemDiscountedTotal * (item.tax_rate / 100);
        
        subtotal += itemOriginalTotal;
        totalDiscount += (itemOriginalTotal - itemDiscountedTotal);
        totalTax += itemTax;
    });
    
    let calculatedTotal = (subtotal - totalDiscount) + totalTax;

    // Phase 11 (pos_upgrade_plan.md §7) — fold the loyalty redemption preview
    // into the total BEFORE computing amount_tendered/change_given below, so
    // the change due a cashier sees (and the amount_paid/change_given sent to
    // the server) reflects what the customer actually owes after redeeming
    // points — not the pre-discount total. The server independently
    // re-validates and applies the real discount regardless of this value.
    const redeemPointsRequested = parseInt($('#redeemPointsInput').val()) || 0;
    if (redeemPointsRequested > 0) {
        calculatedTotal = Math.max(0, calculatedTotal - Math.min(redeemPointsRequested * POS_LOYALTY_REDEEM_VALUE, calculatedTotal));
    }

    // Calculate global percentage for records if needed (weighted average or just 0)
    // We will send 0 as global percentage since we use itemized discounts
    const globalDiscountPercent = 0;

    const paymentData = {
        receipt_number: currentReceiptNumber,
        customer_id: customerId || null,
        warehouse_id: warehouseId,
        project_id: $('#posProjectId').val() || null,
        price_group_id: posSelectedPriceGroupId || null,
        items: cart,
        subtotal: subtotal,
        discount_percentage: globalDiscountPercent,
        discount_amount: totalDiscount,
        tax: totalTax,
        total: calculatedTotal,
        payment_method: isSplitPayment ? 'split' : paymentMethod,
        split_details: isSplitPayment ? splitAmounts : null,
        amount_tendered: isSplitPayment ? calculatedTotal : (paymentMethod === 'cash' ? parseFloat($('#amountTendered').val()) || calculatedTotal : calculatedTotal),
        change_given: isSplitPayment ? 0 : (paymentMethod === 'cash' ? (parseFloat($('#amountTendered').val()) || calculatedTotal) - calculatedTotal : 0),
        // How much is actually collected now. Credit: deposit typed in the tendered
        // box (0 = full credit). Everything else: paid in full.
        amount_paid: (paymentMethod === 'credit')
            ? Math.min(parseFloat($('#amountTendered').val()) || 0, calculatedTotal)
            : calculatedTotal,
        redeem_points: redeemPointsRequested
    };
    
    $('#processPaymentBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + PT.processing);

    submitPayment(paymentData);
}

// Phase 19 (pos_upgrade_plan.md §8) — extracted so a blocked credit-limit
// sale can be retried once with override_credit_limit=1 after a manager
// confirms, without duplicating the whole request-building step above.
function submitPayment(paymentData) {
    $.ajax({
        url: '<?= buildUrl('/api/pos/process_sale.php') ?>',
        type: 'POST',
        data: JSON.stringify(paymentData),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Phase 10 (pos_upgrade_plan.md §7) — "Automatically print the
                // receipt" POS setting: skip waiting for the button click.
                if (POS_AUTO_PRINT_RECEIPT) { printReceipt(response.sale_id); }
                // Phase 11 (pos_upgrade_plan.md §7) — surface what the loyalty
                // program actually did, since it's silent otherwise.
                let loyaltyMsg = '';
                if (response.loyalty_points_earned > 0) loyaltyMsg += ' ' + PT.earnedPts.replace('%d', response.loyalty_points_earned);
                if (response.loyalty_points_redeemed > 0) loyaltyMsg += ' ' + PT.redeemedPts.replace('%d', response.loyalty_points_redeemed);
                // Phase 22 (pos_upgrade_plan.md §8) — build the WhatsApp share
                // text from the cart BEFORE it's cleared below (no gateway —
                // a plain wa.me deep-link the cashier's own device opens).
                const whatsappReceiptText = buildWhatsAppReceiptText(currentReceiptNumber);

                Swal.fire({
                    icon: 'success',
                    title: PT.saleCompleted,
                    text: PT.receiptHash + currentReceiptNumber + loyaltyMsg,
                    showCancelButton: true,
                    showDenyButton: true,
                    confirmButtonText: POS_AUTO_PRINT_RECEIPT ? PT.printAgain : PT.printReceipt,
                    denyButtonText: PT.shareViaWhatsApp,
                    cancelButtonText: PT.nextCustomer,
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        printReceipt(response.sale_id);
                    } else if (result.isDenied) {
                        shareReceiptViaWhatsApp(whatsappReceiptText);
                    }

                    // === RESET FOR NEXT CUSTOMER ===
                    // 1. Clear Cart
                    cart = [];
                    updateCartDisplay();
                    clearCartStorage();
                    
                    // 2. Clear Payment Inputs
                    $('#amountTendered').val('0');
                    $('#changeAlert').hide();
                    
                    // 3. Reset Customer to Walk-in (value "")
                    setCustomerSelection('', '');
                    $('#redeemPointsInput').val(0);
                    $('#loyaltyPointsSection').addClass('d-none');
                    
                    // 4. Generate New Receipt Number for next sale
                    generateNewReceipt();
                    
                    // 5. Reset Payment Method to Cash (Default)
                    $('input[name="paymentMethod"][value="cash"]').prop('checked', true).trigger('change');

                    // 6. Reset Discount & Split
                    currentDiscountPercentage = 0;
                    isSplitPayment = false;
                    splitAmounts = { cash: 0, mobile: 0, bank: 0, card: 0 };
                    calculateCartTotal();

                    // 7. Update Cash Balance UI
                    updateCashBalanceUI();
                });
            } else if (response.error_code === 'credit_limit_exceeded' && response.can_override) {
                // Phase 19 (pos_upgrade_plan.md §8) — a manager (canEdit('pos'),
                // re-checked fresh server-side on the retry) may explicitly
                // override a blocked credit sale.
                Swal.fire({
                    icon: 'warning',
                    title: PT.paymentFailed,
                    text: response.message,
                    showCancelButton: true,
                    confirmButtonText: PT.overrideAndProceed,
                    cancelButtonText: PT.cancel
                }).then(r => {
                    if (r.isConfirmed) {
                        paymentData.override_credit_limit = 1;
                        submitPayment(paymentData);
                    } else {
                        $('#processPaymentBtn').prop('disabled', false).html('<i class="bi bi-check-circle"></i> ' + PT.processPaymentBtn);
                    }
                });
                return;
            } else {
                Swal.fire({
                    icon: 'error',
                    title: PT.paymentFailed,
                    text: response.message
                });
            }
            $('#processPaymentBtn').prop('disabled', false).html('<i class="bi bi-check-circle"></i> ' + PT.processPaymentBtn);
        },
        error: function() {
            Swal.fire({
                icon: 'error',
                title: PT.error,
                text: PT.genericErrorRetry
            });
            $('#processPaymentBtn').prop('disabled', false).html('<i class="bi bi-check-circle"></i> ' + PT.processPaymentBtn);
        }
    });
}

// Phase 22 (pos_upgrade_plan.md §8) — a free, real WhatsApp receipt share:
// no gateway, no new dependency, just a wa.me deep-link with the receipt
// text URL-encoded — opens the cashier's own WhatsApp Web/app to send it.
function buildWhatsAppReceiptText(receiptNumber) {
    let lines = [];
    lines.push(<?= json_encode(t('Receipt #')) ?> + receiptNumber);
    cart.forEach(item => {
        const total = (item.discounted_price * item.quantity).toLocaleString();
        lines.push(`${item.product_name} x${item.quantity} = ${POS_CURRENCY} ${total}`);
    });
    const total = $('#cartTotal').text().trim();
    lines.push('---');
    lines.push(<?= json_encode(t('TOTAL:')) ?> + ' ' + total);
    lines.push(<?= json_encode(t('THANK YOU')) ?>);
    return lines.join('\n');
}

// Normalizes a raw phone number into the pure-digits, full international
// format wa.me requires. Found live in the customers table: numbers are
// stored in every shape imaginable — '+255 723 578 982', '0723578982',
// '255759086682' — and wa.me silently fails to resolve a local-trunk '0...'
// number (it just reopens WhatsApp's own contact picker instead of the
// chat), which is exactly the "have to type it again" symptom this fixes.
// Tanzania-specific fallback (255) since that's this deployment's market;
// a number that's already in full international form passes through as-is.
function normalizeWhatsAppPhone(raw) {
    if (!raw) return '';
    let digits = String(raw).replace(/[^\d+]/g, '');
    if (digits.startsWith('+')) digits = digits.slice(1);
    if (digits.startsWith('0') && digits.length >= 9) digits = '255' + digits.slice(1);
    return /^\d{9,15}$/.test(digits) ? digits : '';
}

function shareReceiptViaWhatsApp(text) {
    const selectedCustomer = $('#customerSelect').select2('data')[0];
    const phone = normalizeWhatsAppPhone(selectedCustomer ? selectedCustomer.phone : '');

    if (phone) {
        // A known, valid number on file — open straight into that chat,
        // ready to send. No extra BMS-side prompt in between.
        window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(text), '_blank');
        return;
    }

    // No usable phone on file for this customer (or no customer selected at
    // all — a walk-in sale) — the only case that still asks.
    Swal.fire({
        title: PT.shareViaWhatsApp,
        input: 'text',
        inputLabel: PT.whatsappNumberLabel,
        inputPlaceholder: '2557XXXXXXXX',
        showCancelButton: true,
        confirmButtonText: PT.shareViaWhatsApp,
        cancelButtonText: PT.cancel
    }).then(r => {
        if (!r.isConfirmed || !r.value) return;
        const typed = normalizeWhatsAppPhone(r.value);
        if (!typed) {
            Swal.fire({ icon: 'error', title: PT.shareViaWhatsApp, text: PT.invalidWhatsappNumber });
            return;
        }
        window.open('https://wa.me/' + typed + '?text=' + encodeURIComponent(text), '_blank');
    });
}

function printReceipt(saleId) {
    window.open('<?= getUrl('pos/print-receipt') ?>?id=' + saleId, '_blank');
}

function holdSale() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: PT.emptyCartTitle,
            text: PT.addItemsBeforeHold,
            timer: 2000
        });
        return;
    }

    const customerId = $('#customerSelect').val();
    const customerName = $('#customerSelect option:selected').text();

    Swal.fire({
        title: PT.holdSaleTitle,
        input: 'text',
        inputLabel: PT.holdReferenceLabel,
        inputPlaceholder: PT.holdReferencePlaceholder,
        showCancelButton: true,
        confirmButtonText: PT.holdSaleTitle,
        inputValue: customerName !== PT.walkInCustomer ? customerName : ''
    }).then((result) => {
        if (result.isConfirmed) {
            const holdData = {
                reference: result.value,
                customer_id: customerId || null,
                items: cart,
                subtotal: cart.reduce((sum, item) => sum + (item.price * item.quantity), 0),
                tax: cart.reduce((sum, item) => sum + (item.discounted_price * item.quantity) * ((parseFloat(item.tax_rate) || 0) / 100), 0)
            };
            
            $.ajax({
                url: '<?= buildUrl('/api/pos/hold_sale.php') ?>',
                type: 'POST',
                data: JSON.stringify(holdData),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: PT.saleHeld,
                            text: PT.saleHeldText,
                            timer: 1500
                        });
                        cart = [];
                        updateCartDisplay();
                        clearCartStorage();
                        generateNewReceipt();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: PT.error,
                            text: response.message
                        });
                    }
                }
            });
        }
    });
}

function showHeldSales() {
    $.ajax({
        url: '<?= buildUrl('/api/pos/get_held_sales.php') ?>',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const tbody = $('#heldSalesBody');
                tbody.empty();

                if (response.data.length === 0) {
                    tbody.html(`
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                ${PT.noHeldSalesFound}
                            </td>
                        </tr>
                    `);
                } else {
                    response.data.forEach(sale => {
                        const row = `
                            <tr>
                                <td>${sale.hold_reference || 'HOLD-' + sale.hold_id}</td>
                                <td>${sale.customer_name || PT.walkIn}</td>
                                <td>${JSON.parse(sale.items_data).length}</td>
                                <td>${POS_CURRENCY} ${parseFloat(sale.total_amount).toLocaleString()}</td>
                                <td>${new Date(sale.held_at).toLocaleTimeString()}</td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="loadHeldSale(${sale.hold_id})">
                                        <i class="bi bi-arrow-clockwise"></i> ${PT.load}
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteHeldSale(${sale.hold_id})">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                        tbody.append(row);
                    });
                }
                
                $('#heldSalesModal').modal('show');
            }
        }
    });
}


function loadHeldSale(holdId) {
    $.ajax({
        url: '<?= buildUrl('/api/pos/get_held_sales.php') ?>',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const sale = response.data.find(s => s.hold_id == holdId);
                if (sale) {
                    Swal.fire({
                        title: PT.loadHeldSaleTitle,
                        text: PT.loadHeldSaleText,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: PT.yesLoadIt,
                        cancelButtonText: PT.cancel
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Parse items and load into cart
                            try {
                                cart = JSON.parse(sale.items_data);
                                
                                // Restore customer if saved
                                if (sale.customer_id) {
                                    setCustomerSelection(sale.customer_id, sale.customer_name);
                                }
                                
                                updateCartDisplay();
                                saveCartToStorage();
                                $('#heldSalesModal').modal('hide');
                                
                                Swal.fire({
                                    icon: 'success',
                                    title: PT.loaded,
                                    text: PT.saleLoadedSuccessfully,
                                    timer: 1000,
                                    showConfirmButton: false
                                });

                                // Optionally delete the held sale after loading
                                deleteHeldSale(holdId, true); // true = silent delete
                            } catch (e) {
                                console.error('Error parsing cart data', e);
                                Swal.fire(PT.error, PT.failedToLoadSaleData, 'error');
                            }
                        }
                    });
                }
            }
        }
    });
}

function deleteHeldSale(holdId, silent = false) {
    if (!silent) {
        Swal.fire({
            title: PT.deleteHeldSaleTitle,
            text: PT.cannotRevertText,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: PT.yesDeleteIt
        }).then((result) => {
            if (result.isConfirmed) {
                performDelete(holdId, false);
            }
        });
    } else {
        performDelete(holdId, true);
    }
}

function performDelete(holdId, silent) {
    $.ajax({
        url: '<?= buildUrl('/api/pos/delete_held_sale.php') ?>',
        type: 'POST',
        data: JSON.stringify({ hold_id: holdId }),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                if (!silent) {
                    Swal.fire(
                        PT.deletedBang,
                        PT.heldSaleDeleted,
                        'success'
                    );
                    showHeldSales(); // Refresh list
                }
            } else {
                if (!silent) Swal.fire(PT.error, response.message, 'error');
            }
        },
        error: function() {
            if (!silent) Swal.fire(PT.error, PT.failedToDeleteSale, 'error');
        }
    });
}

function startShift() {
    const $reg = $('#startShiftRegister');
    if ($reg.hasClass('select2-hidden-accessible')) $reg.select2('destroy');
    $reg.html('<option value="">' + PT.loadingRegisters + '</option>');

    $.getJSON('<?= buildUrl('/api/pos/get_registers.php') ?>', { active_only: 1 }, function (res) {
        $reg.empty();
        if (res.success && res.data.length) {
            res.data.forEach(r => {
                // Built via .text() (auto-escaping), not string-concatenated HTML —
                // this page has no output-escaping JS helper of its own (that's a
                // per-page local convention elsewhere, not something pos.php defines).
                const label = r.register_name + ' (' + r.register_code + ')';
                const opt = $('<option>').val(r.register_id).text(label);
                if (r.active_shift_id) {
                    // Busy till — disable it up front instead of letting the cashier
                    // pick it and only find out after submitting (open_shift.php's
                    // "already in an active shift with another cashier" guard still
                    // backstops this server-side for the rare simultaneous-click race).
                    opt.prop('disabled', true)
                       .text(label + ' — ' + PT.inUseBySince.replace('%s', r.active_cashier_name).replace('%s', r.active_shift_started_label));
                }
                $reg.append(opt);
            });
            const firstFree = $reg.find('option:not(:disabled)').first().val();
            if (firstFree) $reg.val(firstFree);
        } else {
            $reg.append('<option value="1">Main Counter</option>');
        }
        $reg.select2({ theme: 'bootstrap-5', dropdownParent: $('#startShiftModal'), width: '100%' });
        $('#startShiftModal').modal('show');
    }).fail(function () {
        $reg.html('<option value="1">Main Counter</option>');
        $reg.select2({ theme: 'bootstrap-5', dropdownParent: $('#startShiftModal'), width: '100%' });
        $('#startShiftModal').modal('show');
    });
}

// Phase 20 (pos_upgrade_plan.md §8) — cash denomination counting. Renders
// once per container (idempotent — checks for existing rows first), sums
// live into the target cash input as counts change, and exposes the current
// breakdown via jQuery .data() for the submit handlers below to read.
function renderDenomGrid(containerId) {
    const $container = $('#' + containerId);
    if ($container.find('tr').length) return; // already rendered
    const targetInput = $container.data('target-input');

    let html = '<table class="table table-sm"><tbody>';
    POS_DENOMINATIONS.forEach(v => {
        html += `<tr>
            <td class="align-middle small">${POS_CURRENCY} ${v.toLocaleString()}</td>
            <td style="width:90px;"><input type="number" class="form-control form-control-sm denom-count" data-value="${v}" min="0" step="1" value=""></td>
            <td class="align-middle text-end small denom-subtotal" style="width:110px;">—</td>
        </tr>`;
    });
    html += `</tbody><tfoot><tr><th colspan="2" class="text-end small">${PT.totalLabel}</th><th class="text-end denom-total">${POS_CURRENCY} 0</th></tr></tfoot></table>`;
    $container.html(html);

    $container.on('input', '.denom-count', function () {
        let total = 0;
        const breakdown = [];
        $container.find('.denom-count').each(function () {
            const value = parseFloat($(this).data('value'));
            const count = parseInt($(this).val()) || 0;
            const sub = value * count;
            $(this).closest('tr').find('.denom-subtotal').text(count > 0 ? (POS_CURRENCY + ' ' + sub.toLocaleString()) : '—');
            total += sub;
            if (count > 0) breakdown.push({ value: value, count: count });
        });
        $container.find('.denom-total').text(POS_CURRENCY + ' ' + total.toLocaleString());
        $container.data('breakdown', breakdown);
        if (targetInput) $('#' + targetInput).val(total);
    });
}

function confirmStartShift() {
    const openingCash = parseFloat($('#openingCash').val()) || 0;
    const registerId = $('#startShiftRegister').val() || 1;

    console.log('=== STARTING SHIFT ===');
    console.log('Opening Cash:', openingCash, 'Register:', registerId);

    // Disable button to prevent double-click
    const btn = event.target;
    $(btn).prop('disabled', true).text(PT.starting);

    $.ajax({
        url: '<?= buildUrl('/api/pos/open_shift.php') ?>',
        type: 'POST',
        data: {
            opening_cash: openingCash,
            register_id: registerId,
            // Phase 20 (pos_upgrade_plan.md §8) — only sent if the cashier
            // actually used the optional denomination grid.
            denominations: JSON.stringify($('#openDenomGrid').data('breakdown') || [])
        },
        dataType: 'json',
        success: function(response) {
            console.log('=== SHIFT RESPONSE ===');
            console.log(response);
            
            if (response.success) {
                $('#startShiftModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: PT.shiftStarted,
                    text: PT.shiftStartedText.replace('%s', response.shift_code).replace('%s', response.register_name || PT.registerWord),
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: PT.error,
                    text: response.message
                });
                $(btn).prop('disabled', false).text(PT.startShift);
            }
        },
        error: function(xhr, status, error) {
            console.error('=== SHIFT ERROR ===');
            console.error('Status:', status);
            console.error('Error:', error);
            console.error('Response:', xhr.responseText);

            Swal.fire({
                icon: 'error',
                title: PT.error,
                text: PT.failedToStartShift + ' ' + error
            });
            $(btn).prop('disabled', false).text(PT.startShift);
        }
    });
}

function endShift() {
    $('#endShiftModal').modal('show');
}

function confirmEndShift() {
    const endingCash = parseFloat($('#endingCash').val()) || 0;
    const notes = $('#shiftNotes').val();

    console.log('=== ENDING SHIFT ===');
    console.log('Ending Cash:', endingCash);
    console.log('Notes:', notes);

    // Disable button
    const btn = event.target;
    $(btn).prop('disabled', true).text(PT.closing);
    
    $.ajax({
        url: '<?= buildUrl('/api/pos/close_shift.php') ?>',
        type: 'POST',
        data: {
            ending_cash: endingCash,
            notes: notes,
            // Phase 20 (pos_upgrade_plan.md §8) — only sent if the cashier
            // actually used the optional denomination grid.
            denominations: JSON.stringify($('#closeDenomGrid').data('breakdown') || [])
        },
        dataType: 'json',
        success: function(response) {
            console.log('=== CLOSE SHIFT RESPONSE ===');
            console.log(response);
            
            if (response.success) {
                $('#endShiftModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: PT.shiftEnded,
                    html: `
                        <p>${PT.shiftClosedSuccessfully}</p>
                        <p><strong>${PT.expectedLabel}</strong> ${POS_CURRENCY} ${response.expected_cash.toLocaleString()}</p>
                        <p><strong>${PT.actualLabel}</strong> ${POS_CURRENCY} ${response.ending_cash.toLocaleString()}</p>
                        <p><strong>${PT.differenceLabel}</strong> ${POS_CURRENCY} ${response.cash_difference.toLocaleString()}</p>
                        <p><strong>${PT.totalSalesLabel}</strong> ${POS_CURRENCY} ${(response.total_sales || 0).toLocaleString()}</p>
                    `,
                    showCancelButton: true,
                    confirmButtonText: PT.viewZReport,
                    cancelButtonText: PT.close
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.open('<?= getUrl('pos/zreport') ?>?shift_id=' + response.shift_id, '_blank');
                    }
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: PT.error,
                    text: response.message
                });
                $(btn).prop('disabled', false).text(PT.endShift);
            }
        },
        error: function(xhr, status, error) {
            console.error('=== CLOSE SHIFT ERROR ===');
            console.error('Status:', status);
            console.error('Error:', error);
            console.error('Response:', xhr.responseText);

            Swal.fire({
                icon: 'error',
                title: PT.error,
                text: PT.failedToCloseShift + ' ' + error
            });
            $(btn).prop('disabled', false).text(PT.endShift);
        }
    });
}

function openCashDrawer() {
    // Phase 10 (pos_upgrade_plan.md §7) — a browser cannot send a raw hardware
    // "open drawer" command; that needs either a native print-bridge or
    // WebUSB (Chrome-only, HTTPS-only — unusable on a plain-HTTP LAN
    // deployment). This used to claim success and do nothing at all. Most
    // thermal receipt printers with a drawer wired to their kick port (RJ11)
    // open it automatically on every print job — which already happens for
    // free whenever a receipt prints — so this is now honest about that
    // instead of pretending to have opened anything itself.
    Swal.fire({
        icon: 'info',
        title: PT.cashDrawerTitle,
        html: PT.cashDrawerHtml1 + '<br><br>' + PT.cashDrawerHtml2,
    });
}

function openDiscountModal() {
    $('#discountPercentage').val(currentDiscountPercentage);
    $('#discountModal').modal('show');
}

function applyDiscount() {
    const val = parseFloat($('#discountPercentage').val()) || 0;
    if (val < 0 || val > 100) {
        Swal.fire(PT.error, PT.discountRange, 'error');
        return;
    }
    currentDiscountPercentage = val;
    calculateCartTotal();
    $('#discountModal').modal('hide');
    Swal.fire({
        icon: 'success',
        title: PT.discountApplied,
        text: PT.discountAppliedText.replace('%d', currentDiscountPercentage),
        timer: 1500,
        showConfirmButton: false
    });
}

function openSplitPaymentModal() {
    if (cart.length === 0) {
        Swal.fire(PT.emptyCartTitle, PT.addItemsFirst, 'warning');
        return;
    }
    const total = calculateCartTotal();
    $('#splitTotalDisplay').text(POS_CURRENCY + ' ' + total.toLocaleString());
    $('#splitRemaining').text(POS_CURRENCY + ' ' + total.toLocaleString());
    $('.split-amount').val(0);
    $('#splitPaymentModal').modal('show');
}

function calculateSplitRemaining() {
    const total = parseFloat($('#cartTotal').text().replace(POS_CURRENCY + ' ', '').replace(/,/g, '')) || 0;
    let paid = 0;
    $('.split-amount').each(function() {
        paid += parseFloat($(this).val()) || 0;
    });
    const remaining = total - paid;
    $('#splitRemaining').text(POS_CURRENCY + ' ' + remaining.toLocaleString());
    if (remaining < 0) {
        $('#splitRemaining').addClass('text-danger');
    } else {
        $('#splitRemaining').removeClass('text-danger');
    }
}

function processSplitPayment() {
    const total = parseFloat($('#cartTotal').text().replace(POS_CURRENCY + ' ', '').replace(/,/g, '')) || 0;
    let paid = 0;
    splitAmounts = {
        cash: parseFloat($('#splitCash').val()) || 0,
        mobile: parseFloat($('#splitMobile').val()) || 0,
        bank: parseFloat($('#splitBank').val()) || 0,
        card: parseFloat($('#splitCard').val()) || 0
    };
    
    Object.values(splitAmounts).forEach(v => paid += v);

    if (Math.abs(paid - total) > 0.1) {
        Swal.fire(PT.balanceMismatch, PT.splitMustEqualTotal.replace('%s', POS_CURRENCY + ' ' + total.toLocaleString()), 'error');
        return;
    }

    isSplitPayment = true;
    $('#splitPaymentModal').modal('hide');
    processPayment();
}

function openDiscountModal() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: PT.emptyCartTitle,
            text: PT.addItemsBeforeDiscount,
            timer: 2000
        });
        return;
    }

    const container = $('#discountProductList');
    container.empty();

    // Configure Modal based on Setting
    const discountPresets = $('#discountPresets');
    const discountIcon = $('#discountIcon');

    if (posDiscountType === 'fixed') {
        $('#discountLabel').text(PT.discountAmountLabel.replace('%s', POS_CURRENCY));
        $('#discountSuffix').text(POS_CURRENCY);
        $('#discountValue').removeAttr('max');
        discountPresets.addClass('d-none');
        discountIcon.removeClass('bi-percent').addClass('bi-cash');
    } else {
        $('#discountLabel').text(PT.discountPercentageLabel);
        $('#discountSuffix').text('%');
        $('#discountValue').attr('max', '100');
        discountPresets.removeClass('d-none');
        discountIcon.removeClass('bi-cash').addClass('bi-percent');
    }

    // Create a "Select All" option
    container.append(`
        <div class="list-group-item bg-light">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="selectAllDiscounts" onchange="toggleAllDiscounts(this)">
                <label class="form-check-label fw-bold" for="selectAllDiscounts">
                    ${PT.selectAllProducts}
                </label>
            </div>
        </div>
    `);

    cart.forEach((item, index) => {
        const isChecked = item.discount_percent > 0 ? 'checked' : '';
        
        let minPriceInfo = '';
        if (posDiscountType === 'percentage') {
             const priceDiff = item.price - item.min_selling_price;
             const maxDiscount = priceDiff > 0 ? Math.floor((priceDiff / item.price) * 100) : 0;
             minPriceInfo = `<small class="text-muted">
                            ${PT.minSellingPriceInfo.replace('%s', item.min_selling_price.toLocaleString()).replace('%s%%', maxDiscount + '%')}
                        </small>`;
        } else {
             // For fixed amount, show minimal info or nothing as requested ("flexible")
             minPriceInfo = `<small class="text-success"><i class="bi bi-unlock"></i> ${PT.flexibleAmount}</small>`;
        }
        
        container.append(`
            <div class="list-group-item">
                <div class="form-check">
                    <input class="form-check-input discount-item-check" type="checkbox" 
                           value="${index}" id="discount_item_${index}" ${isChecked}>
                    <label class="form-check-label w-100" for="discount_item_${index}">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>${item.product_name}</span>
                            <span class="badge bg-secondary">${item.price.toLocaleString()}</span>
                        </div>
                        ${minPriceInfo}
                    </label>
                </div>
            </div>
        `);
    });

    $('#discountValue').val(0);
    $('#discountModal').modal('show');
}

function toggleAllDiscounts(source) {
    $('.discount-item-check').prop('checked', source.checked);
}

function applyProductDiscount() {
    const value = parseFloat($('#discountValue').val()) || 0;
    
    if (value < 0) {
        Swal.fire({
            icon: 'error',
            title: PT.invalidDiscount,
            text: PT.discountCannotBeNegative
        });
        return;
    }

    if (posDiscountType === 'percentage' && value > 100) {
        Swal.fire({
            icon: 'error',
            title: PT.invalidDiscount,
            text: PT.percentageCannotExceed100
        });
        return;
    }

    const selectedIndices = [];
    $('.discount-item-check:checked').each(function() {
        selectedIndices.push(parseInt($(this).val()));
    });

    if (selectedIndices.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: PT.noSelection,
            text: PT.selectAtLeastOneProduct
        });
        return;
    }

    let errorMessages = [];
    let updatedCount = 0;

    selectedIndices.forEach(index => {
        const item = cart[index];
        let newPrice = item.price;
        let percentage = 0;

        if (posDiscountType === 'fixed') {
             // Fixed Amount Logic
             newPrice = item.price - value;
             if (item.price > 0) {
                 percentage = ((item.price - newPrice) / item.price) * 100;
             }
        } else {
             // Percentage Logic
             newPrice = item.price * (1 - (value / 100));
             percentage = value;
        }
        
        // Validation: Check min selling price
        // Adjust epsilon for potential float issues
        // Validation
        let isValid = true;
        
        // Enforce Min Selling Price ONLY for Percentage Mode
        if (posDiscountType === 'percentage') {
            if (value > 0 && newPrice < (item.min_selling_price - 0.01)) {
                errorMessages.push(PT.priceBelowMinimum.replace('%s', item.product_name).replace('%s', newPrice.toLocaleString()).replace('%s', item.min_selling_price.toLocaleString()));
                isValid = false;
            }
        }

        // Basic limit for fixed (can't be negative)
        if (newPrice < 0) {
             errorMessages.push(PT.resultingPriceNegative.replace('%s', item.product_name));
             isValid = false;
        }

        if (isValid) {
            item.discount_type = posDiscountType;
            item.discount_value = value;
            item.discount_percent = parseFloat(percentage.toFixed(2));
            item.discounted_price = newPrice;
            updatedCount++;
        }
    });

    if (errorMessages.length > 0) {
        Swal.fire({
            icon: 'error',
            title: PT.priceValidationFailed,
            html: errorMessages.join('<br>') + '<br><br>' + PT.otherItemsUpdatedNote,
            confirmButtonText: PT.ok
        });
    } else {
        Swal.fire({
            icon: 'success',
            title: PT.discountApplied,
            text: PT.successfullyUpdatedItems.replace('%d', updatedCount),
            timer: 1500,
            showConfirmButton: false
        });
        $('#discountModal').modal('hide');
    }

    updateCartDisplay();
    saveCartToStorage();
}

function generateNewReceipt() {
    $.get('<?= buildUrl('/api/pos/generate_receipt_number.php') ?>', function(res) {
        if (res.success) {
            currentReceiptNumber = res.receipt_number;
            $('#receiptNumber').text(currentReceiptNumber);
        } else {
            // Fallback
            currentReceiptNumber = 'POS-' + Date.now().toString().slice(-10);
            $('#receiptNumber').text(currentReceiptNumber);
        }
    });
}

function updateCashBalanceUI() {
    if (!currentShiftId) return;
    
    $.ajax({
        url: '<?= buildUrl('/app/bms/pos/api/pos_controller.php') ?>',
        type: 'GET',
        data: { 
            action: 'get_cash_balance', 
            shift_id: currentShiftId 
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('.cash-balance-display').text('TSh ' + response.data.balance);
            }
        },
        error: function(err) {
            console.error('Failed to update cash balance:', err);
        }
    });
}

// ── Barcode Scanner Interceptor ──────────────────────────────────────────────
// USB/Bluetooth barcode scanners behave as keyboards: they send all characters
// in a burst (< 80 ms total), then fire Enter. We distinguish this from normal
// human typing (> 100 ms per keystroke) using a timing buffer.
(function () {
    'use strict';

    let _scanBuffer = '';
    let _scanTimer  = null;
    const SCAN_MAX_MS = 80;

    // ── Audio feedback (Web Audio API — no library needed) ───────────────────
    function _beep(freq, durationMs, type) {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx  = new AudioCtx();
            const osc  = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type            = type || 'square';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + durationMs / 1000);
            osc.start();
            osc.stop(ctx.currentTime + durationMs / 1000);
        } catch (e) { /* audio is non-critical */ }
    }
    function scanSuccess() { _beep(880,  80,  'square');   }
    function scanError()   { _beep(200, 300, 'sawtooth'); }

    // ── Visual feedback — flash the POS header bar ───────────────────────────
    function flashHeader(cssColour, ms) {
        const bar = document.getElementById('posHeaderBar');
        if (!bar) return;
        bar.style.transition       = 'background-color 0.08s';
        bar.style.backgroundColor  = cssColour;
        setTimeout(function () {
            bar.style.backgroundColor = '';
            setTimeout(function () { bar.style.transition = ''; }, 200);
        }, ms || 400);
    }

    // ── Non-blocking toast notification ─────────────────────────────────────
    function scanToast(html, isError) {
        const id = 'posScanToast_' + Date.now();
        const el = document.createElement('div');
        el.id = id;
        el.setAttribute('aria-live', 'assertive');
        el.style.cssText = [
            'position:fixed', 'top:70px', 'right:16px',
            'z-index:99999',  'min-width:240px', 'max-width:320px'
        ].join(';');
        el.innerHTML = '<div class="toast show border-0 shadow ' +
            (isError ? 'bg-danger' : 'bg-success') +
            ' text-white">' +
            '<div class="toast-body d-flex align-items-start gap-2" style="font-size:0.85rem">' +
            html + '</div></div>';
        document.body.appendChild(el);
        setTimeout(function () {
            if (document.getElementById(id)) el.remove();
        }, 3000);
    }

    // ── Add product to cart (scanner path — no modal, no click required) ─────
    function scanAddToCart(product) {
        // Phase 14 (pos_upgrade_plan.md §8) — same price-group resolution as
        // the click-to-cart path (addToCart()), so a scanned item respects
        // the active price group too.
        const price    = parseFloat(product.effective_price ?? product.selling_price) || 0;
        const existing = cart.find(function (item) {
            return item.product_id == product.product_id;
        });

        if (existing) {
            existing.quantity += 1;
        } else {
            cart.push({
                product_id:        product.product_id,
                product_name:      product.product_name,
                sku:               product.sku || '',
                price:             price,
                quantity:          1,
                tax_rate:          saleVatRate,
                min_selling_price: parseFloat(product.min_selling_price) || 0,
                discount_type:     'percentage',
                discount_value:    0,
                discount_percent:  0,
                discounted_price:  price
            });
        }

        updateCartDisplay();
        saveCartToStorage();

        const newQty = existing ? existing.quantity : 1;
        const fmtPrice = price.toLocaleString('en-US');
        scanToast(
            '<i class="bi bi-check-circle-fill me-1" style="margin-top:2px;flex-shrink:0"></i>' +
            '<span><strong>' + product.product_name + '</strong><br>' +
            '<small>' + POS_CURRENCY + ' ' + fmtPrice + ' &mdash; ' + PT.cartQtyLabel + ' ' + newQty + '</small></span>',
            false
        );
    }

    // ── Core barcode lookup ──────────────────────────────────────────────────
    function handleBarcodeScanned(code) {
        if (!code || code.length < 3) return;

        // Search allProducts first (full catalog, unaffected by category filter).
        // Fall back to products[] if allProducts hasn't been populated yet.
        var catalog = (allProducts && allProducts.length > 0) ? allProducts : products;

        if (!catalog || catalog.length === 0) {
            console.warn('[Scanner] Products not yet loaded — scan ignored:', code);
            return;
        }

        const needle = code.toLowerCase();
        const found  = catalog.find(function (p) {
            return (p.barcode && p.barcode.toLowerCase() === needle) ||
                   (p.sku     && p.sku.toLowerCase()     === needle);
        });

        if (found) {
            scanSuccess();
            flashHeader('#198754', 400);
            scanAddToCart(found);
            console.info('[Scanner] Found:', found.product_name, '— code:', code);
        } else {
            scanError();
            flashHeader('#dc3545', 600);
            scanToast(
                '<i class="bi bi-exclamation-triangle-fill me-1" style="margin-top:2px;flex-shrink:0"></i>' +
                '<span>' + PT.barcodeNotFound + '<br><small><code>' + code + '</code></small></span>',
                true
            );
            console.warn('[Scanner] Not found:', code);
        }
    }

    // ── Focus management ─────────────────────────────────────────────────────
    // Keep the hidden input focused so some scanner configs that require a
    // focused field still work. Re-focus after clicking non-interactive areas.
    function reFocusHidden() {
        var h = document.getElementById('hiddenScanInput');
        if (h && document.activeElement !== h) h.focus({ preventScroll: true });
    }

    // Show the "SCANNER READY" badge once DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
        var badge = document.getElementById('scannerReadyBadge');
        if (badge) badge.style.display = '';
        reFocusHidden();
    });
    // If DOM is already ready (script runs after DOMContentLoaded)
    if (document.readyState !== 'loading') {
        setTimeout(function () {
            var badge = document.getElementById('scannerReadyBadge');
            if (badge) badge.style.display = '';
            reFocusHidden();
        }, 0);
    }

    // Inputs where scanner should NOT intercept (cashier is typing there)
    var INTERACTIVE_IDS = [
        'productSearch', 'amountTendered', 'openingCash', 'endingCash',
        'quickViewQty', 'modal_barcode', 'edit_barcode', 'customerSearch'
    ];

    document.addEventListener('keydown', function (e) {
        var active = document.activeElement;
        var tag    = active ? active.tagName : '';
        var id     = active ? (active.id || '') : '';

        // If focus is in a real user input (not the hidden scanner field), skip
        if ((tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') &&
             id !== 'hiddenScanInput') {
            return;
        }

        // Skip modifier combos — scanners never produce those
        if (e.ctrlKey || e.altKey || e.metaKey) return;

        // F-key passthrough (handled by the existing shortcuts block above)
        if (e.key.length > 1 && e.key !== 'Enter') return;

        if (e.key === 'Enter') {
            clearTimeout(_scanTimer);
            var code = _scanBuffer.trim();
            _scanBuffer = '';
            if (code.length >= 3) {
                handleBarcodeScanned(code);
            }
            return;
        }

        if (e.key.length === 1) {
            _scanBuffer += e.key;
            clearTimeout(_scanTimer);
            // If Enter does not arrive within SCAN_MAX_MS the burst ended without
            // Enter — discard (shouldn't normally happen with a scanner).
            _scanTimer = setTimeout(function () { _scanBuffer = ''; }, SCAN_MAX_MS);
        }
    });

    // Re-focus hidden input when user clicks on non-interactive areas of the POS
    document.addEventListener('click', function (e) {
        var tag = e.target ? e.target.tagName : '';
        if (['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A', 'LABEL'].indexOf(tag) === -1) {
            setTimeout(reFocusHidden, 150);
        }
    });

    // Also re-focus when a Bootstrap modal closes (the modal steals focus)
    document.addEventListener('hidden.bs.modal', function () {
        setTimeout(reFocusHidden, 200);
    });

    // Expose for CLI/browser testing
    window._posHandleScan    = handleBarcodeScanned;
    window._posScanAddToCart = scanAddToCart;
})();
</script>
