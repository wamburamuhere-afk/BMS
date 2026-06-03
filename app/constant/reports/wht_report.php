<?php
// app/constant/reports/wht_report.php
// Withholding Tax (WHT) report — per-supplier WHT withheld for the TRA monthly
// return. Server-rendered + DataTable + print-ready. Mirrors the Taxation Report
// styling (.claude/ui-constants.md). WHT is recognised at PAYMENT, so the period
// filter is on the payment_date. Company-wide totals; project-scoped per §23.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('tax_report');

$date_from   = $_GET['date_from'] ?? date('Y-m-01');           // default: this month
$date_to     = $_GET['date_to']   ?? date('Y-m-t');
$currency    = get_setting('currency', 'TZS');
$company     = get_setting('company_name') ?: 'Business Management System';
$company_tin = get_setting('company_tax_id', '');

// Per-supplier WHT withheld in the period (recognised at payment_date). Source is
// supplier_invoices.wht_posted (the drift-proof posted flag). Project-scoped for
// non-admins; admins see all. (Ad-hoc supplier_payments WHT joins here in Phase 5.)
$scope  = scopeFilterSqlNullable('project', 'si');
$stmt = $pdo->prepare("
    SELECT s.supplier_id, s.supplier_name, s.tax_id,
           COALESCE(SUM(si.wht_base), 0)   AS total_base,
           COALESCE(SUM(si.wht_posted), 0) AS total_wht,
           COUNT(*)                        AS doc_count
      FROM supplier_invoices si
      JOIN suppliers s ON s.supplier_id = si.supplier_id
     WHERE si.wht_posted IS NOT NULL
       AND si.status <> 'deleted'
       AND si.payment_date BETWEEN ? AND ? $scope
  GROUP BY s.supplier_id, s.supplier_name, s.tax_id
  ORDER BY total_wht DESC
");
$stmt->execute([$date_from, $date_to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_base = 0.0; $total_wht = 0.0; $total_docs = 0;
foreach ($rows as $r) { $total_base += (float)$r['total_base']; $total_wht += (float)$r['total_wht']; $total_docs += (int)$r['doc_count']; }
$cf = fn($v) => $currency . ' ' . number_format((float)$v, 2);
?>

<div class="container-fluid py-4">
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">WITHHOLDING TAX REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;"><?= safe_output($company) ?><?= $company_tin ? ' &middot; TIN ' . safe_output($company_tin) : '' ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Period: <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-percent me-2"></i>Withholding Tax Report</h2>
            <p class="text-muted mb-0">WHT withheld from suppliers, by supplier — for the TRA monthly return</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()"><i class="bi bi-printer me-2"></i> Print</button>
        </div>
    </div>

    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-4"><label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>"></div>
                <div class="col-md-4"><label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>"></div>
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter me-1"></i> Apply</button></div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([['Total WHT Withheld', $cf($total_wht)], ['Taxable Base', $cf($total_base)], ['Suppliers', number_format(count($rows))], ['Documents', number_format($total_docs)]] as $c): ?>
            <div class="col-6 col-md-3"><div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                <div class="card-body p-3 text-center"><p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                <h4 class="fw-bold mb-0" style="color:#0d6efd;"><?= $c[1] ?></h4></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-table me-2"></i>WHT by Supplier</h6>
            <span class="badge" style="background:#cfe2ff;color:#084298;">PAYMENT BASIS</span>
        </div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="whtTable">
                <thead class="table-light"><tr>
                    <th class="ps-3">S/No</th><th>Supplier</th><th>TIN</th>
                    <th class="text-end">Taxable Base</th><th class="text-end">WHT Withheld</th><th class="pe-3 text-end">Docs</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td class="ps-3"><?= $i + 1 ?></td>
                        <td><?= safe_output($r['supplier_name']) ?></td>
                        <td><?= safe_output($r['tax_id'], '—') ?></td>
                        <td class="text-end"><?= $cf($r['total_base']) ?></td>
                        <td class="text-end fw-semibold"><?= $cf($r['total_wht']) ?></td>
                        <td class="pe-3 text-end"><?= (int)$r['doc_count'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold"><tr>
                    <td colspan="3" class="ps-3">GRAND TOTAL</td>
                    <td class="text-end"><?= $cf($total_base) ?></td>
                    <td class="text-end"><?= $cf($total_wht) ?></td>
                    <td class="pe-3 text-end"><?= (int)$total_docs ?></td>
                </tr></tfoot>
            </table>
        </div></div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    #whtTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        .table-responsive { overflow: visible !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        #whtTable { border: 1px solid #000 !important; }
        #whtTable th, #whtTable tfoot td { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #whtTable td { border: 1px solid #dee2e6 !important; }
        .badge { border: 1px solid #999 !important; }
    }
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script>
$(function () {
    if (!$.fn.DataTable.isDataTable('#whtTable')) {
        $('#whtTable').DataTable({
            responsive: false, scrollX: false, pageLength: 25, order: [[4, 'desc']],
            dom: 'rtip', columnDefs: [{ targets: [3, 4, 5], className: 'text-end' }],
            language: { emptyTable: 'No withholding tax recorded for this period.', zeroRecords: 'No matching suppliers.' }
        });
    }
    if (typeof logReportAction === 'function') logReportAction('Viewed WHT Report', 'Loaded withholding tax report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
