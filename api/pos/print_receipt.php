<?php
// scope-audit: skip — POS receipt print; POS scope deferred to Phase G-2
/**
 * API: Print POS Receipt
 * Generate printable receipt for completed sale
 */
require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped strings below come back in the right
// language, not always English — same pattern used across api/pos/*.php.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';

if (!isAuthenticated()) { die("Unauthorized"); }
if (!canView('pos'))    { die("Permission denied"); }

$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($sale_id <= 0) {
    die("Invalid Sale ID");
}

global $pdo;

// Get sale details. The sale's own register_id/register_name (denormalised at
// sale time — see process_sale.php Phase 8) is the source of truth for which
// till it was rung up on; pos_registers is joined only for that register's
// optional receipt branding overrides.
$stmt = $pdo->prepare("
    SELECT
        s.*,
        c.customer_name,
        c.phone as customer_phone,
        c.email as customer_email,
        u.username as cashier_name,
        w.warehouse_name,
        r.register_code, r.receipt_header AS reg_receipt_header,
        r.receipt_footer AS reg_receipt_footer, r.receipt_logo AS reg_receipt_logo,
        r.printer_connection_type, r.printer_ip_address, r.printer_port, r.receipt_template
    FROM pos_sales s
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN warehouses w ON s.warehouse_id = w.warehouse_id
    LEFT JOIN pos_registers r ON s.register_id = r.register_id
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

// Company info — read from the tenant's own Company Profile settings (was
// hardcoded to placeholder BJP values, so every tenant's receipts printed the
// same fake address/phone/TIN regardless of who they actually are).
$company_name    = getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM');
$company_address = getSetting('company_physical_address', getSetting('company_address', ''));
$company_phone   = getSetting('company_phone', '');
$company_tin     = getSetting('company_tin', '');
$company_vrn     = getSetting('company_vrn', '');
$currency        = getSetting('currency', 'TZS'); // Phase 11 (pos_upgrade_plan.md §7) — was hardcoded 'TZS'

// Phase 10 (pos_upgrade_plan.md §7) — configurable paper width + auto-print,
// set on the POS Settings page (app/constant/settings/pos_config_settings.php).
$receipt_width = getSetting('pos_receipt_width', '80') === '58' ? '58' : '80';
$auto_print    = getSetting('pos_auto_print_receipt', '0') === '1';

// Phase 8 (pos_upgrade_plan.md §7) — a register (till) may override the receipt
// header/footer for its own counter (e.g. a branch name/location distinct from
// the company header). Falls back to the company-wide text when the register
// hasn't set its own — most tenants will never touch this and just get the
// company header, exactly as before.
$receipt_header_extra = trim($sale['reg_receipt_header'] ?? '');
$receipt_footer_extra = trim($sale['reg_receipt_footer'] ?? '');
$register_label        = trim($sale['register_name'] ?? '');

// Phase 22 (pos_upgrade_plan.md §8) — receipt layout variety, per register.
// 'classic' (the pre-existing, unmodified layout) is the default.
$receipt_template = in_array($sale['receipt_template'] ?? 'classic', ['classic', 'detailed', 'slim'], true)
    ? $sale['receipt_template'] : 'classic';

// Phase 21 (pos_upgrade_plan.md §8) — real network (IP) thermal-printer
// support. Only for a register explicitly configured for it; every other
// register is completely unaffected (printer_connection_type defaults to
// 'browser'). Fail-open: any socket error falls through to the existing
// browser print-dialog page below, never blocks the cashier from getting a
// receipt at all.
if (($sale['printer_connection_type'] ?? 'browser') === 'network' && !empty($sale['printer_ip_address'])) {
    require_once __DIR__ . '/../../core/escpos_printer.php';

    $receiptData = [
        'company_name' => $company_name, 'company_address' => $company_address,
        'company_phone' => $company_phone, 'company_tin' => $company_tin, 'company_vrn' => $company_vrn,
        'receipt_number' => $sale['receipt_number'], 'cashier_name' => $sale['cashier_name'],
        'sale_date' => $sale['sale_date'], 'customer_name' => $sale['customer_name'],
        'items' => array_map(fn($i) => [
            'product_name' => $i['product_name'], 'quantity' => $i['quantity'],
            'unit_price' => $i['unit_price'], 'line_total' => $i['line_total'],
        ], $items),
        'subtotal' => $sale['subtotal'], 'tax_amount' => $sale['tax_amount'],
        'discount_amount' => $sale['discount_amount'], 'grand_total' => $sale['grand_total'],
        'payment_method' => $sale['payment_method'], 'amount_tendered' => $sale['amount_tendered'],
        'change_given' => $sale['change_given'], 'currency' => $currency,
        'char_width' => $receipt_width === '58' ? 32 : 42,
    ];
    $bytes = buildEscPosReceipt($receiptData);
    $result = sendToNetworkPrinter((string)$sale['printer_ip_address'], (int)($sale['printer_port'] ?: 9100), $bytes);

    if ($result['success']) {
        logActivity($pdo, $_SESSION['user_id'], "Printed POS Receipt #{$sale['receipt_number']} to network printer {$sale['printer_ip_address']}");
        ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title><?= t('Receipt Printed') ?></title></head>
<body style="font-family:sans-serif;text-align:center;padding:40px;">
    <div style="font-size:48px;color:#198754;">&#10003;</div>
    <h3><?= t('Receipt sent to the till printer.') ?></h3>
    <p style="color:#6c757d;"><?= sprintf(t('Receipt #%s'), $sale['receipt_number']) ?></p>
    <button onclick="window.close()" style="padding:10px 20px;font-size:14px;"><?= t('Close') ?></button>
</body></html>
        <?php
        exit;
    }
    // Fail-open: log the reason, fall through to the browser print page below.
    error_log("Network printer failed for sale #{$sale['sale_id']}: {$result['error']}");
}
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
            width: <?= $receipt_width ?>mm;
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
                width: <?= $receipt_width ?>mm;
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
            <?= t('Print Receipt') ?>
        </button>
        <button onclick="emailReceipt()" style="padding: 10px 20px; font-size: 14px; cursor: pointer; margin-left: 10px;">
            <?= t('Email Receipt') ?>
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 14px; cursor: pointer; margin-left: 10px;">
            <?= t('Close') ?>
        </button>
    </div>

    <div class="header">
        <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
        <?php if ($company_address !== ''): ?><div><?= htmlspecialchars($company_address) ?></div><?php endif; ?>
        <?php if ($company_phone !== ''): ?><div><?= t('Tel:') ?> <?= htmlspecialchars($company_phone) ?></div><?php endif; ?>
        <?php if ($company_tin !== ''): ?><div><?= t('TIN:') ?> <?= htmlspecialchars($company_tin) ?></div><?php endif; ?>
        <?php if ($company_vrn !== ''): ?><div><?= t('VRN:') ?> <?= htmlspecialchars($company_vrn) ?></div><?php endif; ?>
        <?php if ($receipt_header_extra !== ''): ?><div><?= nl2br(htmlspecialchars($receipt_header_extra)) ?></div><?php endif; ?>
    </div>

    <div class="receipt-info">
        <div>
            <span><?= t('Receipt #:') ?></span>
            <span><strong><?= $sale['receipt_number'] ?></strong></span>
        </div>
        <div>
            <span><?= t('Date:') ?></span>
            <span><?= date('d/m/Y H:i', strtotime($sale['sale_date'])) ?></span>
        </div>
        <div>
            <span><?= t('Cashier:') ?></span>
            <span><?= $sale['cashier_name'] ?? t('N/A') ?></span>
        </div>
        <?php if ($register_label !== ''): ?>
        <div>
            <span><?= t('Register:') ?></span>
            <span><?= htmlspecialchars($register_label) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($sale['warehouse_name'])): ?>
        <div>
            <span><?= t('Warehouse:') ?></span>
            <span><?= htmlspecialchars($sale['warehouse_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($sale['customer_name']): ?>
        <div>
            <span><?= t('Customer:') ?></span>
            <span><?= $sale['customer_name'] ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="items-table">
        <div class="item-row" style="font-weight: bold; border-bottom: 1px solid #000; padding-bottom: 5px;">
            <div class="item-name"><?= t('ITEM') ?></div>
            <div class="item-qty"><?= t('QTY') ?></div>
            <div class="item-price"><?= t('PRICE') ?></div>
        </div>
        <?php foreach ($items as $item): ?>
        <div class="item-row">
            <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
            <div class="item-qty"><?= $item['quantity'] ?></div>
            <div class="item-price"><?= number_format($item['line_total'], 0) ?></div>
        </div>
        <?php if ($receipt_template !== 'slim'): ?>
        <div style="font-size: 10px; color: #666; margin-left: 5px;">
            @ <?= number_format($item['unit_price'], 0) ?> x <?= $item['quantity'] ?>
            <?php if ($receipt_template === 'detailed' && (float)($item['discount_amount'] ?? 0) > 0.009): ?>
                — <?= t('Discount:') ?> -<?= number_format($item['discount_amount'], 0) ?>
            <?php endif; ?>
            <?php if ($receipt_template === 'detailed' && (float)($item['tax_rate'] ?? 0) > 0.009): ?>
                — <?= t('VAT:') ?> <?= number_format($item['tax_rate'], 0) ?>%
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="totals">
        <?php if ($receipt_template !== 'slim'): ?>
        <div class="total-row">
            <span><?= t('Subtotal:') ?></span>
            <span><?= number_format($sale['subtotal'], 0) ?></span>
        </div>
        <?php if ($receipt_template === 'detailed' && (float)($sale['discount_amount'] ?? 0) > 0.009): ?>
        <div class="total-row">
            <span><?= t('Discount:') ?></span>
            <span>-<?= number_format($sale['discount_amount'], 0) ?></span>
        </div>
        <?php endif; ?>
        <div class="total-row">
            <span><?= t('Total Tax:') ?></span>
            <span><?= number_format($sale['tax_amount'], 0) ?></span>
        </div>
        <?php endif; ?>
        <div class="total-row grand-total">
            <span><?= t('TOTAL:') ?></span>
            <span><?= htmlspecialchars($currency) ?> <?= number_format($sale['grand_total'], 0) ?></span>
        </div>
        <div class="total-row" style="margin-top: 10px;">
            <span><?= sprintf(t('Payment (%s):'), t(ucfirst(str_replace('_', ' ', $sale['payment_method'])))) ?></span>
            <span><?= number_format($sale['amount_tendered'], 0) ?></span>
        </div>
        <div class="total-row">
            <span><?= t('Change:') ?></span>
            <span><?= number_format($sale['change_given'], 0) ?></span>
        </div>
    </div>

    <div class="footer">
        <div style="margin-bottom: 10px;">*** <?= t('THANK YOU') ?> ***</div>
        <div><?= t('Please keep this receipt for your records') ?></div>
        <div style="margin-top: 10px;"><?= t('Goods sold are not returnable') ?></div>
        <?php if ($receipt_footer_extra !== ''): ?>
        <div style="margin-top: 10px;"><?= nl2br(htmlspecialchars($receipt_footer_extra)) ?></div>
        <?php endif; ?>
    </div>

    <script>
        // Phase 10 (pos_upgrade_plan.md §7) — Email Receipt.
        function emailReceipt() {
            const email = prompt(<?= json_encode(t('Send this receipt to which email address?')) ?>, <?= json_encode($sale['customer_email'] ?? '') ?>);
            if (!email) return;
            const fd = new FormData();
            fd.append('sale_id', <?= (int)$sale_id ?>);
            fd.append('email', email);
            fd.append('_csrf', <?= json_encode(csrf_token()) ?>);
            fetch('<?= buildUrl('/api/pos/email_receipt.php') ?>', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => alert(res.message))
                .catch(() => alert(<?= json_encode(t('Could not reach the server. Please try again.')) ?>));
        }
    </script>

    <?php if ($auto_print): ?>
    <script>
        // Phase 10 (pos_upgrade_plan.md §7) — "Automatically print the receipt"
        // POS setting. Still just the browser's print dialog / OS default
        // printer — a plain web app cannot silently print without one.
        window.onload = function () { window.print(); };
    </script>
    <?php endif; ?>
</body>
</html>
