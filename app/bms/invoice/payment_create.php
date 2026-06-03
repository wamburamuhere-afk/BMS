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
assertScopeForRecordHtml('invoices', 'invoice_id', $invoice_id);

// Fetch Invoice Details
global $pdo;
$stmt = $pdo->prepare("
    SELECT
        i.*,
        c.customer_name,
        c.default_wht_rate_id
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

// Active WHT rates — customer may withhold WHT from this payment (a receivable/credit).
$pay_wht_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage
                                FROM tax_rates WHERE tax_kind = 'wht' AND status = 'active'
                            ORDER BY rate_percentage")->fetchAll(PDO::FETCH_ASSOC);

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
                    <form id="paymentForm" enctype="multipart/form-data">
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
                                <input type="number" class="form-control form-control-lg fw-bold" name="amount" id="pay-amount"
                                       value="<?= $invoice['balance_due'] ?>" max="<?= $invoice['balance_due'] ?>" step="0.01" required>
                            </div>
                            <div class="form-text text-danger">Maximum payable amount: <?= number_format($invoice['balance_due'], 2) ?></div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Withholding Tax (WHT)</label>
                                <select class="form-select" name="wht_rate_id" id="pay-wht-rate">
                                    <option value="" data-rate="0">No withholding tax</option>
                                    <?php foreach ($pay_wht_rates as $w): $pct = rtrim(rtrim(number_format((float)$w['rate_percentage'], 2), '0'), '.'); ?>
                                    <option value="<?= (int)$w['rate_id'] ?>" data-rate="<?= htmlspecialchars($w['rate_percentage']) ?>"><?= safe_output($w['rate_name']) ?> (<?= $pct ?>%)</option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">If the customer withholds WHT, it's recorded as a receivable (tax credit). Computed on the VAT-exclusive amount.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Withheld (−)</label>
                                <input type="text" class="form-control" id="pay-wht-amount" readonly value="0.00">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Net Received</label>
                                <input type="text" class="form-control fw-bold text-success" id="pay-net" readonly>
                            </div>
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

                        <!-- Sprint 1: Payment Attachments (Mirrored from Purchase Module) -->
                        <div class="mb-3">
                            <label class="form-label fw-bold small mb-2">Payment Attachments <span class="text-muted small fw-normal">(Optional)</span></label>
                            <div id="attachments-container" class="border rounded p-3 bg-light">
                                <div id="attachment-fields">
                                    <div class="row g-2 attachment-row mb-2">
                                        <div class="col-md-5">
                                            <input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Document Name (e.g. Receipt, Check Scan)">
                                        </div>
                                        <div class="col-md-6">
                                            <div class="custom-file-input-wrapper">
                                                <label class="input-group input-group-sm mb-0 cursor-pointer">
                                                    <span class="input-group-text bg-light border-end-0">Choose File</span>
                                                    <div class="form-control form-control-sm file-display-name text-truncate small text-muted bg-white border-start-0">No file chosen</div>
                                                    <input type="file" class="d-none actual-file-input" name="attachments[]" onchange="handleFileSelect(this)">
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-1 text-end">
                                            <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeAttachmentRow(this)" title="Remove">
                                                <i class="bi bi-trash fs-5"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="addAttachmentRow()">
                                    <i class="bi bi-plus-circle me-1"></i> Add Attachment
                                </button>
                            </div>
                            <div class="form-text text-muted mt-2">Accepted: PDF, DOC, DOCX, JPG, PNG (max 10MB each).</div>
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

<style>
@media (max-width: 767px) {
    .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
}
</style>

<script>
// Sales-side WHT preview: customer withholds on the VAT-exclusive base, proportional
// to the amount being settled (mirrors api/account/record_payment.php).
const INV_SUBTOTAL    = <?= (float)($invoice['subtotal'] ?? 0) ?>;
const INV_GRAND       = <?= (float)($invoice['grand_total'] ?? 0) ?>;
const CUST_WHT_DEFAULT = <?= (int)($invoice['default_wht_rate_id'] ?? 0) ?>;
function recalcPayNet() {
    const amt  = parseFloat($('#pay-amount').val()) || 0;
    const rate = parseFloat($('#pay-wht-rate').find(':selected').data('rate')) || 0;
    const prop = INV_GRAND > 0 ? Math.min(1, amt / INV_GRAND) : 1;
    const base = (INV_SUBTOTAL > 0 ? INV_SUBTOTAL : amt) * prop;
    const wht  = +(base * rate / 100).toFixed(2);
    const f = n => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    $('#pay-wht-amount').val(f(wht));
    $('#pay-net').val(f(amt - wht));
}
$(document).ready(function() {
    // Auto-fill the customer's default WHT, then keep the net in sync.
    $('#pay-wht-rate').val(CUST_WHT_DEFAULT ? String(CUST_WHT_DEFAULT) : '');
    recalcPayNet();
    $('#pay-wht-rate').on('change', recalcPayNet);
    $('#pay-amount').on('input', recalcPayNet);

    // Sprint 2: Attachment Logic
    window.handleFileSelect = function(input) {
        if (input.files && input.files[0]) {
            const f = input.files[0];
            if (f.size > 10 * 1024 * 1024) {
                Swal.fire({icon:'warning',title:'File Too Large',text:'Maximum file size is 10MB.',confirmButtonColor:'#198754'});
                input.value = '';
                return;
            }
            const fileName = f.name;
            $(input).closest('.attachment-row').find('.file-display-name').html('<i class="bi bi-file-earmark-plus text-success me-1"></i> ' + fileName);
        }
    }

    window.addAttachmentRow = function() {
        const rowId = 'attach_' + Date.now();
        const html = `
            <div class="attachment-row mb-2" id="${rowId}">
                <div class="row g-2 align-items-center">
                    <div class="col-md-5">
                        <input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Document Name (e.g. Receipt, Check Scan)">
                    </div>
                    <div class="col-md-6">
                        <div class="custom-file-input-wrapper">
                            <label class="input-group input-group-sm mb-0 cursor-pointer">
                                <span class="input-group-text bg-light border-end-0">Choose File</span>
                                <div class="form-control form-control-sm file-display-name text-truncate small text-muted bg-white border-start-0">No file chosen</div>
                                <input type="file" class="d-none actual-file-input" name="attachments[]" onchange="handleFileSelect(this)">
                            </label>
                        </div>
                    </div>
                    <div class="col-md-1 text-end">
                        <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="$('#${rowId}').remove()" title="Remove">
                            <i class="bi bi-trash fs-5"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        $('#attachment-fields').append(html);
    }

    window.removeAttachmentRow = function(btn) {
        $(btn).closest('.attachment-row').remove();
    }

    $('#paymentForm').on('submit', function(e) {
        e.preventDefault();
        
        const $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

        // Use FormData to support file uploads
        const formData = new FormData(this);
        
        $.ajax({
            url: '<?= buildUrl('api/account/record_payment.php') ?>',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
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
            },
            error: function() {
                Swal.fire('Error', 'Server connection failed', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Payment');
            }
        });
    });
});
</script>

<?php includeFooter(); ?>
