<?php
// app/constant/reports/wht_receivable_report.php
// Withholding Tax CREDIT report — WHT your CUSTOMERS withheld from you, by
// customer (a tax credit you reclaim from TRA). Sales-side mirror of wht_report.php.
// Recognised at customer payment, so the period filter is on payment_date.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();

autoEnforcePermission('tax_report');

$date_from   = $_GET['date_from'] ?? date('Y-m-01');
$date_to     = $_GET['date_to']   ?? date('Y-m-t');
$currency    = get_setting('currency', 'TZS');
$company     = get_setting('company_name') ?: 'Business Management System';
$company_tin = get_setting('company_tax_id', '');

// Per-customer WHT withheld from us in the period (completed payments only).
$scope = scopeFilterSqlNullable('project', 'p');
$stmt = $pdo->prepare("
    SELECT c.customer_id, c.customer_name, c.tin_number,
           COALESCE(SUM(p.wht_base), 0)   AS total_base,
           COALESCE(SUM(p.wht_posted), 0) AS total_wht,
           COUNT(*)                       AS doc_count
      FROM payments p
      JOIN customers c ON c.customer_id = p.customer_id
     WHERE p.wht_posted IS NOT NULL
       AND p.status = 'completed'
       AND p.payment_date BETWEEN ? AND ? $scope
  GROUP BY c.customer_id, c.customer_name, c.tin_number
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
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">WITHHOLDING TAX CREDIT REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;"><?= safe_output($company) ?><?= $company_tin ? ' &middot; TIN ' . safe_output($company_tin) : '' ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">WHT withheld from us &middot; Period: <?= date('d M Y', strtotime($date_from)) ?> &ndash; <?= date('d M Y', strtotime($date_to)) ?></p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-cash-stack me-2"></i>Withholding Tax Credit</h2>
            <p class="text-muted mb-0">WHT your customers withheld from you, by customer — the credit you reclaim from TRA</p>
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
        <?php foreach ([['Total WHT Credit', $cf($total_wht)], ['Taxable Base', $cf($total_base)], ['Customers', number_format(count($rows))], ['Payments', number_format($total_docs)]] as $c): ?>
            <div class="col-6 col-md-3"><div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                <div class="card-body p-3 text-center"><p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                <h4 class="fw-bold mb-0" style="color:#0d6efd;"><?= $c[1] ?></h4></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-table me-2"></i>WHT Withheld From Us, by Customer</h6>
            <span class="badge" style="background:#cfe2ff;color:#084298;">PAYMENT BASIS</span>
        </div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="whtrTable">
                <thead class="table-light"><tr>
                    <th class="ps-3">S/No</th><th>Customer</th><th>TIN</th>
                    <th class="text-end">Taxable Base</th><th class="text-end">WHT Credit</th><th class="pe-3 text-end">Payments</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td class="ps-3"><?= $i + 1 ?></td>
                        <td><?= safe_output($r['customer_name']) ?></td>
                        <td><?= safe_output($r['tin_number'], '—') ?></td>
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
    #whtrTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        .table-responsive { overflow: visible !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .card-header { background: #fff !important; }
        #whtrTable { border: 1px solid #000 !important; }
        #whtrTable th, #whtrTable tfoot td { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #whtrTable td { border: 1px solid #dee2e6 !important; }
        .badge { border: 1px solid #999 !important; }
    }
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script>
$(function () {
    if (!$.fn.DataTable.isDataTable('#whtrTable')) {
        $('#whtrTable').DataTable({
            responsive: false, scrollX: false, pageLength: 25, order: [[4, 'desc']],
            dom: 'rtip', columnDefs: [{ targets: [3, 4, 5], className: 'text-end' }],
            language: { emptyTable: 'No withholding-tax credit recorded for this period.', zeroRecords: 'No matching customers.' }
        });
    }
    if (typeof logReportAction === 'function') logReportAction('Viewed WHT Credit Report', 'Loaded withholding-tax credit report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
