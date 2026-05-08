<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

// Use existing permission mapping
autoEnforcePermission('customer_analysis');

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-12-31');

try {
    $sql = "SELECT c.customer_name, COUNT(so.sales_order_id) as total_orders,
                   SUM(so.total_amount) as total_spent, MAX(so.order_date) as last_order,
                   AVG(so.total_amount) as avg_order,
                   c.phone, c.email
            FROM customers c
            LEFT JOIN sales_orders so ON c.customer_id = so.customer_id
                AND so.order_date BETWEEN ? AND ? AND so.status != 'cancelled'
            GROUP BY c.customer_id, c.customer_name, c.phone, c.email
            HAVING total_orders > 0
            ORDER BY total_spent DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute([$start_date, $end_date]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_revenue = array_sum(array_column($customers, 'total_spent'));
} catch (Exception $e) { $error = $e->getMessage(); $customers = []; $total_revenue = 0; }
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
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">CUSTOMER ANALYSIS REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Detailed breakdown of customer purchasing behavior, revenue contribution, and loyalty metrics.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Active Customers</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= count($customers) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Revenue</p>
                <h4 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_revenue) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Avg Recovery / Cust</p>
                <h4 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency(count($customers)>0 ? $total_revenue/count($customers) : 0) ?></h4>
            </div>
        </div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-primary"><i class="bi bi-people-fill"></i> Customer Analysis</h2>
                            <p class="mb-0 text-muted">Detailed breakdown of customer purchasing behavior and loyalty</p>
                        </div>
                        <div class="d-flex gap-2">
                             <button class="btn btn-outline-primary shadow-sm" onclick="window.print()">
                                <i class="bi bi-printer me-1"></i> Print Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Active Customers</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= count($customers) ?></h4>
                    <span class="small text-primary fw-bold">Buying Clients</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Revenue</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_revenue) ?></h4>
                    <span class="small text-success fw-bold">Aggregate Sales</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Avg Recovery / Cust</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency(count($customers)>0 ? $total_revenue/count($customers) : 0) ?></h4>
                    <span class="small text-info fw-bold">Customer LTV</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-radius: 12px;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                        <i class="bi bi-filter-circle me-1"></i> Apply Analysis
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Data Table -->
    <div class="card border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark">Customer Ranking by Revenue</h5>
            <span class="text-muted small">Showing results for <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 text-uppercase small text-muted">S/NO</th>
                            <th class="text-uppercase small text-muted">Customer Details</th>
                            <th class="text-end text-uppercase small text-muted">Orders</th>
                            <th class="text-end text-uppercase small text-muted">Average Item</th>
                            <th class="text-uppercase small text-muted">Last Purchase</th>
                            <th class="text-end pe-4 text-uppercase small text-muted">Total Contribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($customers)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
                                    <span class="text-muted">No customer data found for this period.</span>
                                </td>
                            </tr>
                        <?php else: foreach($customers as $i=>$c): ?>
                            <tr>
                                <td class="ps-4 text-muted"><?= $i+1 ?></td>
                                <td>
                                    <div class="fw-bold text-primary"><?= htmlspecialchars((string)($c['customer_name'] ?? '')) ?></div>
                                    <div class="small text-muted"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars((string)($c['email'] ?? 'N/A')) ?></div>
                                </td>
                                <td class="text-end fw-semibold"><?= number_format($c['total_orders']) ?></td>
                                <td class="text-end"><?= format_currency($c['avg_order']) ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?= date('d M Y', strtotime($c['last_order'])) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <h6 class="fw-bold text-success mb-0"><?= format_currency($c['total_spent']) ?></h6>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="5" class="ps-4 py-3 fw-bold text-uppercase">Aggregated Revenue</td>
                            <td class="text-end pe-4 py-3 h5 fw-bold text-success"><?= format_currency($total_revenue) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    if(typeof logReportAction==='function') {
        logReportAction('Viewed Customer Analysis', 'Period: <?= $start_date ?> to <?= $end_date ?>');
    }
});
</script>

<style>
    .card { transition: transform 0.2s; }
    .table thead th { border-top: 0; }
    @media print {
        .navbar, .sidebar, .d-print-none, .btn { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .badge { border: 1px solid #ddd !important; color: #000 !important; background: transparent !important; }
        /* Prevent tfoot from repeating on every page - show only at the very end */
        tfoot { display: table-row-group !important; }
    }
</style>

<?php includeFooter(); ob_end_flush(); ?>
