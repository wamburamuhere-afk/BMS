<?php
// app/constant/accounts/receive_payment.php
// Receive Payment — record ONE customer receipt and apply it across several
// outstanding invoices (payment allocation). Posts a Bank-Statement deposit and
// updates each invoice's balance/status via api/account/save_receipt.php.
// Standards: .claude/ui-constants.md (white/blue, Select2, DataTable, SweetAlert2,
// CSRF), .claude/security.md (§23 project scope).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';   // cashBankAccounts()
includeHeader();
global $pdo;

autoEnforcePermission('invoices');
if (!canEdit('invoices')) { header('Location: ' . getUrl('unauthorized')); exit; }

$currency      = get_setting('currency', 'TZS');
$cash_accounts = cashBankAccounts($pdo);

$max_payment_id   = (int)$pdo->query("SELECT MAX(payment_id) FROM payments")->fetchColumn();
$preview_receipt  = 'RCP-' . date('Ymd') . '-' . str_pad((string)($max_payment_id + 1), 4, '0', STR_PAD_LEFT);
?>

<div class="container-fluid py-4">
    <!-- Page header -->
    <div class="row mb-3 align-items-center" style="position:sticky;top:0;z-index:1020;background:#fff;padding:8px 0;">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-cash-stack me-2"></i>Receive Payment</h2>
            <p class="text-muted mb-0">Record one receipt and apply it across a customer's outstanding invoices</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="<?= getUrl('customer_deposits') ?>" class="btn btn-outline-primary"><i class="bi bi-piggy-bank me-1"></i> Customer Deposits</a>
            <a href="<?= getUrl('invoices') ?>" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i> Back to Invoices</a>
        </div>
    </div>

    <!-- Section 1: Customer Lookup -->
    <div class="card border shadow-sm mb-3" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="receiptForm" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                <!-- Customer + Date + Action buttons -->
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Customer <span class="text-danger">*</span></label>
                        <select id="f-customer" name="customer_id" class="form-select" style="width:100%" required></select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" id="f-date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-6 col-md-3 d-flex gap-2">
                        <button type="button" class="btn btn-primary flex-fill" id="btnFilter" onclick="loadOutstanding()">
                            <i class="bi bi-funnel-fill me-1"></i> Filter
                        </button>
                        <button type="button" class="btn btn-secondary flex-fill" id="btnCancel" onclick="clearReceipt()">
                            <i class="bi bi-x-lg me-1"></i> Clear
                        </button>
                    </div>
                </div>

                <!-- Customer Balance Summary (appears after customer is loaded) -->
                <div id="customerSummary" class="row g-2 mt-3 d-none">
                    <div class="col-4 col-md-3">
                        <div class="rounded p-2 text-center" style="background:#f0f4ff;">
                            <div class="small text-muted text-uppercase" style="font-size:.67rem;letter-spacing:.3px;">Total Outstanding</div>
                            <div class="fw-bold text-primary" id="sum-total-outstanding">—</div>
                        </div>
                    </div>
                    <div class="col-4 col-md-3">
                        <div class="rounded p-2 text-center" style="background:#fff3f3;">
                            <div class="small text-muted text-uppercase" style="font-size:.67rem;letter-spacing:.3px;">Overdue</div>
                            <div class="fw-bold text-danger" id="sum-overdue">—</div>
                        </div>
                    </div>
                    <div class="col-4 col-md-3">
                        <div class="rounded p-2 text-center" style="background:#f5f5f5;">
                            <div class="small text-muted text-uppercase" style="font-size:.67rem;letter-spacing:.3px;">Last Payment</div>
                            <div class="fw-bold text-secondary" id="sum-last-payment">—</div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Payment Details -->
                <div class="row g-3 mt-1">
                    <div class="col-12"><hr class="mb-2 mt-1"><small class="text-muted text-uppercase fw-bold" style="font-size:.7rem;letter-spacing:.4px;"><i class="bi bi-credit-card me-1"></i>Payment Details</small></div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Method</label>
                        <select name="payment_method" id="f-method" class="form-select select2-static">
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="check">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="credit_card">Credit Card</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Received Into</label>
                        <select name="received_into_account_id" id="f-bank" class="form-select select2-static">
                            <option value="">— Select cash/bank account —</option>
                            <?php foreach ($cash_accounts as $a): ?>
                                <option value="<?= (int)$a['account_id'] ?>"><?= htmlspecialchars((!empty($a['account_code']) ? $a['account_code'] . ' — ' : '') . $a['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted">Writes a deposit to the Bank Statement.</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Amount Received <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="f-amount" class="form-control fw-bold" step="0.01" min="0" required placeholder="0.00">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Reference</label>
                        <input type="text" name="reference_number" class="form-control" placeholder="Cheque / txn ref">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Receipt No.</label>
                        <div class="form-control bg-light text-muted d-flex align-items-center gap-1" id="preview-receipt-no" style="cursor:default;font-size:.8rem;letter-spacing:.4px;font-family:monospace;">
                            <?= htmlspecialchars($preview_receipt) ?>
                        </div>
                        <div class="form-text">Preview — assigned on save</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional">
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Overpayment warning -->
    <div id="overpaymentWarn" class="alert alert-warning d-none py-2 mb-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>Overpayment:</strong> the amount entered exceeds this customer's total outstanding balance.
    </div>

    <!-- Section 3: Allocation grid -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-receipt me-2"></i>Outstanding Invoices</h6>
            <div class="d-flex gap-3 small">
                <span>Allocated: <strong id="lbl-allocated"><?= htmlspecialchars($currency) ?> 0.00</strong></span>
                <span>Unapplied: <strong id="lbl-unapplied" class="text-danger"><?= htmlspecialchars($currency) ?> 0.00</strong></span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="allocTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Due</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th class="text-end pe-3" style="width:160px;">Allocate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="8" class="text-center text-muted py-5">Select a customer to load their outstanding invoices.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAutoApply"><i class="bi bi-magic me-1"></i> Auto-apply (oldest first)</button>
            <button type="button" class="btn btn-primary px-4" id="btnSaveReceipt"><i class="bi bi-check-circle me-1"></i> Save Receipt</button>
        </div>
    </div>
</div>

<style>
    #allocTable thead th { font-size:.72rem; text-transform:uppercase; color:#6c757d; letter-spacing:.3px; }
    .alloc-input { max-width:150px; }
    tr.row-overdue td { background:#fff8f2 !important; }
    tr.row-overdue .due-cell { color:#dc3545; font-weight:600; }
</style>

<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const OUT_URL   = '<?= buildUrl('api/account/get_outstanding.php') ?>';
    const SAVE_URL  = '<?= buildUrl('api/account/save_receipt.php') ?>';
    const CUST_URL  = '<?= buildUrl('api/account/search_customers.php') ?>';
    const PRINT_URL = '<?= buildUrl('api/account/print_receipt.php') ?>';
    const TODAY     = new Date().toISOString().split('T')[0];
    const fmt = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => $('<div>').text(s == null ? '' : s).html();
    let invoices = [];
    let totalOutstanding = 0;

    $('#f-customer').select2({
        theme: 'bootstrap-5', placeholder: 'Search a customer…', allowClear: true, width: '100%',
        ajax: { url: CUST_URL, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d, cache: true }
    });
    $('.select2-static').each(function () { $(this).select2({ theme: 'bootstrap-5', width: '100%' }); });

    // Auto-load when customer changes
    $('#f-customer').on('change', loadOutstanding);

    window.clearReceipt = function () {
        $('#receiptForm')[0].reset();
        $('#f-customer').val(null).trigger('change');
        $('#f-date').val('<?= date('Y-m-d') ?>');
        invoices = []; totalOutstanding = 0;
        recompute();
        $('#customerSummary').addClass('d-none');
        $('#overpaymentWarn').addClass('d-none');
        $('#allocTable tbody').html('<tr><td colspan="8" class="text-center text-muted py-5">Select a customer to load their outstanding invoices.</td></tr>');
    };

    window.loadOutstanding = function () {
        const cid = $('#f-customer').val();
        const $tb = $('#allocTable tbody');
        if (!cid) {
            $tb.html('<tr><td colspan="8" class="text-center text-muted py-5">Select a customer.</td></tr>');
            invoices = []; totalOutstanding = 0;
            recompute();
            $('#customerSummary').addClass('d-none');
            return;
        }
        $tb.html('<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>');
        $.getJSON(OUT_URL, { customer_id: cid })
            .done(function (res) {
                if (!res || !res.success) {
                    $tb.html('<tr><td colspan="8" class="text-center text-danger py-4">Could not load invoices.</td></tr>');
                    return;
                }
                invoices = res.invoices || [];
                totalOutstanding = parseFloat(res.total_outstanding) || 0;

                // Customer summary
                $('#sum-total-outstanding').text(fmt(res.total_outstanding));
                $('#sum-overdue').text(fmt(res.overdue_total || 0));
                const lpd = res.last_payment_date ? new Date(res.last_payment_date).toLocaleDateString() : '—';
                $('#sum-last-payment').text(lpd);
                $('#customerSummary').removeClass('d-none');

                if (!invoices.length) {
                    $tb.html('<tr><td colspan="8" class="text-center text-muted py-5">This customer has no outstanding invoices.</td></tr>');
                    recompute(); return;
                }

                let html = '';
                invoices.forEach((inv, i) => {
                    const overdue = inv.due_date && inv.due_date < TODAY;
                    const rowClass = overdue ? 'row-overdue' : '';
                    const dueHtml = inv.due_date
                        ? `<span class="${overdue ? 'due-cell' : ''}">${new Date(inv.due_date).toLocaleDateString()}${overdue ? ' <i class="bi bi-clock"></i>' : ''}</span>`
                        : '—';
                    html += `<tr data-id="${inv.invoice_id}" data-balance="${inv.balance}" class="${rowClass}">
                        <td class="ps-3">${i + 1}</td>
                        <td class="fw-semibold">${esc(inv.invoice_number)}</td>
                        <td>${inv.invoice_date ? new Date(inv.invoice_date).toLocaleDateString() : ''}</td>
                        <td>${dueHtml}</td>
                        <td class="text-end">${fmt(inv.grand_total)}</td>
                        <td class="text-end">${fmt(inv.paid_amount)}</td>
                        <td class="text-end fw-semibold">${fmt(inv.balance)}</td>
                        <td class="text-end pe-3"><input type="number" class="form-control form-control-sm text-end alloc-input ms-auto" step="0.01" min="0" max="${inv.balance}" placeholder="0.00"></td>
                    </tr>`;
                });
                $tb.html(html);
                $('.alloc-input').on('input', onAllocInput);
                recompute();
            })
            .fail(() => $tb.html('<tr><td colspan="8" class="text-center text-danger py-4">Server error.</td></tr>'));
    };

    function onAllocInput() {
        const $row = $(this).closest('tr');
        const bal = parseFloat($row.data('balance')) || 0;
        let v = parseFloat($(this).val()) || 0;
        if (v > bal) { v = bal; $(this).val(bal.toFixed(2)); }
        recompute();
    }

    function recompute() {
        let allocated = 0;
        $('.alloc-input').each(function () { allocated += parseFloat($(this).val()) || 0; });
        const amount = parseFloat($('#f-amount').val()) || 0;
        const unapplied = Math.round((amount - allocated) * 100) / 100;
        $('#lbl-allocated').text(fmt(allocated));
        $('#lbl-unapplied').text(fmt(unapplied))
            .toggleClass('text-danger', Math.abs(unapplied) > 0.001)
            .toggleClass('text-success', Math.abs(unapplied) <= 0.001);
        // Overpayment: amount entered exceeds total outstanding
        const showWarn = totalOutstanding > 0 && amount > totalOutstanding + 0.01;
        $('#overpaymentWarn').toggleClass('d-none', !showWarn);
    }

    $('#f-amount').on('input', recompute);

    // Auto-apply oldest-first
    $('#btnAutoApply').on('click', function () {
        let remaining = parseFloat($('#f-amount').val()) || 0;
        if (remaining <= 0) { Swal.fire({ icon: 'info', title: 'Enter an amount', text: 'Type the amount received first.' }); return; }
        $('#allocTable tbody tr').each(function () {
            const bal = parseFloat($(this).data('balance')) || 0;
            const give = Math.min(bal, remaining);
            $(this).find('.alloc-input').val(give > 0 ? give.toFixed(2) : '');
            remaining = Math.round((remaining - give) * 100) / 100;
        });
        recompute();
    });

    $('#btnSaveReceipt').on('click', function () {
        const cid = $('#f-customer').val();
        const amount = parseFloat($('#f-amount').val()) || 0;
        if (!cid) { Swal.fire({ icon: 'error', title: 'Error', text: 'Select a customer.' }); return; }
        if (amount <= 0) { Swal.fire({ icon: 'error', title: 'Error', text: 'Enter the amount received.' }); return; }

        const allocations = [];
        let allocated = 0;
        $('#allocTable tbody tr').each(function () {
            const id = $(this).data('id');
            const v = parseFloat($(this).find('.alloc-input').val()) || 0;
            if (id && v > 0) { allocations.push({ invoice_id: id, amount: v }); allocated += v; }
        });
        if (!allocations.length) { Swal.fire({ icon: 'error', title: 'Error', text: 'Allocate the receipt to at least one invoice.' }); return; }
        if (Math.abs(allocated - amount) > 0.01) {
            Swal.fire({ icon: 'error', title: 'Amounts do not match', text: 'The allocated total (' + fmt(allocated) + ') must equal the amount received (' + fmt(amount) + ').' });
            return;
        }

        const form = document.getElementById('receiptForm');
        const fd = new FormData(form);
        fd.append('amount', amount);
        allocations.forEach((a, i) => {
            fd.append(`allocations[${i}][invoice_id]`, a.invoice_id);
            fd.append(`allocations[${i}][amount]`, a.amount);
        });

        const btn = $(this); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({
            url: SAVE_URL, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Receipt Saved!',
                        html: `Receipt number: <strong>${esc(res.payment_number)}</strong><br><small class="text-muted">${esc(res.message)}</small>`,
                        confirmButtonText: '<i class="bi bi-check-lg"></i> Done',
                        showDenyButton: true,
                        denyButtonText: '<i class="bi bi-printer"></i> Print',
                        denyButtonColor: '#0d6efd',
                        showCancelButton: true,
                        cancelButtonText: '<i class="bi bi-arrow-repeat"></i> New Receipt',
                        reverseButtons: true
                    }).then(r => {
                        if (r.isDenied) {
                            window.open(PRINT_URL + '?payment_id=' + res.payment_id, '_blank');
                            location.reload();
                        } else if (!r.isConfirmed && r.dismiss === Swal.DismissReason.cancel) {
                            clearReceipt();
                        } else {
                            location.reload();
                        }
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not save the receipt.' });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    if (typeof logReportAction === 'function') logReportAction('Opened Receive Payment', 'Payment allocation screen');
});
</script>

<?php includeFooter(); ob_end_flush(); ?>
