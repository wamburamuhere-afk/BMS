<?php
// File: app/bms/sales/sales_returns/sales_return_create.php
// scope-audit: skip — create form; linked SO must be selected from scoped sales_orders list
require_once __DIR__ . '/../../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('sales_returns');

includeHeader();

global $pdo;

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$order_data = null;
$order_items = [];

if ($order_id) {
    // Fetch Order Details
    $stmt = $pdo->prepare("
        SELECT so.*, c.customer_name, c.company_name 
        FROM sales_orders so 
        JOIN customers c ON so.customer_id = c.customer_id 
        WHERE so.sales_order_id = ? AND so.status IN ('approved', 'completed')
    ");
    $stmt->execute([$order_id]);
    $order_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order_data) {
        $stmtItems = $pdo->prepare("
            SELECT soi.*, 
                   COALESCE(p.product_name, 'Unknown Product') as product_name, 
                   COALESCE(p.sku, 'N/A') as sku 
            FROM sales_order_items soi
            LEFT JOIN products p ON soi.product_id = p.product_id
            WHERE soi.order_id = ?
        ");
        $stmtItems->execute([$order_id]);
        $order_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: If no items found with order_id, check if column is sales_order_id
        if (empty($order_items)) {
             $stmtItems = $pdo->prepare("
                SELECT soi.*, 
                       COALESCE(p.product_name, 'Unknown Product') as product_name, 
                       COALESCE(p.sku, 'N/A') as sku 
                FROM sales_order_items soi
                LEFT JOIN products p ON soi.product_id = p.product_id
                WHERE soi.sales_order_id = ?
            "); // Try alternative column just in case
             try {
                $stmtItems->execute([$order_id]);
                $secondary_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($secondary_items)) $order_items = $secondary_items;
             } catch (Exception $e) { } // Ignore if column doesn't exist
        }
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Create Sales Return</h1>
            <p class="text-muted mb-0">Process a refund or return for an existing order</p>
        </div>
        <a href="<?= getUrl('sales_returns') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Returns
        </a>
    </div>

    <!-- Step 1: Select Order -->
    <div class="card shadow mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="m-0 font-weight-bold text-primary">1. Select Original Order</h6>
        </div>
        <div class="card-body">
            <form action="" method="GET" class="row align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Search Sales Order</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <select name="order_id" class="form-select select2-ajax" required>
                            <?php if ($order_data): ?>
                                <option value="<?= $order_data['sales_order_id'] ?>" selected>
                                    #<?= safe_output($order_data['order_number']) ?> - <?= safe_output($order_data['customer_name']) ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Load Items</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($order_data): ?>
    <!-- Step 2: Return Items -->
    <form id="returnForm">
        <input type="hidden" name="sales_order_id" value="<?= $order_data['sales_order_id'] ?>">
        <input type="hidden" name="customer_id" value="<?= $order_data['customer_id'] ?>">

        <div class="row">
            <!-- Left Column: Items -->
            <div class="col-lg-8">
                <div class="card shadow mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">2. Select Items to Return</h6>
                        <span class="badge bg-info">Order #<?= safe_output($order_data['order_number']) ?></span>
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
                                <?php $sn = 1; foreach ($order_items as $item):
                                    // BMS VAT standard {0,18}: snap any other rate down to 0%
                                    // when pre-selecting from the source SO item.
                                    $source_rate = isset($item['tax_rate']) ? floatval($item['tax_rate']) : 0;
                                    $default_rate = ($source_rate == 18) ? 18 : 0;
                                ?>
                                <tr>
                                    <td class="text-center fw-bold text-muted"><?= $sn++ ?></td>
                                    <td>
                                        <div class="fw-bold"><?= safe_output($item['product_name']) ?></div>
                                        <div class="small text-muted">
                                            SKU: <?= safe_output($item['sku']) ?> |
                                            Price: <?= number_format($item['unit_price'], 2) ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border"><?= $item['quantity'] ?></span>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" max="<?= $item['quantity'] ?>"
                                               class="form-control form-control-sm text-center return-qty"
                                               name="items[<?= $item['product_id'] ?>]"
                                               data-price="<?= $item['unit_price'] ?>"
                                               data-item-id="<?= $item['product_id'] ?>"
                                               value="0">
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
                                        <span class="item-total">0.00</span>
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
                            <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Condition / Reason</label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Why is this being returned?"></textarea>
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

                        <button type="submit" class="btn btn-danger w-100 py-2">
                            <i class="bi bi-check-circle"></i> Submit Return
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php elseif ($order_id): ?>
        <div class="alert alert-warning">
            Sales Order #<?= safe_output($_GET['order_id']) ?> not found or not in 'Approved/Completed' status.
        </div>
    <?php endif; ?>

</div>

<!-- Select2 Implementation for Order Search -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    logReportAction('View Sales Return Create Page', 'User opened the page to create a new sales return');
    // Initialize Select2 for Order Search
    $('.select2-ajax').select2({
        theme: 'bootstrap-5',
        placeholder: 'Search by Order Number or Customer',
        ajax: {
            url: '<?= buildUrl('api/sales/search_orders.php') ?>',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    q: params.term // search term
                };
            },
            processResults: function (data) {
                return {
                    results: data
                };
            },
            cache: true
        }
    });

    // Calculate per-row line total (incl. its own VAT) and grand totals.
    function recomputeRow($row) {
        const qty       = parseFloat($row.find('.return-qty').val()) || 0;
        const price     = parseFloat($row.find('.return-qty').data('price')) || 0;
        const rate      = parseFloat($row.find('.item-tax').val()) || 0;
        const lineBase  = qty * price;
        const lineTax   = lineBase * (rate / 100);
        const lineTotal = lineBase + lineTax;
        $row.find('.item-total').text(lineTotal.toFixed(2));
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
        $('#subtotalRefund').text(subtotal.toFixed(2));
        $('#vatTotal').text(vatTotal.toFixed(2));
        $('#totalRefund').text(grand.toFixed(2));
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

    // Trigger on tax dropdown change (no max guard needed).
    $('.item-tax').on('change', calculateGrandTotal);

    // Run once on page load so 0% items still display 0.00 cleanly.
    calculateGrandTotal();

    // Handle Form Submit
    $('#returnForm').on('submit', function(e) {
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
            title: 'Submit Return?',
            text: 'This will create a return record and may affect inventory.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Submit',
            confirmButtonColor: '#dc3545'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading() });
                
                $.ajax({
                    url: '<?= buildUrl('api/sales/create_return.php') ?>',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: 'Sales Return created successfully',
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
