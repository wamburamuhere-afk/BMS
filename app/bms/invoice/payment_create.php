<?php
// File: payment_create.php
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';

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

if (!in_array($invoice['status'], ['approved', 'overdue', 'partial'], true)) {
    header("Location: " . getUrl('invoice_view') . "?id={$invoice_id}&error=Payment can only be recorded on open invoices");
    exit();
}

// Generate Payment Reference
function generate_payment_ref() {
    return 'PAY-' . date('Ymd') . '-' . mt_rand(100, 999);
}

// Payment Methods
$payment_methods = ['cash' => 'Cash', 'bank_transfer' => 'Bank Account', 'check' => 'Check', 'mobile_money' => 'Mobile Money', 'credit_card' => 'Credit Card'];

// Bank/Cash accounts for "Received Into" dropdown (same set as Paid From on expenses)
$bank_accounts = cashBankAccounts($pdo);

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
                                       value="<?= $invoice['balance_due'] ?>" step="0.01" required>
                                <button type="button" class="btn btn-outline-success" id="btn-full-balance" title="Fill full balance due">
                                    <i class="bi bi-check-all"></i> Full
                                </button>
                            </div>
                            <div class="form-text text-muted">Balance due: <strong class="text-danger"><?= number_format($invoice['balance_due'], 2) ?></strong></div>
                            <div id="settlement-preview" class="mt-2 p-2 rounded bg-light border d-none" style="font-size:0.8rem;">
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Remaining after payment:</span>
                                    <span id="preview-remaining" class="fw-bold text-danger font-monospace">—</span>
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <span class="text-muted">This payment covers:</span>
                                    <span id="preview-pct" class="fw-bold text-success">—</span>
                                </div>
                            </div>
                            <div id="overpayment-warn" class="alert alert-warning d-none mt-2 py-2 px-3 mb-0" style="font-size:0.8rem;">
                                <i class="bi bi-exclamation-triangle me-1"></i> Amount exceeds the balance due — overpayment will be recorded as credit.
                            </div>
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
                                <select class="form-select" name="payment_method" id="pay-method" required>
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
                            <label class="form-label fw-bold small">Received Into Account <span class="text-danger">*</span></label>
                            <select class="form-select" name="received_into_account_id" id="pay-received-account" required>
                                <option value="">— Select cash / bank account —</option>
                                <?php foreach ($bank_accounts as $ba): ?>
                                    <option value="<?= (int)$ba['account_id'] ?>"><?= htmlspecialchars((!empty($ba['account_code']) ? $ba['account_code'] . ' — ' : '') . $ba['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted">The cash or bank account this payment is deposited into — this is where the money will appear.</div>
                        </div>

                        <!-- Method-specific extra fields -->
                        <div id="method-extra-fields" class="mb-3 d-none">
                            <div id="mef-check" class="d-none">
                                <label class="form-label fw-bold small">Cheque Number</label>
                                <input type="text" class="form-control form-control-sm" id="mef-check-num" placeholder="e.g. 001234">
                            </div>
                            <div id="mef-mobile" class="d-none">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Transaction ID</label>
                                        <input type="text" class="form-control form-control-sm" id="mef-mobile-txn" placeholder="e.g. SAN7HKQX2">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Phone Number</label>
                                        <input type="text" class="form-control form-control-sm" id="mef-mobile-phone" placeholder="e.g. +255712345678">
                                    </div>
                                </div>
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
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-receipt me-1"></i>Invoice Summary</h6>
                </div>
                <div class="card-body">
                    <?php
                    $pc_gt  = floatval($invoice['grand_total']);
                    $pc_pa  = floatval($invoice['paid_amount']);
                    $pc_bd  = floatval($invoice['balance_due']);
                    $pc_pct = $pc_gt > 0 ? min(100, round($pc_pa / $pc_gt * 100)) : 0;
                    if ($pc_pct >= 100)  { $pc_chip = '<span class="badge bg-success">Fully Paid</span>';        $pc_bar = 'bg-success'; }
                    elseif ($pc_pct > 0) { $pc_chip = '<span class="badge bg-warning text-dark">Partial</span>'; $pc_bar = 'bg-warning'; }
                    else                 { $pc_chip = '<span class="badge bg-danger">Unpaid</span>';              $pc_bar = 'bg-danger';  }
                    $pc_due_ts    = !empty($invoice['due_date']) ? strtotime($invoice['due_date']) : 0;
                    $pc_today_ts  = strtotime(date('Y-m-d'));
                    $pc_overdue   = $pc_due_ts && $pc_due_ts < $pc_today_ts && $pc_bd > 0 && !in_array($invoice['status'], ['paid','cancelled']);
                    $pc_over_days = $pc_overdue ? (int)(($pc_today_ts - $pc_due_ts) / 86400) : 0;
                    ?>
                    <!-- Progress bar -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-muted fw-bold text-uppercase" style="font-size:0.65rem;">Payment Progress</small>
                            <?= $pc_chip ?>
                        </div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar <?= $pc_bar ?>" style="width:<?= $pc_pct ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-1" style="font-size:0.7rem;">
                            <span class="text-success font-monospace"><?= number_format($pc_pa, 2) ?> paid</span>
                            <span class="text-danger font-monospace"><?= number_format($pc_bd, 2) ?> remaining</span>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Invoice #:</span>
                        <span class="fw-bold small"><?= safe_output($invoice['invoice_number']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Customer:</span>
                        <span class="fw-bold small text-end" style="max-width:60%;"><?= safe_output($invoice['customer_name']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Invoice Date:</span>
                        <span class="small"><?= !empty($invoice['invoice_date']) ? date('M d, Y', strtotime($invoice['invoice_date'])) : 'N/A' ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Due Date:</span>
                        <span class="small">
                            <?= $pc_due_ts ? date('M d, Y', $pc_due_ts) : 'N/A' ?>
                            <?php if ($pc_overdue): ?>
                            <span class="badge bg-danger ms-1" style="font-size:0.58rem;">Overdue <?= $pc_over_days ?>d</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Grand Total:</span>
                        <span class="small fw-bold font-monospace"><?= number_format($pc_gt, 2) ?> <?= safe_output($invoice['currency']) ?></span>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between">
                        <span class="fw-bold text-danger">Balance Due:</span>
                        <span class="fw-bold text-danger font-monospace"><?= number_format($pc_bd, 2) ?> <?= safe_output($invoice['currency']) ?></span>
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
const INV_BALANCE     = <?= (float)$invoice['balance_due'] ?>;
const CUST_WHT_DEFAULT = <?= (int)($invoice['default_wht_rate_id'] ?? 0) ?>;
const f = n => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function recalcPayNet() {
    const amt  = parseFloat($('#pay-amount').val()) || 0;
    const rate = parseFloat($('#pay-wht-rate').find(':selected').data('rate')) || 0;
    const prop = INV_GRAND > 0 ? Math.min(1, amt / INV_GRAND) : 1;
    const base = (INV_SUBTOTAL > 0 ? INV_SUBTOTAL : amt) * prop;
    const wht  = +(base * rate / 100).toFixed(2);
    $('#pay-wht-amount').val(f(wht));
    $('#pay-net').val(f(amt - wht));
    updateAmountPreview(amt);
}

function updateAmountPreview(amt) {
    const remaining = INV_BALANCE - amt;
    const pct = INV_BALANCE > 0 ? Math.min(100, Math.round(amt / INV_BALANCE * 100)) : 0;
    if (amt > 0) {
        $('#preview-remaining').text(remaining > 0 ? f(remaining) : '0.00');
        $('#preview-pct').text(pct + '%');
        $('#settlement-preview').removeClass('d-none');
    } else {
        $('#settlement-preview').addClass('d-none');
    }
    if (amt > INV_BALANCE + 0.001) {
        $('#overpayment-warn').removeClass('d-none');
    } else {
        $('#overpayment-warn').addClass('d-none');
    }
}

function fillFullBalance() {
    $('#pay-amount').val(INV_BALANCE.toFixed(2)).trigger('input');
}
$(document).ready(function() {
    // Auto-fill the customer's default WHT, then keep the net in sync.
    $('#pay-wht-rate').val(CUST_WHT_DEFAULT ? String(CUST_WHT_DEFAULT) : '');
    recalcPayNet();
    $('#pay-wht-rate').on('change', recalcPayNet);
    $('#pay-amount').on('input', recalcPayNet);

    // Full-balance quick-fill
    $('#btn-full-balance').on('click', fillFullBalance);

    // Method-specific extra fields (cheque # and mobile details only)
    $('#pay-method').on('change', function() {
        const method = $(this).val();
        $('#mef-check, #mef-mobile').addClass('d-none');
        if (method === 'check') {
            $('#method-extra-fields').removeClass('d-none');
            $('#mef-check').removeClass('d-none');
        } else if (method === 'mobile_money') {
            $('#method-extra-fields').removeClass('d-none');
            $('#mef-mobile').removeClass('d-none');
        } else {
            $('#method-extra-fields').addClass('d-none');
        }
    });

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

        // Append method-specific details to notes and reference_number
        const method = $('#pay-method').val();
        const $notes = $('textarea[name="notes"]');
        const $ref   = $('input[name="reference_number"]');
        let extraNote = '';
        if (method === 'check') {
            const chq = $('#mef-check-num').val().trim();
            if (chq) { extraNote = 'Cheque No: ' + chq + '.'; if (!$ref.val().trim()) $ref.val('CHQ-' + chq); }
        } else if (method === 'mobile_money') {
            const txn   = $('#mef-mobile-txn').val().trim();
            const phone = $('#mef-mobile-phone').val().trim();
            if (txn)   { extraNote += 'TxnID: ' + txn + '. '; if (!$ref.val().trim()) $ref.val(txn); }
            if (phone) extraNote += 'Phone: ' + phone + '.';
        }
        if (extraNote) {
            const current = $notes.val().trim();
            $notes.val(current ? current + ' | ' + extraNote.trim() : extraNote.trim());
        }

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
                        showConfirmButton: true
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
