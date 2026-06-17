<?php
// app/constant/reports/vendor_statement.php
// Vendor (Supplier / Sub-contractor) Statement of Account — document-style,
// AJAX-driven (get_vendor_statement.php). Opening payable + dated bills/payments
// + running balance + closing payable, printable with the standard letterhead.
// Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md (§23 scope).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

autoEnforcePermission('financial_reports');

$currency  = get_setting('currency', 'TZS');
$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

// scope-audit: skip — display-only name lookup for the picker label; the statement's
// financial rows are project-scoped inside get_vendor_statement.php (§23).
// vendor_type disambiguates: suppliers and sub_contractors are separate tables
// that each auto-increment their own supplier_id, so the same numeric id can
// refer to two different real entities — without it we'd risk pre-filling the
// wrong name (or none at all for a sub-contractor, since this used to only
// check the suppliers table).
$preVendId   = (isset($_GET['vendor_id']) && $_GET['vendor_id'] !== '') ? (int)$_GET['vendor_id'] : 0;
$preVendType = in_array($_GET['vendor_type'] ?? '', ['supplier', 'sub_contractor'], true) ? $_GET['vendor_type'] : '';
$preVendName = '';
if ($preVendId > 0) {
    if ($preVendType === 'sub_contractor') {
        $st = $pdo->prepare("SELECT supplier_name FROM sub_contractors WHERE supplier_id = ?");
    } elseif ($preVendType === 'supplier') {
        $st = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
    } else {
        $st = $pdo->prepare("
            SELECT supplier_name FROM suppliers WHERE supplier_id = ?
            UNION ALL
            SELECT supplier_name FROM sub_contractors WHERE supplier_id = ?
            LIMIT 1
        ");
    }
    $st->execute($preVendType ? [$preVendId] : [$preVendId, $preVendId]);
    $preVendName = (string)$st->fetchColumn();
}
?>

<div class="container-fluid py-4">
    <div class="row mb-3 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-file-earmark-text me-2"></i>Vendor Statement</h2>
            <p class="text-muted mb-0">Supplier / sub-contractor account with a running payable balance</p>
        </div>
        <div class="col-md-6 text-end d-flex justify-content-end gap-2">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" id="btnPrint" onclick="window.print()" disabled>
                <i class="bi bi-printer me-2"></i> Print
            </button>
            <button class="btn btn-outline-secondary shadow-sm" onclick="history.back()">
                <i class="bi bi-arrow-left me-1"></i> Back
            </button>
        </div>
    </div>

    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <?php if ($preVendId > 0): ?>
                        <?php $vendLabel = $preVendType === 'sub_contractor' ? 'Sub-contractor' : 'Supplier'; ?>
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= $vendLabel ?></label>
                        <input type="hidden" id="f-vendor" value="<?= $preVendId ?>">
                        <input type="hidden" id="f-vendor-type" value="<?= htmlspecialchars($preVendType) ?>">
                        <div class="form-control bg-light fw-bold"><?= safe_output($preVendName) ?></div>
                    <?php else: ?>
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Supplier / Sub-contractor</label>
                        <select name="vendor_id" id="f-vendor" class="form-select" style="width:100%"></select>
                    <?php endif; ?>
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

    <!-- Summary cards (populated after load) -->
    <div id="summaryCards" class="row g-3 mb-4 d-none d-print-none">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#d1e7dd;border-left:4px solid #0d6efd!important;border-radius:10px;">
                <div class="fs-5 fw-bold text-primary" id="sc-invoiced">—</div>
                <div class="small text-muted">Total Invoiced</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#d1e7dd;border-left:4px solid #198754!important;border-radius:10px;">
                <div class="fs-5 fw-bold text-success" id="sc-paid">—</div>
                <div class="small text-muted">Total Paid</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#d1e7dd;border-left:4px solid #6f42c1!important;border-radius:10px;">
                <div class="fs-5 fw-bold" id="sc-opening" style="color:#6f42c1;">—</div>
                <div class="small text-muted">Opening Balance</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#d1e7dd;border-left:4px solid #052c65!important;border-radius:10px;">
                <div class="fs-5 fw-bold" id="sc-closing" style="color:#052c65;">—</div>
                <div class="small text-muted">Closing Balance</div>
            </div>
        </div>
    </div>

    <div id="statementDoc" style="display:none;">
        <!-- Company logo + name on print come from the global header (renderPrintHeader in header.php). -->
        <div class="text-center mb-3">
            <h3 class="fw-bold text-primary mb-0" style="letter-spacing:1px;">VENDOR STATEMENT OF ACCOUNT</h3>
            <div class="text-muted small" id="doc-period"></div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100" style="border-color:#b6ccfe!important;">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Statement For</div>
                    <div class="fw-bold" id="doc-vend-name">—</div>
                    <div class="small text-muted" id="doc-vend-contact"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100 d-flex flex-column justify-content-center" style="border-color:#b6ccfe!important;background:#e7f0ff;">
                    <div class="d-flex justify-content-between"><span class="small text-muted text-uppercase fw-bold">Opening Payable</span><span class="fw-bold" id="doc-opening">—</span></div>
                    <div class="d-flex justify-content-between mt-1"><span class="small text-muted text-uppercase fw-bold">Closing Payable</span><span class="fw-bold fs-5" id="doc-closing" style="color:#052c65;">—</span></div>
                </div>
            </div>
        </div>

        <div id="tableView">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="stmtTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">S/No</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th class="text-end">Invoice</th>
                            <th class="text-end">Payment</th>
                            <th class="text-end pe-3">Balance</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot></tfoot>
                </table>
            </div>
        </div>
        <div id="cardView" class="row g-2 mt-2 d-none"></div>
    </div>

    <div id="emptyState" class="text-center text-muted py-5">
        <i class="bi bi-file-earmark-text display-4 opacity-25"></i>
        <p class="mt-2 mb-0">Select a vendor and date range to generate a statement.</p>
    </div>
</div>

<style>
    #stmtTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    #stmtTable tbody tr td { font-size: .85rem; }
    .row-opening td, #stmtTable tfoot td { font-weight: 700; background: #f1f5ff; }
    .row-bill td   { background: #fff9f0; }
    .row-payment td { background: #f0fff4; }
    .row-credit td  { background: #f0f4ff; }
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
    const DATA_URL = '<?= buildUrl('api/account/get_vendor_statement.php') ?>';
    const VEND_URL = '<?= buildUrl('api/account/search_vendors.php') ?>';
    const fmt = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const dt  = s => s ? new Date(s).toLocaleDateString() : '';

    const TYPE_META = {
        bill:        { label: 'Invoice',     cls: 'bg-warning text-dark',  icon: 'bi-file-earmark-text', rowCls: 'row-bill'     },
        payment:     { label: 'Payment',     cls: 'bg-success text-white', icon: 'bi-cash-stack',        rowCls: 'row-payment'  },
        credit_note: { label: 'Credit Note', cls: 'bg-info text-dark',     icon: 'bi-receipt-cutoff',   rowCls: 'row-credit'   },
    };

    function typeBadge(type) {
        const m = TYPE_META[type] || { label: type, cls: 'bg-secondary text-white', icon: 'bi-dot', rowCls: '' };
        return `<span class="badge ${m.cls}"><i class="bi ${m.icon} me-1"></i>${m.label}</span>`;
    }

    <?php if (!$preVendId): ?>
    $('#f-vendor').select2({
        theme: 'bootstrap-5', placeholder: 'Search a vendor…', allowClear: true, width: '100%',
        ajax: { url: VEND_URL, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d, cache: true }
    });
    <?php endif; ?>

    // ── DataTable ────────────────────────────────────────────────────────────
    const table = $('#stmtTable').DataTable({
        responsive: false,
        scrollX: true,
        pageLength: 25,
        order: [],
        dom: 'rtipB',
        buttons: [
            { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
        ],
        columns: [
            { data: null,          orderable: false, className: 'ps-3',  render: (d, t, r, m) => m.row + m.settings._iDisplayStart + 1 },
            { data: 'date',                                               render: d => dt(d) },
            { data: 'type',                                               render: d => typeBadge(d) },
            { data: 'ref',                                                render: d => esc(d) },
            { data: 'description',                                        render: d => esc(d) },
            { data: 'charge',      className: 'text-end',                render: d => d ? fmt(d) : '' },
            { data: 'payment',     className: 'text-end',                render: d => d ? fmt(d) : '' },
            { data: 'balance',     className: 'text-end pe-3',           render: d => fmt(d) },
        ],
        rowCallback: function (row, data) {
            const rowCls = (TYPE_META[data.type] || {}).rowCls || '';
            if (rowCls) $(row).addClass(rowCls);
        },
        language: { emptyTable: 'No transactions in this period.', zeroRecords: 'No matching records.' },
        drawCallback: function () {
            renderCards(this.api().rows({ page: 'current' }).data().toArray());
        }
    });

    // ── Mobile card view (§UI-7) ─────────────────────────────────────────────
    function renderCards(rows) {
        if (!rows.length) {
            $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No transactions found</div>');
            return;
        }
        let html = '';
        rows.forEach(row => {
            html += `
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted">${dt(row.date)}</span>
                            ${typeBadge(row.type)}
                        </div>
                        <div class="fw-semibold">${esc(row.ref)}</div>
                        <div class="small text-muted mb-2">${esc(row.description)}</div>
                        <div class="d-flex gap-3">
                            ${row.charge  ? `<div><div class="small text-muted">Invoice</div><div class="fw-bold">${fmt(row.charge)}</div></div>` : ''}
                            ${row.payment ? `<div><div class="small text-muted">Payment</div><div class="fw-bold text-success">${fmt(row.payment)}</div></div>` : ''}
                            <div class="ms-auto text-end"><div class="small text-muted">Balance</div><div class="fw-bold text-primary">${fmt(row.balance)}</div></div>
                        </div>
                    </div>
                </div>
            </div>`;
        });
        $('#cardView').html(html);
    }

    function applyView() {
        if (window.innerWidth < 768) {
            $('#tableView').addClass('d-none');
            $('#cardView').removeClass('d-none');
        } else {
            $('#tableView').removeClass('d-none');
            $('#cardView').addClass('d-none');
        }
    }
    applyView();
    $(window).on('resize', applyView);

    // ── Load statement ────────────────────────────────────────────────────────
    function loadStatement() {
        const vid = $('#f-vendor').val();
        if (!vid) { Swal.fire({ icon: 'info', title: 'Select a vendor', text: 'Please choose a vendor first.' }); return; }
        const vtype = $('#f-vendor-type').length
            ? $('#f-vendor-type').val()
            : ($('#f-vendor').find(':selected').data('type') || '');
        const params = { vendor_id: vid, vendor_type: vtype, date_from: $('#f-from').val(), date_to: $('#f-to').val() };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the statement.' });
                    return;
                }

                $('#doc-vend-name').text(res.vendor.supplier_name || '—');
                const contact = [res.vendor.phone, res.vendor.email, res.vendor.address].filter(Boolean).join(' · ');
                $('#doc-vend-contact').text(contact);
                $('#doc-period').text('Period: ' + dt(res.date_from) + '  –  ' + dt(res.date_to));
                $('#doc-opening').text(fmt(res.opening_balance));
                $('#doc-closing').text(fmt(res.closing_balance));

                $('#sc-invoiced').text(fmt(res.totals.charge));
                $('#sc-paid').text(fmt(res.totals.payment));
                $('#sc-opening').text(fmt(res.opening_balance));
                $('#sc-closing').text(fmt(res.closing_balance));
                $('#summaryCards').removeClass('d-none');

                table.clear().rows.add(res.lines || []).draw();

                $('#stmtTable tfoot').html(
                    `<tr><td class="ps-3" colspan="5">Totals</td>
                         <td class="text-end">${fmt(res.totals.charge)}</td>
                         <td class="text-end">${fmt(res.totals.payment)}</td>
                         <td class="text-end pe-3">${fmt(res.closing_balance)}</td></tr>`
                );

                $('#emptyState').hide();
                $('#statementDoc').show();
                table.columns.adjust();
                $('#btnPrint').prop('disabled', false);
            })
            .fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error loading the statement.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadStatement(); });

    <?php if ($preVendId > 0): ?>loadStatement();<?php endif; ?>
    if (typeof logReportAction === 'function') logReportAction('Viewed Vendor Statement', 'Opened vendor statement');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
