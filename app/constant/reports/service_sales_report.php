<?php
// app/constant/reports/service_sales_report.php
// Service Sales — which services sell most, their trend, categories and how
// quotations convert. Data: api/account/get_service_sales_report.php (posted
// invoices + POS). Standards: .claude/ui-constants.md, i_e_print.md, security.md §23.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('service_sales_report');

$projects   = projectsForSelect($pdo);
$categories = $pdo->query("SELECT DISTINCT c.category_id, c.category_name
                             FROM categories c JOIN products p ON p.category_id = c.category_id AND p.is_service = 1
                            WHERE c.status = 'active' ORDER BY c.category_name")->fetchAll(PDO::FETCH_ASSOC);

$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? date('Y-12-31');
$currency  = get_setting('currency', 'TZS');
$posOn     = function_exists('tenantFeatureEnabled') ? tenantFeatureEnabled('pos') : true;
?>

<div class="container-fluid py-4">
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;"><?= t('Service Sales Report') ?></h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;"><?= t('Period') ?>: <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;"><?= t('Generated') ?>: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none" style="position:sticky;top:0;z-index:1020;background:#fff;">
        <div class="col-md-7">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-briefcase me-2"></i><?= t('Service Sales Report') ?></h2>
            <p class="text-muted mb-0"><?= t('Which services sell most, how they trend, and how quotations turn into sales') ?></p>
        </div>
        <div class="col-md-5 text-end">
            <button class="btn btn-outline-primary shadow-sm px-3 fw-bold me-1" id="btnExcel"><i class="bi bi-download me-1"></i> Excel</button>
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()"><i class="bi bi-printer me-2"></i> <?= t('Print') ?></button>
        </div>
    </div>

    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-6 col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('From') ?></label>
                    <input type="date" id="f-from" class="form-control" value="<?= htmlspecialchars($date_from) ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('To') ?></label>
                    <input type="date" id="f-to" class="form-control" value="<?= htmlspecialchars($date_to) ?>"></div>
                <?php if ($posOn): ?>
                <div class="col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('Source') ?></label>
                    <select id="f-source" class="form-select" style="width:100%">
                        <option value=""><?= t('Invoices + POS') ?></option>
                        <option value="invoice"><?= t('Invoices only') ?></option>
                        <option value="pos"><?= t('POS only') ?></option>
                    </select></div>
                <?php endif; ?>
                <div class="col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('Customer') ?></label>
                    <select id="f-customer" class="form-select" style="width:100%"></select></div>
                <div class="col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('Category') ?></label>
                    <select id="f-category" class="form-select" style="width:100%">
                        <option value=""><?= t('All Categories') ?></option>
                        <?php foreach ($categories as $c): ?><option value="<?= (int)$c['category_id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <?php if (projectsModuleActive()): ?>
                <div class="col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('Project') ?></label>
                    <select id="f-project" class="form-select" style="width:100%">
                        <option value=""><?= t('All My Projects') ?></option>
                        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['project_id'] ?>"><?= caseFormat($p['project_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <?php endif; ?>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter me-1"></i> <?= t('Apply') ?></button></div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4" id="summaryCards">
        <?php foreach ([[t('Net Revenue'), 'stat-net'], [t('VAT Charged'), 'stat-vat'], [t('Documents'), 'stat-docs'], [t('Customers'), 'stat-cust']] as $c): ?>
            <div class="col-6 col-md-3"><div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                <div class="card-body p-3 text-center"><p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h4></div></div></div>
        <?php endforeach; ?>
        <div class="col-12"><div class="small text-muted" id="topServiceNote"></div></div>
    </div>

    <div class="row g-3 mb-4 chart-row">
        <div class="col-12 col-lg-6"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart-line text-primary me-2"></i><?= t('Top Services by Revenue') ?></div>
            <div class="card-body"><div style="height:260px;"><canvas id="chartTop"></canvas></div></div></div></div>
        <div class="col-12 col-lg-6"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-graph-up text-primary me-2"></i><?= t('Monthly Trend (top 5 services)') ?></div>
            <div class="card-body"><div style="height:260px;"><canvas id="chartTrend"></canvas></div></div></div></div>
    </div>
    <div class="row g-3 mb-4 chart-row">
        <div class="col-12 col-lg-4"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i><?= t('Revenue by Category') ?></div>
            <div class="card-body"><div style="height:240px;"><canvas id="chartCat"></canvas></div></div></div></div>
        <div class="col-12 col-lg-8"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-arrow-left-right text-primary me-2"></i><?= t('Quoted vs Invoiced') ?>
                <span class="text-muted fw-normal small ms-1">(<?= t('quotations in the period; conversion = quotations turned into sales orders') ?>)</span></div>
            <div class="card-body"><div style="height:240px;"><canvas id="chartConv"></canvas></div></div></div></div>
    </div>

    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-list-ul me-2"></i><?= t('Services') ?></h6>
            <span class="small text-muted"><?= t('Invoices count once posted to the ledger; cancelled invoices are excluded.') ?></span>
        </div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="svcTable">
                <thead class="table-light"><tr>
                    <th class="ps-3"><?= t('S/No') ?></th><th><?= t('Code') ?></th><th><?= t('Service') ?></th><th><?= t('Category') ?></th>
                    <th class="text-end"><?= t('Qty') ?></th><th class="text-end"><?= t('Documents') ?></th><th class="text-end"><?= t('Customers') ?></th>
                    <th class="text-end"><?= t('Gross') ?></th><th class="text-end"><?= t('Discount') ?></th><th class="text-end"><?= t('Net Revenue') ?></th>
                    <th class="text-end"><?= t('VAT') ?></th><th class="text-end"><?= t('Share') ?></th><th class="text-end"><?= t('Avg Price') ?></th><th class="pe-3"><?= t('Last Sold') ?></th>
                </tr></thead>
                <tbody></tbody>
                <tfoot class="table-light fw-bold"><tr>
                    <td class="ps-3"></td><td></td><td><?= t('Total') ?></td><td></td>
                    <td class="text-end" id="ft-qty"></td><td></td><td></td>
                    <td class="text-end" id="ft-gross"></td><td class="text-end" id="ft-disc"></td><td class="text-end" id="ft-net"></td>
                    <td class="text-end" id="ft-vat"></td><td class="text-end">100%</td><td></td><td class="pe-3"></td>
                </tr></tfoot>
            </table>
        </div></div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    #svcTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; white-space: nowrap; }
    #svcTable td { white-space: nowrap; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length, .dt-buttons { display: none !important; }
        .table-responsive { overflow: visible !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .chart-row .card, #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        canvas { print-color-adjust: exact; -webkit-print-color-adjust: exact; max-width: 100% !important; }
        #svcTable { border: 1px solid #000 !important; font-size: 8pt; }
        #svcTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #svcTable td { border: 1px solid #dee2e6 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = <?= json_encode($currency) ?>;
    const DATA_URL = '<?= buildUrl('api/account/get_service_sales_report.php') ?>';
    const CUST_URL = '<?= buildUrl('api/account/search_customers.php') ?>';
    const L = <?= json_encode([
        'allCustomers' => t('All Customers'), 'empty' => t('No service sales in this period.'), 'noMatch' => t('No matching records.'),
        'error' => t('Could not load the report.'), 'serverError' => t('Server error loading the report.'), 'top' => t('Top service'),
        'deleted' => t('deleted'), 'quoted' => t('Quoted'), 'invoiced' => t('Invoiced'), 'conv' => t('Conversion %'), 'revenue' => t('Net Revenue'),
        'noLink' => t('Not linked to a service'),
    ]) ?>;
    const BLUES = ['#0d6efd', '#052c65', '#6ea8fe', '#1e3a8a', '#9ec5fe', '#3d8bfd', '#cfe2ff'];
    const fmt = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const num = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
    const esc = s => $('<span>').text(s == null ? '' : String(s)).html();

    $('#f-customer').select2({
        theme: 'bootstrap-5', placeholder: L.allCustomers, allowClear: true, width: '100%',
        ajax: { url: CUST_URL, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d, cache: true }
    });
    $('#f-source, #f-category, #f-project').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%', minimumResultsForSearch: 8 });

    const table = $('#svcTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[9, 'desc']],
        dom: 'rtipB',
        buttons: [{ extend: 'excelHtml5', className: 'd-none', title: 'Service Sales Report', footer: true }],
        columnDefs: [{ targets: [4, 5, 6, 7, 8, 9, 10, 11, 12], className: 'text-end' },
                     { targets: [7, 8, 9, 10, 12], render: (d, type) => type === 'display' ? fmt(d) : d },
                     { targets: [4], render: (d, type) => type === 'display' ? num(d) : d },
                     { targets: [11], render: (d, type) => type === 'display' ? d + '%' : d }],
        language: { emptyTable: L.empty, zeroRecords: L.noMatch }
    });
    $('#btnExcel').on('click', () => { try { table.button('.buttons-excel').trigger(); } catch (e) { Swal.fire({ icon: 'error', title: 'Error', text: 'Excel export is not available on this page.' }); } });

    let charts = [];
    const base = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };
    const short = s => (s && s.length > 28 ? s.slice(0, 27) + '…' : s);

    function renderCharts(c) {
        charts.forEach(x => x && x.destroy()); charts = [];
        charts.push(new Chart(document.getElementById('chartTop'), {
            type: 'bar',
            data: { labels: c.top_services.map(r => short(r.name)), datasets: [{ label: L.revenue, data: c.top_services.map(r => +r.net), backgroundColor: '#0d6efd' }] },
            options: { ...base, indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: x => fmt(x.raw) } } } }
        }));
        charts.push(new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: { labels: c.trend.months, datasets: c.trend.series.map((s, i) => ({ label: short(s.name), data: s.data, borderColor: BLUES[i % BLUES.length], backgroundColor: BLUES[i % BLUES.length], tension: .25, fill: false })) },
            options: { ...base, plugins: { ...base.plugins, tooltip: { callbacks: { label: x => x.dataset.label + ': ' + fmt(x.raw) } } } }
        }));
        charts.push(new Chart(document.getElementById('chartCat'), {
            type: 'doughnut',
            data: { labels: c.by_category.map(r => r.label), datasets: [{ data: c.by_category.map(r => +r.value), backgroundColor: BLUES }] },
            options: { ...base, plugins: { ...base.plugins, tooltip: { callbacks: { label: x => x.label + ': ' + fmt(x.raw) } } } }
        }));
        charts.push(new Chart(document.getElementById('chartConv'), {
            data: {
                labels: c.conversion.map(r => short(r.name)),
                datasets: [
                    { type: 'bar', label: L.quoted, data: c.conversion.map(r => +r.quoted), backgroundColor: '#9ec5fe', yAxisID: 'y' },
                    { type: 'bar', label: L.invoiced, data: c.conversion.map(r => +r.invoiced), backgroundColor: '#0d6efd', yAxisID: 'y' },
                    { type: 'line', label: L.conv, data: c.conversion.map(r => +r.rate), borderColor: '#052c65', backgroundColor: '#052c65', yAxisID: 'y1' }
                ]
            },
            options: { ...base, scales: { y: { beginAtZero: true }, y1: { position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false }, ticks: { callback: v => v + '%' } } } }
        }));
    }

    function loadReport() {
        const params = {
            date_from: $('#f-from').val(), date_to: $('#f-to').val(), source: $('#f-source').val() || '',
            customer_id: $('#f-customer').val() || '', category_id: $('#f-category').val() || '', project_id: $('#f-project').val() || ''
        };
        $.getJSON(DATA_URL, params).done(function (res) {
            if (!res || !res.success) { Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || L.error }); return; }
            const s = res.summary;
            $('#stat-net').text(fmt(s.net_revenue));
            $('#stat-vat').text(fmt(s.vat));
            $('#stat-docs').text(num(s.documents));
            $('#stat-cust').text(num(s.customers));
            $('#topServiceNote').html(s.top_service ? '<i class="bi bi-trophy text-primary me-1"></i>' + esc(L.top) + ': <strong>' + esc(s.top_service.name) + '</strong> — ' + esc(fmt(s.top_service.net)) : '');
            renderCharts(res.charts);
            table.clear();
            let t = { qty: 0, gross: 0, disc: 0, net: 0, vat: 0 };
            res.rows.forEach((r, i) => {
                const name = (r.product_id === 0 ? L.noLink : (r.name ? caseFormatJs(r.name) : '')) + (r.deleted ? ' (' + L.deleted + ')' : '');
                table.row.add([i + 1, esc(r.code || '—'), esc(name), esc(r.category), r.qty, r.documents, r.customers,
                               r.gross, r.discount, r.net, r.vat, r.share, r.avg_price, r.last_sold || '—']);
                t.qty += +r.qty; t.gross += +r.gross; t.disc += +r.discount; t.net += +r.net; t.vat += +r.vat;
            });
            table.draw();
            $('#ft-qty').text(num(t.qty)); $('#ft-gross').text(fmt(t.gross)); $('#ft-disc').text(fmt(t.disc));
            $('#ft-net').text(fmt(t.net)); $('#ft-vat').text(fmt(t.vat));
        }).fail(() => Swal.fire({ icon: 'error', title: 'Error', text: L.serverError }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-source, #f-customer, #f-category, #f-project').on('change', loadReport);
    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Service Sales Report', 'Loaded service sales report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
