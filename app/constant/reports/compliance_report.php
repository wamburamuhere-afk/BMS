<?php
// app/constant/reports/compliance_report.php

// Start the buffer
ob_start();

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
includeHeader();

autoEnforcePermission('admin'); // Only admins should see compliance reports

// 1. Product Compliance: Expired or Expiring Soon
$expiring_sql = "
    SELECT 
        product_name,
        sku,
        stock_quantity,
        expiry_date,
        DATEDIFF(expiry_date, CURDATE()) as days_remaining
    FROM products
    WHERE expiry_date IS NOT NULL 
    AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND stock_quantity > 0
    ORDER BY expiry_date ASC
";
$stmt = $pdo->query($expiring_sql);
$expiring_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Customer Compliance: Missing TIN for High Value Customers (e.g. Total Sales > 1,000,000)
// Assuming high value transactions require TIN for compliance
$missing_tin_sql = "
    SELECT 
        c.customer_name,
        c.phone,
        SUM(i.grand_total) as total_sales
    FROM customers c
    JOIN invoices i ON c.customer_id = i.customer_id
    WHERE (c.tin_number IS NULL OR c.tin_number = '')
    AND i.status != 'cancelled'
    GROUP BY c.customer_id, c.customer_name, c.phone
    HAVING total_sales > 1000000
    ORDER BY total_sales DESC
";
$stmt = $pdo->query($missing_tin_sql);
$missing_tin_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Transaction Compliance: Cancelled Invoices Review
$cancelled_inv_sql = "
    SELECT 
        invoice_number,
        invoice_date,
        grand_total,
        status
    FROM invoices
    WHERE status = 'cancelled'
    AND invoice_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY invoice_date DESC
";
$stmt = $pdo->query($cancelled_inv_sql);
$cancelled_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Data Integrity: Products with Zero/Missing Cost Price (Affects Profit calc)
$missing_cost_sql = "
    SELECT 
        product_name,
        sku,
        selling_price,
        cost_price
    FROM products
    WHERE (cost_price IS NULL OR cost_price = 0)
    AND stock_quantity > 0
";
$stmt = $pdo->query($missing_cost_sql);
$missing_cost_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
    /* Styling for Print and View */
    .report-title {
        color: #0d6efd;
        font-weight: 800;
        letter-spacing: 1px;
    }
    
    @media print {
        .d-print-none, .btn, .breadcrumb {
            display: none !important;
        }
        .container-fluid {
            width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        .card {
            border: 1px solid #dee2e6 !important;
            box-shadow: none !important;
            margin-bottom: 15px !important;
            break-inside: auto !important;
        }
        .card-header {
            background-color: #f8f9fa !important;
            border-bottom: 1px solid #dee2e6 !important;
            padding: 8px 15px !important;
            -webkit-print-color-adjust: exact;
        }
        body {
            background: white !important;
            font-size: 11px !important;
        }
        .table thead th {
            background-color: #e9ecef !important;
            padding: 8px !important;
            -webkit-print-color-adjust: exact;
        }
        .print-header {
            display: block !important;
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 10px;
        }
        .table-responsive {
            overflow: visible !important;
        }
    }
    .print-header {
        display: none;
    }
    @media print {
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
    }
</style>

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
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">COMPLIANCE & SYSTEM AUDIT REPORT</h2>
            
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Screen Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h2 class="mb-1 fw-bold text-primary" style="text-transform: uppercase;"><i class="bi bi-ui-checks"></i> COMPLIANCE REPORT</h2>
            <p class="text-muted mb-0">System health, regulatory, and data integrity checks</p>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-primary shadow-sm px-4">
                <i class="bi bi-printer me-2"></i> PRINT REPORT
            </button>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Expiring Products -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-danger text-uppercase"><i class="bi bi-hourglass-bottom"></i> Expiring Inventory (Next 30 Days)</h6>
                    <span class="badge bg-danger rounded-pill"><?= count($expiring_products) ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Product</th>
                                    <th class="text-end">Stock</th>
                                    <th class="text-end pe-4">Days Left</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($expiring_products)): ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted">No expiring products found.</td></tr>
                                <?php else: ?>
                                    <?php foreach($expiring_products as $p): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?= htmlspecialchars($p['product_name']) ?></td>
                                        <td class="text-end"><?= format_number($p['stock_quantity']) ?></td>
                                        <td class="text-end pe-4">
                                            <?php 
                                            $days = $p['days_remaining'];
                                            if ($days < 0) {
                                                echo '<span class="badge bg-danger">Expired</span>';
                                            } elseif ($days <= 7) {
                                                echo '<span class="badge bg-warning text-dark">' . $days . ' days</span>';
                                            } else {
                                                echo '<span class="badge bg-secondary">' . $days . ' days</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Missing Cost Price -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-warning text-uppercase"><i class="bi bi-exclamation-triangle"></i> Data Integrity: Missing Cost Price</h6>
                </div>
                <div class="card-body p-0">
                    <div class="alert alert-light m-3 small text-muted">
                        Products with 0 cost price affect Profit &amp; Loss reports accuracy.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Product</th>
                                    <th class="text-end pe-4">Selling Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($missing_cost_products)): ?>
                                    <tr><td colspan="2" class="text-center py-4 text-muted">All active products have cost prices set.</td></tr>
                                <?php else: ?>
                                    <?php foreach($missing_cost_products as $p): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold"><?= htmlspecialchars($p['product_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($p['sku'] ?? '') ?></small>
                                        </td>
                                        <td class="text-end pe-4"><?= format_currency($p['selling_price']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Missing TIN -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-info text-uppercase"><i class="bi bi-person-exclamation"></i> High Value Customers Missing TIN</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Customer</th>
                                    <th class="text-end pe-4">Total Purchases</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($missing_tin_customers)): ?>
                                    <tr><td colspan="2" class="text-center py-4 text-muted">All high value customers have TIN recorded.</td></tr>
                                <?php else: ?>
                                    <?php foreach($missing_tin_customers as $c): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold"><?= htmlspecialchars($c['customer_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($c['phone'] ?? '-') ?></small>
                                        </td>
                                        <td class="text-end pe-4"><?= format_currency($c['total_sales']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cancelled Invoices -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-secondary text-uppercase"><i class="bi bi-x-circle"></i> Recently Cancelled Invoices</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Invoice #</th>
                                    <th>Date</th>
                                    <th class="text-end pe-4">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($cancelled_invoices)): ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted">No cancelled invoices in last 30 days.</td></tr>
                                <?php else: ?>
                                    <?php foreach($cancelled_invoices as $inv): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-decoration-line-through text-muted"><?= htmlspecialchars($inv['invoice_number']) ?></td>
                                        <td class="small"><?= date('M d, Y', strtotime($inv['invoice_date'])) ?></td>
                                        <td class="text-end pe-4 text-muted"><?= format_currency($inv['grand_total']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
includeFooter();
ob_end_flush();
?>
