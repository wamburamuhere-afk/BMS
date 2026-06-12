<?php
$page_title = 'PO vs Invoice Report';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('received_invoices');
includeHeader();

global $pdo;
?>
<style>
.status-fully  { background:#d1e7dd; color:#0a3622; }
.status-partial{ background:#cfe2ff; color:#084298; }
.status-open   { background:#e2e3e5; color:#41464b; }
.status-over   { background:#f8d7da; color:#842029; }
.progress { height: 8px; }
.progress-fully  .progress-bar { background:#198754; }
.progress-partial .progress-bar{ background:#0d6efd; }
.progress-open    .progress-bar{ background:#6c757d; }
.progress-over   .progress-bar { background:#dc3545; }
</style>

<div class="container-fluid mt-3">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('received_invoices') ?>">Received Invoices</a></li>
            <li class="breadcrumb-item active">PO vs Invoice Report</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2" style="position:sticky;top:0;z-index:1020;background:#fff;padding:8px 0;">
        <h4 class="mb-0 fw-bold"><i class="bi bi-clipboard-data text-primary me-2"></i>PO vs Invoice Report</h4>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-success btn-sm" onclick="exportExcel()">
                <i class="bi bi-file-earmark-excel me-1"></i> Export
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="loadReport()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        </div>
    </div>

    <!-- Statistics cards -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary" id="stat-pos">0</div>
                <div class="small text-muted">Total POs</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-success" id="stat-fully">0</div>
                <div class="small text-muted">Fully Billed</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-info" id="stat-partial">0</div>
                <div class="small text-muted">Partially Billed</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-danger" id="stat-over">0</div>
                <div class="small text-muted">Over-billed</div>
            </div>
        </div>
    </div>

    <div class="d-none d-md-flex justify-content-end mb-2" id="viewToggle">
        <div class="btn-group">
            <button class="btn btn-sm" id="btnTableView" title="Table view" style="background:#0d6efd;color:#fff;border:1px solid #0d6efd;"><i class="bi bi-table"></i></button>
            <button class="btn btn-sm" id="btnCardView" title="Card view" style="background:#fff;color:#0d6efd;border:1px solid #0d6efd;"><i class="bi bi-grid-3x3-gap"></i></button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold mb-1">Supplier</label>
                    <select id="f-supplier" class="form-select form-select-sm">
                        <option value="">All Suppliers</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Status</label>
                    <select id="f-status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="open">Open (No invoices)</option>
                        <option value="partial">Partially Billed</option>
                        <option value="fully">Fully Billed</option>
                        <option value="over">Over-billed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">From</label>
                    <input type="date" id="f-from" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">To</label>
                    <input type="date" id="f-to" class="form-control form-control-sm">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm flex-fill" onclick="loadReport()">
                        <i class="bi bi-funnel"></i> Apply
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Desktop table -->
    <div id="tableView" class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="reportTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">S/NO</th>
                        <th>PO Number</th>
                        <th>Supplier</th>
                        <th>PO Date</th>
                        <th class="text-end">PO Total</th>
                        <th class="text-end">Invoiced</th>
                        <th class="text-end">Remaining</th>
                        <th style="min-width:140px;">% Billed</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Invoices</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Mobile cards -->
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<script>
const RIR_API = '<?= buildUrl('api/po_invoice_report.php') ?>';
let _rows = [];
let viewMode = 'table';

function setToggleColors(mode) {
    if (mode === 'table') {
        $('#btnTableView').css({ background:'#0d6efd', color:'#fff', border:'1px solid #0d6efd' });
        $('#btnCardView').css({ background:'#fff', color:'#0d6efd', border:'1px solid #0d6efd' });
    } else {
        $('#btnTableView').css({ background:'#fff', color:'#0d6efd', border:'1px solid #0d6efd' });
        $('#btnCardView').css({ background:'#0d6efd', color:'#fff', border:'1px solid #0d6efd' });
    }
}

function applyView(mode) {
    if (window.innerWidth < 768) {
        $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none');
        $('#viewToggle').addClass('d-none');
    } else {
        $('#viewToggle').removeClass('d-none').addClass('d-flex');
        if (mode === 'card') {
            $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none');
        } else {
            $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none');
        }
        setToggleColors(mode);
    }
}

$(function () {
    $('#btnTableView').on('click', function () { viewMode = 'table'; applyView('table'); });
    $('#btnCardView').on('click', function () { viewMode = 'card'; applyView('card'); });
    applyView(viewMode);
    $(window).on('resize', function () { applyView(viewMode); });
    loadSuppliers();
    loadReport();
});

function loadSuppliers() {
    $.getJSON('<?= buildUrl('api/received_invoices.php') ?>', { action: 'get_suppliers' }, function (res) {
        const $sel = $('#f-supplier');
        (res.data || []).forEach(s => $sel.append($('<option>').val(s.id).text(s.text)));
    });
}

function clearFilters() {
    $('#f-supplier, #f-status, #f-from, #f-to').val('');
    loadReport();
}

function loadReport() {
    const params = {
        supplier_id: $('#f-supplier').val() || '',
        status:      $('#f-status').val()   || '',
        from:        $('#f-from').val()     || '',
        to:          $('#f-to').val()       || '',
    };
    $.getJSON(RIR_API, params, function (res) {
        if (!res.success) {
            Swal.fire('Error', res.message || 'Failed to load report', 'error');
            return;
        }
        _rows = res.data || [];
        renderTable(_rows);
        renderCards(_rows);
        renderStats(_rows);
        applyView(viewMode);
    }).fail(function (jqXHR, textStatus) {
        let msg;
        if (jqXHR.status === 401)      msg = 'You are not logged in. Please refresh and sign in again.';
        else if (jqXHR.status === 403) msg = 'You do not have permission to view this report. Ask an administrator to grant the "Received Invoices" view permission.';
        else if (jqXHR.status === 500) msg = 'Server error loading the report. Please contact support if this persists.';
        else if (textStatus === 'timeout') msg = 'The report took too long to load. Try narrowing the date range.';
        else                            msg = 'Could not load the report (HTTP ' + jqXHR.status + '). Check your connection and try again.';
        Swal.fire({ icon: 'error', title: 'Report Failed to Load', text: msg });
    });
}

function fmtTZS(n) {
    return parseFloat(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function statusFor(row) {
    const total    = parseFloat(row.grand_total);
    const invoiced = parseFloat(row.invoiced_total);
    const diff     = invoiced - total;
    if (diff > 1)                          return { key: 'over',    label: 'Over-billed',      cls: 'status-over',    pcls: 'progress-over' };
    if (Math.abs(diff) <= 1 && total > 0)  return { key: 'fully',   label: 'Fully Billed',     cls: 'status-fully',   pcls: 'progress-fully' };
    if (invoiced > 0)                      return { key: 'partial', label: 'Partially Billed', cls: 'status-partial', pcls: 'progress-partial' };
    return                                       { key: 'open',    label: 'Open',              cls: 'status-open',    pcls: 'progress-open' };
}

function getFilterSummary() {
    const parts = [];
    if ($('#f-supplier').val()) parts.push('supplier filter active');
    if ($('#f-status').val())   parts.push('status: ' + $('#f-status option:selected').text());
    if ($('#f-from').val())     parts.push('from ' + $('#f-from').val());
    if ($('#f-to').val())       parts.push('to ' + $('#f-to').val());
    return parts.length
        ? 'Try widening these filters: ' + parts.join(', ')
        : 'No purchase orders exist yet, or none are linked to received invoices.';
}

function pctFor(row) {
    const total = parseFloat(row.grand_total) || 0;
    if (total <= 0) return 0;
    return Math.min(200, (parseFloat(row.invoiced_total) / total) * 100);
}

function renderTable(rows) {
    const $tb = $('#reportTable tbody').empty();
    if (!rows.length) {
        const f = getFilterSummary();
        $tb.append('<tr><td colspan="10" class="text-center text-muted py-4"><i class="bi bi-inbox d-block mb-2 fs-2 opacity-25"></i><div class="fw-bold mb-1">No purchase orders found</div><small>' + f + '</small></td></tr>');
        return;
    }
    rows.forEach((r, i) => {
        const s   = statusFor(r);
        const pct = pctFor(r);
        $tb.append(`
            <tr>
                <td class="ps-3">${i + 1}</td>
                <td><a href="<?= getUrl('purchase_order_view') ?>?id=${r.purchase_order_id}" class="fw-bold text-decoration-none">${safeOutput(r.order_number)}</a></td>
                <td>${safeOutput(r.supplier_name)}</td>
                <td>${safeOutput(r.order_date)}</td>
                <td class="text-end fw-bold">${fmtTZS(r.grand_total)}</td>
                <td class="text-end">${fmtTZS(r.invoiced_total)}</td>
                <td class="text-end ${parseFloat(r.remaining) < 0 ? 'text-danger fw-bold' : ''}">${fmtTZS(r.remaining)}</td>
                <td>
                    <div class="progress ${s.pcls}"><div class="progress-bar" style="width:${Math.min(100, pct)}%"></div></div>
                    <small class="text-muted">${pct.toFixed(0)}%</small>
                </td>
                <td class="text-center"><span class="badge ${s.cls}">${s.label}</span></td>
                <td class="text-center"><span class="badge bg-secondary">${r.invoice_count}</span></td>
            </tr>
        `);
    });
}

function renderCards(rows) {
    const $cv = $('#cardView').empty();
    if (!rows.length) {
        const f = getFilterSummary();
        $cv.append('<div class="col-12 text-center text-muted py-5"><i class="bi bi-inbox d-block mb-2 fs-2 opacity-25"></i><div class="fw-bold mb-1">No purchase orders found</div><small>' + f + '</small></div>');
        return;
    }
    rows.forEach(r => {
        const s   = statusFor(r);
        const pct = pctFor(r);
        $cv.append(`
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bold">${safeOutput(r.order_number)}</span>
                            <span class="badge ${s.cls}">${s.label}</span>
                        </div>
                        <div class="small text-muted mb-2">${safeOutput(r.supplier_name)} · ${safeOutput(r.order_date)}</div>
                        <div class="row g-1 small">
                            <div class="col-4"><div class="text-muted">PO</div><div class="fw-bold">${fmtTZS(r.grand_total)}</div></div>
                            <div class="col-4"><div class="text-muted">Invoiced</div><div class="fw-bold">${fmtTZS(r.invoiced_total)}</div></div>
                            <div class="col-4"><div class="text-muted">Remaining</div><div class="fw-bold ${parseFloat(r.remaining) < 0 ? 'text-danger' : ''}">${fmtTZS(r.remaining)}</div></div>
                        </div>
                        <div class="progress mt-2 ${s.pcls}"><div class="progress-bar" style="width:${Math.min(100, pct)}%"></div></div>
                        <div class="small text-muted mt-1">${r.invoice_count} invoice(s) · ${pct.toFixed(0)}% billed</div>
                    </div>
                </div>
            </div>
        `);
    });
}

function renderStats(rows) {
    let fully = 0, partial = 0, over = 0;
    rows.forEach(r => {
        const s = statusFor(r).key;
        if (s === 'fully')   fully++;
        if (s === 'partial') partial++;
        if (s === 'over')    over++;
    });
    $('#stat-pos').text(rows.length);
    $('#stat-fully').text(fully);
    $('#stat-partial').text(partial);
    $('#stat-over').text(over);
}

function exportExcel() {
    if (!_rows.length) { Swal.fire('No Data', 'No rows to export.', 'info'); return; }
    let csv = 'PO Number,Supplier,PO Date,PO Total,Invoiced,Remaining,% Billed,Status,Invoices\n';
    _rows.forEach(r => {
        const s   = statusFor(r);
        const pct = pctFor(r).toFixed(0);
        csv += [
            '"' + r.order_number + '"',
            '"' + (r.supplier_name || '').replace(/"/g, '""') + '"',
            r.order_date || '',
            r.grand_total,
            r.invoiced_total,
            r.remaining,
            pct + '%',
            s.label,
            r.invoice_count
        ].join(',') + '\n';
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'po_invoice_report_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php includeFooter(); ?>
