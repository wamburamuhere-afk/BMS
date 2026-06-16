<?php
// app/constant/reports/employee_statement.php
// Employee Statement of Account — document-style, AJAX-driven
// (get_employee_statement.php). Opening payable + payroll runs/payments
// + running balance + closing payable, printable with standard letterhead.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

autoEnforcePermission('financial_reports');

$currency  = get_setting('currency', 'TZS');
$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

// scope-audit: skip — display-only name lookup to pre-fill the picker label.
// The statement's financial rows are scope-guarded inside get_employee_statement.php
// (assertScopeForEmployee + scopeFilterSqlNullable on the employee and payroll queries).
$preEmpId   = (isset($_GET['employee_id']) && $_GET['employee_id'] !== '') ? (int)$_GET['employee_id'] : 0;
$preEmpName = '';
if ($preEmpId > 0) {
    $st = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM employees WHERE employee_id = ? AND status != 'terminated'");
    $st->execute([$preEmpId]);
    $preEmpName = (string)$st->fetchColumn();
}
?>

<div class="container-fluid py-4">
    <div class="row mb-3 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-person-lines-fill me-2"></i>Employee Statement</h2>
            <p class="text-muted mb-0">Employee payroll account with a running payable balance</p>
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
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Employee</label>
                    <select name="employee_id" id="f-employee" class="form-select" style="width:100%">
                        <?php if ($preEmpId > 0): ?>
                            <option value="<?= $preEmpId ?>" selected><?= safe_output($preEmpName) ?></option>
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

    <!-- Summary cards -->
    <div id="summaryCards" class="row g-3 mb-4 d-none d-print-none">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #0d6efd!important;border-radius:10px;">
                <div class="fs-5 fw-bold text-primary" id="sc-charged">—</div>
                <div class="small text-muted">Total Payroll</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #198754!important;border-radius:10px;">
                <div class="fs-5 fw-bold text-success" id="sc-paid">—</div>
                <div class="small text-muted">Total Paid</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #6f42c1!important;border-radius:10px;">
                <div class="fs-5 fw-bold" id="sc-opening" style="color:#6f42c1;">—</div>
                <div class="small text-muted">Opening Balance</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="border-left:4px solid #052c65!important;border-radius:10px;">
                <div class="fs-5 fw-bold" id="sc-closing" style="color:#052c65;">—</div>
                <div class="small text-muted">Closing Balance</div>
            </div>
        </div>
    </div>

    <div id="statementDoc" style="display:none;">
        <div class="text-center mb-3">
            <h3 class="fw-bold text-primary mb-0" style="letter-spacing:1px;">EMPLOYEE PAYROLL STATEMENT</h3>
            <div class="text-muted small" id="doc-period"></div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100" style="border-color:#b6ccfe!important;">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Statement For</div>
                    <div class="fw-bold" id="doc-emp-name">—</div>
                    <div class="small text-muted" id="doc-emp-detail"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100 d-flex flex-column justify-content-center" style="border-color:#b6ccfe!important;background:#e7f0ff;">
                    <div class="d-flex justify-content-between"><span class="small text-muted text-uppercase fw-bold">Opening Payable</span><span class="fw-bold" id="doc-opening">—</span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="small text-muted text-uppercase fw-bold">Closing Payable</span><span class="fw-bold fs-5" id="doc-closing" style="color:#052c65;">—</span></div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="stmtTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">S/No</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Description</th>
                        <th class="text-end">Payroll</th>
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
        <i class="bi bi-person-lines-fill display-4 opacity-25"></i>
        <p class="mt-2 mb-0">Select an employee and date range to generate a statement.</p>
    </div>
</div>

<style>
    #stmtTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    #stmtTable tbody tr td { font-size: .85rem; }
    .row-opening td, #stmtTable tfoot td { font-weight: 700; background: #f1f5ff; }
    .row-charge td   { background: #fff9f0; }
    .row-payment td  { background: #f0fff4; }
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
    const DATA_URL = '<?= buildUrl('api/account/get_employee_statement.php') ?>';
    const EMP_URL  = '<?= buildUrl('api/account/search_employees.php') ?>';
    const fmt = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const dt  = s => s ? new Date(s).toLocaleDateString() : '';

    const TYPE_META = {
        charge:  { label: 'Payroll',  cls: 'bg-warning text-dark',  icon: 'bi-file-earmark-text', rowCls: 'row-charge' },
        payment: { label: 'Payment',  cls: 'bg-success text-white', icon: 'bi-cash-stack',         rowCls: 'row-payment' },
    };

    function typeBadge(type) {
        const m = TYPE_META[type] || { label: type, cls: 'bg-secondary text-white', icon: 'bi-dot', rowCls: '' };
        return `<span class="badge ${m.cls}"><i class="bi ${m.icon} me-1"></i>${m.label}</span>`;
    }

    $('#f-employee').select2({
        theme: 'bootstrap-5', placeholder: 'Search an employee…', allowClear: true, width: '100%',
        ajax: { url: EMP_URL, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d, cache: true }
    });

    function loadStatement() {
        const eid = $('#f-employee').val();
        if (!eid) { Swal.fire({ icon: 'info', title: 'Select an employee', text: 'Please choose an employee first.' }); return; }
        const params = { employee_id: eid, date_from: $('#f-from').val(), date_to: $('#f-to').val() };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the statement.' });
                    return;
                }

                $('#doc-emp-name').text(res.employee.full_name || '—');
                const detail = [res.employee.employee_number, res.employee.department].filter(Boolean).join(' · ');
                $('#doc-emp-detail').text(detail);
                $('#doc-period').text('Period: ' + dt(res.date_from) + '  –  ' + dt(res.date_to));
                $('#doc-opening').text(fmt(res.opening_balance));
                $('#doc-closing').text(fmt(res.closing_balance));

                $('#sc-charged').text(fmt(res.totals.charge));
                $('#sc-paid').text(fmt(res.totals.payment));
                $('#sc-opening').text(fmt(res.opening_balance));
                $('#sc-closing').text(fmt(res.closing_balance));
                $('#summaryCards').removeClass('d-none');

                let body = `<tr class="row-opening"><td class="ps-3" colspan="7">Opening Payable as of ${dt(res.date_from)}</td><td class="text-end pe-3">${fmt(res.opening_balance)}</td></tr>`;
                if (!res.lines.length) {
                    body += `<tr><td colspan="8" class="text-center text-muted py-3">No transactions in this period.</td></tr>`;
                } else {
                    res.lines.forEach((l, i) => {
                        const rowCls = (TYPE_META[l.type] || {}).rowCls || '';
                        body += `<tr class="${rowCls}">
                            <td class="ps-3">${i + 1}</td>
                            <td>${dt(l.date)}</td>
                            <td>${typeBadge(l.type)}</td>
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
                    `<tr><td class="ps-3" colspan="5">Totals</td>
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

    <?php if ($preEmpId > 0): ?>loadStatement();<?php endif; ?>
    if (typeof logReportAction === 'function') logReportAction('Viewed Employee Statement', 'Opened employee statement');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
