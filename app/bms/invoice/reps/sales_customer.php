<?php
// File: reps/sales_customer.php
// Phase 5c — partial; normally included by app/bms/invoice/reports.php
// (which already gates 'reports'), but a direct hit on this URL must
// also be denied. roots.php and the permission helpers are idempotent.
require_once __DIR__ . '/../../../../roots.php';
if (!canView('reports')) {
    http_response_code(403);
    die("Access Denied");
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

try {
    global $pdo;
    
    // Combine POS Sales and Invoices by Customer
    $sql = "
        SELECT 
            c.customer_name,
            c.phone,
            COUNT(*) as sale_count,
            SUM(s.grand_total) as total_amount,
            AVG(s.grand_total) as avg_sale
        FROM pos_sales s
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        WHERE DATE(s.sale_date) BETWEEN ? AND ?
        AND s.sale_status = 'completed'
        GROUP BY s.customer_id
        
        UNION ALL
        
        SELECT 
            c.customer_name,
            c.phone,
            COUNT(*) as sale_count,
            SUM(i.grand_total) as total_amount,
            AVG(i.grand_total) as avg_sale
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        WHERE i.invoice_date BETWEEN ? AND ?
        AND i.status != 'cancelled'
        GROUP BY i.customer_id
        
        ORDER BY total_amount DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date, $start_date, $end_date]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Aggregate by Customer
    $customer_data = [];
    foreach ($results as $row) {
        $name = $row['customer_name'] ?: 'Guest / Walk-in';
        if (!isset($customer_data[$name])) {
            $customer_data[$name] = [
                'name' => $name,
                'phone' => $row['phone'] ?: '-',
                'sale_count' => 0,
                'total_amount' => 0
            ];
        }
        $customer_data[$name]['sale_count'] += $row['sale_count'];
        $customer_data[$name]['total_amount'] += $row['total_amount'];
    }
    
    // Calculate final totals and averages
    foreach ($customer_data as &$cd) {
        $cd['avg_sale'] = $cd['total_amount'] / $cd['sale_count'];
    }
    
    // Sort by total amount descending
    uasort($customer_data, function($a, $b) {
        return $b['total_amount'] <=> $a['total_amount'];
    });

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!-- Print-only Header -->
<div class="d-none d-print-block text-center mb-4">
    <?php 
    $c_name = getSetting('company_name', 'BMS');
    $c_logo = getSetting('company_logo', '');
    $c_email = getSetting('company_email', '');
    $c_web = getSetting('company_website', '');
    $c_tin = getSetting('company_tin', '');
    $c_vrn = getSetting('company_vrn', '');
    ?>
    <?php if(!empty($c_logo)): ?>
        <div class="mb-3">
            <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
        </div>
    <?php endif; ?>
    <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= safe_output($c_name) ?></h1>
    
    <p class="text-dark mb-1 small text-uppercase">
        <?php 
        $web_email = [];
        if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
        if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
        if (!empty($web_email)) echo implode(" | ", $web_email);
        ?>
    </p>

    <p class="text-dark mb-1 small text-uppercase">
        <?php 
        $tin_vrn = [];
        if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
        if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
        if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
        ?>
    </p>

    <div class="mt-3">
        <h3 class="fw-bold text-success text-uppercase" style="color: #198754 !important;">SALES BY CUSTOMER REPORT</h3>
        <h6 class="text-muted">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></h6>
        <div class="mt-2" style="border-top: 2px solid #198754; width: 100px; margin: 0 auto;"></div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <h5 class="mb-0 fw-bold text-success"><i class="bi bi-people me-2"></i> Sales by Customer</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>
    <div class="card-body border-bottom bg-light d-print-none">
        <form method="GET" action="<?= getUrl('reports') ?>" class="row g-3 align-items-end">
            <input type="hidden" name="report" value="sales_customer">
            <div class="col-md-4">
                <label class="form-label small fw-bold">From Date</label>
                <input type="date" class="form-control form-control-sm" name="start_date" value="<?= $start_date ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">To Date</label>
                <input type="date" class="form-control form-control-sm" name="end_date" value="<?= $end_date ?>">
            </div>
            <div class="col-md-4 d-grid">
                <button type="submit" class="btn btn-success btn-sm text-white">
                    <i class="bi bi-filter"></i> Apply Filter
                </button>
            </div>
        </form>
    </div>

    <style>
    @media print {
        body { background: white !important; }
        .container, .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table { width: 100% !important; border: 1px solid #dee2e6 !important; }
        .table th { background-color: #f8f9fa !important; color: black !important; }
        .badge { border: 1px solid #ddd !important; background: transparent !important; color: black !important; }
        .text-success { color: #198754 !important; }
    }
    </style>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light text-uppercase small fw-bold">
                <tr>
                    <th class="ps-4">Customer Name</th>
                    <th>Phone</th>
                    <th class="text-center">Total Orders</th>
                    <th class="text-end">Avg. Value</th>
                    <th class="text-end pe-4">Total Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($customer_data)): ?>
                    <?php 
                    $total_orders = 0;
                    $total_grand = 0;
                    ?>
                    <?php foreach ($customer_data as $row): ?>
                        <tr>
                            <td class="ps-4 fw-bold text-dark"><?= htmlspecialchars($row['name']) ?></td>
                            <td><span class="text-muted small"><?= htmlspecialchars($row['phone']) ?></span></td>
                            <td class="text-center"><span class="badge bg-light text-dark border"><?= $row['sale_count'] ?></span></td>
                            <td class="text-end text-muted"><?= format_currency($row['avg_sale']) ?></td>
                            <td class="text-end pe-4 fw-bold text-success"><?= format_currency($row['total_amount']) ?></td>
                        </tr>
                        <?php 
                        $total_orders += $row['sale_count'];
                        $total_grand += $row['total_amount'];
                        ?>
                    <?php endforeach; ?>
                    <tr class="table-light fw-bold">
                        <td colspan="2" class="ps-4">TOTAL</td>
                        <td class="text-center"><?= $total_orders ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end pe-4 text-success"><?= format_currency($total_grand) ?></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <i class="bi bi-people display-4 d-block mb-3 opacity-25"></i>
                            No customer sales data found for the selected period.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
$(document).ready(function() {
    logReportAction('Viewed Sales by Customer', 'User viewed sales by customer report for period <?= $start_date ?> to <?= $end_date ?>');
    
    $('.card-body form').on('submit', function() {
        logReportAction('Filtered Sales by Customer', 'User applied filters to sales by customer report');
    });
});
</script>
