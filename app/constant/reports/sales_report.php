<?php
// app/constant/reports/sales_report.php
// Professional Sales Report — AJAX-driven (get_sales_report.php), Chart.js
// charts that also print, DataTable detail grid, Select2 search filters.
// Standards: .claude/ui-constants.md (white+blue, DataTable, Select2,
// SweetAlert2), i_e_print.md (print borders/footer), .claude/security.md.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('sales_report');

// Static filter sources (small lists rendered in PHP; Customer is AJAX-searched).
$users = $pdo->query("SELECT user_id, CONCAT(first_name,' ',last_name) AS name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Projects the CURRENT user may see (admins → all; others → assigned only),
// per security.md §23. The picker therefore can only offer in-scope projects.
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
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">SALES PERFORMANCE REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Period: <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <!-- Screen header + actions -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Sales Report</h2>
            <p class="text-muted mb-0">Revenue performance and transactional insights</p>
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
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Customer</label>
                    <select name="customer_id" id="f-customer" class="form-select" style="width:100%"></select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Salesperson</label>
                    <select name="salesperson_id" id="f-salesperson" class="form-select" style="width:100%">
                        <option value="">All Staff</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['user_id'] ?>"><?= safe_output($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Status</label>
                    <select name="status" id="f-status" class="form-select" style="width:100%">
                        <option value="">All Statuses</option>
                        <option value="paid">Paid</option>
                        <option value="partial">Partial</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="overdue">Overdue</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Source</label>
                    <select name="source" id="f-source" class="form-select" style="width:100%">
                        <option value="">All Sources</option>
                        <option value="invoice">Invoice Only</option>
                        <option value="pos">POS Only</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter"></i></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary cards (screen + print) -->
    <div class="row g-3 mb-4" id="summaryCards">
        <?php
        $cards = [
            ['Gross Revenue',       'stat-sales',     '#0d6efd'],
            ['Total Collected',     'stat-paid',      '#0d6efd'],
            ['Accounts Receivable', 'stat-due',       '#0d6efd'],
            ['Unique Buyers',       'stat-customers', '#0d6efd'],
        ];
        foreach ($cards as $c): ?>
            <div class="col-6 col-md-3">
                <div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                    <div class="card-body p-3 text-center">
                        <p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                        <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:<?= $c[2] ?>;">—</h4>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts (screen + print) -->
    <div class="row g-3 mb-4" id="chartRow">
        <div class="col-12 col-md-5">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Revenue Trend</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartTrend"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>By Status</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartStatus"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-people text-primary me-2"></i>Top Customers</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartCustomers"></canvas></div></div>
            </div>
        </div>
    </div>

    <!-- Detail table -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-receipt me-2"></i>Transactions</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="salesTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">S/No</th>
                            <th>Ref #</th>
                            <th>Date</th>
                            <th>Due Date</th>
                            <th>Customer</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Paid</th>
                            <th class="text-center">Status</th>
                            <th>Payment Method</th>
                            <th class="text-center">Source</th>
                            <th class="pe-3">Salesperson / Cashier</th>
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
    #salesTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
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
        #salesTable { border: 1px solid #000 !important; }
        #salesTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #salesTable td { border: 1px solid #dee2e6 !important; }
        .badge-status { border: 1px solid #999 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_sales_report.php') ?>';
    const CUST_URL = '<?= buildUrl('api/account/search_customers.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Blue-scale status badge (per ui-constants §UI-1)
    const STATUS_BG = { paid:'#052c65', partial:'#cfe2ff', unpaid:'#dc3545', overdue:'#dc3545', completed:'#052c65', draft:'#e9ecef', pending:'#e9ecef' };
    const STATUS_FG = { paid:'#fff', partial:'#084298', unpaid:'#fff', overdue:'#fff', completed:'#fff', draft:'#495057', pending:'#495057' };
    function statusBadge(s) {
        const k = (s || '').toLowerCase();
        const bg = STATUS_BG[k] || '#0d6efd', fg = STATUS_FG[k] || '#fff';
        return `<span class="badge-status" style="background:${bg};color:${fg};">${(s || '').toUpperCase()}</span>`;
    }
    function sourceBadge(s) {
        return s === 'POS'
            ? `<span class="badge-status" style="background:#17a2b8;color:#fff;">POS</span>`
            : `<span class="badge-status" style="background:#0d6efd;color:#fff;">INV</span>`;
    }

    // ── Select2 filters ───────────────────────────────────────────────────
    $('#f-customer').select2({
        theme: 'bootstrap-5', placeholder: 'All Customers', allowClear: true, width: '100%',
        ajax: { url: CUST_URL, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d, cache: true }
    });
    $('#f-project, #f-salesperson, #f-status, #f-source').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    // ── DataTable (per §UI-2) ─────────────────────────────────────────────
    const table = $('#salesTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: [5, 6], className: 'text-end' }, { targets: [7, 9], className: 'text-center' }],
        language: { emptyTable: 'No sales records found.', zeroRecords: 'No matching records.' }
    });

    // ── Charts ────────────────────────────────────────────────────────────
    let cTrend, cStatus, cCustomers;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(charts) {
        [cTrend, cStatus, cCustomers].forEach(c => c && c.destroy());

        cTrend = new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: { labels: charts.revenue_trend.map(r => r.label),
                    datasets: [{ label: 'Revenue', data: charts.revenue_trend.map(r => +r.value),
                                 borderColor: BLUE, backgroundColor: 'rgba(13,110,253,.12)', fill: true, tension: .3, pointRadius: 2 }] },
            options: { ...baseOpts, plugins: { legend: { display: false } }, scales: { y: { ticks: { font: { size: 9 } } }, x: { ticks: { font: { size: 9 } } } } }
        });

        const blues = ['#0d6efd', '#052c65', '#6ea8fe', '#cfe2ff', '#1e3a8a', '#9ec5fe'];
        cStatus = new Chart(document.getElementById('chartStatus'), {
            type: 'doughnut',
            data: { labels: charts.by_status.map(r => (r.status || '').toUpperCase()),
                    datasets: [{ data: charts.by_status.map(r => +r.total), backgroundColor: blues }] },
            options: { ...baseOpts }
        });

        cCustomers = new Chart(document.getElementById('chartCustomers'), {
            type: 'bar',
            data: { labels: charts.top_customers.map(r => r.name),
                    datasets: [{ label: 'Revenue', data: charts.top_customers.map(r => +r.total), backgroundColor: BLUE }] },
            options: { ...baseOpts, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { font: { size: 9 } } }, y: { ticks: { font: { size: 9 } } } } }
        });
    }

    // ── Load (AJAX) ───────────────────────────────────────────────────────
    function loadReport() {
        const params = {
            date_from: $('#f-from').val(), date_to: $('#f-to').val(),
            project_id: $('#f-project').val() || '',
            customer_id: $('#f-customer').val() || '', salesperson_id: $('#f-salesperson').val() || '',
            status: $('#f-status').val() || '',
            source: $('#f-source').val() || ''
        };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the report.' });
                    return;
                }
                $('#stat-sales').text(fmt(res.summary.total_sales));
                $('#stat-paid').text(fmt(res.summary.total_paid));
                $('#stat-due').text(fmt(res.summary.total_due));
                $('#stat-customers').text(res.summary.unique_customers);

                renderCharts(res.charts);

                const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                table.clear();
                res.rows.forEach((r, i) => table.row.add([
                    i + 1,
                    esc(r.ref_number || ''),
                    r.sale_date ? new Date(r.sale_date).toLocaleDateString() : '',
                    r.due_date  ? new Date(r.due_date).toLocaleDateString()  : '—',
                    esc(r.customer_name || 'Walk-in'),
                    fmt(r.grand_total),
                    fmt(r.paid_amount),
                    statusBadge(r.status),
                    esc(r.payment_method || '—'),
                    sourceBadge(r.source),
                    esc((r.salesperson || '').trim() || '—')
                ]));
                table.draw();
                adjustColumns(params.source);
            })
            .fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error loading the report.' }));
    }

    // Show/hide columns that are irrelevant for the active source.
    // col 3 = Due Date (invoices only), col 8 = Payment Method (POS only),
    // col 9 = Source badge (only useful when both sources are mixed).
    function adjustColumns(src) {
        table.column(3).visible(src !== 'pos');     // Due Date — hide for POS-only
        table.column(8).visible(src !== 'invoice'); // Payment Method — hide for Invoice-only
        table.column(9).visible(src === '');        // Source badge — only when both are shown
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project, #f-customer, #f-salesperson, #f-status, #f-source').on('change', loadReport);

    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Sales Report', 'Loaded sales report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
