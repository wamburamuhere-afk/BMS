<?php
// File: pos_modals.php - Modal dialogs for POS
?>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin"></i> Process Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="paymentMethod">
                
                <div class="alert alert-info">
                    <h4 class="mb-0">Total Amount: <span id="paymentTotal">0.00</span></h4>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Amount Tendered</label>
                    <input type="number" class="form-control form-control-lg" id="amountTendered" 
                           step="0.01" onchange="calculateChange()">
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Change</label>
                    <input type="text" class="form-control form-control-lg" id="changeAmount" readonly>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-lg" onclick="completeSale()">
                    <i class="bi bi-check-circle"></i> Complete Sale
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div class="modal fade" id="barcodeScannerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-upc-scan"></i> Scan Barcode</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="bi bi-upc" style="font-size: 5rem; color: #667eea;"></i>
                </div>
                <input type="text" class="form-control form-control-lg text-center" 
                       id="barcodeInput" placeholder="Scan or enter barcode" autofocus>
            </div>
        </div>
    </div>
</div>

<!-- Quick Add Customer Modal -->
<div class="modal fade" id="quickCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-plus"></i> Quick Add Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Customer Name *</label>
                    <input type="text" class="form-control" id="quickCustomerName">
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" id="quickCustomerPhone">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveQuickCustomer()">
                    <i class="bi bi-check"></i> Add Customer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Shift Management Modal -->
<div class="modal fade" id="shiftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-cash-stack"></i> Shift Management</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($active_shift): ?>
                    <div class="alert alert-success">
                        <h6>Active Shift: <?= $active_shift['shift_code'] ?></h6>
                        <p class="mb-0">Started: <?= date('M d, Y H:i', strtotime($active_shift['start_time'])) ?></p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Closing Cash Amount</label>
                        <input type="number" class="form-control" id="closingCash" step="0.01">
                    </div>
                    
                    <button class="btn btn-danger w-100" onclick="closeShift()">
                        <i class="bi bi-x-circle"></i> Close Shift
                    </button>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <p class="mb-0">No active shift. Please open a shift to start selling.</p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Opening Cash Amount</label>
                        <input type="number" class="form-control" id="openingCash" step="0.01" value="0">
                    </div>
                    
                    <button class="btn btn-success w-100" onclick="openShift()">
                        <i class="bi bi-play-circle"></i> Open Shift
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Calculate change
function calculateChange() {
    const total = parseFloat($('#paymentTotal').text().replace('TZS ', '').replace(/,/g, ''));
    const tendered = parseFloat($('#amountTendered').val()) || 0;
    const change = tendered - total;
    
    $('#changeAmount').val('TZS ' + Math.max(0, change).toLocaleString('en-US', {minimumFractionDigits: 2}));
}

// Complete sale
function completeSale() {
    const total = parseFloat($('#paymentTotal').text().replace('TZS ', '').replace(/,/g, ''));
    const tendered = parseFloat($('#amountTendered').val()) || 0;
    
    if (tendered < total) {
        Swal.fire({
            icon: 'warning',
            title: 'Insufficient Amount',
            text: 'Amount tendered is less than total.',
            timer: 2000
        });
        return;
    }
    
    const saleData = {
        customer_id: $('#customerId').val() || null,
        payment_method: $('#paymentMethod').val(),
        amount_tendered: tendered,
        items: cart,
        subtotal: cart.reduce((sum, item) => sum + (item.price * item.quantity), 0),
        tax: cart.reduce((sum, item) => sum + (item.price * item.quantity), 0) * 0.18,
        total: total
    };
    
    $.post('<?= buildUrl('/api/pos/process_sale.php') ?>', saleData, function(response) {
        if (response.success) {
            $('#paymentModal').modal('hide');
            
            Swal.fire({
                icon: 'success',
                title: 'Sale Completed!',
                text: 'Receipt #' + response.receipt_number,
                showCancelButton: true,
                confirmButtonText: 'Print Receipt',
                cancelButtonText: 'New Sale'
            }).then((result) => {
                if (result.isConfirmed) {
                    printReceipt(response.sale_id);
                }
                cart = [];
                updateCart();
                clearCartStorage();
                $('#customerId').val('');
            });
        } else {
            Swal.fire('Error', response.message, 'error');
        }
    }, 'json').fail(function() {
        Swal.fire('Error', 'Failed to process sale', 'error');
    });
}

// Print receipt
function printReceipt(saleId) {
    window.open('<?= getUrl('/api/pos/print_receipt.php') ?>?id=' + saleId, '_blank');
}

// Save quick customer
function saveQuickCustomer() {
    const name = $('#quickCustomerName').val().trim();
    const phone = $('#quickCustomerPhone').val().trim();
    
    if (!name) {
        Swal.fire('Error', 'Customer name is required', 'warning');
        return;
    }
    
    $.post('<?= buildUrl('/api/quick_add_customer.php') ?>', {
        customer_name: name,
        phone: phone
    }, function(response) {
        if (response.success) {
            const option = new Option(name, response.customer_id, true, true);
            $('#customerId').append(option);
            $('#quickCustomerModal').modal('hide');
            $('#quickCustomerName').val('');
            $('#quickCustomerPhone').val('');
            
            Swal.fire({
                icon: 'success',
                title: 'Customer Added',
                timer: 1500,
                showConfirmButton: false
            });
        } else {
            Swal.fire('Error', response.message, 'error');
        }
    }, 'json');
}

// Open shift
function openShift() {
    const openingCash = parseFloat($('#openingCash').val()) || 0;
    
    $.post('<?= buildUrl('/api/pos/open_shift.php') ?>', {
        opening_cash: openingCash
    }, function(response) {
        if (response.success) {
            Swal.fire({
                icon: 'success',
                title: 'Shift Opened',
                text: 'Shift ' + response.shift_code,
                timer: 2000
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Error', response.message, 'error');
        }
    }, 'json');
}

// Close shift
function closeShift() {
    const closingCash = parseFloat($('#closingCash').val()) || 0;
    
    Swal.fire({
        title: 'Close Shift?',
        text: 'This will end your current shift.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Close Shift'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('<?= buildUrl('/api/pos/close_shift.php') ?>', {
                closing_cash: closingCash
            }, function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Shift Closed',
                        text: 'Shift summary generated.',
                        timer: 2000
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            }, 'json');
        }
    });
}

// Barcode scanner
$('#barcodeInput').on('keypress', function(e) {
    if (e.which === 13) {
        const barcode = $(this).val().trim();
        if (barcode) {
            const product = products.find(p => p.barcode === barcode);
            if (product) {
                addToCart(product.product_id);
                $('#barcodeScannerModal').modal('hide');
                $(this).val('');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Not Found',
                    text: 'Product not found',
                    timer: 2000
                });
            }
        }
    }
});
</script>
