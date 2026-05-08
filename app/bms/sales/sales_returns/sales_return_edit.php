<?php
// File: app/bms/sales/sales_returns/sales_return_edit.php
require_once __DIR__ . '/../../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('sales_returns');

includeHeader();

$return_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($return_id <= 0) {
    echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Invalid Return ID</div></div>';
    includeFooter();
    exit;
}

global $pdo;

// Fetch return details
$stmt = $pdo->prepare("
    SELECT 
        sr.sales_return_id as return_id,
        sr.return_number,
        sr.return_date,
        sr.reason,
        sr.status,
        sr.sales_order_id,
        so.order_number,
        c.customer_name,
        c.customer_id
    FROM sales_returns sr
    LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
    LEFT JOIN customers c ON sr.customer_id = c.customer_id
    WHERE sr.sales_return_id = ?
");
$stmt->execute([$return_id]);
$return = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$return) {
    echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Return not found</div></div>';
    includeFooter();
    exit;
}

if ($return['status'] !== 'pending') {
    echo '<div class="container-fluid mt-4"><div class="alert alert-warning">Only pending returns can be edited.</div></div>';
    includeFooter();
    exit;
}

// Fetch Original Order Items and merge with existing return quantities
$stmtItems = $pdo->prepare("
    SELECT 
        soi.product_id,
        COALESCE(p.product_name, soi.product_name, 'Unknown Product') as product_name,
        COALESCE(p.sku, soi.sku, 'N/A') as sku,
        soi.quantity as sold_quantity,
        soi.unit_price as original_price,
        COALESCE(sri.quantity, 0) as returned_quantity,
        COALESCE(sri.return_item_id, 0) as return_item_id
    FROM sales_order_items soi
    LEFT JOIN products p ON soi.product_id = p.product_id
    LEFT JOIN sales_return_items sri ON sri.product_id = soi.product_id AND sri.sales_return_id = ?
    WHERE soi.order_id = ?
");
$stmtItems->execute([$return_id, $return['sales_order_id']]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// If no items found, handle gracefully
if (empty($items)) {
    // This could happen if the order has no items or IDs are mismatched
}

?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Edit Sales Return #<?= safe_output($return['return_number']) ?></h1>
            <p class="text-muted mb-0">Original Order: <span class="fw-bold">#<?= safe_output($return['order_number']) ?></span> | Customer: <span class="fw-bold"><?= safe_output($return['customer_name']) ?></span></p>
        </div>
        <a href="<?= getUrl('sales_returns') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Returns
        </a>
    </div>

    <!-- Edit Form -->
    <form id="editReturnForm">
        <input type="hidden" name="return_id" value="<?= $return_id ?>">
        <input type="hidden" name="sales_order_id" value="<?= $return['sales_order_id'] ?>">
        <input type="hidden" name="customer_id" value="<?= $return['customer_id'] ?>">

        <div class="row">
            <!-- Left Column: Items -->
            <div class="col-lg-8">
                <div class="card shadow mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">Return Items</h6>
                        <span class="badge bg-primary">Editing #<?= safe_output($return['return_number']) ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width: 50px;">S/NO</th>
                                    <th style="width: 40%">Product</th>
                                    <th class="text-center" style="width: 15%">Sold Qty</th>
                                    <th class="text-center" style="width: 20%">Return Qty</th>
                                    <th class="text-end" style="width: 20%">Refund Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sn = 1; foreach ($items as $item): 
                                    $is_part_of_return = $item['return_item_id'] > 0;
                                ?>
                                <tr class="<?= $is_part_of_return ? 'table-light' : '' ?>">
                                    <td class="text-center fw-bold text-muted"><?= $sn++ ?></td>
                                    <td>
                                        <div class="fw-bold"><?= safe_output($item['product_name']) ?></div>
                                        <div class="small text-muted">
                                            SKU: <?= safe_output($item['sku']) ?> | 
                                            Price: <?= number_format($item['original_price'], 2) ?>
                                        </div>
                                        <?php if (!$is_part_of_return): ?>
                                            <span class="badge bg-warning text-dark x-small">Not in current return</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="fw-bold"><?= format_number($item['sold_quantity'], 2) ?></div>
                                        <small class="text-muted">Total Sold</small>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.01" min="0" max="<?= $item['sold_quantity'] ?>" 
                                                   class="form-control text-center return-qty fw-bold" 
                                                   name="items[<?= $item['product_id'] ?>]" 
                                                   data-price="<?= $item['original_price'] ?>"
                                                   data-product-id="<?= $item['product_id'] ?>"
                                                   value="<?= floatval($item['returned_quantity']) ?>">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setFullReturn(this, <?= $item['sold_quantity'] ?>)" title="Return All">
                                                <i class="bi bi-arrow-up-circle"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <span class="text-primary"><?= $return['currency'] ?? 'TZS' ?></span>
                                        <span class="item-total"><?= number_format($item['returned_quantity'] * $item['original_price'], 2) ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column: Summary -->
            <div class="col-lg-4">
                <div class="card shadow mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold text-danger">Return Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Return Date</label>
                            <input type="date" name="return_date" class="form-control" value="<?= $return['return_date'] ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Condition / Reason</label>
                            <textarea name="reason" class="form-control" rows="4" placeholder="Why is this being returned?"><?= safe_output($return['reason']) ?></textarea>
                        </div>

                        <hr>

                        <div class="d-flex justify-content-between mb-3">
                            <span class="h6">Total Refund:</span>
                            <span class="h5 fw-bold text-danger" id="totalRefund">0.00</span>
                        </div>
                        
                        <div class="alert alert-warning small mb-3">
                            <i class="bi bi-info-circle"></i> Tax and shipping adjustments are not automatically calculated.
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2">
                            <i class="bi bi-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    logReportAction('View Sales Return Edit Page', 'User opened the page to edit sales return #<?= $return['return_number'] ?>');
    
    // Initial Calculation
    calculateGrandTotal();

    // Calculate Totals on Input Change
    $('.return-qty').on('input', function() {
        let max = parseFloat($(this).attr('max'));
        let val = parseFloat($(this).val()) || 0;
        let price = parseFloat($(this).data('price'));

        if (val > max) {
            $(this).val(max);
            val = max;
            Swal.fire({
                toast: true,
                icon: 'warning',
                title: 'Cannot return more than sold quantity',
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000
            });
        }

        let total = val * price;
        $(this).closest('tr').find('.item-total').text(total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        
        calculateGrandTotal();
    });

    function setFullReturn(btn, qty) {
        $(btn).siblings('input').val(qty).trigger('input');
    }

    function calculateGrandTotal() {
        let grandTotal = 0;
        $('.return-qty').each(function() {
            let val = parseFloat($(this).val()) || 0;
            let price = parseFloat($(this).data('price'));
            grandTotal += (val * price);
        });
        $('#totalRefund').text(grandTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    }

    // Handle Form Submit
    $('#editReturnForm').on('submit', function(e) {
        e.preventDefault();
        
        // Validation: Ensure at least one item is being returned
        let hasItems = false;
        $('.return-qty').each(function() {
            if (parseFloat($(this).val()) > 0) hasItems = true;
        });

        if (!hasItems) {
            Swal.fire('Error', 'Please enter a return quantity for at least one item.', 'error');
            return;
        }

        Swal.fire({
            title: 'Update Sales Return?',
            text: 'This will modify the existing return record.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Update',
            confirmButtonColor: '#0d6efd'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading() });
                
                $.ajax({
                    url: '<?= buildUrl('api/sales/update_return.php') ?>',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Updated!',
                                text: 'Sales Return has been updated successfully.',
                                confirmButtonColor: '#198754',
                                confirmButtonText: 'OK',
                                timer: 3000,
                                showConfirmButton: true
                            }).then(() => {
                                window.location.href = 'sales_returns.php';
                            });
                        } else {
                            Swal.fire('Error', res.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Server error occurred', 'error');
                    }
                });
            }
        });
    });
});
</script>

<?php includeFooter(); ?>
