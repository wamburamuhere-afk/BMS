<?php
// File: app/bms/sales/sales_returns/sales_return_edit.php
// scope-audit: skip — sales_returns has no direct project_id; scope enforced via parent list (sales_returns.php filters by so.project_id)
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

// Fetch Original Order Items and merge with existing return quantities.
// VAT rate priority: existing return-item value (if this row was already in the return),
// otherwise fall back to the source SO item's tax_rate. BMS standard {0, 18}.
$stmtItems = $pdo->prepare("
    SELECT
        soi.product_id,
        COALESCE(p.product_name, soi.product_name, 'Unknown Product') as product_name,
        COALESCE(p.sku, soi.sku, 'N/A') as sku,
        soi.quantity as sold_quantity,
        soi.unit_price as original_price,
        COALESCE(soi.tax_rate, 0) as source_tax_rate,
        COALESCE(sri.tax_rate, soi.tax_rate, 0) as current_tax_rate,
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
                                    <th style="width: 40px;">S/NO</th>
                                    <th style="width: 32%">Product</th>
                                    <th class="text-center" style="width: 11%">Sold Qty</th>
                                    <th class="text-center" style="width: 14%">Return Qty</th>
                                    <th class="text-center" style="width: 13%">VAT</th>
                                    <th class="text-end" style="width: 18%">Refund Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sn = 1; foreach ($items as $item):
                                    $is_part_of_return = $item['return_item_id'] > 0;
                                    // BMS VAT standard {0,18}: snap any other rate down to 0%.
                                    $current_rate = floatval($item['current_tax_rate']);
                                    $default_rate = ($current_rate == 18) ? 18 : 0;
                                    $row_base     = floatval($item['returned_quantity']) * floatval($item['original_price']);
                                    $row_tax      = $row_base * ($default_rate / 100);
                                    $row_total    = $row_base + $row_tax;
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
                                    <td>
                                        <select class="form-select form-select-sm item-tax"
                                                name="tax_rates[<?= $item['product_id'] ?>]"
                                                data-product-id="<?= $item['product_id'] ?>">
                                            <option value="0"  <?= $default_rate === 0  ? 'selected' : '' ?>>No Tax (0%)</option>
                                            <option value="18" <?= $default_rate === 18 ? 'selected' : '' ?>>VAT 18%</option>
                                        </select>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <span class="text-primary"><?= $return['currency'] ?? 'TZS' ?></span>
                                        <span class="item-total"><?= number_format($row_total, 2) ?></span>
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

                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal Refund:</span>
                            <span class="fw-bold" id="subtotalRefund">0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>VAT (18%):</span>
                            <span class="fw-bold" id="vatTotal">0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3 border-top pt-2">
                            <span class="h6 mb-0">Grand Total Refund:</span>
                            <span class="h5 fw-bold text-danger mb-0" id="totalRefund">0.00</span>
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

    // Calculate per-row line total (incl. VAT) and grand totals.
    function recomputeRow($row) {
        const qty       = parseFloat($row.find('.return-qty').val()) || 0;
        const price     = parseFloat($row.find('.return-qty').data('price')) || 0;
        const rate      = parseFloat($row.find('.item-tax').val()) || 0;
        const lineBase  = qty * price;
        const lineTax   = lineBase * (rate / 100);
        const lineTotal = lineBase + lineTax;
        const fmt = lineTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        $row.find('.item-total').text(fmt);
        return { base: lineBase, tax: lineTax };
    }

    function calculateGrandTotal() {
        let subtotal = 0, vatTotal = 0;
        $('.return-qty').each(function() {
            const r = recomputeRow($(this).closest('tr'));
            subtotal += r.base;
            vatTotal += r.tax;
        });
        const grand = subtotal + vatTotal;
        const fmtOpts = {minimumFractionDigits: 2, maximumFractionDigits: 2};
        $('#subtotalRefund').text(subtotal.toLocaleString(undefined, fmtOpts));
        $('#vatTotal').text(vatTotal.toLocaleString(undefined, fmtOpts));
        $('#totalRefund').text(grand.toLocaleString(undefined, fmtOpts));
    }

    // Trigger on qty change with max guard.
    $('.return-qty').on('input', function() {
        let max = parseFloat($(this).attr('max'));
        let val = parseFloat($(this).val()) || 0;

        if (val > max) {
            $(this).val(max);
            Swal.fire({
                toast: true,
                icon: 'warning',
                title: 'Cannot return more than sold quantity',
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000
            });
        }
        calculateGrandTotal();
    });

    // Trigger on tax dropdown change.
    $('.item-tax').on('change', calculateGrandTotal);

    function setFullReturn(btn, qty) {
        $(btn).siblings('input').val(qty).trigger('input');
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
