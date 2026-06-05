<?php
// app/constant/reports/customer_statement.php
// Customer Statement of Account — document-style, AJAX-driven
// (get_customer_statement.php). Opening balance + dated charges/payments +
// running balance + closing balance, printable with the standard letterhead.
// Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md (§23 scope).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

autoEnforcePermission('financial_reports');

$currency  = get_setting('currency', 'TZS');
$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

// Preselect a customer if one was passed in (e.g. from the AR Aging "Statement" link).
// scope-audit: skip — display-only name lookup for the picker label; the statement's
// financial rows are project-scoped inside get_customer_statement.php (§23).
$preCustId = (isset($_GET['customer_id']) && $_GET['customer_id'] !== '') ? (int)$_GET['customer_id'] : 0;
$preCustName = '';
if ($preCustId > 0) {
    $st = $pdo->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
    $st->execute([$preCustId]);
    $preCustName = (string)$st->fetchColumn();
}
?>

<div class="container-fluid py-4">
    <!-- Screen header + filters -->
    <div class="row mb-3 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-file-earmark-text me-2"></i>Customer Statement</h2>
            <p class="text-muted mb-0">Statement of account with a running balance</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" id="btnPrint" onclick="window.print()" disabled>
                <i class="bi bi-printer me-2"></i> Print
            </button>
        </div>
    </div>

    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Customer</label>
                    <select name="customer_id" id="f-customer" class="form-select" style="width:100%">
                        <?php if ($preCustId > 0): ?>
                            <option value="<?= $preCustId ?>" selected><?= safe_output($preCustName) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                    <input type="date" name="date_from" id="f-from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                    <input type="date" name="date_to" id="f-to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-search"></i></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Statement document -->
    <div id="statementDoc" style="display:none;">
        <?php renderPrintHeader(); ?>

        <div class="text-center mb-3">
            <h3 class="fw-bold text-primary mb-0" style="letter-spacing:1px;">STATEMENT OF ACCOUNT</h3>
            <div class="text-muted small" id="doc-period"></div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100" style="border-color:#b6ccfe!important;">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Statement For</div>
                    <div class="fw-bold" id="doc-cust-name">—</div>
                    <div class="small text-muted" id="doc-cust-contact"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100 d-flex flex-column justify-content-center" style="border-color:#b6ccfe!important;background:#e7f0ff;">
                    <div class="d-flex justify-content-between"><span class="small text-muted text-uppercase fw-bold">Opening Balance</span><span class="fw-bold" id="doc-opening">—</span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="small text-muted text-uppercase fw-bold">Closing Balance</span><span class="fw-bold fs-5" id="doc-closing" style="color:#052c65;">—</span></div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="stmtTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Date</th>
                        <th>Reference</th>
                        <th>Description</th>
                        <th class="text-end">Charge</th>
                        <th class="text-end">Payment</th>
                        <th class="text-end pe-3">Balance</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot></tfoot>
            </table>
        </div>
    </div>

    <div id="emptyState" class="text-center text-muted py-5">
        <i class="bi bi-file-earmark-text display-4 opacity-25"></i>
        <p class="mt-2 mb-0">Select a customer and date range to generate a statement.</p>
    </div>
</div>

<style>
    #stmtTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    #stmtTable tbody tr td { font-size: .85rem; }
    .row-opening td, #stmtTable tfoot td { font-weight: 700; background: #f1f5ff; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info { display: none !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        #statementDoc { display: block !important; }
        .card { border: none !important; box-shadow: none !important; }
        #stmtTable { border: 1px solid #000 !important; }
        #stmtTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #stmtTable td { border: 1px solid #dee2e6 !important; }
    }
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_customer_statement.php') ?>';
    const CUST_URL = '<?= buildUrl('api/account/search_customers.php') ?>';
    const fmt = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const dt  = s => s ? new Date(s).toLocaleDateString() : '';

    $('#f-customer').select2({
        theme: 'bootstrap-5', placeholder: 'Search a customer…', allowClear: true, width: '100%',
        ajax: { url: CUST_URL, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d, cache: true }
    });

    function loadStatement() {
        const cid = $('#f-customer').val();
        if (!cid) { Swal.fire({ icon: 'info', title: 'Select a customer', text: 'Please choose a customer first.' }); return; }
        const params = { customer_id: cid, date_from: $('#f-from').val(), date_to: $('#f-to').val() };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the statement.' });
                    return;
                }
                $('#doc-cust-name').text(res.customer.customer_name || '—');
                const contact = [res.customer.phone, res.customer.email, res.customer.address].filter(Boolean).join(' · ');
                $('#doc-cust-contact').text(contact);
                $('#doc-period').text('Period: ' + dt(res.date_from) + '  –  ' + dt(res.date_to));
                $('#doc-opening').text(fmt(res.opening_balance));
                $('#doc-closing').text(fmt(res.closing_balance));

                let body = `<tr class="row-opening"><td class="ps-3" colspan="5">Opening Balance as of ${dt(res.date_from)}</td><td class="text-end pe-3">${fmt(res.opening_balance)}</td></tr>`;
                if (!res.lines.length) {
                    body += `<tr><td colspan="6" class="text-center text-muted py-3">No transactions in this period.</td></tr>`;
                } else {
                    res.lines.forEach(l => {
                        body += `<tr>
                            <td class="ps-3">${dt(l.date)}</td>
                            <td>${esc(l.ref)}</td>
                            <td>${esc(l.description)}</td>
                            <td class="text-end">${l.charge ? fmt(l.charge) : ''}</td>
                            <td class="text-end">${l.payment ? fmt(l.payment) : ''}</td>
                            <td class="text-end pe-3">${fmt(l.balance)}</td>
                        </tr>`;
                    });
                }
                $('#stmtTable tbody').html(body);
                $('#stmtTable tfoot').html(
                    `<tr><td class="ps-3" colspan="3">Totals</td>
                         <td class="text-end">${fmt(res.totals.charge)}</td>
                         <td class="text-end">${fmt(res.totals.payment)}</td>
                         <td class="text-end pe-3">${fmt(res.closing_balance)}</td></tr>`
                );

                $('#emptyState').hide();
                $('#statementDoc').show();
                $('#btnPrint').prop('disabled', false);
            })
            .fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error loading the statement.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadStatement(); });

    // Auto-load when a customer was passed in via the URL.
    <?php if ($preCustId > 0): ?>loadStatement();<?php endif; ?>
    if (typeof logReportAction === 'function') logReportAction('Viewed Customer Statement', 'Opened customer statement');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
