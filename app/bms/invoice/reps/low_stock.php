<?php
// File: reps/low_stock.php

try {
    global $pdo;
    
    // Fetch products where current stock is below or equal to reorder level
    // Select product_id for actions
    $sql = "
        SELECT 
            p.product_id,
            p.product_code,
            p.product_name,
            p.current_stock,
            p.reorder_level,
            p.min_stock_level,
            p.status
        FROM products p
        WHERE p.status = 'active'
        AND (COALESCE(p.current_stock, 0) <= COALESCE(p.reorder_level, p.min_stock_level, 0))
        ORDER BY (COALESCE(p.current_stock, 0) - COALESCE(p.reorder_level, p.min_stock_level, 0)) ASC
    ";
    
    $results = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!-- Print-only Header -->
<div class="d-none d-print-block text-center mb-4">
    <?php 
    $c_name = getSetting('company_name', 'BMS');
    $c_logo = getSetting('company_logo', '');
    $c_email = getSetting('company_email', '');
    $c_web = getSetting('company_website', '');
    $c_tin = getSetting('company_tin', '');
    $c_vrn = getSetting('company_vrn', '');
    ?>
    <?php if(!empty($c_logo)): ?>
        <div class="mb-3">
            <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
        </div>
    <?php endif; ?>
    <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= safe_output($c_name) ?></h1>
    
    <p class="text-dark mb-1 small text-uppercase">
        <?php 
        $web_email = [];
        if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
        if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
        if (!empty($web_email)) echo implode(" | ", $web_email);
        ?>
    </p>

    <p class="text-dark mb-1 small text-uppercase">
        <?php 
        $tin_vrn = [];
        if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
        if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
        if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
        ?>
    </p>

    <div class="mt-3">
        <h3 class="fw-bold text-success text-uppercase" style="color: #198754 !important;">LOW STOCK ALERT REPORT</h3>
        <h6 class="text-muted">Generated on: <?= date('d M Y H:i') ?></h6>
        <div class="mt-2" style="border-top: 2px solid #198754; width: 100px; margin: 0 auto;"></div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <h5 class="mb-0 fw-bold text-danger report-title">
            <i class="bi bi-exclamation-triangle me-2 no-print"></i> 
            <span class="report-text">Low Stock Alert Report</span>
        </h5>
        <div class="d-flex gap-2 no-print">
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>
    <div class="card-body bg-light border-bottom no-print">
        <p class="mb-0 small text-muted">
            <i class="bi bi-info-circle me-1"></i> This report shows products that have reached or fallen below their set reorder levels.
        </p>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light text-uppercase small fw-bold">
                <tr>
                    <th class="ps-4">Product Details</th>
                    <th class="text-center">Current Stock</th>
                    <th class="text-center">Reorder Level</th>
                    <th class="text-center">Status</th>
                    <th class="text-end pe-4 no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($results)): ?>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($row['product_name']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($row['product_code']) ?></div>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold <?= $row['current_stock'] <= 0 ? 'text-danger' : 'text-warning' ?>">
                                    <?= round($row['current_stock'], 2) ?>
                                </span>
                            </td>
                            <td class="text-center text-muted"><?= round($row['reorder_level'] ?: $row['min_stock_level'], 2) ?></td>
                            <td class="text-center">
                                <?php if($row['current_stock'] <= 0): ?>
                                    <span class="badge bg-danger text-white">Out of Stock</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Critical Level</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4 no-print">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light border dropdown-toggle px-2 py-1" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-gear"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                        <li>
                                            <a class="dropdown-item py-2" href="#" onclick="adjustStock(<?= $row['product_id'] ?>); return false;">
                                                <i class="bi bi-plus-circle me-2 text-success"></i> Add / Adjust Stock
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item py-2" href="<?= getUrl('purchase_order_create') ?>?product=<?= $row['product_id'] ?>">
                                                <i class="bi bi-cart-plus me-2 text-primary"></i> Create Purchase Order
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item py-2" href="<?= getUrl('product_edit') ?>?id=<?= $row['product_id'] ?>">
                                                <i class="bi bi-pencil me-2 text-warning"></i> Edit Product
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-success">
                            <i class="bi bi-check-circle display-4 d-block mb-3 opacity-25"></i>
                            Excellent! No low stock items detected.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div class="modal fade" id="stockAdjustmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning-subtle border-bottom-0">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="bi bi-box-arrow-in-down me-2"></i> Adjust Stock Level
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="stockAdjustmentForm">
                    <input type="hidden" id="adjust_product_id" name="product_id">
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-uppercase text-muted">Product</label>
                            <input type="text" class="form-control bg-light border-0 fw-bold" id="adjust_product_name" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-uppercase text-muted">Current Total</label>
                            <input type="text" class="form-control bg-light border-0 fw-bold text-primary" id="current_stock_display" readonly title="Total stock across all warehouses">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase text-muted">Warehouse</label>
                        <select class="form-select border-0 bg-light shadow-sm" id="warehouse_id" name="warehouse_id" required>
                            <option value="">Select Warehouse...</option>
                        </select>
                        <div id="warehouse_stock_info" class="small mt-1 text-muted" style="display:none">
                            Current in this warehouse: <span id="wh_current_qty" class="fw-bold">0</span>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Adjustment Type</label>
                            <select class="form-select border-0 bg-light shadow-sm" id="movement_type" name="movement_type" required>
                                <option value="adjustment_in">Add (+)</option>
                                <option value="adjustment_out">Remove (-)</option>
                                <option value="set">Set Exact (=)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Quantity</label>
                            <input type="number" class="form-control border-0 bg-light shadow-sm fw-bold" id="adjustment_quantity" 
                                   name="quantity" min="0.001" step="0.001" required placeholder="Enter amount">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase text-muted">Reason</label>
                        <select class="form-select border-0 bg-light" id="adjustment_reason" name="reason" required>
                            <option value="found" selected>Stock Found / New Arrival</option>
                            <option value="correction">Stock Correction</option>
                            <option value="damaged">Damaged Goods</option>
                            <option value="expired">Expired Products</option>
                            <option value="theft">Theft/Loss</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-primary border-0 shadow-sm" id="new_stock_info" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-bold">RESULTING STOCK:</span>
                            <span class="fs-5 fw-bold" id="new_stock_level">0</span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning px-4 fw-bold" onclick="submitStockAdjustment()">
                    <i class="bi bi-save me-1"></i> Update Stock
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function adjustStock(productId) {
    $.ajax({
        url: 'api/get_product_stock.php', // Assuming this exists or using a generic one
        type: 'GET',
        data: { product_id: productId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const product = response.data;
                $('#adjust_product_id').val(product.product_id);
                $('#adjust_product_name').val(product.product_name);
                $('#current_stock_display').val(product.total_stock + ' units');
                
                // Load warehouses
                loadWarehouses(productId);
                
                $('#stockAdjustmentModal').modal('show');
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        }
    });
}

function loadWarehouses(productId) {
    $.ajax({
        url: 'api/get_product_warehouses.php',
        type: 'GET',
        data: { product_id: productId },
        dataType: 'json',
        success: function(response) {
            const select = $('#warehouse_id');
            select.empty();
            select.append('<option value="">Select Warehouse...</option>');
            
            if (response.success && response.data.length > 0) {
                response.data.forEach(warehouse => {
                    select.append(`<option value="${warehouse.warehouse_id}" data-stock="${warehouse.stock_quantity}">
                        ${warehouse.warehouse_name} (Current: ${warehouse.stock_quantity})
                    </option>`);
                });
                
                // Auto-select the first warehouse to avoid "missing required field" error
                if (select.find('option').length > 1) {
                    select.prop('selectedIndex', 1).trigger('change');
                }
            }
        }
    });
}

$('#movement_type').on('change', function() {
    const type = $(this).val();
    const selected = $('#warehouse_id option:selected');
    const current = parseFloat(selected.data('stock')) || 0;
    
    // If 'set' is chosen, pre-fill with current stock for easy editing
    if (type === 'set') {
        $('#adjustment_quantity').val(current);
    } else {
        $('#adjustment_quantity').val('');
    }
    updateResultingStock();
});

$('#warehouse_id').on('change', function() {
    const selected = $(this).find('option:selected');
    const stock = selected.data('stock');
    if (selected.val()) {
        $('#wh_current_qty').text(stock);
        $('#warehouse_stock_info').fadeIn();
        
        // If 'set' is active, update quantity to this warehouse's stock
        if ($('#movement_type').val() === 'set') {
            $('#adjustment_quantity').val(stock);
        }
    } else {
        $('#warehouse_stock_info').fadeOut();
    }
    updateResultingStock();
});

function updateResultingStock() {
    const type = $('#movement_type').val();
    const quantity = parseFloat($('#adjustment_quantity').val()) || 0;
    const selected = $('#warehouse_id option:selected');
    const current = parseFloat(selected.data('stock')) || 0;
    
    let newStock = current;
    if (type === 'adjustment_in') newStock = current + quantity;
    else if (type === 'adjustment_out') newStock = current - quantity;
    else if (type === 'set') newStock = quantity;
    
    $('#new_stock_level').text(newStock.toFixed(2));
    if (quantity !== 0 && $('#warehouse_id').val()) {
        $('#new_stock_info').fadeIn();
    } else {
        if (type !== 'set') $('#new_stock_info').fadeOut();
    }
}

$('#adjustment_quantity').on('keyup change', function() {
    updateResultingStock();
});

function submitStockAdjustment() {
    const formData = $('#stockAdjustmentForm').serialize();
    
    if (!$('#warehouse_id').val() || !$('#adjustment_quantity').val()) {
        Swal.fire('Error', 'Please fill in all required fields.', 'warning');
        return;
    }
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';

    $.ajax({
        url: 'api/adjust_stock',
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#stockAdjustmentModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Stock Updated',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', response.message, 'error');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        },
        error: function() {
            Swal.fire('Server Error', 'Could not save stock adjustment.', 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    });
}
</script>

<style>
    @media print {
        body { background: white !important; }
        .container, .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
        .no-print, .d-print-none {
            display: none !important;
        }
        .card {
            border: none !important;
            box-shadow: none !important;
        }
        .table {
            width: 100% !important;
            border: 1px solid #dee2e6 !important;
        }
        .table th {
            background-color: #f8f9fa !important;
            color: #000 !important;
        }
        .badge { border: 1px solid #ddd !important; background: transparent !important; color: black !important; }
        .text-danger { color: #dc3545 !important; }
        .text-warning { color: #ffc107 !important; }
    }
</style>
<script>
$(document).ready(function() {
    logReportAction('Viewed Low Stock Alert', 'User viewed the low stock alert report');
});

// Update the submitStockAdjustment function to include logging
const originalSubmitStockAdjustment = submitStockAdjustment;
submitStockAdjustment = function() {
    const product = $('#adjust_product_name').val();
    const type = $('#movement_type option:selected').text();
    const qty = $('#adjustment_quantity').val();
    logReportAction('Adjusted Stock (Low Stock Report)', 'User adjusted stock for ' + product + ' (' + type + ' ' + qty + ')');
    originalSubmitStockAdjustment();
}
</script>
