<?php
/**
 * app/constant/reports/pos_profit_report.php
 *
 * Profit Report — Simple POS only. Gross/net profit for a chosen date range,
 * read from the one canonical ledger via glProfitLoss() (money.md F3,
 * .claude/reporting-source.md) — never raw POS/expense tables. AJAX
 * (get_profit_report.php), Chart.js, project/warehouse scope.
 * Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md §23.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
require_once __DIR__ . '/../../../core/pos_nav.php';
includeHeader();

autoEnforcePermission('pos_profit_report');
if (!canView('pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
if (!posSimpleModeEnabled()) {
    header('Location: ' . getUrl('pos_dashboard'));
    exit();
}

$projects = projectsForSelect($pdo);

$warehouses = tenantFeatureEnabled('warehouses') ? $pdo->query(
    "SELECT warehouse_id, warehouse_name FROM warehouses
      WHERE status = 'active' " . scopeFilterSql('warehouse', 'warehouses') . "
      ORDER BY warehouse_name ASC"
)->fetchAll(PDO::FETCH_ASSOC) : [];

$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$currency  = get_setting('currency', 'TZS');
?>

<div class="container-fluid py-4">
    <!-- Print Header (title only — borders/footer come from i_e_print.md) -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;"><?= t('PROFIT REPORT') ?></h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;"><?= t('Period:') ?> <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;"><?= t('Generated:') ?> <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <!-- Screen header + actions -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-graph-up-arrow me-2"></i><?= t('Profit Report') ?></h2>
            <p class="text-muted mb-0"><?= t('Profit earned for the period you choose') ?></p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> <?= t('Print') ?>
            </button>
        </div>
    </div>

    <!-- Filters (AJAX — no page reload) -->
    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('From') ?></label>
                    <input type="date" name="date_from" id="f-from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('To') ?></label>
                    <input type="date" name="date_to" id="f-to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <?php if (projectsModuleActive()): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('Project') ?></label>
                    <select name="project_id" id="f-project" class="form-select" style="width:100%">
                        <option value=""><?= t('All My Projects') ?></option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (tenantFeatureEnabled('warehouses')): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= wLabel('Warehouse', 'Shop') ?></label>
                    <select name="warehouse_id" id="f-warehouse" class="form-select" style="width:100%">
                        <option value=""><?= wLabel('All Warehouses', 'All Shops') ?></option>
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?= (int)$w['warehouse_id'] ?>"><?= safe_output($w['warehouse_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Summary cards (screen + print) -->
    <div class="row g-3 mb-4" id="summaryCards">
        <?php
        $cards = [
            [t('Total Sales'),        'stat-revenue'],
            [t('Cost of Goods'),      'stat-cogs'],
            [t('Gross Profit'),       'stat-gross'],
            [t('Total Expenses'),     'stat-expense'],
            [t('Net Profit'),         'stat-net'],
            [t('Net Margin'),         'stat-margin'],
        ];
        foreach ($cards as $c): ?>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                    <div class="card-body p-3 text-center">
                        <p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                        <h5 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h5>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Chart (screen + print) -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-graph-up text-primary me-2"></i><?= t('Monthly Trend') ?></div>
                <div class="card-body"><div style="height:280px;"><canvas id="chartTrend"></canvas></div></div>
            </div>
        </div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        canvas { print-color-adjust: exact; -webkit-print-color-adjust: exact; max-width: 100% !important; }
    }
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const PT = {
        error: <?= json_encode(t('Error')) ?>,
        couldNotLoadReport: <?= json_encode(t('Could not load the report.')) ?>,
        serverErrorLoadingReport: <?= json_encode(t('Server error loading the report.')) ?>,
        revenue: <?= json_encode(t('Sales')) ?>,
        cogs: <?= json_encode(t('Cost of Goods')) ?>,
        netProfit: <?= json_encode(t('Net Profit')) ?>,
    };
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_profit_report.php') ?>';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    $('#f-project, #f-warehouse').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    let cTrend;
    function renderChart(trend) {
        if (cTrend) cTrend.destroy();
        cTrend = new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: {
                labels: trend.map(r => r.label),
                datasets: [
                    { label: PT.revenue, data: trend.map(r => +r.revenue), borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.12)', tension: .3, pointRadius: 2 },
                    { label: PT.cogs, data: trend.map(r => +r.cogs), borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.08)', tension: .3, pointRadius: 2 },
                    { label: PT.netProfit, data: trend.map(r => +r.net_profit), borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.10)', fill: true, tension: .3, pointRadius: 2 },
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, animation: false,
                       plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } },
                       scales: { y: { ticks: { font: { size: 9 } } }, x: { ticks: { font: { size: 9 } } } } }
        });
    }

    function loadReport() {
        const params = {
            date_from: $('#f-from').val(), date_to: $('#f-to').val(),
            project_id: $('#f-project').val() || '',
            warehouse_id: $('#f-warehouse').val() || ''
        };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: PT.error, text: (res && res.message) || PT.couldNotLoadReport });
                    return;
                }
                $('#stat-revenue').text(fmt(res.summary.total_revenue));
                $('#stat-cogs').text(fmt(res.summary.total_cogs));
                $('#stat-gross').text(fmt(res.summary.gross_profit));
                $('#stat-expense').text(fmt(res.summary.total_expense));
                $('#stat-net').text(fmt(res.summary.net_profit));
                $('#stat-margin').text(Number(res.summary.net_margin_pct).toLocaleString(undefined, { maximumFractionDigits: 1 }) + '%');

                renderChart(res.trend);
            })
            .fail(() => Swal.fire({ icon: 'error', title: PT.error, text: PT.serverErrorLoadingReport }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project, #f-warehouse').on('change', loadReport);

    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Profit Report', 'Loaded profit report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
