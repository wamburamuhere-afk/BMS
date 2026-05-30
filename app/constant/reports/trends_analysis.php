<?php
// app/constant/reports/trends_analysis.php
// Professional Historical Trends — AJAX (get_trends_report.php), Chart.js
// charts that also print, DataTable, Select2 + Project scope.
// Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md §23.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('trends_analysis');

$projects = $pdo->query(
    "SELECT project_id, project_name FROM projects
      WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
      ORDER BY project_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$currency = get_setting('currency', 'TZS');
?>

<div class="container-fluid py-4">
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">HISTORICAL TRENDS ANALYSIS</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Sales, Expenses &amp; Profit over time</p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-activity me-2"></i>Trends Analysis</h2>
            <p class="text-muted mb-0">Sales, expenses and profit movement over time</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()"><i class="bi bi-printer me-2"></i> Print</button>
        </div>
    </div>

    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label small fw-bold text-muted text-uppercase mb-1">Window</label>
                    <select name="months" id="f-months" class="form-select" style="width:100%">
                        <option value="6">Last 6 months</option>
                        <option value="12" selected>Last 12 months</option>
                        <option value="24">Last 24 months</option>
                    </select></div>
                <div class="col-md-5"><label class="form-label small fw-bold text-muted text-uppercase mb-1">Project</label>
                    <select name="project_id" id="f-project" class="form-select" style="width:100%">
                        <option value="">All My Projects</option>
                        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter me-1"></i> Apply</button></div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4" id="summaryCards">
        <?php foreach ([['Total Sales','stat-sales'],['Total Expenses','stat-expenses'],['Net Profit','stat-profit'],['Avg Monthly Sales','stat-avg']] as $c): ?>
            <div class="col-6 col-md-3"><div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                <div class="card-body p-3 text-center"><p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h4></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4" id="chartRow">
        <div class="col-12 col-md-7"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-graph-up text-primary me-2"></i>Sales vs Expenses vs Profit</div>
            <div class="card-body"><div style="height:260px;"><canvas id="chartTrend"></canvas></div></div></div></div>
        <div class="col-12 col-md-5"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart text-primary me-2"></i>Monthly Profit</div>
            <div class="card-body"><div style="height:260px;"><canvas id="chartProfit"></canvas></div></div></div></div>
    </div>

    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-table me-2"></i>Monthly Trend</h6></div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="trendTable">
                <thead class="table-light"><tr>
                    <th class="ps-3">S/No</th><th>Month</th>
                    <th class="text-end">Sales</th><th class="text-end">Expenses</th><th class="pe-3 text-end">Profit / Loss</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div></div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    #trendTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
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
        #trendTable { border: 1px solid #000 !important; }
        #trendTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #trendTable td { border: 1px solid #dee2e6 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_trends_report.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    $('#f-months, #f-project').select2({ theme: 'bootstrap-5', allowClear: false, width: '100%' });

    const table = $('#trendTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: [2, 3, 4], className: 'text-end' }],
        language: { emptyTable: 'No trend data found.', zeroRecords: 'No matching records.' }
    });

    let cTrend, cProfit;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(charts) {
        [cTrend, cProfit].forEach(c => c && c.destroy());
        cTrend = new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: { labels: charts.trend.map(r=>r.label), datasets: [
                { label:'Sales',    data: charts.trend.map(r=>+r.sales),    borderColor: BLUE,      backgroundColor:'rgba(13,110,253,.10)', fill:true,  tension:.3, pointRadius:2 },
                { label:'Expenses', data: charts.trend.map(r=>+r.expenses), borderColor: '#dc3545', backgroundColor:'rgba(220,53,69,.06)',  fill:false, tension:.3, pointRadius:2 },
                { label:'Profit',   data: charts.trend.map(r=>+r.profit),   borderColor: '#052c65', backgroundColor:'rgba(5,44,101,.06)',   fill:false, tension:.3, pointRadius:2 }
            ] },
            options: { ...baseOpts, scales:{y:{ticks:{font:{size:9}}},x:{ticks:{font:{size:9}}}} } });
        cProfit = new Chart(document.getElementById('chartProfit'), {
            type: 'bar',
            data: { labels: charts.trend.map(r=>r.label), datasets: [{ label:'Profit', data: charts.trend.map(r=>+r.profit),
                    backgroundColor: charts.trend.map(r => (+r.profit) >= 0 ? BLUE : '#dc3545') }] },
            options: { ...baseOpts, plugins:{legend:{display:false}}, scales:{y:{ticks:{font:{size:9}}},x:{ticks:{font:{size:9}}}} } });
    }

    function loadReport() {
        const params = { months: $('#f-months').val() || '12', project_id: $('#f-project').val() || '' };
        $.getJSON(DATA_URL, params).done(function (res) {
            if (!res || !res.success) { Swal.fire({ icon:'error', title:'Error', text:(res&&res.message)||'Could not load the report.' }); return; }
            $('#stat-sales').text(fmt(res.summary.total_sales));
            $('#stat-expenses').text(fmt(res.summary.total_expenses));
            $('#stat-profit').text(fmt(res.summary.total_profit));
            $('#stat-avg').text(fmt(res.summary.avg_monthly));
            renderCharts(res.charts);
            table.clear();
            res.rows.forEach((r, i) => table.row.add([
                i + 1, r.month || '', fmt(r.sales), fmt(r.expenses), fmt(r.profit)
            ]));
            table.draw();
        }).fail(() => Swal.fire({ icon:'error', title:'Error', text:'Server error loading the report.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-months, #f-project').on('change', loadReport);
    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Trends Analysis', 'Loaded historical trends report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
