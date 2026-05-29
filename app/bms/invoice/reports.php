<?php
// File: reports.php
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('reports');

includeHeader();
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Reports</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <nav class="navbar navbar-expand-lg navbar-light bg-light rounded shadow-sm mb-4 d-print-none" style="position: relative; z-index: 1000;">
        <div class="container-fluid">
            <span class="navbar-brand fw-bold text-success"><i class="bi bi-graph-up me-2"></i> Reports Dashboard</span>
            <?php if (isset($_GET['report'])): ?>
                <a href="<?= getUrl('reports') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <?php 
    $report = $_GET['report'] ?? '';
    
    if ($report === 'daily_sales') {
        include 'reps/daily_sales.php';
    } elseif ($report === 'sales_customer') {
        include 'reps/sales_customer.php';
    } elseif ($report === 'balance_sheet') {
        include 'reps/balance_sheet.php';
    } elseif ($report === 'cash_flow') {
        include 'reps/cash_flow.php';
    } elseif ($report === 'stock_value') {
        include 'reps/stock_value.php';
    } elseif ($report === 'low_stock') {
        include 'reps/low_stock.php';
    } else {
    ?>
    <div class="row g-4 text-dark">
        <!-- Sales Reports -->
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 custom-stat-card-blue">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title fw-bold text-primary mb-4"><i class="bi bi-receipt me-2"></i> Sales Reports</h5>
                    <ul class="list-group list-group-flush bg-transparent flex-grow-1">
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Sales</span>
                            <a href="<?= getUrl('reports') ?>?report=daily_sales" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">View Report</a>
                        </li>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Sales by Customer</span>
                            <a href="<?= getUrl('reports') ?>?report=sales_customer" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">View Report</a>
                        </li>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <span class="text-muted">Sales by Product</span>
                            <span class="badge bg-light text-dark border-0 small">Coming Soon</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Financial Reports -->
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 custom-stat-card-green">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title fw-bold text-success mb-4"><i class="bi bi-calculator me-2"></i> Financial Reports</h5>
                    <ul class="list-group list-group-flush bg-transparent flex-grow-1">
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Income Statement</span>
                            <a href="<?= getUrl('income_statement') ?>" class="btn btn-sm btn-success text-white rounded-pill px-3 shadow-sm">Open Report</a>
                        </li>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Balance Sheet</span>
                            <a href="<?= getUrl('reports') ?>?report=balance_sheet" class="btn btn-sm btn-success text-white rounded-pill px-3 shadow-sm">View Report</a>
                        </li>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Cash Flow Statement</span>
                            <a href="<?= getUrl('reports') ?>?report=cash_flow" class="btn btn-sm btn-success text-white rounded-pill px-3 shadow-sm">View Report</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Inventory Reports -->
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 custom-stat-card-orange">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title fw-bold text-warning-dark mb-4"><i class="bi bi-box me-2"></i> Inventory Reports</h5>
                    <ul class="list-group list-group-flush bg-transparent flex-grow-1">
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Stock Valuation</span>
                            <a href="<?= getUrl('reports') ?>?report=stock_value" class="btn btn-sm btn-warning rounded-pill px-3 shadow-sm">View Report</a>
                        </li>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Low Stock Alert</span>
                            <a href="<?= getUrl('reports') ?>?report=low_stock" class="btn btn-sm btn-warning rounded-pill px-3 shadow-sm">View Report</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>
</div>

<style>
.custom-stat-card-blue, .custom-stat-card-green, .custom-stat-card-orange {
    border-radius: 1rem;
    transition: all 0.3s ease;
    border: 1px solid rgba(0,0,0,0.05);
}
.custom-stat-card-blue:hover, .custom-stat-card-green:hover, .custom-stat-card-orange:hover { 
    transform: translateY(-5px); 
    box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
}

.custom-stat-card-blue { border-left: 5px solid #0d6efd !important; background-color: #f0f7ff !important; }
.custom-stat-card-green { border-left: 5px solid #198754 !important; background-color: #f0fff4 !important; }
.custom-stat-card-orange { border-left: 5px solid #ffc107 !important; background-color: #fffbef !important; }

.list-group-item {
    border-bottom: 1px solid rgba(0,0,0,0.05) !important;
    padding: 0.75rem 0 !important;
}
.list-group-item:last-child { border-bottom: none !important; }
.text-warning-dark { color: #856404 !important; }
</style>

<script>
$(document).ready(function() {
    <?php if (empty($report)): ?>
        logReportAction('Viewed Reports Dashboard', 'User opened the main reports dashboard');
    <?php endif; ?>
});
</script>

<?php includeFooter(); ?>
