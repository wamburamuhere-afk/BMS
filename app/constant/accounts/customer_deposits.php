<?php
// app/constant/accounts/customer_deposits.php
// Customer Deposits / Advances (money.md IN-7) — record money a customer pays
// BEFORE an invoice exists (held as a liability, Client Deposits 2-1600), and apply
// that deposit to an outstanding invoice later.
//   Record → api/account/record_customer_advance.php  (Dr Bank / Cr Client Deposits)
//   Apply  → api/account/apply_customer_advance.php    (Dr Client Deposits / Cr AR)
// Standards: .claude/ui-constants.md (white/blue, Select2, SweetAlert2, CSRF),
// .claude/security.md (§23 project scope handled in the APIs).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';     // cashBankAccounts()
require_once __DIR__ . '/../../../core/project_scope.php';      // scopeFilterSqlNullable (§23)
require_once __DIR__ . '/../../../core/customer_advance.php';   // advance sub-ledger
includeHeader();
global $pdo;

autoEnforcePermission('invoices');
if (!canEdit('invoices')) { header('Location: ' . getUrl('unauthorized')); exit; }

$currency      = get_setting('currency', 'TZS');
$cash_accounts = cashBankAccounts($pdo);

// Advance receipts (the 'advance' marker rows) + available balance per receipt.
// §23 — non-admins only see advances tagged to their projects (+ untagged).
$payScope = scopeFilterSqlNullable('project', 'p');
$rows = $pdo->query("
    SELECT p.payment_id, p.payment_number, p.payment_date, p.reference_number,
           pa.allocated_amount AS gross,
           c.customer_id, COALESCE(c.customer_name,'Unknown') AS customer_name
      FROM payment_allocations pa
      JOIN payments  p ON p.payment_id  = pa.payment_id
      LEFT JOIN customers c ON c.customer_id = pa.target_id
     WHERE pa.target_type = 'advance' AND p.status = 'completed'
           $payScope
  ORDER BY p.payment_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$advances = [];
$total_available = 0.0; $total_gross = 0.0; $custWithBal = [];
foreach ($rows as $r) {
    $pid   = (int)$r['payment_id'];
    $gross = round((float)$r['gross'], 2);
    $avail = advancePaymentAvailable($pdo, $pid);
    $total_gross     += $gross;
    $total_available += $avail;
    if ($avail > 0.005) $custWithBal[(int)$r['customer_id']] = true;
    $advances[] = [
        'payment_id'     => $pid,
        'payment_number' => $r['payment_number'],
        'payment_date'   => $r['payment_date'],
        'reference'      => $r['reference_number'],
        'customer_id'    => (int)$r['customer_id'],
        'customer_name'  => $r['customer_name'],
        'gross'          => $gross,
        'applied'        => round($gross - $avail, 2),
        'available'      => $avail,
    ];
}
$fmtc = fn($n) => htmlspecialchars($currency) . ' ' . number_format((float)$n, 2);
?>

<div class="container-fluid py-4">
    <!-- Page header -->
    <div class="row mb-3 align-items-center" style="position:sticky;top:0;z-index:1020;background:#fff;padding:8px 0;">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-piggy-bank me-2"></i>Customer Deposits</h2>
            <p class="text-muted mb-0">Record advances paid before invoicing, and apply them to invoices later</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary" id="btnRecordAdvance"><i class="bi bi-plus-circle me-1"></i> Record Advance</button>
            <a href="<?= getUrl('receive_payment') ?>" class="btn btn-outline-primary"><i class="bi bi-cash-stack me-1"></i> Receive Payment</a>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary" id="stat-available"><?= $fmtc($total_available) ?></div>
                <div class="small text-muted">Deposits On Hand (unapplied)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-secondary"><?= $fmtc($total_gross) ?></div>
                <div class="small text-muted">Total Advances Received</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= count($custWithBal) ?></div>
                <div class="small text-muted">Customers With Deposits</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-secondary"><?= count($advances) ?></div>
                <div class="small text-muted">Advance Receipts</div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div id="tableView" class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-receipt me-2"></i>Advance Receipts</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="advTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Receipt #</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Applied</th>
                            <th class="text-end">Available</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($advances)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-5">No customer advances recorded yet. Click <strong>Record Advance</strong> to add one.</td></tr>
                        <?php else: foreach ($advances as $a): ?>
                            <tr>
                                <td class="ps-3 fw-semibold"><?= htmlspecialchars($a['payment_number']) ?></td>
                                <td><?= htmlspecialchars($a['customer_name']) ?></td>
                                <td><?= htmlspecialchars($a['payment_date']) ?></td>
                                <td class="text-end"><?= $fmtc($a['gross']) ?></td>
                                <td class="text-end text-muted"><?= $fmtc($a['applied']) ?></td>
                                <td class="text-end fw-bold <?= $a['available'] > 0.005 ? 'text-primary' : 'text-muted' ?>"><?= $fmtc($a['available']) ?></td>
                                <td class="text-end pe-3">
                                    <div class="dropdown d-flex justify-content-end">
                                        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                            <li>
                                                <button class="dropdown-item py-2 rounded <?= $a['available'] > 0.005 ? '' : 'disabled' ?>"
                                                        onclick="openApply(<?= $a['customer_id'] ?>, '<?= htmlspecialchars(addslashes($a['customer_name']), ENT_QUOTES) ?>', <?= $a['available'] ?>)">
                                                    <i class="bi bi-arrow-right-circle text-primary me-2"></i> Apply to Invoice
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Record Advance Modal -->
<div class="modal fade" id="recordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Record Customer Advance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="recordForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Customer <span class="text-danger">*</span></label>
                        <select id="r-customer" name="customer_id" class="form-select" style="width:100%" required></select>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Amount <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="r-amount" class="form-control fw-bold" step="0.01" min="0.01" required placeholder="0.00">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" id="r-date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Method</label>
                            <select name="payment_method" class="form-select select2-static">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="check">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="credit_card">Credit Card</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Received Into <span class="text-danger">*</span></label>
                            <select name="received_into_account_id" class="form-select select2-static" required>
                                <option value="">— Select cash/bank —</option>
                                <?php foreach ($cash_accounts as $a): ?>
                                    <option value="<?= (int)$a['account_id'] ?>"><?= htmlspecialchars((!empty($a['account_code']) ? $a['account_code'] . ' — ' : '') . $a['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Reference</label>
                            <input type="text" name="reference_number" class="form-control" placeholder="Cheque / txn ref">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted text-uppercase">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Optional">
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small text-muted">
                        <i class="bi bi-info-circle me-1"></i> Posts <strong>Dr Bank / Cr Client Deposits</strong>. The deposit is held as a liability until you apply it to an invoice.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Record Advance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Apply Advance Modal -->
<div class="modal fade" id="applyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-right-circle me-1"></i> Apply Deposit to Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="applyForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="customer_id" id="a-customer-id">
                    <div class="mb-2">
                        <span class="text-muted small">Customer:</span> <strong id="a-customer-name"></strong>
                        <span class="badge bg-primary ms-2">Available: <span id="a-available">—</span></span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Invoice <span class="text-danger">*</span></label>
                        <select id="a-invoice" name="invoice_id" class="form-select" style="width:100%" required></select>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Amount to Apply <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="a-amount" class="form-control fw-bold" step="0.01" min="0.01" required placeholder="0.00">
                            <div class="form-text" id="a-hint"></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Date</label>
                            <input type="date" name="apply_date" id="a-date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small text-muted">
                        <i class="bi bi-info-circle me-1"></i> Posts <strong>Dr Client Deposits / Cr Accounts Receivable</strong> and reduces the invoice balance. No new cash moves.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Apply Deposit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(function () {
    const CURRENCY  = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const CUST_URL  = '<?= buildUrl('api/account/search_customers.php') ?>';
    const OUT_URL   = '<?= buildUrl('api/account/get_outstanding.php') ?>';
    const REC_URL   = '<?= buildUrl('api/account/record_customer_advance.php') ?>';
    const APPLY_URL = '<?= buildUrl('api/account/apply_customer_advance.php') ?>';
    const fmt = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => $('<div>').text(s == null ? '' : s).html();
    let applyAvailable = 0;

    // ── Record Advance ────────────────────────────────────────────────────
    $('#btnRecordAdvance').on('click', () => new bootstrap.Modal(document.getElementById('recordModal')).show());

    $('#recordModal').on('shown.bs.modal', function () {
        if (!$('#r-customer').hasClass('select2-hidden-accessible')) {
            $('#r-customer').select2({
                theme: 'bootstrap-5', dropdownParent: $('#recordModal'),
                placeholder: 'Search a customer…', allowClear: true, width: '100%', minimumInputLength: 1,
                ajax: { url: CUST_URL, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d, cache: true }
            });
        }
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#recordModal'), width: '100%' });
            }
        });
    });

    $('#recordForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Recording…');
        $.ajax({
            url: REC_URL, type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('recordModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Advance recorded', text: res.message, timer: 1600, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not record the advance.' });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    // ── Apply Advance ─────────────────────────────────────────────────────
    window.openApply = function (customerId, customerName, available) {
        applyAvailable = parseFloat(available) || 0;
        if (applyAvailable <= 0) { Swal.fire({ icon: 'info', title: 'Nothing to apply', text: 'This advance has no available balance left.' }); return; }
        $('#a-customer-id').val(customerId);
        $('#a-customer-name').text(customerName);
        $('#a-available').text(fmt(applyAvailable));
        $('#a-amount').val('').attr('max', applyAvailable);
        $('#a-hint').text('Max ' + fmt(applyAvailable) + ' (or the invoice balance, whichever is lower).');

        // Reset + load the customer's outstanding invoices.
        if ($('#a-invoice').hasClass('select2-hidden-accessible')) $('#a-invoice').select2('destroy');
        $('#a-invoice').empty().append('<option value="">Loading invoices…</option>');
        new bootstrap.Modal(document.getElementById('applyModal')).show();

        $.getJSON(OUT_URL, { customer_id: customerId }).done(function (res) {
            $('#a-invoice').empty().append('<option value="">— Select an invoice —</option>');
            if (res && res.success && (res.invoices || []).length) {
                res.invoices.forEach(inv => {
                    $('#a-invoice').append(`<option value="${inv.invoice_id}" data-balance="${inv.balance}">${esc(inv.invoice_number)} — bal ${fmt(inv.balance)}</option>`);
                });
            } else {
                $('#a-invoice').append('<option value="" disabled>No outstanding invoices for this customer</option>');
            }
            $('#a-invoice').select2({ theme: 'bootstrap-5', dropdownParent: $('#applyModal'), placeholder: 'Select an invoice', width: '100%' });
        });
    };

    // Cap the amount at min(available, invoice balance).
    $('#a-invoice').on('change', function () {
        const bal = parseFloat($(this).find(':selected').data('balance')) || 0;
        const cap = Math.min(applyAvailable, bal || applyAvailable);
        $('#a-amount').attr('max', cap);
        $('#a-hint').text('Max ' + fmt(cap) + ' (lower of deposit available and invoice balance).');
    });
    $('#a-amount').on('input', function () {
        const cap = parseFloat($(this).attr('max')) || 0;
        if ((parseFloat($(this).val()) || 0) > cap) $(this).val(cap.toFixed(2));
    });

    $('#applyForm').on('submit', function (e) {
        e.preventDefault();
        if (!$('#a-invoice').val()) { Swal.fire({ icon: 'warning', title: 'Pick an invoice', text: 'Select an invoice to apply the deposit to.' }); return; }
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Applying…');
        $.ajax({
            url: APPLY_URL, type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('applyModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Deposit applied', text: res.message, timer: 1600, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not apply the deposit.' });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    if (typeof logReportAction === 'function') logReportAction('Opened Customer Deposits', 'Advance record/apply screen');
});
</script>

<?php includeFooter(); ob_end_flush(); ?>
