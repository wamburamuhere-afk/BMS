<?php
// app/constant/reports/sales_forecast.php
// Professional Sales Forecast — AJAX (get_sales_forecast_report.php), Chart.js
// charts that also print, DataTable, Select2 + Project scope.
// Baseline moving-average projection with conservative/optimistic bands.
// Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md §23.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('sales_forecast');

$projects = $pdo->query(
    "SELECT project_id, project_name FROM projects
      WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
      ORDER BY project_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$currency = get_setting('currency', 'TZS');
?>

<div class="container-fluid py-4">
    <!-- Print Header — title only (global header renders company logo/name once). -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">SALES FORECASTING REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Baseline moving-average projection</p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Sales Forecast</h2>
            <p class="text-muted mb-0">Projected revenue from historical patterns</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()"><i class="bi bi-printer me-2"></i> Print</button>
        </div>
    </div>

    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label small fw-bold text-muted text-uppercase mb-1">Horizon</label>
                    <select name="horizon" id="f-horizon" class="form-select" style="width:100%">
                        <option value="3">Next 3 months</option>
                        <option value="6" selected>Next 6 months</option>
                        <option value="12">Next 12 months</option>
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
        <?php foreach ([['Avg Monthly Sales','stat-avg'],['Trailing 12m Total','stat-trailing'],['Forecast Horizon','stat-horizon'],['Projected Total','stat-projected']] as $c): ?>
            <div class="col-6 col-md-3"><div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                <div class="card-body p-3 text-center"><p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h4></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4" id="chartRow">
        <div class="col-12"><div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-graph-up text-primary me-2"></i>Historical &amp; Projected Revenue</div>
            <div class="card-body"><div style="height:280px;"><canvas id="chartForecast"></canvas></div></div></div></div>
    </div>

    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-table me-2"></i>Forecast Detail</h6></div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="fcTable">
                <thead class="table-light"><tr>
                    <th class="ps-3">S/No</th><th>Forecast Month</th>
                    <th class="text-end">Conservative (-15%)</th><th class="text-end">Baseline</th><th class="pe-3 text-end">Optimistic (+15%)</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div></div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    #fcTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
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
        #fcTable { border: 1px solid #000 !important; }
        #fcTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #fcTable td { border: 1px solid #dee2e6 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_sales_forecast_report.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    $('#f-horizon, #f-project').select2({ theme: 'bootstrap-5', allowClear: false, width: '100%' });

    const table = $('#fcTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: [2, 3, 4], className: 'text-end' }],
        language: { emptyTable: 'Not enough history to forecast.', zeroRecords: 'No matching records.' }
    });

    let cForecast;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(charts) {
        if (cForecast) cForecast.destroy();
        const histLabels = charts.historical.map(r => r.label);
        const fcLabels   = charts.forecast.map(r => r.month);
        const labels = histLabels.concat(fcLabels);
        const histData = charts.historical.map(r => +r.value).concat(fcLabels.map(() => null));
        const pad = histLabels.map(() => null);
        const baseData = pad.concat(charts.forecast.map(r => +r.projection));
        const consData = pad.concat(charts.forecast.map(r => +r.conservative));
        const optiData = pad.concat(charts.forecast.map(r => +r.optimistic));

        cForecast = new Chart(document.getElementById('chartForecast'), {
            type: 'line',
            data: { labels, datasets: [
                { label:'Historical',   data: histData, borderColor: BLUE,      backgroundColor:'rgba(13,110,253,.10)', fill:true, tension:.3, pointRadius:2 },
                { label:'Baseline',     data: baseData, borderColor: '#052c65', borderDash:[6,4], tension:.3, pointRadius:2 },
                { label:'Conservative', data: consData, borderColor: '#9ec5fe', borderDash:[3,3], tension:.3, pointRadius:0 },
                { label:'Optimistic',   data: optiData, borderColor: '#6ea8fe', borderDash:[3,3], tension:.3, pointRadius:0 }
            ] },
            options: { ...baseOpts, spanGaps: true, scales:{y:{ticks:{font:{size:9}}},x:{ticks:{font:{size:9}}}} } });
    }

    function loadReport() {
        const params = { horizon: $('#f-horizon').val() || '6', project_id: $('#f-project').val() || '' };
        $.getJSON(DATA_URL, params).done(function (res) {
            if (!res || !res.success) { Swal.fire({ icon:'error', title:'Error', text:(res&&res.message)||'Could not load the report.' }); return; }
            $('#stat-avg').text(fmt(res.summary.avg_monthly));
            $('#stat-trailing').text(fmt(res.summary.trailing_total));
            $('#stat-horizon').text(res.summary.horizon + ' months');
            $('#stat-projected').text(fmt(res.summary.projected_total));
            renderCharts(res.charts);
            table.clear();
            res.rows.forEach((r, i) => table.row.add([
                i + 1, r.month || '', fmt(r.conservative), fmt(r.projection), fmt(r.optimistic)
            ]));
            table.draw();
        }).fail(() => Swal.fire({ icon:'error', title:'Error', text:'Server error loading the report.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-horizon, #f-project').on('change', loadReport);
    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Sales Forecast', 'Loaded sales forecast report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
