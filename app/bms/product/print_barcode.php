<?php
// File: app/bms/product/print_barcode.php
// scope-audit: skip — barcode print for specific product; product catalog is global; no project scope needed
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . getUrl('login'));
    exit();
}

// Phase 5d — print pages get a canView gate (admin auto-bypass)
if (!canView('products')) die("Access Denied");

$product_id = intval($_GET['product_id'] ?? 0);
$quantity   = max(1, min(100, intval($_GET['quantity'] ?? 10)));

if (!$product_id) {
    echo '<div style="color:red;padding:20px;">Invalid product ID.</div>';
    exit();
}

$stmt = $pdo->prepare("
    SELECT p.*, c.category_name, b.brand_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    LEFT JOIN brands b ON p.brand_id = b.brand_id
    WHERE p.product_id = ?
");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo '<div style="color:red;padding:20px;">Product not found.</div>';
    exit();
}

$company_name = getSetting('company_name', 'BMS');
$barcode_val  = htmlspecialchars($product['barcode'] ?: $product['sku'] ?: 'N/A');
$product_name = htmlspecialchars($product['product_name']);
$sku          = htmlspecialchars($product['sku'] ?? 'N/A');
$price        = format_currency($product['selling_price']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Barcode - <?= $product_name ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .controls {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .controls h4 { color: #333; font-size: 16px; }
        .controls .btn {
            background: #0d6efd;
            color: #fff;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        .controls .btn-secondary {
            background: #6c757d;
            margin-right: 8px;
        }
        .barcode-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .barcode-label {
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 10px 12px;
            width: 200px;
            text-align: center;
            page-break-inside: avoid;
        }
        .company-name {
            font-size: 8px;
            text-transform: uppercase;
            color: #555;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .product-name {
            font-size: 10px;
            font-weight: 700;
            color: #111;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 6px;
        }
        .barcode-lines {
            display: flex;
            justify-content: center;
            align-items: flex-end;
            height: 50px;
            gap: 1px;
            margin-bottom: 4px;
        }
        .bar { background: #000; width: 2px; display: inline-block; }
        .barcode-text {
            font-size: 9px;
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
            color: #000;
            margin-bottom: 3px;
        }
        .sku-text {
            font-size: 8px;
            color: #666;
        }
        .price-text {
            font-size: 10px;
            font-weight: 700;
            color: #0d6efd;
            margin-top: 3px;
        }

        @media print {
            body { background: white; padding: 5mm; }
            .controls { display: none !important; }
            .barcode-label {
                border: 0.5pt solid #999;
                width: 50mm;
                padding: 4pt;
            }
            .barcode-grid { gap: 4pt; }
        }
    </style>
</head>
<body>
    <div class="controls">
        <h4>🔖 Barcode Labels — <?= $product_name ?> (<?= $quantity ?> labels)</h4>
        <div>
            <button class="btn btn-secondary" onclick="history.back()">← Back</button>
            <button class="btn" onclick="window.print()">🖨 Print Labels</button>
        </div>
    </div>

    <div class="barcode-grid" id="barcodeGrid">
        <?php for ($i = 0; $i < $quantity; $i++): ?>
        <div class="barcode-label">
            <div class="company-name"><?= $company_name ?></div>
            <div class="product-name"><?= $product_name ?></div>
            <div class="barcode-lines" id="barcode_<?= $i ?>">
                <!-- JS-generated barcode lines -->
            </div>
            <div class="barcode-text"><?= $barcode_val ?></div>
            <div class="sku-text">SKU: <?= $sku ?></div>
            <div class="price-text"><?= $price ?></div>
        </div>
        <?php endfor; ?>
    </div>

    <script>
    // Simple visual barcode generator (Code-39 style visual representation)
    const barcodeValue = <?= json_encode($barcode_val) ?>;
    const quantity = <?= $quantity ?>;

    function generateBarsHTML(value) {
        // Generate a deterministic sequence of bars from the char codes
        let html = '';
        const chars = value.split('');
        chars.forEach(ch => {
            const code = ch.charCodeAt(0);
            // Narrow bar
            html += `<div class="bar" style="height:${28 + (code % 12)}px; width:${(code % 2 === 0) ? '2px' : '3px'};"></div>`;
            // Space (invisible narrow bar)
            html += `<div class="bar" style="height:1px; width:${(code % 3 === 0) ? '3px' : '2px'}; background:transparent;"></div>`;
        });
        // End guard
        html += `<div class="bar" style="height:42px; width:2px;"></div>`;
        html += `<div class="bar" style="height:42px; width:1px; background:transparent;"></div>`;
        html += `<div class="bar" style="height:42px; width:2px;"></div>`;
        return html;
    }

    for (let i = 0; i < quantity; i++) {
        const el = document.getElementById('barcode_' + i);
        if (el) el.innerHTML = generateBarsHTML(barcodeValue);
    }
    </script>
</body>
</html>
