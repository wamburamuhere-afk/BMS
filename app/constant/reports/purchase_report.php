<?php
// scope-audit: skip — purchase report; read-only multi-table report; scope filter pending Phase G-2
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

// Use existing permission mapping
autoEnforcePermission('purchase_report');

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-12-31');
$status     = $_GET['status']     ?? '';

try {
    $where = "WHERE po.order_date BETWEEN :s AND :e";
    $params = [':s' => $start_date, ':e' => $end_date];
    if ($status) { 
        $where .= " AND po.status = :st"; 
        $params[':st'] = $status; 
    }

    // Main Query: Order details
    $sql = "SELECT po.order_number, s.supplier_name, s.supplier_code,
                   po.order_date, po.status, po.grand_total as total_amount, 
                   po.payment_status, po.paid_amount
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
            $where 
            ORDER BY po.order_date DESC";
    $stmt = $pdo->prepare($sql); 
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary Totals
    $total_sql = "SELECT 
                    COUNT(*) as cnt, 
                    SUM(grand_total) as total,
                    SUM(paid_amount) as total_paid,
                    SUM(grand_total - paid_amount) as total_due
                  FROM purchase_orders po 
                  $where";
    $stmt2 = $pdo->prepare($total_sql); 
    $stmt2->execute($params);
    $totals = $stmt2->fetch(PDO::FETCH_ASSOC);

    // Vendor Analytics: Top 5 Suppliers by Spend
    $vendor_sql = "SELECT s.supplier_name, SUM(po.grand_total) as total_spend, COUNT(po.purchase_order_id) as order_count
                   FROM purchase_orders po
                   JOIN suppliers s ON po.supplier_id = s.supplier_id
                   $where
                   GROUP BY s.supplier_id, s.supplier_name
                   ORDER BY total_spend DESC
                   LIMIT 5";
    $stmt3 = $pdo->prepare($vendor_sql);
    $stmt3->execute($params);
    $top_vendors = $stmt3->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { 
    $error = $e->getMessage(); 
    $orders = []; 
    $totals = ['cnt'=>0, 'total'=>0, 'total_paid'=>0, 'total_due'=>0];
    $top_vendors = [];
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
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">PROCUREMENT & PURCHASE REPORT</h2>
           
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Order Count</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= number_format($totals['cnt']) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Commitments</p>
                <h4 style="color: #e74c3c; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($totals['total'] ?? 0) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Paid</p>
                <h4 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($totals['total_paid'] ?? 0) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px;
                
                
                font-weight: 600;">Balance Due</p>
                <h4 style="color: #f39c12; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($totals['total_due'] ?? 0) ?></h4>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-cart-check-fill me-2"></i>Procurement & Purchase Report</h2>
            <p class="text-muted mb-0">Analysis of vendor obligations, spending patterns and settlement status</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer-fill me-2"></i> Print Report
            </button>
            <button class="btn btn-dark shadow-sm px-4 fw-bold ms-2" onclick="exportExcel()">
                <i class="bi bi-file-earmark-spreadsheet-fill me-2"></i> Export Excel
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-radius: 12px; background: #f8f9fc;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Period Start</label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0 bg-white"><i class="bi bi-calendar-minus"></i></span>
                        <input type="date" name="start_date" class="form-control border-start-0" value="<?= $start_date ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Period End</label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0 bg-white"><i class="bi bi-calendar-plus"></i></span>
                        <input type="date" name="end_date" class="form-control border-start-0" value="<?= $end_date ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Order Status</label>
                    <select name="status" class="form-select select2">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $status=='pending'?'selected':'' ?>>Pending</option>
                        <option value="approved" <?= $status=='approved'?'selected':'' ?>>Approved</option>
                        <option value="ordered" <?= $status=='ordered'?'selected':'' ?>>Ordered</option>
                        <option value="received" <?= $status=='received'?'selected':'' ?>>Received</option>
                        <option value="completed" <?= $status=='completed'?'selected':'' ?>>Completed</option>
                        <option value="cancelled" <?= $status=='cancelled'?'selected':'' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                        <i class="bi bi-search me-2"></i> Run Analytics
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Order Count</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= number_format($totals['cnt']) ?></h4>
                    <span class="small text-primary fw-bold">POs in period</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Commitments</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($totals['total'] ?? 0) ?></h4>
                    <span class="small text-danger fw-bold">Gross value</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Paid</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($totals['total_paid'] ?? 0) ?></h4>
                    <span class="small text-success fw-bold">Settled payments</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Balance Due</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($totals['total_due'] ?? 0) ?></h4>
                    <span class="small text-warning fw-bold">Liabilities</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <!-- Top Vendors -->
        <div class="col-lg-4 col-md-5 d-print-none">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px;">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-people-fill me-2"></i>Top Suppliers (By Spend)</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php if(empty($top_vendors)): ?>
                            <li class="list-group-item py-4 text-center text-muted italic">No vendor data available.</li>
                        <?php else: foreach($top_vendors as $index => $v): ?>
                            <li class="list-group-item px-4 py-3 border-0 <?= $index%2==0 ? 'bg-light bg-opacity-25' : '' ?>">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark truncate" style="max-width: 180px;"><?= htmlspecialchars((string)($v['supplier_name'] ?? '')) ?></span>
                                    <span class="fw-bold text-primary small"><?= format_currency($v['total_spend']) ?></span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <?php $pct = ($totals['total'] > 0) ? ($v['total_spend'] / $totals['total'] * 100) : 0; ?>
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct ?>%" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <small class="text-muted x-small mt-1 d-block"><?= $v['order_count'] ?> Orders (<?= number_format($pct, 1) ?>% of total)</small>
                            </li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Procurement Ledger -->
        <div class="col-lg-8 col-md-7">
            <div class="card border-0 shadow-lg h-100" style="border-radius: 15px; overflow: hidden;">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
             
                    <div class="d-print-none">
                        <input type="text" id="orderSearch" class="form-control form-control-sm px-3" placeholder="Search orders..." style="width: 200px; border-radius: 20px;">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="orderTable">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4 text-muted small text-uppercase">Reference</th>
                                    <th class="text-muted small text-uppercase">Vendor</th>
                                    <th class="text-muted small text-uppercase text-center">Status</th>
                                    <th class="text-end pe-4 text-muted small text-uppercase">Total Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($orders)): ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted fst-italic">No procurement history within these dates.</td></tr>
                                <?php else: foreach($orders as $o): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark font-monospace"><?= htmlspecialchars((string)($o['order_number'] ?? '')) ?></div>
                                            <div class="small text-muted italic"><?= date('M d, Y', strtotime($o['order_date'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark truncate" style="max-width: 150px;"><?= htmlspecialchars((string)($o['supplier_name'] ?? 'General Vendor')) ?></div>
                                            <div class="x-small text-muted"><?= htmlspecialchars((string)($o['supplier_code'] ?? 'POV-000')) ?></div>
                                        </td>
                                        <td class="text-center">
                                            <?php 
                                                $s_class = match(strtolower($o['status'])) {
                                                    'received', 'completed' => 'success',
                                                    'cancelled', 'rejected' => 'danger',
                                                    'pending', 'draft' => 'warning',
                                                    'ordered', 'approved' => 'info',
                                                    default => 'secondary'
                                                };
                                            ?>
                                            <span class="badge bg-<?= $s_class ?> bg-opacity-10 text-<?= $s_class ?> border border-<?= $s_class ?> border-opacity-25 px-2 py-1" style="min-width: 80px; font-size: 0.75rem;">
                                                <?= strtoupper($o['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="fw-bold text-dark"><?= format_currency($o['total_amount']) ?></div>
                                            
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footnote -->
    <div class="mt-4 p-3 bg-light rounded text-center text-muted small italic d-print-none">
        <i class="bi bi-info-circle-fill me-1 text-primary"></i> Figures include applicable taxes as specified in individual purchase orders. Internal adjustments may apply.
    </div>
</div>

<script>
$(document).ready(function(){
    $('#orderSearch').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $("#orderTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    if(typeof logReportAction==='function') {
        logReportAction('Viewed Purchase Report', 'Analyzed procurement records for <?= $start_date ?> - <?= $end_date ?>');
    }
});

function exportExcel() {
    alert('Exporting Procurement Ledger to Excel...');
}
</script>

<style>
    .card { border-radius: 12px; }
    .mini-stats-card { transition: transform 0.2s; }
    .mini-stats-card:hover { transform: translateY(-3px); }
    .truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; }
    .x-small { font-size: 0.72rem; }
    .italic { font-style: italic; }
    @media print {
        .d-print-none, .btn, #orderSearch, .card-header .d-print-none { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .badge { color: #000 !important; border: 1px solid #ddd !important; background: transparent !important; }
        .col-lg-4, .col-lg-8 { width: 100% !important; margin-bottom: 20px; }
    }
</style>

<?php includeFooter(); ob_end_flush(); ?>
