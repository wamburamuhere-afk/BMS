<?php
// app/constant/reports/tax_report.php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

autoEnforcePermission('tax_report');

$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? date('Y-12-31');

try {
    // 1. Output Tax (Sales)
    $output_sql = "
        SELECT 
            COUNT(invoice_id) as count,
            SUM(subtotal) as taxable_revenue,
            SUM(tax_amount) as tax_collected
        FROM invoices 
        WHERE invoice_date BETWEEN ? AND ? AND status NOT IN ('cancelled', 'draft')
    ";
    $stmt = $pdo->prepare($output_sql);
    $stmt->execute([$date_from, $date_to]);
    $output_data = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Input Tax (Purchases)
    $input_sql = "
        SELECT 
            COUNT(purchase_order_id) as count,
            SUM(total_amount) as taxable_purchases,
            SUM(tax_amount) as tax_paid
        FROM purchase_orders 
        WHERE order_date BETWEEN ? AND ? AND status NOT IN ('cancelled', 'draft', 'rejected')
    ";
    $stmt = $pdo->prepare($input_sql);
    $stmt->execute([$date_from, $date_to]);
    $input_data = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3. Monthly Reconciliation Data
    $monthly_recon_sql = "
        SELECT 
            m.month_label,
            COALESCE(o.tax_collected, 0) as tax_collected,
            COALESCE(i.tax_paid, 0) as tax_paid
        FROM (
            SELECT DISTINCT DATE_FORMAT(invoice_date, '%Y-%m') as month_key, DATE_FORMAT(invoice_date, '%M %Y') as month_label
            FROM invoices WHERE invoice_date BETWEEN ? AND ?
            UNION
            SELECT DISTINCT DATE_FORMAT(order_date, '%Y-%m'), DATE_FORMAT(order_date, '%M %Y')
            FROM purchase_orders WHERE order_date BETWEEN ? AND ?
        ) m
        LEFT JOIN (
            SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month_key, SUM(tax_amount) as tax_collected
            FROM invoices WHERE status NOT IN ('cancelled', 'draft') 
            GROUP BY month_key
        ) o ON m.month_key = o.month_key
        LEFT JOIN (
            SELECT DATE_FORMAT(order_date, '%Y-%m') as month_key, SUM(tax_amount) as tax_paid
            FROM purchase_orders WHERE status NOT IN ('cancelled', 'draft', 'rejected')
            GROUP BY month_key
        ) i ON m.month_key = i.month_key
        ORDER BY m.month_key ASC
    ";
    $stmt = $pdo->prepare($monthly_recon_sql);
    $stmt->execute([$date_from, $date_to, $date_from, $date_to]);
    $monthly_recon = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_output = (float)($output_data['tax_collected'] ?? 0);
    $total_input  = (float)($input_data['tax_paid'] ?? 0);
    $net_payable  = $total_output - $total_input;

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
        ?>
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3 text-center">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= safe_output($c_name) ?></h1>
        
        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">TAXATION & VAT REPORT</h2>
            
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($date_from)) ?> - <?= date('d M Y', strtotime($date_to)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Output Tax (Collected)</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_output) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Input Tax (Paid)</p>
                <h4 style="color: #e74c3c; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_input) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Net Tax Payable</p>
                <h4 style="color: <?= $net_payable >= 0 ? '#0d6efd' : '#2ecc71' ?>; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency(abs($net_payable)) ?></h4>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-shield-check me-2"></i>Taxation Report</h2>
            <p class="text-muted mb-0">Input vs Output tax reconciliation for compliance</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> Print Report
            </button>
            <button class="btn btn-dark shadow-sm px-4 fw-bold ms-2" onclick="alert('Exporting Compliance File...')">
                <i class="bi bi-file-earmark-pdf me-2"></i> Export PDF
            </button>
        </div>
    </div>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-radius: 12px;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Tax Period Start</label>
                    <input type="date" name="date_from" class="form-control rounded-3 border-light shadow-sm" value="<?= $date_from ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Tax Period End</label>
                    <input type="date" name="date_to" class="form-control rounded-3 border-light shadow-sm" value="<?= $date_to ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm rounded-3">
                        <i class="bi bi-arrow-repeat me-1"></i> Re-calculate Compliance
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Output Tax (Collected)</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_output) ?></h4>
                    <span class="small text-success fw-bold">From <?= $output_data['count'] ?> Sales</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Input Tax (Paid)</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_input) ?></h4>
                    <span class="small text-danger fw-bold">From <?= $input_data['count'] ?> Purchases</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Net Tax <?= $net_payable >= 0 ? 'Payable' : 'Credit' ?></p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency(abs($net_payable)) ?></h4>
                    <span class="small text-primary fw-bold">Current Obligation</span>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Monthly Breakdown Table -->
    <div class="card border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
       
            <span class="badge bg-light text-dark border">ACCRUAL BASIS</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 text-muted small text-uppercase" style="width:45px;">S/NO</th>
                            <th class="ps-2 text-muted small text-uppercase">Tax Period</th>
                            <th class="text-end text-muted small text-uppercase">Output Tax (A)</th>
                            <th class="text-end text-muted small text-uppercase">Input Tax (B)</th>
                            <th class="text-end pe-4 text-muted small text-uppercase">Net Differential (A-B)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($monthly_recon)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No taxation activity recorded for this timeframe.</td></tr>
                        <?php else: $sno = 1; foreach($monthly_recon as $m): 
                                $diff = $m['tax_collected'] - $m['tax_paid'];
                        ?>
                            <tr>
                                <td class="ps-3 text-center text-muted fw-bold small"><?= $sno++ ?></td>
                                <td class="ps-2 fw-bold text-dark"><?= htmlspecialchars((string)($m['month_label'] ?? '')) ?></td>
                                <td class="text-end text-success"><?= format_currency($m['tax_collected']) ?></td>
                                <td class="text-end text-danger"><?= format_currency($m['tax_paid']) ?></td>
                                <td class="text-end pe-4 fw-bold <?= $diff >= 0 ? 'text-primary' : 'text-warning' ?>">
                                    <?= format_currency($diff) ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot class="bg-light border-top">
                        <tr class="fw-bold fs-6">
                            <td colspan="2" class="ps-4 py-3">GRAND TOTAL</td>
                            <td class="text-end py-3 text-success"><?= format_currency($total_output) ?></td>
                            <td class="text-end py-3 text-danger"><?= format_currency($total_input) ?></td>
                            <td class="text-end pe-4 py-3 <?= $net_payable >= 0 ? 'text-primary' : 'text-warning' ?>">
                                <?= format_currency($net_payable) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Compliance Note -->
    <div class="mt-4 p-4 bg-white border shadow-sm rounded-4 d-print-none">
        <h6 class="fw-bold mb-2"><i class="bi bi-info-circle text-primary me-2"></i>Filing Information</h6>
        <p class="text-muted small mb-0">
            This report provides a preliminary reconciliation of VAT/Tax figures gathered from finalized invoices and purchase orders. 
            Ensure all manual journal entries affecting tax accounts are cross-referenced with this report before final filing.
        </p>
    </div>
</div>

<script>
$(document).ready(function(){
    if(typeof logReportAction==='function') {
        logReportAction('Viewed Tax Compliance Report', 'Tax reconciliation for period <?= $date_from ?> to <?= $date_to ?>');
    }
});
</script>

<style>
    .card { border-radius: 12px; }
    .table thead th { border-top: none; }
    @media print {
        .navbar, .sidebar, .d-print-none, .btn { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .badge { border: 1px dashed #ccc !important; color: #000 !important; background: transparent !important; }
    }
</style>

<?php includeFooter(); ob_end_flush(); ?>
