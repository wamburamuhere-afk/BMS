<?php
// File: customer_display.php
// Customer-facing display for POS
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Display</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
        }
        
        .display-container {
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 2rem;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .store-name {
            font-size: 2.5rem;
            font-weight: 700;
            color: #667eea;
            margin: 0;
        }
        
        .cart-container {
            flex: 1;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 2rem;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #999;
        }
        
        .empty-state i {
            font-size: 8rem;
            margin-bottom: 2rem;
            opacity: 0.3;
        }
        
        .empty-state h2 {
            font-size: 2.5rem;
            font-weight: 300;
        }
        
        .cart-item {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
            border-radius: 15px;
            transition: all 0.3s ease;
        }
        
        .cart-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .item-name {
            flex: 2; /* Increased flex to give more space to name */
            font-size: 1.8rem;
            font-weight: 600;
            color: #333;
        }
        
        .item-qty {
            flex: 0 0 100px; /* Fixed width for qty */
            font-size: 1.5rem;
            color: #667eea;
            font-weight: 600;
            margin: 0 1rem;
            text-align: center;
        }
        
        .item-price {
            flex: 0 0 200px; /* Fixed width for price */
            font-size: 1.8rem;
            font-weight: 700;
            color: #28a745;
            text-align: right;
        }
        
        .summary {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            font-size: 1.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .summary-row:last-child {
            border-bottom: none;
        }
        
        .summary-total {
            font-size: 3rem;
            font-weight: 700;
            color: #667eea;
            padding-top: 1.5rem;
            border-top: 3px solid #667eea;
        }
        
        .summary-label {
            color: #666;
        }
        
        .summary-value {
            font-weight: 600;
            color: #333;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .cart-item {
            animation: slideIn 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="display-container">
        <!-- Header -->
        <div class="header">
            <h1 class="store-name">
                <i class="bi bi-shop"></i> Business Management System
            </h1>
        </div>
        
        <!-- Cart Items -->
        <div class="cart-container" id="cartDisplay">
            <div class="empty-state" id="emptyState">
                <i class="bi bi-cart3"></i>
                <h2>Welcome!</h2>
                <p class="lead">Your items will appear here</p>
            </div>
            
            <div id="cartItems" style="display: none;">
                <!-- Items will be loaded here -->
            </div>
        </div>
        
        <!-- Summary -->
        <div class="summary" id="summarySection" style="display: none;">
            <div class="summary-row">
                <span class="summary-label">Subtotal:</span>
                <span class="summary-value" id="displaySubtotal">TZS 0.00</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Tax:</span>
                <span class="summary-value" id="displayTax">TZS 0.00</span>
            </div>
            <div class="summary-row summary-total">
                <span>TOTAL:</span>
                <span id="displayTotal">TZS 0.00</span>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let lastTimestamp = 0;
        
        function updateDisplay() {
            $.ajax({
                url: APP_URL + '/api/pos_session',
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Only update if data changed
                        if (response.timestamp !== lastTimestamp) {
                            lastTimestamp = response.timestamp;
                            renderCart(response.cart, response.summary);
                        }
                    }
                },
                error: function() {
                    console.log('Failed to fetch cart data');
                }
            });
        }
        
        function renderCart(cart, summary) {
            const emptyState = $('#emptyState');
            const cartItems = $('#cartItems');
            const summarySection = $('#summarySection');
            
            if (cart.length === 0) {
                emptyState.show();
                cartItems.hide();
                summarySection.hide();
                return;
            }
            
            emptyState.hide();
            cartItems.show();
            summarySection.show();
            
            // Render items
            let html = '';
            cart.forEach(function(item) {
                const itemTotal = (item.price * item.quantity) + (item.price * item.quantity * item.tax_rate / 100);
                html += `
                    <div class="cart-item">
                        <div class="item-name">${item.product_name}</div>
                        <div class="item-qty">x${item.quantity}</div>
                        <div class="item-price">TZS ${itemTotal.toFixed(2)}</div>
                    </div>
                `;
            });
            cartItems.html(html);
            
            // Update summary
            $('#displaySubtotal').text('TZS ' + summary.subtotal.toFixed(2));
            $('#displayTax').text('TZS ' + summary.tax.toFixed(2));
            $('#displayTotal').text('TZS ' + summary.total.toFixed(2));
        }
        
        // Poll every 500ms
        setInterval(updateDisplay, 500);
        
        // Initial load
        updateDisplay();
    </script>
</body>
</html>
