/**
 * POS JavaScript Module
 * Handles all client-side POS functionality with improved performance and security
 */

const POS = (function() {
    'use strict';
    
    // Configuration
    const CONFIG = {
        API_BASE: 'api/pos_controller.php',
        DEBOUNCE_DELAY: 300,
        CACHE_TTL: 300000, // 5 minutes
        MAX_CART_ITEMS: 100,
        CURRENCY: 'TZS'
    };
    
    // State management
    const state = {
        cart: [],
        categories: [],
        currentProduct: null,
        currentReceiptNumber: '',
        shiftActive: false,
        csrfToken: '',
        searchCache: new Map()
    };
    
    // Utility functions
    const utils = {
        /**
         * Debounce function calls
         */
        debounce(func, delay) {
            let timeoutId;
            return function(...args) {
                clearTimeout(timeoutId);
                timeoutId = setTimeout(() => func.apply(this, args), delay);
            };
        },
        
        /**
         * Format currency
         */
        formatCurrency(amount) {
            return `${CONFIG.CURRENCY} ${parseFloat(amount).toFixed(2)}`;
        },
        
        /**
         * Show loading state
         */
        showLoading(element, show = true) {
            if (show) {
                element.classList.add('loading');
                element.setAttribute('disabled', 'disabled');
            } else {
                element.classList.remove('loading');
                element.removeAttribute('disabled');
            }
        },
        
        /**
         * Make API request with CSRF token
         */
        async apiRequest(action, data = {}, method = 'GET') {
            const url = new URL(CONFIG.API_BASE, window.location.origin);
            url.searchParams.append('action', action);
            
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                }
            };
            
            if (method === 'POST') {
                data.csrf_token = state.csrfToken;
                options.body = JSON.stringify(data);
            } else if (method === 'GET' && Object.keys(data).length > 0) {
                Object.keys(data).forEach(key => {
                    url.searchParams.append(key, data[key]);
                });
            }
            
            try {
                const response = await fetch(url, options);
                const result = await response.json();
                
                if (!response.ok) {
                    throw new Error(result.message || 'Request failed');
                }
                
                return result;
            } catch (error) {
                console.error('API Request Error:', error);
                throw error;
            }
        },
        
        /**
         * Save cart to localStorage
         */
        saveCart() {
            try {
                localStorage.setItem('pos_cart', JSON.stringify(state.cart));
                localStorage.setItem('pos_cart_timestamp', Date.now());
            } catch (e) {
                console.error('Failed to save cart:', e);
            }
        },
        
        /**
         * Load cart from localStorage
         */
        loadCart() {
            try {
                const cartData = localStorage.getItem('pos_cart');
                const timestamp = localStorage.getItem('pos_cart_timestamp');
                
                // Check if cart is not too old (1 hour)
                if (cartData && timestamp && (Date.now() - parseInt(timestamp)) < 3600000) {
                    state.cart = JSON.parse(cartData);
                    return true;
                }
            } catch (e) {
                console.error('Failed to load cart:', e);
            }
            return false;
        },
        
        /**
         * Clear cart from localStorage
         */
        clearCartStorage() {
            localStorage.removeItem('pos_cart');
            localStorage.removeItem('pos_cart_timestamp');
        }
    };
    
    // Product management
    const products = {
        /**
         * Load products with caching
         */
        async load(categoryId = 'all', searchTerm = '', page = 1) {
            const cacheKey = `${categoryId}_${searchTerm}_${page}`;
            const cached = state.searchCache.get(cacheKey);
            
            if (cached && (Date.now() - cached.timestamp) < CONFIG.CACHE_TTL) {
                this.display(cached.data);
                return;
            }
            
            const loadingEl = document.getElementById('loadingProducts');
            const gridEl = document.getElementById('productGrid');
            
            loadingEl.style.display = 'block';
            gridEl.innerHTML = '';
            
            try {
                const filters = {
                    search: searchTerm,
                    in_stock: true,
                    page: page,
                    limit: 50
                };
                
                if (categoryId !== 'all') {
                    filters.category_id = categoryId;
                }
                
                const result = await utils.apiRequest('get_products', filters);
                
                if (result.success) {
                    // Cache the result
                    state.searchCache.set(cacheKey, {
                        data: result.data,
                        timestamp: Date.now()
                    });
                    
                    this.display(result.data);
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error('Load products error:', error);
                gridEl.innerHTML = `
                    <div class="col-12 text-center py-5">
                        <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: #dc3545;"></i>
                        <h5 class="mt-3 text-danger">Error loading products</h5>
                        <p class="text-muted">${error.message}</p>
                    </div>
                `;
            } finally {
                loadingEl.style.display = 'none';
            }
        },
        
        /**
         * Display products in grid
         */
        display(productsData) {
            const gridEl = document.getElementById('productGrid');
            gridEl.innerHTML = '';
            
            if (!productsData || productsData.length === 0) {
                gridEl.innerHTML = `
                    <div class="col-12 text-center py-5">
                        <i class="bi bi-search" style="font-size: 3rem; color: #6c757d;"></i>
                        <h5 class="mt-3 text-muted">No products found</h5>
                        <p class="text-muted">Try a different search or category</p>
                    </div>
                `;
                return;
            }
            
            productsData.forEach(product => {
                const card = document.createElement('div');
                card.className = 'col-xl-3 col-lg-4 col-md-6';
                card.innerHTML = `
                    <div class="card product-card h-100" onclick="POS.showProductQuickView(${product.product_id})">
                        <div class="card-body text-center p-2">
                            ${product.image_url ? 
                                `<img src="${product.image_url}" class="img-fluid mb-2" style="height: 100px; object-fit: cover;" alt="${product.product_name}">` : 
                                `<div class="mb-2" style="height: 100px; background: #f8f9fa; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-image text-muted" style="font-size: 2rem;"></i>
                                </div>`
                            }
                            <h6 class="card-title mb-1">${product.product_name}</h6>
                            <p class="card-text text-muted small mb-1">${product.sku || 'No SKU'}</p>
                            <p class="card-text fw-bold text-success mb-1">${utils.formatCurrency(product.selling_price)}</p>
                            <p class="card-text small ${product.stock_quantity <= 10 ? 'text-danger' : 'text-muted'}">
                                Stock: ${product.stock_quantity} ${product.unit || 'pcs'}
                            </p>
                        </div>
                    </div>
                `;
                gridEl.appendChild(card);
            });
        },
        
        /**
         * Search products with debouncing
         */
        search: null // Will be set in init
    };
    
    // Cart management
    const cart = {
        /**
         * Add item to cart
         */
        add(product, quantity = 1) {
            if (state.cart.length >= CONFIG.MAX_CART_ITEMS) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cart Full',
                    text: `Maximum ${CONFIG.MAX_CART_ITEMS} items allowed in cart`,
                    timer: 2000
                });
                return;
            }
            
            const existingItem = state.cart.find(item => item.product_id === product.product_id);
            
            if (existingItem) {
                const newQuantity = existingItem.quantity + quantity;
                if (newQuantity <= product.stock_quantity) {
                    existingItem.quantity = newQuantity;
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Stock Limit',
                        text: `Only ${product.stock_quantity} units available`,
                        timer: 2000
                    });
                    return;
                }
            } else {
                state.cart.push({
                    product_id: product.product_id,
                    product_name: product.product_name,
                    sku: product.sku,
                    unit: product.unit,
                    price: parseFloat(product.selling_price),
                    quantity: quantity,
                    tax_rate: product.tax_rate || 0
                });
            }
            
            this.update();
            utils.saveCart();
            
            // Play success sound
            this.playSound('success');
        },
        
        /**
         * Update cart display
         */
        update() {
            const cartBody = document.getElementById('cartBody');
            const emptyCart = document.getElementById('emptyCart');
            const cartTable = document.getElementById('cartTable');
            
            if (state.cart.length === 0) {
                cartBody.innerHTML = '';
                cartTable.style.display = 'none';
                emptyCart.style.display = 'block';
                document.getElementById('cartItemCount').textContent = '0';
            } else {
                emptyCart.style.display = 'none';
                cartTable.style.display = 'table';
                cartBody.innerHTML = '';
                
                state.cart.forEach((item, index) => {
                    const taxAmount = item.price * item.quantity * (item.tax_rate / 100);
                    const itemTotal = (item.price * item.quantity) + taxAmount;
                    
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${index + 1}</td>
                        <td>
                            <strong>${item.product_name}</strong><br>
                            <small class="text-muted">${item.sku || 'No SKU'}</small>
                        </td>
                        <td>
                            <div class="input-group input-group-sm">
                                <button class="btn btn-outline-secondary btn-sm" onclick="POS.updateCartQuantity(${index}, -1)">-</button>
                                <input type="number" class="form-control text-center" 
                                       value="${item.quantity}" min="1" 
                                       onchange="POS.updateCartQuantityInput(${index}, this.value)">
                                <button class="btn btn-outline-secondary btn-sm" onclick="POS.updateCartQuantity(${index}, 1)">+</button>
                            </div>
                        </td>
                        <td>${utils.formatCurrency(item.price)}</td>
                        <td>
                            ${utils.formatCurrency(itemTotal)}
                            <button class="btn btn-sm btn-link text-danger ms-2" onclick="POS.removeFromCart(${index})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    `;
                    cartBody.appendChild(row);
                });
                
                document.getElementById('cartItemCount').textContent = state.cart.length;
            }
            
            this.calculateTotal();
        },
        
        /**
         * Calculate cart total
         */
        calculateTotal() {
            const subtotal = state.cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const tax = state.cart.reduce((sum, item) => {
                return sum + (item.price * item.quantity * (item.tax_rate / 100));
            }, 0);
            
            const discountInput = parseFloat(document.getElementById('cartDiscount').value) || 0;
            const discountType = document.getElementById('discountType').value;
            const shipping = parseFloat(document.getElementById('cartShipping').value) || 0;
            
            let discount = 0;
            if (discountType === 'percent') {
                discount = subtotal * (discountInput / 100);
            } else {
                discount = discountInput;
            }
            
            const total = subtotal + tax - discount + shipping;
            
            document.getElementById('cartSubtotal').textContent = subtotal.toFixed(2);
            document.getElementById('cartTax').textContent = tax.toFixed(2);
            document.getElementById('cartTotal').textContent = total.toFixed(2);
            
            return total;
        },
        
        /**
         * Clear cart
         */
        clear() {
            if (state.cart.length === 0) return;
            
            Swal.fire({
                title: 'Clear Cart?',
                text: 'Are you sure you want to remove all items?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Clear',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    state.cart = [];
                    this.update();
                    utils.clearCartStorage();
                    this.playSound('clear');
                }
            });
        },
        
        /**
         * Play sound feedback
         */
        playSound(type) {
            // Simple beep sound using Web Audio API
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.frequency.value = type === 'success' ? 800 : 400;
                oscillator.type = 'sine';
                
                gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);
                
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.1);
            } catch (e) {
                console.log('Audio not supported');
            }
        }
    };
    
    // Payment processing
    const payment = {
        /**
         * Process payment
         */
        async process() {
            if (state.cart.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Empty Cart',
                    text: 'Add items to cart before processing payment',
                    timer: 2000
                });
                return;
            }
            
            const paymentMethod = document.querySelector('input[name="paymentMethod"]:checked').value;
            const total = cart.calculateTotal();
            
            // Validate cash payment
            if (paymentMethod === 'cash') {
                const tendered = parseFloat(document.getElementById('amountTendered').value) || 0;
                if (tendered < total) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Insufficient Payment',
                        text: 'Amount tendered is less than total',
                        timer: 2000
                    });
                    return;
                }
            }
            
            const btn = document.getElementById('processPaymentBtn');
            utils.showLoading(btn);
            
            try {
                const saleData = {
                    receipt_number: state.currentReceiptNumber,
                    customer_id: document.getElementById('customerSelect').value || null,
                    items: state.cart,
                    subtotal: parseFloat(document.getElementById('cartSubtotal').textContent),
                    tax: parseFloat(document.getElementById('cartTax').textContent),
                    discount: parseFloat(document.getElementById('cartDiscount').value) || 0,
                    discount_type: document.getElementById('discountType').value,
                    shipping: parseFloat(document.getElementById('cartShipping').value) || 0,
                    total: total,
                    payment_method: paymentMethod,
                    amount_tendered: paymentMethod === 'cash' ? 
                        parseFloat(document.getElementById('amountTendered').value) : total,
                    change_given: paymentMethod === 'cash' ? 
                        (parseFloat(document.getElementById('amountTendered').value) - total) : 0,
                    shift_id: window.shiftId || null,
                    user_id: window.userId || null
                };
                
                const result = await utils.apiRequest('process_sale', saleData, 'POST');
                
                if (result.success) {
                    // Print receipt
                    this.printReceipt(result.data.sale_id);
                    
                    // Reset cart
                    state.cart = [];
                    cart.update();
                    utils.clearCartStorage();
                    this.generateNewReceipt();
                    
                    // Reset form
                    document.getElementById('amountTendered').value = '0';
                    document.getElementById('cartDiscount').value = '0';
                    document.getElementById('cartShipping').value = '0';
                    document.getElementById('changeAlert').style.display = 'none';
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Payment Successful',
                        text: 'Sale completed successfully',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    cart.playSound('success');
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Payment Failed',
                    text: error.message
                });
            } finally {
                utils.showLoading(btn, false);
            }
        },
        
        /**
         * Print receipt
         */
        printReceipt(saleId) {
            const printWindow = window.open(`receipt_print.php?id=${saleId}`, '_blank');
            if (printWindow) {
                printWindow.focus();
            }
        },
        
        /**
         * Generate new receipt number
         */
        generateNewReceipt() {
            state.currentReceiptNumber = 'POS' + Date.now().toString().slice(-10) + 
                                        Math.floor(Math.random() * 100);
            document.getElementById('receiptNumber').textContent = state.currentReceiptNumber;
        },
        
        /**
         * Calculate change
         */
        calculateChange() {
            const total = cart.calculateTotal();
            const tendered = parseFloat(document.getElementById('amountTendered').value) || 0;
            const change = tendered - total;
            
            if (change > 0) {
                document.getElementById('changeAlert').style.display = 'block';
                document.getElementById('changeAmount').textContent = change.toFixed(2);
            } else {
                document.getElementById('changeAlert').style.display = 'none';
            }
        }
    };
    
    // Public API
    return {
        /**
         * Initialize POS system
         */
        init(config = {}) {
            // Merge config
            Object.assign(CONFIG, config);
            
            // Set state
            state.shiftActive = config.shiftActive || false;
            state.csrfToken = config.csrfToken || '';
            state.currentReceiptNumber = config.receiptNumber || '';
            
            // Load cart from storage
            if (utils.loadCart()) {
                cart.update();
            }
            
            // Setup debounced search
            products.search = utils.debounce((searchTerm) => {
                products.load('all', searchTerm);
            }, CONFIG.DEBOUNCE_DELAY);
            
            // Load initial data
            this.loadCategories();
            products.load();
            
            // Setup event listeners
            this.setupEventListeners();
            
            console.log('POS System initialized');
        },
        
        /**
         * Setup event listeners
         */
        setupEventListeners() {
            // Product search
            const searchInput = document.getElementById('productSearch');
            if (searchInput) {
                searchInput.addEventListener('keyup', (e) => {
                    if (e.key === 'Enter') {
                        products.load('all', e.target.value);
                    } else {
                        products.search(e.target.value);
                    }
                });
            }
            
            // Payment method change
            document.querySelectorAll('input[name="paymentMethod"]').forEach(radio => {
                radio.addEventListener('change', (e) => {
                    const method = e.target.value;
                    document.getElementById('cashPaymentSection').style.display = 
                        method === 'cash' ? 'block' : 'none';
                    document.getElementById('creditPaymentSection').style.display = 
                        method === 'credit' ? 'block' : 'none';
                    if (method === 'cash') {
                        payment.calculateChange();
                    }
                });
            });
            
            // Amount tendered change
            const tenderedInput = document.getElementById('amountTendered');
            if (tenderedInput) {
                tenderedInput.addEventListener('input', () => payment.calculateChange());
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                if (e.key === 'F1') {
                    e.preventDefault();
                    searchInput?.focus();
                } else if (e.key === 'F2') {
                    e.preventDefault();
                    cart.clear();
                } else if (e.key === 'F3') {
                    e.preventDefault();
                    payment.process();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    if (searchInput) searchInput.value = '';
                    products.load();
                }
            });
        },
        
        /**
         * Load categories
         */
        async loadCategories() {
            try {
                const result = await utils.apiRequest('get_categories');
                if (result.success) {
                    state.categories = result.data;
                    this.displayCategories();
                }
            } catch (error) {
                console.error('Load categories error:', error);
            }
        },
        
        /**
         * Display categories
         */
        displayCategories() {
            const container = document.getElementById('categoryButtons');
            if (!container) return;
            
            container.innerHTML = `
                <button type="button" class="btn btn-outline-primary active" 
                        onclick="POS.loadProductsByCategory('all')">
                    All Products
                </button>
            `;
            
            state.categories.slice(0, 10).forEach(category => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-outline-secondary';
                btn.textContent = category.category_name;
                btn.onclick = () => this.loadProductsByCategory(category.category_id);
                container.appendChild(btn);
            });
        },
        
        /**
         * Load products by category
         */
        loadProductsByCategory(categoryId) {
            // Update active button
            document.querySelectorAll('#categoryButtons button').forEach(btn => {
                btn.classList.remove('active', 'btn-primary');
                btn.classList.add('btn-outline-secondary');
            });
            event.target.classList.remove('btn-outline-secondary');
            event.target.classList.add('active', 'btn-primary');
            
            products.load(categoryId);
        },
        
        /**
         * Show product quick view
         */
        async showProductQuickView(productId) {
            try {
                const result = await utils.apiRequest('get_product', { id: productId });
                
                if (result.success) {
                    state.currentProduct = result.data;
                    const product = result.data;
                    
                    const html = `
                        <h6>${product.product_name}</h6>
                        <p class="text-muted small mb-2">${product.sku || 'No SKU'}</p>
                        <p class="text-success fw-bold">${utils.formatCurrency(product.selling_price)}</p>
                        <p class="small ${product.stock_quantity <= 10 ? 'text-danger' : 'text-muted'}">
                            Stock: ${product.stock_quantity} ${product.unit || 'pcs'}
                        </p>
                        
                        <div class="mb-3">
                            <label class="form-label">Quantity</label>
                            <div class="input-group">
                                <button class="btn btn-outline-secondary" type="button" onclick="POS.adjustQuantity(-1)">-</button>
                                <input type="number" class="form-control text-center" id="quickViewQty" 
                                       value="1" min="1" max="${product.stock_quantity}" step="1">
                                <button class="btn btn-outline-secondary" type="button" onclick="POS.adjustQuantity(1)">+</button>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button class="btn btn-primary" onclick="POS.addToCartFromQuickView()">
                                <i class="bi bi-cart-plus"></i> Add to Cart
                            </button>
                            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    `;
                    
                    document.getElementById('quickViewContent').innerHTML = html;
                    const modal = new bootstrap.Modal(document.getElementById('productQuickView'));
                    modal.show();
                    
                    setTimeout(() => {
                        document.getElementById('quickViewQty')?.focus();
                    }, 300);
                }
            } catch (error) {
                console.error('Show product error:', error);
            }
        },
        
        /**
         * Adjust quantity in quick view
         */
        adjustQuantity(amount) {
            const input = document.getElementById('quickViewQty');
            if (!input) return;
            
            const current = parseInt(input.value) || 1;
            const max = parseInt(input.max) || 9999;
            const newValue = Math.max(1, Math.min(max, current + amount));
            input.value = newValue;
        },
        
        /**
         * Add to cart from quick view
         */
        addToCartFromQuickView() {
            if (!state.currentProduct) return;
            
            const quantity = parseInt(document.getElementById('quickViewQty')?.value) || 1;
            cart.add(state.currentProduct, quantity);
            
            const modal = bootstrap.Modal.getInstance(document.getElementById('productQuickView'));
            modal?.hide();
        },
        
        /**
         * Update cart quantity
         */
        async updateCartQuantity(index, change) {
            if (!state.cart[index]) return;
            
            const newQuantity = state.cart[index].quantity + change;
            if (newQuantity < 1) return;
            
            try {
                const result = await utils.apiRequest('check_stock', {
                    product_id: state.cart[index].product_id
                });
                
                if (result.success && newQuantity <= result.data.stock_quantity) {
                    state.cart[index].quantity = newQuantity;
                    cart.update();
                    utils.saveCart();
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Stock Limit',
                        text: `Only ${result.data.stock_quantity} units available`,
                        timer: 2000
                    });
                }
            } catch (error) {
                console.error('Update quantity error:', error);
            }
        },
        
        /**
         * Update cart quantity from input
         */
        updateCartQuantityInput(index, value) {
            const quantity = parseInt(value) || 1;
            if (quantity >= 1 && state.cart[index]) {
                state.cart[index].quantity = quantity;
                cart.update();
                utils.saveCart();
            }
        },
        
        /**
         * Remove from cart
         */
        removeFromCart(index) {
            state.cart.splice(index, 1);
            cart.update();
            utils.saveCart();
        },
        
        /**
         * Clear cart
         */
        clearCart() {
            cart.clear();
        },
        
        /**
         * Process payment
         */
        processPayment() {
            payment.process();
        },
        
        /**
         * Calculate change
         */
        calculateChange() {
            payment.calculateChange();
        }
    };
})();

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Configuration will be passed from PHP
    if (typeof POS_CONFIG !== 'undefined') {
        POS.init(POS_CONFIG);
    }
});
