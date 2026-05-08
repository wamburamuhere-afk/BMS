<?php
// File: pos_scripts_new.php - JavaScript for new POS
?>
<script>
let cart = [];
let currentProduct = null;
let categories = [];
let currentReceiptNumber = '<?= generate_receipt_number() ?>';
let products = [];
let currentDiscountPercentage = 0;
let currentShiftId = '<?= $shift_id ?>';
let currentShiftActive = <?= $shift_active ? 'true' : 'false' ?>;
let isSplitPayment = false;
let splitAmounts = { cash: 0, mobile: 0, bank: 0, card: 0 };
let posDiscountType = '<?= get_setting('pos_discount_type', 'percentage') ?>'; // 'percentage' or 'fixed'

$(document).ready(function() {
    // Load cart from localStorage
    loadCartFromStorage();
    
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
        $('#cashDifference').text('TZS ' + difference.toFixed(2));
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

// Save/Load cart from localStorage
function saveCartToStorage() {
    try {
        localStorage.setItem('pos_cart', JSON.stringify(cart));
        localStorage.setItem('pos_customer', $('#customerSelect').val() || '');
    } catch (e) {
        console.error('Error saving cart:', e);
    }
}

function loadCartFromStorage() {
    try {
        const savedCart = localStorage.getItem('pos_cart');
        const savedCustomer = localStorage.getItem('pos_customer');
        
        if (savedCart) {
            cart = JSON.parse(savedCart);
            updateCartDisplay();
        }
        
        if (savedCustomer) {
            $('#customerSelect').val(savedCustomer);
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
            project_id: projectId
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
                                        ${!isService && product.stock_quantity <= 10 ? '<span class="badge bg-danger position-absolute top-0 end-0" style="font-size: 8px;">LOW STOCK</span>' : ''}
                                        ${projectStock > 0 ? '<span class="badge bg-info position-absolute top-0 start-0" style="font-size: 8px;"><i class="bi bi-star-fill"></i> PROJECT STOCK</span>' : ''}
                                    </div>
                                    ${isService ? '<span class="badge bg-info text-white mb-1">Service</span>' : ''}
                                    <h6 class="card-title mb-1 small text-truncate fw-bold" title="${product.product_name}">${product.product_name}</h6>
                                    <p class="card-text text-muted small mb-1">${product.sku || ''}</p>
                                    <p class="card-text fw-bold text-primary mb-1">TZS ${parseFloat(product.selling_price).toLocaleString()}</p>
                                    ${!isService ? `<p class="card-text small ${product.stock_quantity <= 10 ? 'text-danger fw-bold' : 'text-muted'}">
                                        Qty: ${product.stock_quantity}
                                    </p>` : '<p class="card-text small text-muted"><i class="bi bi-infinity"></i> Service</p>'}
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
                        <h5 class="mt-3 text-muted">No products found</h5>
                        <p class="text-muted">Try a different search or category</p>
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
                    <h5 class="mt-3 text-danger">Error loading products</h5>
                    <p class="text-muted">Status: ${xhr.status} - ${error}</p>
                    <button class="btn btn-primary" onclick="loadProducts()">Retry</button>
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

function showProductQuickView(productId) {
    const product = products.find(p => p.product_id == productId);
    if (!product) return;
    
    currentProduct = product;
    
    const html = `
        <h6>${currentProduct.product_name}</h6>
        <p class="text-muted small mb-2">${currentProduct.sku || 'No SKU'}</p>
        <p class="text-success fw-bold">TZS ${parseFloat(currentProduct.selling_price).toLocaleString()}</p>
        ${currentProduct.is_service != 1 ? `<p class="small ${currentProduct.stock_quantity <= 10 ? 'text-danger' : 'text-muted'}">
            Stock: ${currentProduct.stock_quantity}
        </p>` : '<p class="small text-muted"><i class="bi bi-infinity"></i> Service</p>'}
        
        <div class="mb-3">
            <label class="form-label">Quantity</label>
            <div class="input-group">
                <button class="btn btn-outline-secondary" type="button" onclick="adjustQuantity(-1)">-</button>
                <input type="number" class="form-control text-center" id="quickViewQty" 
                       value="1" min="1" step="1">
                <button class="btn btn-outline-secondary" type="button" onclick="adjustQuantity(1)">+</button>
            </div>
        </div>
        
        <div class="d-grid gap-2">
            <button class="btn btn-primary" onclick="addToCart()">
                <i class="bi bi-cart-plus"></i> Add to Cart
            </button>
            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
                Cancel
            </button>
        </div>
    `;
    
    $('#quickViewContent').html(html);
    
    // Proper way to handle focus in Bootstrap modals to avoid aria-hidden issues
    $('#productQuickView').off('shown.bs.modal').on('shown.bs.modal', function () {
        $('#quickViewQty').focus().select();
    });
    
    $('#productQuickView').modal('show');
}

function adjustQuantity(amount) {
    const input = $('#quickViewQty');
    let current = parseInt(input.val()) || 1;
    const newValue = Math.max(1, current + amount);
    input.val(newValue);
}

function addToCart() {
    if (!currentProduct) return;
    
    const quantity = parseInt($('#quickViewQty').val()) || 1;
    
    const existingItem = cart.find(item => item.product_id == currentProduct.product_id);
    
    if (existingItem) {
        existingItem.quantity += quantity;
    } else {
        cart.push({
            product_id: currentProduct.product_id,
            product_name: currentProduct.product_name,
            sku: currentProduct.sku,
            price: parseFloat(currentProduct.selling_price) || 0,
            quantity: quantity,
            tax_rate: parseFloat(currentProduct.tax_rate) || 0,
            min_selling_price: parseFloat(currentProduct.min_selling_price) || 0,
            discount_type: 'percentage', // Default to percentage
            discount_value: 0,
            discount_percent: 0,
            discounted_price: parseFloat(currentProduct.selling_price) || 0
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
                        ${discountBadge}
                    </td>
                    <td class="text-end">
                        <span class="small">${priceDisplay}</span>
                    </td>
                    <td class="text-center" style="padding: 0.25rem;">
                        <div class="d-flex align-items-center justify-content-center" style="gap: 2px;">
                            <button class="btn btn-outline-secondary" onclick="updateCartQuantity(${index}, -1)" 
                                    style="padding: 2px 4px; font-size: 10px; line-height: 1; min-width: 18px;">-</button>
                            <input type="number" class="form-control text-center" 
                                   style="width: 35px; padding: 2px; font-size: 11px; height: 22px;"
                                   value="${item.quantity}" min="1" 
                                   onchange="updateCartQuantityInput(${index}, this.value)">
                            <button class="btn btn-outline-secondary" onclick="updateCartQuantity(${index}, 1)" 
                                    style="padding: 2px 4px; font-size: 10px; line-height: 1; min-width: 18px;">+</button>
                        </div>
                    </td>
                    <td class="text-end">
                        <strong class="small text-success">${itemTotal.toLocaleString()}</strong>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-link text-danger p-0" onclick="removeFromCart(${index})" 
                                style="font-size: 14px;" title="Remove">
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

function removeFromCart(index) {
    cart.splice(index, 1);
    updateCartDisplay();
    saveCartToStorage();
}

function clearCart() {
    if (cart.length === 0) return;
    
    Swal.fire({
        title: 'Clear Cart?',
        text: "Are you sure you want to remove all items?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, clear it!'
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

    const total = subtotal + totalTax;
    
    $('#cartSubtotal').text('TZS ' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2}));
    $('#cartTax').text('TZS ' + totalTax.toLocaleString('en-US', {minimumFractionDigits: 2}));
    $('#cartTotal').text('TZS ' + total.toLocaleString('en-US', {minimumFractionDigits: 2}));
    
    // Hide discount row as we now handle per-item discount
    $('#discountRow').hide();
    
    return total;
}

function calculateChange() {
    const total = parseFloat($('#cartTotal').text().replace('TZS ', '').replace(/,/g, '')) || 0;
    const tendered = parseFloat($('#amountTendered').val()) || 0;
    const change = tendered - total;
    
    if (change >= 0) {
        $('#changeAlert').show();
        $('#changeAmount').text('TZS ' + change.toLocaleString('en-US', {minimumFractionDigits: 2}));
    } else {
        $('#changeAlert').hide();
    }
}

function processPayment() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Empty Cart',
            text: 'Add items to cart before processing payment.',
            timer: 2000
        });
        return;
    }
    
    <?php if (!$shift_active): ?>
    Swal.fire({
        icon: 'warning',
        title: 'No Active Shift',
        text: 'Please start a shift first.',
        showConfirmButton: true,
        confirmButtonText: 'Start Shift'
    }).then((result) => {
        if (result.isConfirmed) {
            startShift();
        }
    });
    return;
    <?php endif; ?>
    
    const paymentMethod = $('input[name="paymentMethod"]:checked').val();
    const customerId = $('#customerSelect').val();
    const total = parseFloat($('#cartTotal').text().replace('TZS ', '').replace(/,/g, '')) || 0;
    
    if (paymentMethod === 'cash') {
        const tendered = parseFloat($('#amountTendered').val()) || 0;
        if (tendered < total) {
            Swal.fire({
                icon: 'error',
                title: 'Insufficient Payment',
                text: 'Amount tendered is less than total amount.',
                timer: 2000
            });
            return;
        }
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
    
    const calculatedTotal = (subtotal - totalDiscount) + totalTax;
    
    // Calculate global percentage for records if needed (weighted average or just 0)
    // We will send 0 as global percentage since we use itemized discounts
    const globalDiscountPercent = 0; 
    
    const paymentData = {
        receipt_number: currentReceiptNumber,
        customer_id: customerId || null,
        warehouse_id: $('#posWarehouseId').val(),
        project_id: $('#posProjectId').val() || null,
        items: cart,
        subtotal: subtotal,
        discount_percentage: globalDiscountPercent,
        discount_amount: totalDiscount,
        tax: totalTax,
        total: calculatedTotal,
        payment_method: isSplitPayment ? 'split' : paymentMethod,
        split_details: isSplitPayment ? splitAmounts : null,
        amount_tendered: isSplitPayment ? calculatedTotal : (paymentMethod === 'cash' ? parseFloat($('#amountTendered').val()) || calculatedTotal : calculatedTotal),
        change_given: isSplitPayment ? 0 : (paymentMethod === 'cash' ? (parseFloat($('#amountTendered').val()) || calculatedTotal) - calculatedTotal : 0)
    };
    
    $('#processPaymentBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');
    
    $.ajax({
        url: '<?= buildUrl('/api/pos/process_sale.php') ?>',
        type: 'POST',
        data: JSON.stringify(paymentData),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Sale Completed!',
                    text: 'Receipt #' + currentReceiptNumber,
                    showCancelButton: true,
                    confirmButtonText: 'Print Receipt',
                    cancelButtonText: 'Next Customer',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        printReceipt(response.sale_id);
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
                    $('#customerSelect').val('');
                    
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
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Payment Failed',
                    text: response.message
                });
            }
            $('#processPaymentBtn').prop('disabled', false).html('<i class="bi bi-check-circle"></i> PROCESS PAYMENT');
        },
        error: function() {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred. Please try again.'
            });
            $('#processPaymentBtn').prop('disabled', false).html('<i class="bi bi-check-circle"></i> PROCESS PAYMENT');
        }
    });
}

function printReceipt(saleId) {
    window.open('<?= getUrl('pos/print-receipt') ?>?id=' + saleId, '_blank');
}

function holdSale() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Empty Cart',
            text: 'Add items to cart before holding sale.',
            timer: 2000
        });
        return;
    }
    
    const customerId = $('#customerSelect').val();
    const customerName = $('#customerSelect option:selected').text();
    
    Swal.fire({
        title: 'Hold Sale',
        input: 'text',
        inputLabel: 'Hold Reference (optional)',
        inputPlaceholder: 'e.g., Customer name or phone',
        showCancelButton: true,
        confirmButtonText: 'Hold Sale',
        inputValue: customerName !== 'Walk-in Customer' ? customerName : ''
    }).then((result) => {
        if (result.isConfirmed) {
            const holdData = {
                reference: result.value,
                customer_id: customerId || null,
                items: cart,
                subtotal: cart.reduce((sum, item) => sum + (item.price * item.quantity), 0),
                tax: cart.reduce((sum, item) => sum + (item.price * item.quantity), 0) * 0.18
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
                            title: 'Sale Held',
                            text: 'Sale has been held successfully.',
                            timer: 1500
                        });
                        cart = [];
                        updateCartDisplay();
                        clearCartStorage();
                        generateNewReceipt();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
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
                                No held sales found
                            </td>
                        </tr>
                    `);
                } else {
                    response.data.forEach(sale => {
                        const row = `
                            <tr>
                                <td>${sale.hold_reference || 'HOLD-' + sale.hold_id}</td>
                                <td>${sale.customer_name || 'Walk-in'}</td>
                                <td>${JSON.parse(sale.items_data).length}</td>
                                <td>TZS ${parseFloat(sale.total_amount).toLocaleString()}</td>
                                <td>${new Date(sale.held_at).toLocaleTimeString()}</td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="loadHeldSale(${sale.hold_id})">
                                        <i class="bi bi-arrow-clockwise"></i> Load
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
                        title: 'Load Held Sale?',
                        text: "Current cart will be replaced. Continue?",
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Load it',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Parse items and load into cart
                            try {
                                cart = JSON.parse(sale.items_data);
                                
                                // Restore customer if saved
                                if (sale.customer_id) {
                                    $('#customerSelect').val(sale.customer_id);
                                }
                                
                                updateCartDisplay();
                                saveCartToStorage();
                                $('#heldSalesModal').modal('hide');
                                
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Loaded',
                                    text: 'Sale loaded successfully',
                                    timer: 1000,
                                    showConfirmButton: false
                                });
                                
                                // Optionally delete the held sale after loading
                                deleteHeldSale(holdId, true); // true = silent delete
                            } catch (e) {
                                console.error('Error parsing cart data', e);
                                Swal.fire('Error', 'Failed to load sale data', 'error');
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
            title: 'Delete Held Sale?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
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
                        'Deleted!',
                        'Held sale has been deleted.',
                        'success'
                    );
                    showHeldSales(); // Refresh list
                }
            } else {
                if (!silent) Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            if (!silent) Swal.fire('Error', 'Failed to delete sale', 'error');
        }
    });
}

function startShift() {
    $('#startShiftModal').modal('show');
}

function confirmStartShift() {
    const openingCash = parseFloat($('#openingCash').val()) || 0;
    
    console.log('=== STARTING SHIFT ===');
    console.log('Opening Cash:', openingCash);
    
    // Disable button to prevent double-click
    const btn = event.target;
    $(btn).prop('disabled', true).text('Starting...');
    
    $.ajax({
        url: '<?= buildUrl('/api/pos/open_shift.php') ?>',
        type: 'POST',
        data: { 
            opening_cash: openingCash 
        },
        dataType: 'json',
        success: function(response) {
            console.log('=== SHIFT RESPONSE ===');
            console.log(response);
            
            if (response.success) {
                $('#startShiftModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Shift Started',
                    text: 'Shift ' + response.shift_code + ' started successfully!',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message
                });
                $(btn).prop('disabled', false).text('Start Shift');
            }
        },
        error: function(xhr, status, error) {
            console.error('=== SHIFT ERROR ===');
            console.error('Status:', status);
            console.error('Error:', error);
            console.error('Response:', xhr.responseText);
            
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to start shift: ' + error
            });
            $(btn).prop('disabled', false).text('Start Shift');
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
    $(btn).prop('disabled', true).text('Closing...');
    
    $.ajax({
        url: '<?= buildUrl('/api/pos/close_shift.php') ?>',
        type: 'POST',
        data: {
            ending_cash: endingCash,
            notes: notes
        },
        dataType: 'json',
        success: function(response) {
            console.log('=== CLOSE SHIFT RESPONSE ===');
            console.log(response);
            
            if (response.success) {
                $('#endShiftModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Shift Ended',
                    html: `
                        <p>Shift closed successfully!</p>
                        <p><strong>Expected:</strong> TZS ${response.expected_cash.toLocaleString()}</p>
                        <p><strong>Actual:</strong> TZS ${response.ending_cash.toLocaleString()}</p>
                        <p><strong>Difference:</strong> TZS ${response.cash_difference.toLocaleString()}</p>
                    `,
                    timer: 3000
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message
                });
                $(btn).prop('disabled', false).text('End Shift');
            }
        },
        error: function(xhr, status, error) {
            console.error('=== CLOSE SHIFT ERROR ===');
            console.error('Status:', status);
            console.error('Error:', error);
            console.error('Response:', xhr.responseText);
            
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to close shift: ' + error
            });
            $(btn).prop('disabled', false).text('End Shift');
        }
    });
}

function openCashDrawer() {
    Swal.fire({
        icon: 'success',
        title: 'Cash Drawer',
        text: 'Cash drawer opened.',
        timer: 1500
    });
}

function openDiscountModal() {
    $('#discountPercentage').val(currentDiscountPercentage);
    $('#discountModal').modal('show');
}

function applyDiscount() {
    const val = parseFloat($('#discountPercentage').val()) || 0;
    if (val < 0 || val > 100) {
        Swal.fire('Error', 'Discount must be between 0 and 100%', 'error');
        return;
    }
    currentDiscountPercentage = val;
    calculateCartTotal();
    $('#discountModal').modal('hide');
    Swal.fire({
        icon: 'success',
        title: 'Discount Applied',
        text: currentDiscountPercentage + '% discount has been applied to this sale.',
        timer: 1500,
        showConfirmButton: false
    });
}

function openSplitPaymentModal() {
    if (cart.length === 0) {
        Swal.fire('Empty Cart', 'Add items to cart first.', 'warning');
        return;
    }
    const total = calculateCartTotal();
    $('#splitTotalDisplay').text('TZS ' + total.toLocaleString());
    $('#splitRemaining').text('TZS ' + total.toLocaleString());
    $('.split-amount').val(0);
    $('#splitPaymentModal').modal('show');
}

function calculateSplitRemaining() {
    const total = parseFloat($('#cartTotal').text().replace('TZS ', '').replace(/,/g, '')) || 0;
    let paid = 0;
    $('.split-amount').each(function() {
        paid += parseFloat($(this).val()) || 0;
    });
    const remaining = total - paid;
    $('#splitRemaining').text('TZS ' + remaining.toLocaleString());
    if (remaining < 0) {
        $('#splitRemaining').addClass('text-danger');
    } else {
        $('#splitRemaining').removeClass('text-danger');
    }
}

function processSplitPayment() {
    const total = parseFloat($('#cartTotal').text().replace('TZS ', '').replace(/,/g, '')) || 0;
    let paid = 0;
    splitAmounts = {
        cash: parseFloat($('#splitCash').val()) || 0,
        mobile: parseFloat($('#splitMobile').val()) || 0,
        bank: parseFloat($('#splitBank').val()) || 0,
        card: parseFloat($('#splitCard').val()) || 0
    };
    
    Object.values(splitAmounts).forEach(v => paid += v);

    if (Math.abs(paid - total) > 0.1) {
        Swal.fire('Balance Mismatch', 'Total split amounts must equal the total payable (TZS ' + total.toLocaleString() + ')', 'error');
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
            title: 'Empty Cart',
            text: 'Add items to cart before applying discount.',
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
        $('#discountLabel').text('Discount Amount (TZS)'); // Use generic currency if possible, or TZS
        $('#discountSuffix').text('TZS');
        $('#discountValue').removeAttr('max');
        discountPresets.addClass('d-none');
        discountIcon.removeClass('bi-percent').addClass('bi-cash');
    } else {
        $('#discountLabel').text('Discount Percentage (%)');
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
                    Select All Products
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
                            Min Selling Price: ${item.min_selling_price.toLocaleString()} 
                            (Max: ${maxDiscount}%)
                        </small>`;
        } else {
             // For fixed amount, show minimal info or nothing as requested ("flexible")
             minPriceInfo = `<small class="text-success"><i class="bi bi-unlock"></i> Flexible Amount</small>`;
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
            title: 'Invalid Discount',
            text: 'Discount cannot be negative.'
        });
        return;
    }

    if (posDiscountType === 'percentage' && value > 100) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Discount',
            text: 'Percentage cannot be greater than 100.'
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
            title: 'No Selection',
            text: 'Please select at least one product to discount.'
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
                errorMessages.push(`${item.product_name}: Price ${newPrice.toLocaleString()} is below minimum ${item.min_selling_price.toLocaleString()}`);
                isValid = false;
            }
        }
        
        // Basic limit for fixed (can't be negative)
        if (newPrice < 0) {
             errorMessages.push(`${item.product_name}: Resulting price cannot be negative.`);
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
            title: 'Price Validation Failed',
            html: errorMessages.join('<br>') + '<br><br><b>Note:</b> Other valid items were updated.',
            confirmButtonText: 'OK'
        });
    } else {
        Swal.fire({
            icon: 'success',
            title: 'Discount Applied',
            text: `Successfully updated ${updatedCount} items.`,
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
</script>
