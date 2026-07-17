<?php
// scope-audit: skip — POS receipt print; POS scope deferred to Phase G-2
/**
 * API: Print POS Receipt
 * Generate printable receipt for completed sale
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';

if (!isAuthenticated()) { die("Unauthorized"); }
if (!canView('pos'))    { die("Permission denied"); }

$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($sale_id <= 0) {
    die("Invalid Sale ID");
}

global $pdo;

// Get sale details
$stmt = $pdo->prepare("
    SELECT
        s.*,
        c.customer_name,
        c.phone as customer_phone,
        u.username as cashier_name,
        w.warehouse_name
    FROM pos_sales s
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN warehouses w ON s.warehouse_id = w.warehouse_id
    WHERE s.sale_id = ?
");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    die("Sale not found");
}

// Warehouse-scope guard: a non-admin may only print a receipt for a sale
// drawn from their assigned warehouse(s).
$wid = $sale['warehouse_id'] !== null && $sale['warehouse_id'] !== '' ? (int)$sale['warehouse_id'] : null;
if ($wid !== null && !userCan('warehouse', $wid)) {
    die("Access denied: this warehouse is not in your assigned scope.");
}

// Log Activity
$username = $_SESSION['username'] ?? 'User';
logActivity($pdo, $_SESSION['user_id'], 'Print POS Receipt', "$username printed POS Receipt #{$sale['receipt_number']} (Total: " . number_format($sale['grand_total'], 2) . ")");

// Get sale items
$stmt = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id = ? ORDER BY sale_item_id");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Company info
$company_name = getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM');
$company_address = "Dar es Salaam, Tanzania";
$company_phone = "+255 123 456 789";
$company_tin = "123-456-789";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?= $sale['receipt_number'] ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            width: 80mm;
            margin: 0 auto;
            padding: 10px;
            font-size: 12px;
        }
        .header {
            text-align: center;
            border-bottom: 2px dashed #000;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        .company-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .receipt-info {
            margin: 10px 0;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }
        .receipt-info div {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
        }
        .items-table {
            width: 100%;
            margin: 10px 0;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }
        .item-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
        }
        .item-name {
            flex: 1;
        }
        .item-qty {
            width: 60px;
            text-align: center;
        }
        .item-price {
            width: 80px;
            text-align: right;
        }
        .totals {
            margin: 10px 0;
            border-bottom: 2px dashed #000;
            padding-bottom: 10px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
        }
        .grand-total {
            font-size: 14px;
            font-weight: bold;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 15px;
            font-size: 11px;
        }
        @media print {
            @page { margin: 0; }
            body { 
                width: 80mm; 
                margin: 0; 
                padding: 10px; /* Compensation for removed page margin */
            }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; margin-bottom: 10px;">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 14px; cursor: pointer;">
            Print Receipt
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 14px; cursor: pointer; margin-left: 10px;">
            Close
        </button>
    </div>

    <div class="header">
        <div class="company-name"><?= $company_name ?></div>
        <div><?= $company_address ?></div>
        <div>Tel: <?= $company_phone ?></div>
        <div>TIN: <?= $company_tin ?></div>
    </div>

    <div class="receipt-info">
        <div>
            <span>Receipt #:</span>
            <span><strong><?= $sale['receipt_number'] ?></strong></span>
        </div>
        <div>
            <span>Date:</span>
            <span><?= date('d/m/Y H:i', strtotime($sale['sale_date'])) ?></span>
        </div>
        <div>
            <span>Cashier:</span>
            <span><?= $sale['cashier_name'] ?? 'N/A' ?></span>
        </div>
        <?php if (!empty($sale['warehouse_name'])): ?>
        <div>
            <span>Warehouse:</span>
            <span><?= htmlspecialchars($sale['warehouse_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($sale['customer_name']): ?>
        <div>
            <span>Customer:</span>
            <span><?= $sale['customer_name'] ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="items-table">
        <div class="item-row" style="font-weight: bold; border-bottom: 1px solid #000; padding-bottom: 5px;">
            <div class="item-name">ITEM</div>
            <div class="item-qty">QTY</div>
            <div class="item-price">PRICE</div>
        </div>
        <?php foreach ($items as $item): ?>
        <div class="item-row">
            <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
            <div class="item-qty"><?= $item['quantity'] ?></div>
            <div class="item-price"><?= number_format($item['line_total'], 0) ?></div>
        </div>
        <div style="font-size: 10px; color: #666; margin-left: 5px;">
            @ <?= number_format($item['unit_price'], 0) ?> x <?= $item['quantity'] ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="totals">
        <div class="total-row">
            <span>Subtotal:</span>
            <span><?= number_format($sale['subtotal'], 0) ?></span>
        </div>
        <div class="total-row">
            <span>Total Tax:</span>
            <span><?= number_format($sale['tax_amount'], 0) ?></span>
        </div>
        <div class="total-row grand-total">
            <span>TOTAL:</span>
            <span>TZS <?= number_format($sale['grand_total'], 0) ?></span>
        </div>
        <div class="total-row" style="margin-top: 10px;">
            <span>Payment (<?= ucfirst(str_replace('_', ' ', $sale['payment_method'])) ?>):</span>
            <span><?= number_format($sale['amount_tendered'], 0) ?></span>
        </div>
        <div class="total-row">
            <span>Change:</span>
            <span><?= number_format($sale['change_given'], 0) ?></span>
        </div>
    </div>

    <div class="footer">
        <div style="margin-bottom: 10px;">*** THANK YOU ***</div>
        <div>Please keep this receipt for your records</div>
        <div style="margin-top: 10px;">Goods sold are not returnable</div>
    </div>

    <script>
        // Auto print on load (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
