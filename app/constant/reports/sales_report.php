<?php
// app/constant/reports/sales_report.php
// scope-audit: skip — cross-project sales report; project-scope filtering deferred to Phase G-2
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

// Use 'sales_report' since 'reports' permission doesn't exist in DB mapping
autoEnforcePermission('sales_report');

$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to'] ?? date('Y-12-31');
$customer_id = $_GET['customer_id'] ?? '';
$salesperson_id = $_GET['salesperson_id'] ?? '';
$status = $_GET['status'] ?? '';
$group_by = $_GET['group_by'] ?? 'day';

// Data loading logic
try {
    $params = [$date_from, $date_to];
    $where_clauses = ["i.invoice_date BETWEEN ? AND ?"];

    if (!empty($customer_id)) {
        $where_clauses[] = "i.customer_id = ?";
        $params[] = $customer_id;
    }

    if (!empty($salesperson_id)) {
        $where_clauses[] = "so.salesperson_id = ?";
        $params[] = $salesperson_id;
    }

    if (!empty($status)) {
        $where_clauses[] = "i.status = ?";
        $params[] = $status;
    } else {
        $where_clauses[] = "i.status != 'cancelled'";
    }

    $where_sql = implode(' AND ', $where_clauses);

    // Summary Statistics
    $summary_sql = "
        SELECT 
            COUNT(DISTINCT i.invoice_id) as total_invoices,
            SUM(i.grand_total) as total_sales,
            SUM(i.paid_amount) as total_paid,
            SUM(i.balance_due) as total_due,
            COUNT(DISTINCT i.customer_id) as unique_customers
        FROM invoices i
        LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
        WHERE $where_sql
    ";
    $stmt = $pdo->prepare($summary_sql);
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Revenue per month for chart
    $chart_sql = "
        SELECT 
            DATE_FORMAT(i.invoice_date, '%Y-%m') as label,
            SUM(i.grand_total) as value
        FROM invoices i
        WHERE $where_sql
        GROUP BY label
        ORDER BY label ASC
        LIMIT 12
    ";
    $stmt = $pdo->prepare($chart_sql);
    $stmt->execute($params);
    $chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top Products (if needed, but usually on product_analysis)
    // Detailed List
    $details_sql = "
        SELECT 
            i.invoice_id, i.invoice_number, i.invoice_date, c.customer_name,
            i.grand_total, i.paid_amount, i.status,
            CONCAT(u.first_name, ' ', u.last_name) as salesperson
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
        LEFT JOIN users u ON so.salesperson_id = u.user_id
        WHERE $where_sql
        ORDER BY i.invoice_date DESC
    ";
    $stmt = $pdo->prepare($details_sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Helpers
    $users = $pdo->query("SELECT user_id, CONCAT(first_name, ' ', last_name) as name FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $customers = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <div class="mt-2 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">SALES PERFORMANCE REPORT</h2>
           
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($date_from)) ?> - <?= date('d M Y', strtotime($date_to)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Gross Revenue</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($summary['total_sales'] ?? 0) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Collected</p>
                <h4 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($summary['total_paid'] ?? 0) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Accounts Receivable</p>
                <h4 style="color: #e67e22; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($summary['total_due'] ?? 0) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Unique Buyers</p>
                <h4 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 14pt;"><?= $summary['unique_customers'] ?></h4>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Sales Intelligence</h2>
            <p class="text-muted mb-0">Revenue performance and transactional insights</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer-fill me-2"></i> Export PDF
            </button>
            <button class="btn btn-dark shadow-sm px-4 fw-bold ms-2" onclick="alert('Exporting to Excel...')">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> CSV Export
            </button>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Gross Revenue</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($summary['total_sales'] ?? 0) ?></h4>
                    <span class="small text-primary fw-bold"><?= $summary['total_invoices'] ?> Invoices</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Collected</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($summary['total_paid'] ?? 0) ?></h4>
                    <span class="small text-success fw-bold">Cash Receipts</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Accounts Receivable</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($summary['total_due'] ?? 0) ?></h4>
                    <span class="small text-warning fw-bold">Awaiting Payment</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Unique Buyers</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= $summary['unique_customers'] ?></h4>
                    <span class="small text-info fw-bold">Active Clients</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-radius: 15px; background: #fdfdfd;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                    <input type="date" name="date_from" class="form-control rounded-3 shadow-sm border-light" value="<?= $date_from ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                    <input type="date" name="date_to" class="form-control rounded-3 shadow-sm border-light" value="<?= $date_to ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Customer</label>
                    <select name="customer_id" class="form-select rounded-3 shadow-sm border-light select2">
                        <option value="">All Customers</option>
                        <?php foreach($customers as $c): ?>
                            <option value="<?= $c['customer_id'] ?>" <?= $customer_id == $c['customer_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($c['customer_name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Salesperson</label>
                    <select name="salesperson_id" class="form-select rounded-3 shadow-sm border-light select2">
                        <option value="">All Staff</option>
                        <?php foreach($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $salesperson_id == $u['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($u['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm rounded-3">
                        <i class="bi bi-filter me-1"></i> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <!-- Chart placeholder / Trend -->
        <div class="col-lg-4 mb-4 d-print-none">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px;">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Revenue Trend</h6>
                </div>
                <div class="card-body">
                    <?php if(empty($chart_data)): ?>
                        <div class="text-center py-5 text-muted">No trend data available for this range.</div>
                    <?php else: 
                        $max_val = max(array_column($chart_data, 'value')) ?: 1;
                        foreach($chart_data as $row): 
                            $pct = ($row['value'] / $max_val) * 100;
                    ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1 small">
                                <span class="fw-bold text-muted"><?= $row['label'] ?></span>
                                <span class="fw-bold text-dark"><?= format_currency($row['value']) ?></span>
                            </div>
                            <div class="progress" style="height: 10px; border-radius: 5px; background: #f0f0f0;">
                                <div class="progress-bar bg-primary shadow-sm" style="width: <?= $pct ?>%; border-radius: 5px;"></div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Details Table -->
        <div class="col-lg-8 mb-4 w-100 flex-fill">
            <div class="card border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold d-print-none">Recent Transactions</h5>
                    <div class="d-print-none">
                        <input type="text" id="salesSearch" class="form-control form-control-sm px-3 shadow-sm border-light" placeholder="Search invoices..." style="width: 200px; border-radius: 20px;">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="salesTable">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4 text-muted small text-uppercase">Invoice</th>
                                    <th class="text-muted small text-uppercase">Customer</th>
                                    <th class="text-end text-muted small text-uppercase">Amount</th>
                                    <th class="text-center text-muted small text-uppercase">Status</th>
                                    <th class="text-end pe-4 text-muted small text-uppercase">Staff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($invoices)): ?>
                                    <tr><td colspan="5" class="text-center py-5 text-muted">No sales records found.</td></tr>
                                <?php else: foreach($invoices as $inv): 
                                    $status_color = match (strtolower($inv['status'])) {
                                        'paid' => 'success',
                                        'partial' => 'warning',
                                        'unpaid', 'overdue' => 'danger',
                                        default => 'secondary'
                                    };
                                ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars((string)($inv['invoice_number'] ?? '')) ?></div>
                                            <div class="small text-muted"><?= date('d M Y', strtotime($inv['invoice_date'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-primary"><?= htmlspecialchars((string)($inv['customer_name'] ?? 'Walk-in')) ?></div>
                                        </td>
                                        <td class="text-end fw-bold"><?= format_currency($inv['grand_total']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> border border-<?= $status_color ?> border-opacity-25 px-3 py-2 text-uppercase" style="font-size: 0.65rem; min-width: 80px;">
                                                <?= htmlspecialchars((string)($inv['status'] ?? '')) ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4 small text-muted"><?= htmlspecialchars((string)($inv['salesperson'] ?? 'System')) ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#salesSearch').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $("#salesTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    if(typeof logReportAction==='function') {
        logReportAction('Viewed Sales Performance', 'Generated analytical sales report for period <?= $date_from ?> to <?= $date_to ?>');
    }
});
</script>

<style>
    .card { border-radius: 15px; }
    .table thead th { border-top: none; }
    .progress-bar { transition: width 1s ease-in-out; }
    @media print {
        .d-print-none, .btn, #salesSearch, .filter-card, .card-header .d-print-none { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .badge { color: #000 !important; border: 1px solid #ddd !important; background: transparent !important; }
        .col-lg-4, .col-lg-8 { width: 100% !important; margin-bottom: 20px; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
