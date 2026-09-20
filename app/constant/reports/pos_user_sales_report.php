<?php
/**
 * app/constant/reports/pos_user_sales_report.php
 *
 * Sales by User Report — Simple POS only. Per-cashier sales totals for a
 * date range, with a drill-down modal showing exactly which products each
 * cashier sold and at what value. AJAX (get_user_sales_report.php), Chart.js,
 * DataTable, Select2, project/warehouse scope.
 * Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md §23.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
require_once __DIR__ . '/../../../core/pos_nav.php';
includeHeader();

autoEnforcePermission('pos_user_sales_report');
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

$cashiers = $pdo->query(
    "SELECT user_id, CONCAT(first_name,' ',last_name) AS name FROM users WHERE is_active = 1 ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? date('Y-12-31');
$currency  = get_setting('currency', 'TZS');
?>

<div class="container-fluid py-4">
    <!-- Print Header (title only — borders/footer come from i_e_print.md) -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;"><?= t('SALES BY USER REPORT') ?></h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;"><?= t('Period:') ?> <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;"><?= t('Generated:') ?> <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <!-- Screen header + actions -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-person-badge me-2"></i><?= t('Sales by User Report') ?></h2>
            <p class="text-muted mb-0"><?= t('What each cashier sold, and its total value') ?></p>
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
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('From') ?></label>
                    <input type="date" name="date_from" id="f-from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('To') ?></label>
                    <input type="date" name="date_to" id="f-to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <?php if (projectsModuleActive()): ?>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('Project') ?></label>
                    <select name="project_id" id="f-project" class="form-select" style="width:100%">
                        <option value=""><?= t('All My Projects') ?></option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int)$p['project_id'] ?>"><?= caseFormat($p['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (tenantFeatureEnabled('warehouses')): ?>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= wLabel('Warehouse', 'Shop') ?></label>
                    <select name="warehouse_id" id="f-warehouse" class="form-select" style="width:100%">
                        <option value=""><?= wLabel('All Warehouses', 'All Shops') ?></option>
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?= (int)$w['warehouse_id'] ?>"><?= caseFormat($w['warehouse_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1"><?= t('Cashier') ?></label>
                    <select name="user_id" id="f-user" class="form-select" style="width:100%">
                        <option value=""><?= t('All Staff') ?></option>
                        <?php foreach ($cashiers as $c): ?>
                            <option value="<?= (int)$c['user_id'] ?>"><?= caseFormat($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary cards (screen + print) -->
    <div class="row g-3 mb-4" id="summaryCards">
        <?php
        $cards = [
            [t('Total Sales Value'), 'stat-total'],
            [t('Transactions'),      'stat-count'],
            [t('Items Sold'),        'stat-items'],
            [t('Active Cashiers'),   'stat-cashiers'],
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

    <!-- Chart (screen + print) -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
                <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart text-primary me-2"></i><?= t('Sales Value by Cashier') ?></div>
                <div class="card-body"><div style="height:260px;"><canvas id="chartByUser"></canvas></div></div>
            </div>
        </div>
    </div>

    <!-- Detail table -->
    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-people me-2"></i><?= t('Sales by Cashier') ?></h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="userTable">
                    <thead style="--bs-table-color:#fff;--bs-table-bg:#0d6efd;">
                        <tr>
                            <th class="ps-3 no-sort" style="width:56px;"><?= t('S/No') ?></th>
                            <th><?= t('Cashier') ?></th>
                            <th class="text-end"><?= t('Transactions') ?></th>
                            <th class="text-end"><?= t('Items Sold') ?></th>
                            <th class="text-end"><?= t('Total Value') ?></th>
                            <th class="pe-3 text-end no-sort no-export d-print-none"><?= t('Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Sales Summary modal (Z-Report style, shown per cashier on action click) -->
<div class="modal fade d-print-none" id="summaryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:#0d6efd;">
                <h5 class="modal-title text-white fw-bold">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i><span id="summaryModalCashier"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="summaryModalBody">
                <div class="text-center py-4 text-muted">…</div>
            </div>
        </div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    #userTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #userTable { border: 1px solid #000 !important; }
        #userTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #userTable td { border: 1px solid #dee2e6 !important; }
    }
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const PT = {
        noRecordsFound: <?= json_encode(t('No records found.')) ?>,
        noMatchingRecords: <?= json_encode(t('No matching records.')) ?>,
        salesValue: <?= json_encode(t('Sales Value')) ?>,
        error: <?= json_encode(t('Error')) ?>,
        couldNotLoadReport: <?= json_encode(t('Could not load the report.')) ?>,
        serverErrorLoadingReport: <?= json_encode(t('Server error loading the report.')) ?>,
        noItemsFound: <?= json_encode(t('No items found for this cashier in this period.')) ?>,
        viewSummary: <?= json_encode(t('View Sales Summary')) ?>,
        cashier: <?= json_encode(t('Cashier')) ?>,
        period: <?= json_encode(t('Period')) ?>,
        transactions: <?= json_encode(t('Transactions')) ?>,
        salesByPaymentMethod: <?= json_encode(t('Sales by Payment Method')) ?>,
        paymentMethod: <?= json_encode(t('Payment Method')) ?>,
        total: <?= json_encode(t('Total')) ?>,
        grandTotal: <?= json_encode(t('Grand Total')) ?>,
        itemsSold: <?= json_encode(t('Items Sold')) ?>,
        product: <?= json_encode(t('Product')) ?>,
        qty: <?= json_encode(t('Qty')) ?>,
        value: <?= json_encode(t('Value')) ?>,
    };
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_user_sales_report.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtQty = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
    function esc(t) { return $('<div>').text(t == null ? '' : t).html(); }

    $('#f-project, #f-warehouse, #f-user').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    const table = $('#userTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[4, 'desc']],
        dom: 'rtip',
        columnDefs: [
            { targets: [2,3,4], className: 'text-end' },
            { targets: 5, className: 'text-end', orderable: false }
        ],
        language: { emptyTable: PT.noRecordsFound, zeroRecords: PT.noMatchingRecords }
    });

    let cByUser;
    function renderChart(rows) {
        if (cByUser) cByUser.destroy();
        cByUser = new Chart(document.getElementById('chartByUser'), {
            type: 'bar',
            data: { labels: rows.map(r => r.name), datasets: [{ label: PT.salesValue, data: rows.map(r => +r.total_value), backgroundColor: BLUE }] },
            options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false } },
                       scales: { y: { ticks: { font: { size: 9 } } }, x: { ticks: { font: { size: 9 } } } } }
        });
    }

    function currentParams() {
        return {
            date_from: $('#f-from').val(), date_to: $('#f-to').val(),
            project_id: $('#f-project').val() || '',
            warehouse_id: $('#f-warehouse').val() || '',
            user_id: $('#f-user').val() || ''
        };
    }

    function loadReport() {
        $.getJSON(DATA_URL, currentParams())
            .done(function (res) {
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: PT.error, text: (res && res.message) || PT.couldNotLoadReport });
                    return;
                }
                $('#stat-total').text(fmt(res.summary.total_value));
                $('#stat-count').text(Number(res.summary.total_sales).toLocaleString());
                $('#stat-items').text(fmtQty(res.summary.qty_sold));
                $('#stat-cashiers').text(Number(res.summary.active_cashiers).toLocaleString());

                renderChart(res.rows);

                table.clear();
                res.rows.forEach((r, i) => table.row.add([
                    i + 1,
                    caseFormatJs(r.name),
                    Number(r.sales_count).toLocaleString(),
                    fmtQty(r.qty_sold),
                    fmt(r.total_value),
                    `<div class="dropdown d-flex justify-content-end">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                            <li><button type="button" class="dropdown-item py-2 rounded" onclick="viewCashierDetail(${r.user_id},'${caseFormatJs(r.name).replace(/'/g,"\\'").replace(/"/g,'&quot;')}')">
                                <i class="bi bi-file-earmark-bar-graph text-primary me-2"></i>${PT.viewSummary}
                            </button></li>
                        </ul>
                    </div>`
                ]));
                table.draw();
            })
            .fail(() => Swal.fire({ icon: 'error', title: PT.error, text: PT.serverErrorLoadingReport }));
    }

    window.viewCashierDetail = function (userId, userName) {
        $('#summaryModalCashier').text(userName);
        $('#summaryModalBody').html('<div class="text-center py-4 text-muted">…</div>');
        const modal = new bootstrap.Modal(document.getElementById('summaryModal'));
        modal.show();

        const params = currentParams();
        params.user_id = userId;
        params.mode = 'detail';
        $.getJSON(DATA_URL, params)
            .done(function (res) {
                if (!res || !res.success) {
                    $('#summaryModalBody').html('<div class="text-center text-danger py-3">' + PT.couldNotLoadReport + '</div>');
                    return;
                }

                // Info bar
                let html = `<div class="row g-3 mb-3 text-center">
                    <div class="col-4">
                        <div class="small text-muted text-uppercase fw-bold mb-1">${PT.cashier}</div>
                        <div class="fw-bold">${esc(userName)}</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted text-uppercase fw-bold mb-1">${PT.period}</div>
                        <div class="fw-bold">${esc($('#f-from').val())} – ${esc($('#f-to').val())}</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted text-uppercase fw-bold mb-1">${PT.transactions}</div>
                        <div class="fw-bold fs-4 text-primary">${Number(res.totals.tx_count).toLocaleString()}</div>
                    </div>
                </div><hr class="my-2">`;

                // Payment method breakdown
                html += `<h6 class="fw-bold text-muted text-uppercase small mb-2">${PT.salesByPaymentMethod}</h6>
                <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered mb-0">
                    <thead style="background:#e7f0ff;">
                        <tr>
                            <th>${PT.paymentMethod}</th>
                            <th class="text-end">${PT.transactions}</th>
                            <th class="text-end">${PT.total}</th>
                        </tr>
                    </thead><tbody>`;
                res.payment_breakdown.forEach(pb => {
                    html += `<tr>
                        <td class="text-capitalize">${esc(pb.payment_method || '—')}</td>
                        <td class="text-end">${Number(pb.tx_count).toLocaleString()}</td>
                        <td class="text-end">${fmt(pb.total)}</td>
                    </tr>`;
                });
                html += `</tbody>
                    <tfoot class="fw-bold" style="background:#e7f0ff;">
                        <tr>
                            <td>${PT.grandTotal}</td>
                            <td class="text-end">${Number(res.totals.tx_count).toLocaleString()}</td>
                            <td class="text-end">${fmt(res.totals.total_value)}</td>
                        </tr>
                    </tfoot>
                </table></div>`;

                // Items sold
                html += `<h6 class="fw-bold text-muted text-uppercase small mb-2">${PT.itemsSold}</h6>`;
                if (res.items.length) {
                    html += `<div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead style="background:#e7f0ff;">
                            <tr>
                                <th class="ps-2">${PT.product}</th>
                                <th class="text-end">${PT.qty}</th>
                                <th class="text-end pe-2">${PT.value}</th>
                            </tr>
                        </thead><tbody>`;
                    res.items.forEach(it => {
                        html += `<tr>
                            <td class="ps-2">${caseFormatJs(it.product_name)}</td>
                            <td class="text-end">${fmtQty(it.qty_sold)}</td>
                            <td class="text-end pe-2">${fmt(it.total_value)}</td>
                        </tr>`;
                    });
                    html += `</tbody></table></div>`;
                } else {
                    html += `<p class="text-muted text-center py-2 mb-0">${PT.noItemsFound}</p>`;
                }

                $('#summaryModalBody').html(html);
            })
            .fail(() => $('#summaryModalBody').html('<div class="text-center text-danger py-3">' + PT.serverErrorLoadingReport + '</div>'));
    };

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project, #f-warehouse, #f-user').on('change', loadReport);

    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Sales by User Report', 'Loaded sales-by-user report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
