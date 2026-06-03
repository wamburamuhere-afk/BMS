<?php
// app/constant/reports/tax_report.php
// Professional Taxation & VAT report — AJAX (get_tax_report.php), Chart.js
// charts that also print, DataTable, Select2 + Project scope.
// Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md §23.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('tax_report');

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
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">TAXATION & VAT REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Period: <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-percent me-2"></i>Taxation Report</h2>
            <p class="text-muted mb-0">Output vs input VAT reconciliation</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()"><i class="bi bi-printer me-2"></i> Print</button>
        </div>
    </div>

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

    <div class="row g-3 mb-4" id="summaryCards">
        <?php foreach ([['VAT OUT (Output — Sales)','stat-output'],['VAT IN (Input — Purchases)','stat-input'],['Net VAT (Payable / Refundable)','stat-net'],['Documents','stat-docs']] as $c): ?>
            <div class="col-6 col-md-3"><div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                <div class="card-body p-3 text-center"><p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;">—</h4></div></div></div>
        <?php endforeach; ?>
    </div>

    <!-- Ledger reconciliation: compares this report against the VAT control
         accounts that drive the Balance Sheet — a mismatch flags a bug. -->
    <div id="vatReconcile" class="alert d-none mb-4 d-print-none" role="alert"></div>

    <div class="row g-3 mb-4" id="chartRow">
        <div class="col-12 col-md-8"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-bar-chart text-primary me-2"></i>Output vs Input Tax (Monthly)</div>
            <div class="card-body"><div style="height:250px;"><canvas id="chartMonthly"></canvas></div></div></div></div>
        <div class="col-12 col-md-4"><div class="card border shadow-sm h-100" style="border-color:#b6ccfe!important;border-radius:12px;">
            <div class="card-header bg-white fw-bold border-0"><i class="bi bi-pie-chart text-primary me-2"></i>Tax Split</div>
            <div class="card-body"><div style="height:250px;"><canvas id="chartSplit"></canvas></div></div></div></div>
    </div>

    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-table me-2"></i>Monthly Reconciliation</h6>
            <span class="badge" style="background:#cfe2ff;color:#084298;">ACCRUAL BASIS</span>
        </div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="taxTable">
                <thead class="table-light"><tr>
                    <th class="ps-3">S/No</th><th>Tax Period</th>
                    <th class="text-end">Output Tax (A)</th><th class="text-end">Input Tax (B)</th><th class="pe-3 text-end">Net (A&minus;B)</th>
                </tr></thead>
                <tbody></tbody>
                <tfoot class="table-light fw-bold"><tr>
                    <td colspan="2" class="ps-3">GRAND TOTAL</td>
                    <td class="text-end" id="ft-output">—</td><td class="text-end" id="ft-input">—</td><td class="pe-3 text-end" id="ft-net">—</td>
                </tr></tfoot>
            </table>
        </div></div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    #taxTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
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
        #taxTable { border: 1px solid #000 !important; }
        #taxTable th, #taxTable tfoot td { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #taxTable td { border: 1px solid #dee2e6 !important; }
        .badge { border: 1px solid #999 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function () {
    const CURRENCY = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const DATA_URL = '<?= buildUrl('api/account/get_tax_report.php') ?>';
    const BLUE = '#0d6efd';
    const fmt  = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    $('#f-project').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    const table = $('#taxTable').DataTable({
        responsive: false, scrollX: false, pageLength: 25, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: [2, 3, 4], className: 'text-end' }],
        language: { emptyTable: 'No taxation activity for this period.', zeroRecords: 'No matching records.' }
    });

    let cMonthly, cSplit;
    const baseOpts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } } };

    function renderCharts(charts) {
        [cMonthly, cSplit].forEach(c => c && c.destroy());
        cMonthly = new Chart(document.getElementById('chartMonthly'), {
            type: 'bar',
            data: { labels: charts.monthly.map(r=>r.label), datasets: [
                { label:'Output Tax', data: charts.monthly.map(r=>+r.output), backgroundColor: BLUE },
                { label:'Input Tax',  data: charts.monthly.map(r=>+r.input),  backgroundColor: '#6ea8fe' }
            ] },
            options: { ...baseOpts, scales:{y:{ticks:{font:{size:9}}},x:{ticks:{font:{size:9}}}} } });
        cSplit = new Chart(document.getElementById('chartSplit'), {
            type: 'doughnut',
            data: { labels: charts.split.map(r=>r.label), datasets: [{ data: charts.split.map(r=>+r.value), backgroundColor: ['#0d6efd', '#6ea8fe'] }] },
            options: { ...baseOpts } });
    }

    function loadReport() {
        const params = { date_from: $('#f-from').val(), date_to: $('#f-to').val(), project_id: $('#f-project').val() || '' };
        $.getJSON(DATA_URL, params).done(function (res) {
            if (!res || !res.success) { Swal.fire({ icon:'error', title:'Error', text:(res&&res.message)||'Could not load the report.' }); return; }
            const s = res.summary;
            $('#stat-output').text(fmt(s.output_tax));
            $('#stat-input').text(fmt(s.input_tax));
            $('#stat-net').text((s.net_payable < 0 ? '(Credit) ' : '') + fmt(Math.abs(s.net_payable)));
            $('#stat-docs').text((s.sales_count + s.purchase_count).toLocaleString());
            $('#ft-output').text(fmt(s.output_tax));
            $('#ft-input').text(fmt(s.input_tax));
            $('#ft-net').text(fmt(s.net_payable));

            // Ledger reconciliation vs the VAT control accounts (Balance Sheet).
            const L = res.ledger;
            const $rec = $('#vatReconcile').removeClass('d-none alert-success alert-info');
            if (L && L.output !== null && L.output !== undefined) {
                const matched = Math.abs((+s.output_tax) - (+L.output)) < 0.5
                             && Math.abs((+s.input_tax)  - (+L.input))  < 0.5;
                const netLabel = (+L.net >= 0 ? 'PAYABLE' : 'REFUNDABLE');
                if (matched) {
                    $rec.addClass('alert-success').html(
                        '<i class="bi bi-check-circle-fill me-1"></i> <strong>Reconciled.</strong> '
                        + 'This report\'s VAT OUT and VAT IN equal the VAT control accounts on the Balance Sheet — '
                        + 'ledger net <strong>' + fmt(Math.abs(L.net)) + ' ' + netLabel + '</strong>.');
                } else {
                    $rec.addClass('alert-info').html(
                        '<i class="bi bi-info-circle-fill me-1"></i> <strong>Ledger position</strong> (Balance Sheet VAT control accounts): '
                        + 'VAT OUT <strong>' + fmt(L.output) + '</strong>, VAT IN <strong>' + fmt(L.input) + '</strong>, '
                        + 'net <strong>' + fmt(Math.abs(L.net)) + ' ' + netLabel + '</strong>. '
                        + 'Widen the date range to cover all invoices to match this report to the Balance Sheet.');
                }
            } else {
                $rec.addClass('d-none');
            }

            renderCharts(res.charts);
            table.clear();
            res.rows.forEach((r, i) => table.row.add([ i + 1, r.month || '', fmt(r.output), fmt(r.input), fmt(r.net) ]));
            table.draw();
        }).fail(() => Swal.fire({ icon:'error', title:'Error', text:'Server error loading the report.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-project').on('change', loadReport);
    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Tax Report', 'Loaded taxation & VAT report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
