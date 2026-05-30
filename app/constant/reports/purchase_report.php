<?php
// app/constant/reports/purchase_report.php
// Professional Purchase Report — AJAX (get_purchase_report.php), Chart.js
// charts that also print, DataTable detail grid, Select2 search filters,
// project-scope security. Standards: .claude/ui-constants.md, i_e_print.md,
// .claude/security.md (§23 project scope).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('purchase_report');

// Projects the current user may see (admins → all; others → assigned only).
$projects = $pdo->query(
    "SELECT project_id, project_name FROM projects
      WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
      ORDER BY project_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? date('Y-12-31');
$currency  = get_setting('currency', 'TZS');
?>

<div class="container-fluid py-4">
    <!-- Print Header (title only — borders/footer come from i_e_print.md) -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">PROCUREMENT & PURCHASE REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Period: <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <!-- Screen header + actions -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-basket me-2"></i>Purchase Report</h2>
            <p class="text-muted mb-0">Procurement spend and supplier insights</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> Print
            </button>
        </div>
    </div>

    <!-- Filters (AJAX — no page reload) -->
    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                    <input type="date" name="date_from" id="f-from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                    <input type="date" name="date_to" id="f-to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Project</label>
                    <select name="project_id" id="f-project" class="form-select" style="width:100%">
                        <option value="">All My Projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Supplier</label>
                    <select name="supplier_id" id="f-supplier" class="form-select" style="width:100%"></select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Status</label>
                    <select name="status" id="f-status" class="form-select" style="width:100%">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="ordered">Ordered</option>
                        <option value="partially_received">Partially Received</option>
                        <option value="received">Received</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary cards (screen + print) -->
    <div class="row g-3 mb-4" id="summaryCards">
        <?php
        $cards = [
            ['Order Count',  'stat-orders'],
            ['Total Spend',  'stat-spend'],
            ['Total Paid',   'stat-paid'],
            ['Balance Due',  'stat-due'],
        ];
        foreach ($cards as $c): ?>
            <div class="col-6 col-md-3">
                <div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                    <div class="card-body p-3 text-center">
                        <p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                        <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h4>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts (screen + print) -->
    <div class="row g-3 mb-4" id="chartRow">
        <div class="col-12 col-md-5">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Spend Trend</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartTrend"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-truck text-primary me-2"></i>Top Suppliers</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartSuppliers"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>By Status</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartStatus"></canvas></div></div>
            </div>
        </div>
    </div>

    <!-- Detail table -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-receipt me-2"></i>Purchase Orders</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="poTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">S/No</th>
                            <th>PO Number</th>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Paid</th>
                            <th class="text-center">Status</th>
                            <th class="pe-3 text-center">Payment</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    #poTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    .badge-status { font-size: .68rem; padding: .35em .6em; border-radius: 6px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        /* Blank-first-page fix: zero ONLY the top spacing the fixed navbar
           reserves; never touch padding-bottom (print_footer_css.php needs it). */
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #chartRow .card, #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        canvas { print-color-adjust: exact; -webkit-print-color-adjust: exact; max-width: 100% !important; }
        #poTable { border: 1px solid #000 !important; }
        #poTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #poTable td { border: 1px solid #dee2e6 !important; }
        .badge-status { border: 1px solid #999 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_purchase_report.php') ?>';
    const SUP_URL  = '<?= buildUrl('api/account/search_suppliers.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Blue-scale status badge (per ui-constants §UI-1)
    const STATUS_BG = { received:'#052c65', completed:'#052c65', approved:'#0d6efd', ordered:'#bfdbfe', partially_received:'#cfe2ff', pending:'#e9ecef', draft:'#e9ecef', cancelled:'#6c757d', paid:'#052c65', partial:'#cfe2ff', unpaid:'#dc3545' };
    const STATUS_FG = { received:'#fff', completed:'#fff', approved:'#fff', ordered:'#1e3a8a', partially_received:'#084298', pending:'#495057', draft:'#495057', cancelled:'#fff', paid:'#fff', partial:'#084298', unpaid:'#fff' };
    function badge(s) {
        const k = (s || '').toLowerCase();
        const bg = STATUS_BG[k] || '#0d6efd', fg = STATUS_FG[k] || '#fff';
        return `<span class="badge-status" style="background:${bg};color:${fg};">${(s || '').replace(/_/g, ' ').toUpperCase()}</span>`;
    }

    // ── Select2 filters ───────────────────────────────────────────────────
    $('#f-supplier').select2({
        theme: 'bootstrap-5', placeholder: 'All Suppliers', allowClear: true, width: '100%',
        ajax: { url: SUP_URL, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d, cache: true }
    });
    $('#f-project, #f-status').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    // ── DataTable (per §UI-2) ─────────────────────────────────────────────
    const table = $('#poTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: [4, 5], className: 'text-end' }, { targets: [6, 7], className: 'text-center' }],
        language: { emptyTable: 'No purchase orders found.', zeroRecords: 'No matching records.' }
    });

    // ── Charts ────────────────────────────────────────────────────────────
    let cTrend, cSuppliers, cStatus;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(charts) {
        [cTrend, cSuppliers, cStatus].forEach(c => c && c.destroy());

        cTrend = new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: { labels: charts.spend_trend.map(r => r.label),
                    datasets: [{ label: 'Spend', data: charts.spend_trend.map(r => +r.value), borderColor: BLUE, backgroundColor: 'rgba(13,110,253,.12)', fill: true, tension: .3, pointRadius: 2 }] },
            options: { ...baseOpts, plugins: { legend: { display: false } }, scales: { y: { ticks: { font: { size: 9 } } }, x: { ticks: { font: { size: 9 } } } } }
        });

        cSuppliers = new Chart(document.getElementById('chartSuppliers'), {
            type: 'bar',
            data: { labels: charts.by_supplier.map(r => r.name),
                    datasets: [{ label: 'Spend', data: charts.by_supplier.map(r => +r.total), backgroundColor: BLUE }] },
            options: { ...baseOpts, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { font: { size: 9 } } }, y: { ticks: { font: { size: 9 } } } } }
        });

        const blues = ['#0d6efd', '#052c65', '#6ea8fe', '#cfe2ff', '#1e3a8a', '#9ec5fe', '#bfdbfe', '#084298'];
        cStatus = new Chart(document.getElementById('chartStatus'), {
            type: 'doughnut',
            data: { labels: charts.by_status.map(r => (r.status || '').replace(/_/g, ' ').toUpperCase()),
                    datasets: [{ data: charts.by_status.map(r => +r.total), backgroundColor: blues }] },
            options: { ...baseOpts }
        });
    }

    // ── Load (AJAX) ───────────────────────────────────────────────────────
    function loadReport() {
        const params = {
            date_from: $('#f-from').val(), date_to: $('#f-to').val(),
            project_id: $('#f-project').val() || '', supplier_id: $('#f-supplier').val() || '',
            status: $('#f-status').val() || ''
        };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the report.' });
                    return;
                }
                $('#stat-orders').text(Number(res.summary.total_orders).toLocaleString());
                $('#stat-spend').text(fmt(res.summary.total_spend));
                $('#stat-paid').text(fmt(res.summary.total_paid));
                $('#stat-due').text(fmt(res.summary.total_due));

                renderCharts(res.charts);

                table.clear();
                res.rows.forEach((r, i) => table.row.add([
                    i + 1,
                    r.order_number || '',
                    r.order_date ? new Date(r.order_date).toLocaleDateString() : '',
                    r.supplier_name || 'Unknown',
                    fmt(r.grand_total),
                    fmt(r.paid_amount),
                    badge(r.status),
                    badge(r.payment_status)
                ]));
                table.draw();
            })
            .fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error loading the report.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project, #f-supplier, #f-status').on('change', loadReport);

    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Purchase Report', 'Loaded purchase report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
