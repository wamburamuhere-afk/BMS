<?php
// File: payment_create.php
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('invoices');

includeHeader();

// Get Invoice ID
$invoice_id = isset($_GET['invoice']) ? intval($_GET['invoice']) : 0;

if ($invoice_id <= 0) {
    header("Location: " . getUrl('invoices') . "?error=Invalid Invoice ID");
    exit();
}

// Fetch Invoice Details
global $pdo;
$stmt = $pdo->prepare("
    SELECT 
        i.*,
        c.customer_name
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.customer_id
    WHERE i.invoice_id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header("Location: " . getUrl('invoices') . "?error=Invoice Not Found");
    exit();
}

// Generate Payment Reference
function generate_payment_ref() {
    return 'PAY-' . date('Ymd') . '-' . mt_rand(100, 999);
}

// Payment Methods
$payment_methods = ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'check' => 'Check', 'mobile_money' => 'Mobile Money', 'credit_card' => 'Credit Card'];

?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('invoices') ?>">Invoices</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('invoice_view') ?>?id=<?= $invoice_id ?>"><?= $invoice['invoice_number'] ?></a></li>
            <li class="breadcrumb-item active">Record Payment</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-cash-coin text-success"></i> Record Payment</h2>
                    <p class="text-muted mb-0">Add payment for Invoice #<?= safe_output($invoice['invoice_number']) ?></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('invoice_view') ?>?id=<?= $invoice_id ?>" class="btn btn-outline-secondary btn-sm shadow-sm">
                        <i class="bi bi-arrow-left"></i> Back to Invoice
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Payment Details Form -->
        <div class="col-md-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-credit-card"></i> Payment Details</h5>
                </div>
                <div class="card-body">
                    <form id="paymentForm">
                        <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
                        <input type="hidden" name="customer_id" value="<?= $invoice['customer_id'] ?>">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Payment Reference</label>
                                <input type="text" class="form-control" name="reference_number" value="<?= generate_payment_ref() ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Payment Date</label>
                                <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text"><?= $invoice['currency'] ?></span>
                                <input type="number" class="form-control form-control-lg fw-bold" name="amount" 
                                       value="<?= $invoice['balance_due'] ?>" max="<?= $invoice['balance_due'] ?>" step="0.01" required>
                            </div>
                            <div class="form-text text-danger">Maximum payable amount: <?= number_format($invoice['balance_due'], 2) ?></div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Payment Method</label>
                                <select class="form-select" name="payment_method" required>
                                    <option value="">Select Method</option>
                                    <?php foreach ($payment_methods as $val => $label): ?>
                                        <option value="<?= $val ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Payment Status</label>
                                <select class="form-select bg-light" name="status" required>
                                    <option value="completed" selected>Paid (Completed)</option>
                                    <option value="pending">Pending (Cheque/Clearing)</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Notes / Remarks</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Optional payment notes..."></textarea>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="<?= getUrl('invoice_view') ?>?id=<?= $invoice_id ?>" class="btn btn-light border">Cancel</a>
                            <button type="submit" class="btn btn-success shadow-sm px-4">
                                <i class="bi bi-check-circle me-1"></i> Save Payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Invoice Summary -->
        <div class="col-md-5">
            <div class="card shadow-sm border-0 bg-light">
                <div class="card-header bg-white">
                    <h6 class="mb-0 fw-bold">Invoice Summary</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Customer:</span>
                        <span class="fw-bold"><?= safe_output($invoice['customer_name']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Total Amount:</span>
                        <span><?= number_format($invoice['grand_total'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Already Paid:</span>
                        <span class="text-success"><?= number_format($invoice['paid_amount'], 2) ?></span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-0 fs-5 text-danger">
                        <span class="fw-bold">Balance Due:</span>
                        <span class="fw-bold"><?= number_format($invoice['balance_due'], 2) ?> <?= $invoice['currency'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#paymentForm').on('submit', function(e) {
        e.preventDefault();
        
        $.post('<?= buildUrl('api/account/record_payment.php') ?>', $(this).serialize(), function(res) {
            if (res.success) {
                Swal.fire({
                    title: 'Payment Recorded',
                    text: 'The payment has been successfully recorded.',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    window.location.href = '<?= getUrl('invoice_view') ?>?id=<?= $invoice_id ?>';
                });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }, 'json').fail(function() {
            Swal.fire('Error', 'Server connection failed', 'error');
        });
    });
});
</script>

<?php includeFooter(); ?>
