<?php
// File: reps/stock_valuation.php

try {
    global $pdo;
    
    // Fetch products with their stock levels and prices
    $sql = "
        SELECT 
            p.product_code,
            p.product_name,
            p.category_id,
            p.current_stock,
            p.cost_price,
            p.purchase_price,
            p.selling_price,
            (COALESCE(p.current_stock, 0) * COALESCE(p.purchase_price, p.cost_price, 0)) as total_cost_value,
            (COALESCE(p.current_stock, 0) * COALESCE(p.selling_price, 0)) as total_retail_value
        FROM products p
        WHERE p.status = 'active'
        ORDER BY p.product_name ASC
    ";
    
    $results = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

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
        <h3 class="fw-bold text-success text-uppercase" style="color: #198754 !important;">STOCK VALUATION REPORT</h3>
        <h6 class="text-muted">Generated on: <?= date('d M Y H:i') ?></h6>
        <div class="mt-2" style="border-top: 2px solid #198754; width: 100px; margin: 0 auto;"></div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <h5 class="mb-0 fw-bold text-warning-dark"><i class="bi bi-box-seam me-2"></i> Stock Valuation Report</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>
    
    <style>
    @media print {
        body { background: white !important; }
        .container, .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table { width: 100% !important; border: 1px solid #dee2e6 !important; }
        .table th { background-color: #f8f9fa !important; color: black !important; }
        .badge { border: 1px solid #ddd !important; background: transparent !important; color: black !important; }
        .text-warning-dark { color: #856404 !important; }
    }
    </style>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light text-uppercase small fw-bold">
                <tr>
                    <th class="ps-4">Product Info</th>
                    <th class="text-center">On Hand</th>
                    <th class="text-end">Avg Cost</th>
                    <th class="text-end">Total Cost Value</th>
                    <th class="text-end">Retail Price</th>
                    <th class="text-end pe-4">Total Retail Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($results)): ?>
                    <?php 
                    $grand_total_cost = 0;
                    $grand_total_retail = 0;
                    ?>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($row['product_name']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($row['product_code']) ?></div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border"><?= round($row['current_stock'], 2) ?></span>
                            </td>
                            <td class="text-end text-muted"><?= format_currency($row['purchase_price'] ?: $row['cost_price']) ?></td>
                            <td class="text-end fw-bold text-dark"><?= format_currency($row['total_cost_value']) ?></td>
                            <td class="text-end text-muted"><?= format_currency($row['selling_price']) ?></td>
                            <td class="text-end pe-4 fw-bold text-warning-dark"><?= format_currency($row['total_retail_value']) ?></td>
                        </tr>
                        <?php 
                        $grand_total_cost += $row['total_cost_value'];
                        $grand_total_retail += $row['total_retail_value'];
                        ?>
                    <?php endforeach; ?>
                    <tr class="table-light fw-bold fs-6">
                        <td colspan="3" class="ps-4">GRAND TOTAL</td>
                        <td class="text-end text-dark"><?= format_currency($grand_total_cost) ?></td>
                        <td></td>
                        <td class="text-end pe-4 text-warning-dark"><?= format_currency($grand_total_retail) ?></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-box display-4 d-block mb-3 opacity-25"></i>
                            No inventory data found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
$(document).ready(function() {
    logReportAction('Viewed Stock Valuation', 'User viewed the current stock valuation report');
});
</script>
