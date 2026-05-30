<?php
// app/constant/reports/performance_dashboard.php
// Professional Business Performance dashboard — AJAX (get_performance_report.php),
// Chart.js charts that also print, DataTable monthly breakdown, Select2 filters,
// project-scope security. Standards: .claude/ui-constants.md, i_e_print.md,
// .claude/security.md §23.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('performance_dashboard');

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
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">BUSINESS PERFORMANCE DASHBOARD</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Period: <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <!-- Screen header + actions -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-speedometer2 me-2"></i>Business Performance</h2>
            <p class="text-muted mb-0">Executive view of revenue, cost and profitability</p>
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
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                    <input type="date" name="date_from" id="f-from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                    <input type="date" name="date_to" id="f-to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Project</label>
                    <select name="project_id" id="f-project" class="form-select" style="width:100%">
                        <option value="">All My Projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter me-1"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary cards (screen + print) -->
    <div class="row g-3 mb-4" id="summaryCards">
        <?php
        $cards = [
            ['Total Revenue',      'stat-revenue'],
            ['Direct Costs',       'stat-costs'],
            ['Operating Expenses', 'stat-expenses'],
            ['Net Profit',         'stat-net'],
        ];
        foreach ($cards as $c): ?>
            <div class="col-6 col-md-3">
                <div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                    <div class="card-body p-3 text-center">
                        <p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                        <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h4>
                        <?php if ($c[1] === 'stat-net'): ?><span class="small text-muted fw-bold" id="stat-margin">—</span><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts (screen + print) -->
    <div class="row g-3 mb-4" id="chartRow">
        <div class="col-12 col-md-5">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-graph-up text-primary me-2"></i>Revenue vs Net Profit (Monthly)</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartTrend"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart text-primary me-2"></i>Financial Comparison</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartCompare"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>Cost Structure</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartBreakdown"></canvas></div></div>
            </div>
        </div>
    </div>

    <!-- Monthly breakdown table -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-table me-2"></i>Monthly Breakdown</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="perfTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">S/No</th>
                            <th>Month</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Direct Costs</th>
                            <th class="text-end">Expenses</th>
                            <th class="text-end">Net Profit</th>
                            <th class="pe-3 text-end">Margin %</th>
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
    #perfTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        /* Column-alignment on print: keep the table whole (no scrollX split) and
           let the responsive wrapper show everything so headers stay over data. */
        .table-responsive { overflow: visible !important; }
        .dataTables_scroll, .dataTables_scrollHead, .dataTables_scrollBody { overflow: visible !important; }
        /* Blank-first-page fix: zero ONLY top spacing (navbar reserve); never
           touch padding-bottom (print_footer_css.php needs it). */
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #chartRow .card, #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        canvas { print-color-adjust: exact; -webkit-print-color-adjust: exact; max-width: 100% !important; }
        #perfTable { border: 1px solid #000 !important; }
        #perfTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #perfTable td { border: 1px solid #dee2e6 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_performance_report.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    $('#f-project').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    const table = $('#perfTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: [2, 3, 4, 5, 6], className: 'text-end' }],
        language: { emptyTable: 'No performance data for this period.', zeroRecords: 'No matching records.' }
    });

    let cTrend, cCompare, cBreakdown;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(charts) {
        [cTrend, cCompare, cBreakdown].forEach(c => c && c.destroy());

        cTrend = new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: { labels: charts.trend.map(r => r.label),
                    datasets: [
                        { label: 'Revenue',    data: charts.trend.map(r => +r.revenue), borderColor: BLUE,      backgroundColor: 'rgba(13,110,253,.12)', fill: true, tension: .3, pointRadius: 2 },
                        { label: 'Net Profit', data: charts.trend.map(r => +r.net),     borderColor: '#052c65', backgroundColor: 'rgba(5,44,101,.08)',   fill: false, tension: .3, pointRadius: 2 }
                    ] },
            options: { ...baseOpts, scales: { y: { ticks: { font: { size: 9 } } }, x: { ticks: { font: { size: 9 } } } } }
        });

        cCompare = new Chart(document.getElementById('chartCompare'), {
            type: 'bar',
            data: { labels: charts.comparison.map(r => r.label),
                    datasets: [{ label: 'Amount', data: charts.comparison.map(r => +r.value), backgroundColor: ['#0d6efd', '#6ea8fe', '#cfe2ff', '#052c65'] }] },
            options: { ...baseOpts, plugins: { legend: { display: false } }, scales: { y: { ticks: { font: { size: 9 } } }, x: { ticks: { font: { size: 9 } } } } }
        });

        cBreakdown = new Chart(document.getElementById('chartBreakdown'), {
            type: 'doughnut',
            data: { labels: charts.breakdown.map(r => r.label),
                    datasets: [{ data: charts.breakdown.map(r => +r.value), backgroundColor: ['#6ea8fe', '#cfe2ff', '#052c65'] }] },
            options: { ...baseOpts }
        });
    }

    function loadReport() {
        const params = { date_from: $('#f-from').val(), date_to: $('#f-to').val(), project_id: $('#f-project').val() || '' };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the dashboard.' });
                    return;
                }
                $('#stat-revenue').text(fmt(res.summary.revenue));
                $('#stat-costs').text(fmt(res.summary.direct_costs));
                $('#stat-expenses').text(fmt(res.summary.expenses));
                $('#stat-net').text(fmt(res.summary.net_profit));
                $('#stat-margin').text('Margin ' + Number(res.summary.margin).toFixed(1) + '%');

                renderCharts(res.charts);

                table.clear();
                res.rows.forEach((r, i) => table.row.add([
                    i + 1,
                    r.month || '',
                    fmt(r.revenue),
                    fmt(r.direct_costs),
                    fmt(r.expenses),
                    fmt(r.net_profit),
                    Number(r.margin).toFixed(1) + '%'
                ]));
                table.draw();
            })
            .fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error loading the dashboard.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project').on('change', loadReport);

    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Performance Dashboard', 'Loaded business performance dashboard');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
