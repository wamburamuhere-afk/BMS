<?php
// File: pos_scripts.php - JavaScript for POS functionality
?>
<script>
// Global variables
let cart = [];
let products = [];
let currentCategory = '';
let currentType = '';

// Initialize on page load
$(document).ready(function() {
    // Load cart from localStorage
    loadCartFromStorage();
    
    loadProducts();
    updateClock();
    setInterval(updateClock, 1000);
    
    // Product search
    $('#productSearch').on('input', function() {
        const searchTerm = $(this).val().toLowerCase();
        filterProducts(searchTerm, currentCategory, currentType);
    });
    
    // Category/Type filters
    $('.category-btn').click(function() {
        $('.category-btn').removeClass('active');
        $(this).addClass('active');
        currentCategory = $(this).data('category') || '';
        currentType = $(this).data('type') || '';
        const searchTerm = $('#productSearch').val().toLowerCase();
        filterProducts(searchTerm, currentCategory, currentType);
    });
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // F1 - Cash payment
        if (e.key === 'F1') {
            e.preventDefault();
            if (cart.length > 0) processPayment('cash');
        }
        // F2 - Card payment
        if (e.key === 'F2') {
            e.preventDefault();
            if (cart.length > 0) processPayment('card');
        }
        // F3 - Hold sale
        if (e.key === 'F3') {
            e.preventDefault();
            if (cart.length > 0) holdSale();
        }
        // F4 - Clear cart
        if (e.key === 'F4') {
            e.preventDefault();
            clearCart();
        }
    });
});

// Save cart to localStorage
function saveCartToStorage() {
    try {
        localStorage.setItem('pos_cart', JSON.stringify(cart));
        localStorage.setItem('pos_customer', $('#customerId').val() || '');
    } catch (e) {
        console.error('Error saving cart:', e);
    }
}

// Load cart from localStorage
function loadCartFromStorage() {
    try {
        const savedCart = localStorage.getItem('pos_cart');
        const savedCustomer = localStorage.getItem('pos_customer');
        
        if (savedCart) {
            cart = JSON.parse(savedCart);
            updateCart();
        }
        
        if (savedCustomer) {
            $('#customerId').val(savedCustomer);
        }
    } catch (e) {
        console.error('Error loading cart:', e);
        cart = [];
    }
}

// Clear cart from storage
function clearCartStorage() {
    try {
        localStorage.removeItem('pos_cart');
        localStorage.removeItem('pos_customer');
    } catch (e) {
        console.error('Error clearing cart storage:', e);
    }
}

// Update clock
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { hour12: false });
    $('#current-time').text(timeString);
}

// Load products
function loadProducts() {
    $.ajax({
        url: '<?= getUrl('/api/pos/get_products.php') ?>',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                products = response.data;
                displayProducts(products);
            }
        },
        error: function() {
            // Fallback to basic product fetch
            $.get('<?= buildUrl('/api/account/get_products.php') ?>', {active_only: true}, function(res) {
                if (res.success) {
                    products = res.data;
                    displayProducts(products);
                }
            }, 'json');
        }
    });
}

// Display products
function displayProducts(productsToDisplay) {
    const grid = $('#productsGrid');
    grid.empty();
    
    if (productsToDisplay.length === 0) {
        grid.html('<div class="text-center text-muted p-5"><i class="bi bi-inbox" style="font-size: 3rem;"></i><p>No products found</p></div>');
        return;
    }
    
    productsToDisplay.forEach(product => {
        const stockClass = product.stock_quantity > 10 ? 'text-success' : product.stock_quantity > 0 ? 'text-warning' : 'text-danger';
        const isService = product.is_service == 1 || product.is_service == '1';
        const serviceBadge = isService ? '<span class="badge bg-info text-white mb-1">Service/Equity</span>' : '';
        
        const card = $(`
            <div class="product-card" onclick="addToCart(${product.product_id})">
                <div class="product-image">
                    <i class="bi ${isService ? 'bi-briefcase' : 'bi-box-seam'}" style="font-size: 3rem; color: #0d6efd;"></i>
                </div>
                ${serviceBadge}
                <div class="product-name">${product.product_name}</div>
                <div class="product-price">TZS ${parseFloat(product.selling_price).toLocaleString()}</div>
                ${!isService ? `<div class="product-stock ${stockClass}">
                    <i class="bi bi-box"></i> ${product.stock_quantity || 0} in stock
                </div>` : '<div class="product-stock text-muted"><i class="bi bi-infinity"></i> Service</div>'}
            </div>
        `);
        grid.append(card);
    });
}

// Filter products
function filterProducts(searchTerm, categoryId, productType) {
    let filtered = products;
    
    // Filter by category
    if (categoryId) {
        filtered = filtered.filter(p => p.category_id == categoryId);
    }
    
    // Filter by product type
    if (productType === 'physical') {
        filtered = filtered.filter(p => !p.is_service || p.is_service == 0 || p.is_service == '0');
    } else if (productType === 'service') {
        filtered = filtered.filter(p => p.is_service == 1 || p.is_service == '1');
    }
    
    // Filter by search term
    if (searchTerm) {
        filtered = filtered.filter(p => 
            p.product_name.toLowerCase().includes(searchTerm) ||
            (p.sku && p.sku.toLowerCase().includes(searchTerm)) ||
            (p.barcode && p.barcode.toLowerCase().includes(searchTerm))
        );
    }
    
    displayProducts(filtered);
}

// Add to cart
function addToCart(productId) {
    const product = products.find(p => p.product_id == productId);
    if (!product) return;
    
    // Check if already in cart
    const existingItem = cart.find(item => item.product_id == productId);
    if (existingItem) {
        // Just increment quantity - no stock limit check for POS flexibility
        existingItem.quantity++;
    } else {
        cart.push({
            product_id: product.product_id,
            product_name: product.product_name,
            price: parseFloat(product.selling_price),
            quantity: 1,
            tax_rate: parseFloat(product.tax_rate || 18),
            max_stock: product.stock_quantity
        });
    }
    
    updateCart();
    saveCartToStorage();
}

// Update cart display
function updateCart() {
    const cartItems = $('#cartItems');
    const cartTotals = $('#cartTotals');
    
    if (cart.length === 0) {
        cartItems.html(`
            <div class="empty-cart">
                <i class="bi bi-cart-x"></i>
                <p>Cart is empty<br><small>Scan or select products to add</small></p>
            </div>
        `);
        cartTotals.hide();
        return;
    }
    
    cartItems.empty();
    let subtotal = 0;
    
    cart.forEach((item, index) => {
        const itemTotal = item.price * item.quantity;
        subtotal += itemTotal;
        
        const cartItem = $(`
            <div class="cart-item">
                <div class="cart-item-name">
                    ${item.product_name}
                    <br><small class="text-muted">TZS ${item.price.toLocaleString()} each</small>
                </div>
                <div class="qty-controls">
                    <button class="qty-btn" onclick="updateQuantity(${index}, -1)">
                        <i class="bi bi-dash"></i>
                    </button>
                    <input type="number" class="qty-input" value="${item.quantity}" 
                           onchange="setQuantity(${index}, this.value)" min="1" max="${item.max_stock}">
                    <button class="qty-btn" onclick="updateQuantity(${index}, 1)">
                        <i class="bi bi-plus"></i>
                    </button>
                </div>
                <div class="fw-bold ms-3" style="min-width: 100px; text-align: right;">
                    TZS ${itemTotal.toLocaleString()}
                </div>
                <button class="btn btn-sm btn-link text-danger" onclick="removeFromCart(${index})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `);
        cartItems.append(cartItem);
    });
    
    // Calculate totals
    const taxAmount = subtotal * 0.18; // 18% tax
    const grandTotal = subtotal + taxAmount;
    
    $('#subtotal').text('TZS ' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2}));
    $('#tax').text('TZS ' + taxAmount.toLocaleString('en-US', {minimumFractionDigits: 2}));
    $('#grandTotal').text('TZS ' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2}));
    
    cartTotals.show();
}

// Update quantity
function updateQuantity(index, change) {
    const item = cart[index];
    const newQty = item.quantity + change;
    
    if (newQty <= 0) {
        removeFromCart(index);
    } else {
        item.quantity = newQty;
        updateCart();
        saveCartToStorage();
    }
}

// Set quantity directly
function setQuantity(index, value) {
    const qty = parseInt(value);
    const item = cart[index];
    
    if (qty <= 0) {
        removeFromCart(index);
    } else {
        item.quantity = qty;
        updateCart();
        saveCartToStorage();
    }
}

// Remove from cart
function removeFromCart(index) {
    cart.splice(index, 1);
    updateCart();
    saveCartToStorage();
}

// Clear cart
function clearCart() {
    if (cart.length === 0) return;
    
    Swal.fire({
        title: 'Clear Cart?',
        text: 'Remove all items from cart?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Clear',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            cart = [];
            updateCart();
            clearCartStorage();
        }
    });
}

// Process payment
function processPayment(method) {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Empty Cart',
            text: 'Please add items to cart first.',
            timer: 2000
        });
        return;
    }
    
    // Check for active shift
    <?php if (!$active_shift): ?>
    Swal.fire({
        icon: 'warning',
        title: 'No Active Shift',
        text: 'Please open a shift first before processing sales.',
        showConfirmButton: true,
        confirmButtonText: 'Open Shift'
    }).then((result) => {
        if (result.isConfirmed) {
            openShiftModal();
        }
    });
    return;
    <?php endif; ?>
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const tax = subtotal * 0.18;
    const total = subtotal + tax;
    
    // Show payment modal
    $('#paymentMethod').val(method);
    $('#paymentTotal').text('TZS ' + total.toLocaleString('en-US', {minimumFractionDigits: 2}));
    $('#amountTendered').val(total.toFixed(2));
    calculateChange();
    $('#paymentModal').modal('show');
}

// Hold sale
function holdSale() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Empty Cart',
            text: 'Cannot hold an empty cart.',
            timer: 2000
        });
        return;
    }
    
    Swal.fire({
        title: 'Hold Sale',
        input: 'text',
        inputLabel: 'Reference (optional)',
        inputPlaceholder: 'Enter reference...',
        showCancelButton: true,
        confirmButtonText: 'Hold Sale'
    }).then((result) => {
        if (result.isConfirmed) {
            // Save held sale via API
            const saleData = {
                customer_id: $('#customerId').val() || null,
                reference: result.value || null,
                items: cart,
                subtotal: cart.reduce((sum, item) => sum + (item.price * item.quantity), 0),
                tax: cart.reduce((sum, item) => sum + (item.price * item.quantity), 0) * 0.18
            };
            
            $.post('<?= buildUrl('/api/pos/hold_sale.php') ?>', saleData, function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sale Held',
                        text: 'Sale has been saved.',
                        timer: 2000
                    });
                    cart = [];
                    updateCart();
                    clearCartStorage();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            }, 'json');
        }
    });
}

// Open barcode scanner
function openBarcodeScanner() {
    $('#barcodeScannerModal').modal('show');
}

// Quick add customer
function quickAddCustomer() {
    $('#quickCustomerModal').modal('show');
}

// Open shift modal
function openShiftModal() {
    $('#shiftModal').modal('show');
}
</script>
