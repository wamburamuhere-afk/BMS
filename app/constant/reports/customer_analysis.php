<?php
// app/constant/reports/customer_analysis.php
// Professional Customer Analysis — AJAX (get_customer_analysis_report.php),
// Chart.js charts that also print, DataTable, Select2 filters, project scope.
// Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md §23.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('customer_analysis');

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
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">CUSTOMER ANALYSIS REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Period: <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <!-- Screen header + actions -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-people me-2"></i>Customer Analysis</h2>
            <p class="text-muted mb-0">Purchasing behaviour and revenue contribution</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> Print
            </button>
        </div>
    </div>

    <!-- Filters (AJAX) -->
    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                    <input type="date" name="date_from" id="f-from" class="form-control" value="<?= htmlspecialchars($date_from) ?>"></div>
                <div class="col-md-3"><label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                    <input type="date" name="date_to" id="f-to" class="form-control" value="<?= htmlspecialchars($date_to) ?>"></div>
                <div class="col-md-4"><label class="form-label small fw-bold text-muted text-uppercase mb-1">Project</label>
                    <select name="project_id" id="f-project" class="form-select" style="width:100%">
                        <option value="">All My Projects</option>
                        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter me-1"></i> Apply</button></div>
            </form>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4" id="summaryCards">
        <?php foreach ([['Active Customers','stat-customers'],['Total Revenue','stat-revenue'],['Avg / Customer','stat-avg'],['Total Orders','stat-orders']] as $c): ?>
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

    <!-- Charts -->
    <div class="row g-3 mb-4" id="chartRow">
        <div class="col-12 col-md-5">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Top Customers by Revenue</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartTop"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>Revenue Concentration</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartConc"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-graph-up text-primary me-2"></i>Monthly Revenue</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartMonthly"></canvas></div></div>
            </div>
        </div>
    </div>

    <!-- Detail table -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-person-lines-fill me-2"></i>Customers</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="custTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">S/No</th>
                            <th>Customer</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Avg Order</th>
                            <th>Last Order</th>
                            <th class="pe-3 text-end">Total Spent</th>
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
    #custTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        .table-responsive { overflow: visible !important; }
        .dataTables_scroll, .dataTables_scrollHead, .dataTables_scrollBody { overflow: visible !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #chartRow .card, #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        canvas { print-color-adjust: exact; -webkit-print-color-adjust: exact; max-width: 100% !important; }
        #custTable { border: 1px solid #000 !important; }
        #custTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #custTable td { border: 1px solid #dee2e6 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_customer_analysis_report.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    $('#f-project').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    const table = $('#custTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: [2, 3, 5], className: 'text-end' }],
        language: { emptyTable: 'No customer data found.', zeroRecords: 'No matching records.' }
    });

    let cTop, cConc, cMonthly;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(charts) {
        [cTop, cConc, cMonthly].forEach(c => c && c.destroy());
        const blues = ['#0d6efd', '#052c65', '#6ea8fe', '#cfe2ff', '#1e3a8a', '#9ec5fe'];

        cTop = new Chart(document.getElementById('chartTop'), {
            type: 'bar',
            data: { labels: charts.top_customers.map(r => r.name), datasets: [{ label: 'Revenue', data: charts.top_customers.map(r => +r.total), backgroundColor: BLUE }] },
            options: { ...baseOpts, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { font: { size: 9 } } }, y: { ticks: { font: { size: 9 } } } } }
        });
        cConc = new Chart(document.getElementById('chartConc'), {
            type: 'doughnut',
            data: { labels: charts.concentration.map(r => r.label), datasets: [{ data: charts.concentration.map(r => +r.value), backgroundColor: blues }] },
            options: { ...baseOpts }
        });
        cMonthly = new Chart(document.getElementById('chartMonthly'), {
            type: 'line',
            data: { labels: charts.monthly.map(r => r.label), datasets: [{ label: 'Revenue', data: charts.monthly.map(r => +r.value), borderColor: BLUE, backgroundColor: 'rgba(13,110,253,.12)', fill: true, tension: .3, pointRadius: 2 }] },
            options: { ...baseOpts, plugins: { legend: { display: false } }, scales: { y: { ticks: { font: { size: 9 } } }, x: { ticks: { font: { size: 9 } } } } }
        });
    }

    function loadReport() {
        const params = { date_from: $('#f-from').val(), date_to: $('#f-to').val(), project_id: $('#f-project').val() || '' };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) { Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the report.' }); return; }
                $('#stat-customers').text(Number(res.summary.active_customers).toLocaleString());
                $('#stat-revenue').text(fmt(res.summary.total_revenue));
                $('#stat-avg').text(fmt(res.summary.avg_per_customer));
                $('#stat-orders').text(Number(res.summary.total_orders).toLocaleString());
                renderCharts(res.charts);
                table.clear();
                res.rows.forEach((r, i) => table.row.add([
                    i + 1, r.customer_name || 'Walk-in',
                    Number(r.total_orders).toLocaleString(), fmt(r.avg_order),
                    r.last_order ? new Date(r.last_order).toLocaleDateString() : '—', fmt(r.total_spent)
                ]));
                table.draw();
            })
            .fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error loading the report.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project').on('change', loadReport);
    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Customer Analysis', 'Loaded customer analysis report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
