<?php
// app/constant/reports/inventory_report.php
// Professional Inventory Valuation Report — AJAX (get_inventory_report.php),
// Chart.js charts that also print, DataTable, Select2 filters, project-scope
// security. Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md §23.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('inventory_report');

// In-scope projects (products carry project_id, so inventory is scoped too).
$projects = $pdo->query(
    "SELECT project_id, project_name FROM projects
      WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
      ORDER BY project_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Categories (small list) for the static Select2 filter.
$categories = $pdo->query("SELECT category_id, category_name FROM categories WHERE status = 'active' ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$currency = get_setting('currency', 'TZS');
?>

<div class="container-fluid py-4">
    <!-- Print Header (title only — borders/footer come from i_e_print.md) -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">INVENTORY VALUATION REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">As of: <?= date('d M Y') ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <!-- Screen header + actions -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-box-seam me-2"></i>Inventory Report</h2>
            <p class="text-muted mb-0">Stock valuation, distribution and reorder status</p>
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
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Category</label>
                    <select name="category_id" id="f-category" class="form-select" style="width:100%">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['category_id'] ?>"><?= safe_output($c['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Stock Status</label>
                    <select name="stock_status" id="f-stock" class="form-select" style="width:100%">
                        <option value="">All Stock</option>
                        <option value="in">In Stock</option>
                        <option value="low">Low Stock</option>
                        <option value="out">Out of Stock</option>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary cards (screen + print) -->
    <div class="row g-3 mb-4" id="summaryCards">
        <?php
        $cards = [
            ['Total SKUs',         'stat-skus'],
            ['Total Stock Value',  'stat-value'],
            ['Total Units',        'stat-units'],
            ['Low / Out of Stock', 'stat-low'],
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
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-diagram-3 text-primary me-2"></i>Stock Value by Category</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartCategory"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>Stock Status</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartStatus"></canvas></div></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-trophy text-primary me-2"></i>Top Items by Value</div>
                <div class="card-body"><div style="height:230px;"><canvas id="chartTop"></canvas></div></div>
            </div>
        </div>
    </div>

    <!-- Detail table -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-boxes me-2"></i>Stock Items</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="invTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">S/No</th>
                            <th>Code</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th class="text-end">In Stock</th>
                            <th class="text-end">Cost Price</th>
                            <th class="text-end">Stock Value</th>
                            <th class="pe-3 text-center">Status</th>
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
    #invTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    .badge-status { font-size: .68rem; padding: .35em .6em; border-radius: 6px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        /* Blank-first-page fix: zero ONLY top spacing (navbar reserve); never
           touch padding-bottom (print_footer_css.php needs it). */
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #chartRow .card, #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        canvas { print-color-adjust: exact; -webkit-print-color-adjust: exact; max-width: 100% !important; }
        #invTable { border: 1px solid #000 !important; }
        #invTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #invTable td { border: 1px solid #dee2e6 !important; }
        .badge-status { border: 1px solid #999 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_inventory_report.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const num  = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });

    const SS = { in:{bg:'#052c65',fg:'#fff',t:'IN STOCK'}, low:{bg:'#cfe2ff',fg:'#084298',t:'LOW STOCK'}, out:{bg:'#dc3545',fg:'#fff',t:'OUT OF STOCK'} };
    function badge(s) { const x = SS[s] || SS.in; return `<span class="badge-status" style="background:${x.bg};color:${x.fg};">${x.t}</span>`; }

    // ── Select2 filters ───────────────────────────────────────────────────
    $('#f-project, #f-category, #f-stock').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    // ── DataTable (per §UI-2) ─────────────────────────────────────────────
    const table = $('#invTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: [4, 5, 6], className: 'text-end' }, { targets: 7, className: 'text-center' }],
        language: { emptyTable: 'No stock items found.', zeroRecords: 'No matching records.' }
    });

    // ── Charts ────────────────────────────────────────────────────────────
    let cCat, cStatus, cTop;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(charts) {
        [cCat, cStatus, cTop].forEach(c => c && c.destroy());
        const blues = ['#0d6efd', '#052c65', '#6ea8fe', '#cfe2ff', '#1e3a8a', '#9ec5fe', '#bfdbfe', '#084298'];

        cCat = new Chart(document.getElementById('chartCategory'), {
            type: 'bar',
            data: { labels: charts.by_category.map(r => r.name),
                    datasets: [{ label: 'Stock Value', data: charts.by_category.map(r => +r.total), backgroundColor: BLUE }] },
            options: { ...baseOpts, plugins: { legend: { display: false } }, scales: { y: { ticks: { font: { size: 9 } } }, x: { ticks: { font: { size: 9 } } } } }
        });

        cStatus = new Chart(document.getElementById('chartStatus'), {
            type: 'doughnut',
            data: { labels: charts.stock_status.map(r => r.label),
                    datasets: [{ data: charts.stock_status.map(r => +r.value), backgroundColor: ['#052c65', '#6ea8fe', '#dc3545'] }] },
            options: { ...baseOpts }
        });

        cTop = new Chart(document.getElementById('chartTop'), {
            type: 'bar',
            data: { labels: charts.top_items.map(r => r.name),
                    datasets: [{ label: 'Value', data: charts.top_items.map(r => +r.total), backgroundColor: blues }] },
            options: { ...baseOpts, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { font: { size: 9 } } }, y: { ticks: { font: { size: 9 } } } } }
        });
    }

    // ── Load (AJAX) ───────────────────────────────────────────────────────
    function loadReport() {
        const params = {
            project_id: $('#f-project').val() || '',
            category_id: $('#f-category').val() || '',
            stock_status: $('#f-stock').val() || ''
        };
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the report.' });
                    return;
                }
                $('#stat-skus').text(Number(res.summary.total_skus).toLocaleString());
                $('#stat-value').text(fmt(res.summary.total_value));
                $('#stat-units').text(num(res.summary.total_units));
                $('#stat-low').text(Number(res.summary.low_count).toLocaleString());

                renderCharts(res.charts);

                table.clear();
                res.rows.forEach((r, i) => table.row.add([
                    i + 1,
                    r.product_code || '',
                    r.product_name || '',
                    r.category || 'Uncategorised',
                    num(r.current_stock),
                    fmt(r.cost_price),
                    fmt(r.stock_value),
                    badge(r.stock_status)
                ]));
                table.draw();
            })
            .fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error loading the report.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project, #f-category, #f-stock').on('change', loadReport);

    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Inventory Report', 'Loaded inventory valuation report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
