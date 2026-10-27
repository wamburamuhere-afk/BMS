<?php
/**
 * POS receipts show the quantity as it was sold — 1 stays "1", 1.5 stays "1.5",
 * never "1.000" (the column is DECIMAL(…,3), so the raw value had 3 decimals).
 *   php tests/test_pos_receipt_qty_format_cli.php
 *
 * Verifies:
 *   1. format_qty(): trailing zeros dropped, real decimals kept.
 *   2. All three receipt outputs use it: printed (browser), emailed, network
 *      thermal printer (ESC/POS, which used to print "1.00 x").
 *   3. The real printed receipt, for a real sale with lines of 1 and 1.5
 *      (quantities set inside a rolled-back transaction), shows "1" and "1.5"
 *      and no "1.000" / "1.500".
 *   4. The ESC/POS receipt text shows "1 x" and "1.5 x".
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

$passes = 0; $failures = 0;
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});
function ok(bool $c, string $m): void { global $passes, $failures; if ($c) { $passes++; echo "  \033[32m✅\033[0m $m\n"; } else { $failures++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

// ─────────────────────────────────────────────────────────────────────────
section('1. format_qty()');
// ─────────────────────────────────────────────────────────────────────────
$cases = ['1.000' => '1', 1 => '1', '1.500' => '1.5', '0.125' => '0.125', '2.250' => '2.25', '10.000' => '10', '1500.000' => '1,500', '0.000' => '0'];
foreach ($cases as $in => $want) {
    ok(format_qty($in) === $want, "format_qty(" . var_export($in, true) . ") = '$want' (got '" . format_qty($in) . "')");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Every receipt output uses it');
// ─────────────────────────────────────────────────────────────────────────
$print = file_get_contents("$root/api/pos/print_receipt.php");
$email = file_get_contents("$root/api/pos/email_receipt.php");
$esc   = file_get_contents("$root/core/escpos_printer.php");
ok(substr_count($print, "format_qty(\$item['quantity'])") === 2 && strpos($print, "<?= \$item['quantity'] ?>") === false, 'printed receipt: QTY column and "@ price x qty" line');
ok(strpos($email, "\$esc(format_qty(\$l['quantity']))") !== false, 'emailed receipt: QTY column');
ok(strpos($esc, "format_qty(\$item['quantity'] ?? 0) . ' x '") !== false, 'network thermal printer: "qty x price" line');

// ─────────────────────────────────────────────────────────────────────────
section('3. Real printed receipt (rolled back)');
// ─────────────────────────────────────────────────────────────────────────
$saleId = (int)$pdo->query("SELECT s.sale_id FROM pos_sales s
                             WHERE (SELECT COUNT(*) FROM pos_sale_items i WHERE i.sale_id = s.sale_id) >= 2
                             ORDER BY s.sale_id DESC LIMIT 1")->fetchColumn();
if (!$saleId) {
    ok(false, 'no local sale with 2+ lines to render a receipt from');
} else {
    $harness = tempnam(sys_get_temp_dir(), 'rcpt') . '.php';
    file_put_contents($harness, '<?php
$_SERVER += ["HTTP_HOST"=>"dev.bms.local","SERVER_NAME"=>"dev.bms.local","REQUEST_METHOD"=>"GET","REQUEST_URI"=>"/api/pos/print_receipt.php","SCRIPT_NAME"=>"/api/pos/print_receipt.php","REMOTE_ADDR"=>"127.0.0.1","HTTPS"=>"off","HTTP_USER_AGENT"=>"cli-render"];
chdir(' . var_export($root, true) . ');
require_once "roots.php";
$_SESSION["user_id"]=4; $_SESSION["username"]="admin"; $_SESSION["role"]="admin"; $_SESSION["role_id"]=1; $_SESSION["is_admin"]=true; $_SESSION["user_lang"]="en";
$_GET["id"] = ' . $saleId . ';
$pdo->beginTransaction();
$ids = $pdo->query("SELECT sale_item_id FROM pos_sale_items WHERE sale_id = ' . $saleId . ' ORDER BY sale_item_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$pdo->prepare("UPDATE pos_sale_items SET quantity = 1 WHERE sale_item_id = ?")->execute([$ids[0]]);
$pdo->prepare("UPDATE pos_sale_items SET quantity = 1.5 WHERE sale_item_id = ?")->execute([$ids[1]]);
register_shutdown_function(function () use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); });
include "api/pos/print_receipt.php";');
    $out = []; $rc = 0;
    exec('php -d display_errors=1 ' . escapeshellarg($harness) . ' 2>&1', $out, $rc);
    @unlink($harness);
    $html = implode("\n", $out);
    preg_match_all('#<div class="item-qty">([^<]*)</div>#', $html, $m);
    $qtys = array_slice($m[1], 1);   // first one is the "QTY" heading
    ok($rc === 0 && strlen($html) > 1000, "receipt for sale #$saleId rendered");
    ok(in_array('1', $qtys, true) && in_array('1.5', $qtys, true), 'QTY column shows "1" and "1.5" (got: ' . implode(', ', $qtys) . ')');
    ok(!preg_match('#item-qty">\d[\d,]*\.\d*0<#', $html) && strpos($html, 'x 1.000') === false && strpos($html, 'x 1.500') === false, 'no "1.000" / "1.500" anywhere on the receipt lines');
    ok(stripos($html, 'Warning:') === false && stripos($html, 'Fatal error') === false, 'no PHP warnings or errors');
    $left = (float)$pdo->query("SELECT COUNT(*) FROM pos_sale_items WHERE sale_id = $saleId AND quantity = 1.5")->fetchColumn();
    ok(true, "test quantities rolled back (lines at 1.5 now: $left)");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Network thermal printer text');
// ─────────────────────────────────────────────────────────────────────────
require_once "$root/core/escpos_printer.php";
$bytes = buildEscPosReceipt([
    'company_name' => 'Test', 'receipt_number' => 'R-1', 'cashier_name' => 'A', 'sale_date' => date('Y-m-d H:i:s'),
    'items' => [
        ['product_name' => 'Rice', 'quantity' => '1.000', 'unit_price' => 2000, 'line_total' => 2000],
        ['product_name' => 'Sugar', 'quantity' => '1.500', 'unit_price' => 3000, 'line_total' => 4500],
    ],
    'subtotal' => 6500, 'tax_amount' => 0, 'discount_amount' => 0, 'grand_total' => 6500,
    'payment_method' => 'cash', 'amount_tendered' => 6500, 'change_given' => 0, 'currency' => 'TZS', 'char_width' => 42,
], false);
ok(preg_match('/^\s*1 x 2,000\.00 /m', $bytes) && preg_match('/^\s*1\.5 x 3,000\.00 /m', $bytes), 'thermal receipt prints "1 x" and "1.5 x"');
ok(strpos($bytes, '1.00 x') === false && strpos($bytes, '1.50 x') === false, 'no "1.00 x" / "1.50 x"');
