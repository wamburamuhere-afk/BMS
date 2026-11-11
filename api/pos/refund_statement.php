<?php
// scope-audit: skip — read-only print of one sale's own refunds; same canView('pos') + warehouse-scope guard as print_receipt.php.
/**
 * API: Refund statement for one POS receipt (2026-11-11)
 * GET: id = the ORIGINAL sale's sale_id
 *
 * A receipt that was refunded in several steps (RET-0006, RET-0008, RET-0011 …) keeps one
 * numbered refund receipt per step, but the Actions menu shows a single "View Refunds"
 * that opens this page: every refund of the receipt, what came back in each, and the totals.
 * Each refund still opens its own receipt from here (api/pos/print_receipt.php?id=<refund>).
 * Display only — reads rows create_return.php already wrote; nothing is written.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php'; mobileBearerAuth();
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_receipt_returns.php';

if (!isAuthenticated()) { die("Unauthorized"); }
if (!canView('pos'))    { die("Permission denied"); }

$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($sale_id <= 0) { die("Invalid Sale ID"); }

global $pdo;

$stmt = $pdo->prepare("
    SELECT s.*, c.customer_name, u.username AS cashier_name, w.warehouse_name
      FROM pos_sales s
      LEFT JOIN customers c ON s.customer_id = c.customer_id
      LEFT JOIN users u ON s.user_id = u.user_id
      LEFT JOIN warehouses w ON s.warehouse_id = w.warehouse_id
     WHERE s.sale_id = ?
");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sale) { die("Sale not found"); }

// A refund row has no statement of its own — it is one refund receipt.
if ((int)$sale['is_return_sale'] === 1) {
    header('Location: ' . buildUrl('api/pos/print_receipt.php') . '?id=' . $sale_id);
    exit;
}

if (empty($sale['customer_name'])) {
    $sale['customer_name'] = t('Walk-in Customer');
}

$wid = $sale['warehouse_id'] !== null && $sale['warehouse_id'] !== '' ? (int)$sale['warehouse_id'] : null;
if ($wid !== null && !userCan('warehouse', $wid)) {
    die(isShopLabel(true) ? "Access denied: this shop is not in your assigned scope." : "Access denied: this warehouse is not in your assigned scope.");
}

$username = $_SESSION['username'] ?? 'User';
logActivity($pdo, $_SESSION['user_id'], 'Print POS Refund Statement', "$username printed the refund statement of POS Receipt #{$sale['receipt_number']}");

$st = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id = ? ORDER BY sale_item_id");
$st->execute([$sale_id]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);
$ret = posReceiptReturns($pdo, $sale, $items);

// Every live refund of this receipt, oldest first, with the lines that came back in each.
$rs = $pdo->prepare("SELECT * FROM pos_sales
                      WHERE original_sale_id = ? AND is_return_sale = 1 AND sale_status NOT IN ('voided','cancelled')
                   ORDER BY sale_id");
$rs->execute([$sale_id]);
$refunds = $rs->fetchAll(PDO::FETCH_ASSOC);
$linesByRefund = [];
if ($refunds) {
    $ids = array_map(fn($r) => (int)$r['sale_id'], $refunds);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $li  = $pdo->prepare("SELECT sale_id, product_name, quantity, line_total FROM pos_sale_items WHERE sale_id IN ($ph) ORDER BY sale_item_id");
    $li->execute($ids);
    foreach ($li->fetchAll(PDO::FETCH_ASSOC) as $l) $linesByRefund[(int)$l['sale_id']][] = $l;
}

$company_name    = getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM');
$company_address = getSetting('company_physical_address', getSetting('company_address', ''));
$company_phone   = getSetting('company_phone', '');
$company_tin     = getSetting('company_tin', '');
$company_vrn     = getSetting('company_vrn', '');
$currency        = getSetting('currency', 'TZS');
$receipt_width   = getSetting('pos_receipt_width', '80') === '58' ? '58' : '80';
$methodLabel     = fn(string $m) => t(ucfirst(str_replace('_', ' ', $m)));
$esc             = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

$refunded_total = 0.0;
foreach ($refunds as $r) $refunded_total += round((float)$r['grand_total'], 2);
$refunded_total = round($refunded_total, 2);
$sold_total     = round((float)$sale['grand_total'], 2);
$net_paid       = round($sold_total - $refunded_total, 2);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $esc(t('REFUND STATEMENT')) ?> <?= $esc($sale['receipt_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; width: <?= $receipt_width ?>mm; margin: 0 auto; padding: 10px; font-size: 13px; }
        .header { text-align: center; border-bottom: 2px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
        .company-name { font-size: 16px; font-weight: bold; margin-bottom: 5px; }
        .receipt-info { margin: 10px 0; border-bottom: 1px dashed #000; padding-bottom: 10px; }
        .row { display: flex; justify-content: space-between; margin: 3px 0; gap: 8px; }
        .refund-block { border-bottom: 1px dashed #000; padding: 8px 0; }
        .refund-head { display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 4px; }
        .line-name { flex: 1; }
        .line-qty { width: 40px; text-align: center; }
        .line-amt { width: 70px; text-align: right; }
        .sub { color: #555; font-size: 11px; }
        .totals { margin: 10px 0; border-bottom: 2px dashed #000; padding-bottom: 10px; }
        .grand { font-size: 14px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px; margin-top: 5px; }
        .refund-row { color: #9a2a1f; }
        .refund-stamp { border: 1.5px solid #000; text-align: center; font-weight: bold; padding: 4px; margin-top: 8px; letter-spacing: .05em; }
        .refund-title { text-align: center; font-weight: bold; font-size: 14px; letter-spacing: .05em; margin-bottom: 6px; }
        .footer { text-align: center; margin-top: 15px; font-size: 11px; }
        @media print {
            @page { size: <?= $receipt_width ?>mm auto; margin: 3mm 5mm; }
            body { width: <?= $receipt_width ?>mm; margin: 0; padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:center;margin-bottom:10px;">
        <button onclick="window.print()" style="padding:10px 20px;font-size:14px;cursor:pointer;"><?= $esc(t('Print')) ?></button>
        <button onclick="window.close()" style="padding:10px 20px;font-size:14px;cursor:pointer;margin-left:10px;"><?= $esc(t('Close')) ?></button>
    </div>

    <div class="header">
        <div class="company-name"><?= $esc($company_name) ?></div>
        <?php if ($company_address !== ''): ?><div><?= $esc($company_address) ?></div><?php endif; ?>
        <?php if ($company_phone !== ''): ?><div><?= $esc(t('Tel:')) ?> <?= $esc($company_phone) ?></div><?php endif; ?>
        <?php if ($company_tin !== ''): ?><div><?= $esc(t('TIN:')) ?> <?= $esc($company_tin) ?></div><?php endif; ?>
        <?php if ($company_vrn !== ''): ?><div><?= $esc(t('VRN:')) ?> <?= $esc($company_vrn) ?></div><?php endif; ?>
    </div>

    <div class="receipt-info">
        <div class="refund-title"><?= $esc(t('REFUND STATEMENT')) ?></div>
        <div class="row"><span><?= $esc(t('For receipt #:')) ?></span><span><strong><?= $esc($sale['receipt_number']) ?></strong></span></div>
        <div class="row"><span><?= $esc(t('Sold on:')) ?></span><span><?= date('d/m/Y H:i', strtotime($sale['sale_date'])) ?></span></div>
        <?php if (!empty($sale['warehouse_name'])): ?>
        <div class="row"><span><?= $esc(wLabel('Warehouse:', 'Shop:', true)) ?></span><span><?= $esc(caseFormat($sale['warehouse_name'])) ?></span></div>
        <?php endif; ?>
        <div class="row"><span><?= $esc(t('Customer:')) ?></span><span><?= $esc(caseFormat($sale['customer_name'])) ?></span></div>
    </div>

    <?php if (!$refunds): ?>
    <div class="refund-block" style="text-align:center;"><?= $esc(t('No refunds have been made on this receipt.')) ?></div>
    <?php endif; ?>

    <?php foreach ($refunds as $r):
        $rid  = (int)$r['sale_id'];
        $info = posReceiptReturns($pdo, $r, []);   // reason, how it was settled (paid back / off the amount owed)
    ?>
    <div class="refund-block">
        <div class="refund-head">
            <span><?= $esc($r['receipt_number']) ?></span>
            <span><?= date('d/m/Y H:i', strtotime($r['sale_date'])) ?></span>
        </div>
        <?php foreach (($linesByRefund[$rid] ?? []) as $l): ?>
        <div class="row">
            <span class="line-name"><?= $esc(caseFormat($l['product_name'])) ?></span>
            <span class="line-qty"><?= $esc(format_qty($l['quantity'])) ?></span>
            <span class="line-amt"><?= number_format((float)$l['line_total'], 0) ?></span>
        </div>
        <?php endforeach; ?>
        <div class="row" style="font-weight:bold;margin-top:4px;">
            <span><?= $esc(t('TOTAL REFUNDED:')) ?></span>
            <span><?= $esc($currency) ?> <?= number_format($info['sold_total'], 0) ?></span>
        </div>
        <?php if ($info['to_balance'] > 0.009): ?>
        <div class="row sub"><span><?= $esc(t('Taken off amount owed:')) ?></span><span><?= number_format($info['to_balance'], 0) ?></span></div>
        <?php endif; ?>
        <?php if ($info['cash_back'] > 0.009): ?>
        <div class="row sub"><span><?= $esc(sprintf(t('Paid back (%s):'), $methodLabel($info['method']))) ?></span><span><?= number_format($info['cash_back'], 0) ?></span></div>
        <?php endif; ?>
        <?php if ($info['reason'] !== ''): ?>
        <div class="row sub"><span><?= $esc(t('Reason:')) ?></span><span><?= $esc($info['reason']) ?></span></div>
        <?php endif; ?>
        <div class="no-print" style="margin-top:4px;"><a href="<?= $esc(buildUrl('api/pos/print_receipt.php')) ?>?id=<?= $rid ?>"><?= $esc(t('View refund receipt')) ?> &rsaquo;</a></div>
    </div>
    <?php endforeach; ?>

    <?php if ($refunds): ?>
    <div class="totals">
        <div class="row"><span><?= $esc(t('Sold total:')) ?></span><span><?= $esc($currency) ?> <?= number_format($sold_total, 0) ?></span></div>
        <div class="row refund-row"><span><?= $esc(t('TOTAL REFUNDED:')) ?></span><span>-<?= number_format($refunded_total, 0) ?></span></div>
        <div class="row grand"><span><?= $esc(t('NET PAID:')) ?></span><span><?= $esc($currency) ?> <?= number_format($net_paid, 0) ?></span></div>
        <?php if ($ret['stamp'] !== ''): ?>
        <div class="refund-stamp"><?= $esc(t($ret['stamp'])) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="footer">
        <div style="margin-bottom:10px;">*** <?= $esc(t('THANK YOU')) ?> ***</div>
        <div><?= $esc(t('Please keep this receipt for your records')) ?></div>
    </div>
</body>
</html>
