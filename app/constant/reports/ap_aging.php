<?php
// app/constant/reports/ap_aging.php
// Accounts-Payable (Bill) Aging — AJAX-driven (get_ap_aging.php), Chart.js aging
// distribution, per-vendor DataTable + bill detail, printable.
// Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md (§23 scope).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('financial_reports');

$projects = $pdo->query(
    "SELECT project_id, project_name FROM projects
      WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
      ORDER BY project_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$as_of    = $_GET['as_of_date'] ?? date('Y-m-d');
$currency = get_setting('currency', 'TZS');
?>

<div class="container-fluid py-4">
    <!-- Print Header -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">ACCOUNTS PAYABLE AGING</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">As of: <?= date('d M Y', strtotime($as_of)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <!-- Screen header + actions -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-hourglass-split me-2"></i>Payables Aging</h2>
            <p class="text-muted mb-0">Unpaid approved supplier &amp; sub-contractor bills, by how long they have been outstanding</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> Print
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">As of date</label>
                    <input type="date" name="as_of_date" id="f-asof" class="form-control" value="<?= htmlspecialchars($as_of) ?>">
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
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Supplier / Sub-contractor</label>
                    <select name="vendor_id" id="f-vendor" class="form-select" style="width:100%"></select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter"></i></button>
                </div>
            </form>
            <p class="text-muted small mb-0 mt-2"><i class="bi bi-info-circle me-1"></i>Bills with a due date are aged from that date; bills without a due date are aged from date raised. <strong>Current</strong> = not yet due. Payable is net of any WHT withheld.</p>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4" id="summaryCards">
        <?php
        $cards = [
            ['Total Payable', 'stat-total',   '#052c65'],
            ['Current',       'stat-current', '#0d6efd'],
            ['1 – 30 days',   'stat-d1',      '#0d6efd'],
            ['31 – 60 days',  'stat-d2',      '#0d6efd'],
            ['61 – 90 days',  'stat-d3',      '#0d6efd'],
            ['Over 90 days',  'stat-d4',      '#dc3545'],
        ];
        foreach ($cards as $c): ?>
            <div class="col-6 col-md-2">
                <div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                    <div class="card-body p-3 text-center">
                        <p class="text-muted small text-uppercase fw-bold mb-1" style="font-size:.66rem;"><?= $c[0] ?></p>
                        <h5 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:<?= $c[2] ?>;">—</h5>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts -->
    <div class="row g-3 mb-4" id="chartRow">
        <div class="col-12 col-md-4">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>Aging Distribution</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartBuckets"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-8">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-building text-primary me-2"></i>Top Outstanding Vendors</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartVendors"></canvas></div></div>
            </div>
        </div>
    </div>

    <!-- Per-vendor aging matrix -->
    <div class="card border shadow-sm mb-4" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-building me-2"></i>By Vendor</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="vendTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">S/No</th>
                            <th>Vendor</th>
                            <th class="text-end">Current</th>
                            <th class="text-end">1–30</th>
                            <th class="text-end">31–60</th>
                            <th class="text-end">61–90</th>
                            <th class="text-end">90+</th>
                            <th class="text-end">Total</th>
                            <th class="text-center pe-3 d-print-none">Statement</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Bill detail -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-receipt me-2"></i>Outstanding Bills</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="billTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">S/No</th>
                            <th>Bill Ref</th>
                            <th>Vendor</th>
                            <th>Type</th>
                            <th>Invoice Date</th>
                            <th>Due Date</th>
                            <th class="text-center">Days Overdue</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">WHT</th>
                            <th class="text-end">Payable</th>
                            <th class="text-center pe-3">Bucket</th>
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
    #vendTable thead th, #billTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    .badge-bucket { font-size: .66rem; padding: .35em .6em; border-radius: 6px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #chartRow .card, #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        canvas { print-color-adjust: exact; -webkit-print-color-adjust: exact; max-width: 100% !important; }
        #vendTable, #billTable { border: 1px solid #000 !important; }
        #vendTable th, #billTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #vendTable td, #billTable td { border: 1px solid #dee2e6 !important; }
        .badge-bucket { border: 1px solid #999 !important; }
    }
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_ap_aging.php') ?>';
    const VEND_URL = '<?= buildUrl('api/account/search_suppliers.php') ?>';
    const STMT_URL = '<?= getUrl('vendor_statement') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc  = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

    const BUCKET_LABEL = { current:'CURRENT', d1_30:'1–30', d31_60:'31–60', d61_90:'61–90', over_90:'90+' };
    const BUCKET_BG    = { current:'#0d6efd', d1_30:'#6ea8fe', d31_60:'#1e3a8a', d61_90:'#cfe2ff', over_90:'#dc3545' };
    const BUCKET_FG    = { current:'#fff',    d1_30:'#fff',    d31_60:'#fff',    d61_90:'#084298', over_90:'#fff' };
    function bucketBadge(b) {
        return `<span class="badge-bucket" style="background:${BUCKET_BG[b]||'#0d6efd'};color:${BUCKET_FG[b]||'#fff'};">${BUCKET_LABEL[b]||b}</span>`;
    }

    $('#f-vendor').select2({
        theme: 'bootstrap-5', placeholder: 'All Vendors', allowClear: true, width: '100%',
        ajax: { url: VEND_URL, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d, cache: true }
    });
    $('#f-project').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    const vendTable = $('#vendTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[7, 'desc']],
        dom: 'rtip', columnDefs: [{ targets: [2,3,4,5,6,7], className: 'text-end' }, { targets: [8], className: 'text-center', orderable: false }],
        language: { emptyTable: 'No outstanding payables.', zeroRecords: 'No matching vendors.' }
    });
    const billTable = $('#billTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[6, 'desc']],
        dom: 'rtip', columnDefs: [{ targets: [7,8,9], className: 'text-end' }, { targets: [6,10], className: 'text-center' }],
        language: { emptyTable: 'No outstanding bills.', zeroRecords: 'No matching bills.' }
    });

    let cBuckets, cVendors;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(summary, vendors) {
        [cBuckets, cVendors].forEach(c => c && c.destroy());
        cBuckets = new Chart(document.getElementById('chartBuckets'), {
            type: 'doughnut',
            data: { labels: ['Current','1–30','31–60','61–90','90+'],
                    datasets: [{ data: [summary.current, summary.d1_30, summary.d31_60, summary.d61_90, summary.over_90],
                                 backgroundColor: ['#0d6efd','#6ea8fe','#1e3a8a','#cfe2ff','#dc3545'] }] },
            options: { ...baseOpts }
        });
        const top = vendors.slice(0, 8);
        cVendors = new Chart(document.getElementById('chartVendors'), {
            type: 'bar',
            data: { labels: top.map(v => v.vendor_name),
                    datasets: [{ label: 'Payable', data: top.map(v => +v.total), backgroundColor: BLUE }] },
            options: { ...baseOpts, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { font: { size: 9 } } }, y: { ticks: { font: { size: 9 } } } } }
        });
    }

    function loadReport() {
        const params = {
            as_of_date: $('#f-asof').val(),
            project_id: $('#f-project').val() || '',
            vendor_id: $('#f-vendor').val() || ''
        };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the report.' });
                    return;
                }
                const s = res.summary;
                $('#stat-total').text(fmt(s.total));
                $('#stat-current').text(fmt(s.current));
                $('#stat-d1').text(fmt(s.d1_30));
                $('#stat-d2').text(fmt(s.d31_60));
                $('#stat-d3').text(fmt(s.d61_90));
                $('#stat-d4').text(fmt(s.over_90));

                renderCharts(s, res.vendors);

                vendTable.clear();
                res.vendors.forEach((v, i) => vendTable.row.add([
                    i + 1,
                    esc(v.vendor_name),
                    fmt(v.current), fmt(v.d1_30), fmt(v.d31_60), fmt(v.d61_90), fmt(v.over_90),
                    fmt(v.total),
                    `<a class="btn btn-sm btn-outline-primary" href="${STMT_URL}?vendor_id=${v.vendor_id}&date_to=${encodeURIComponent(params.as_of_date)}"><i class="bi bi-file-earmark-text me-1"></i>Statement</a>`
                ]));
                vendTable.draw();

                billTable.clear();
                res.rows.forEach((r, i) => billTable.row.add([
                    i + 1,
                    esc(r.invoice_ref),
                    esc(r.vendor_name),
                    (r.invoice_type === 'sub_contractor' ? 'Sub-contractor' : 'Supplier'),
                    r.date_raised ? new Date(r.date_raised).toLocaleDateString() : '',
                    r.due_date ? new Date(r.due_date).toLocaleDateString() : '<span class="text-muted">—</span>',
                    r.days > 0 ? r.days : 0,
                    fmt(r.amount), fmt(r.wht_amount), fmt(r.balance),
                    bucketBadge(r.bucket)
                ]));
                billTable.draw();
            })
            .fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error loading the report.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project, #f-vendor').on('change', loadReport);

    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Payables Aging', 'Loaded AP aging report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
