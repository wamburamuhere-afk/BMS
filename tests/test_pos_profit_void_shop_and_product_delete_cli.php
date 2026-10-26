<?php
/**
 * POS profit report: voided sales per shop + product delete keeps history — CLI test
 *   php tests/test_pos_profit_void_shop_and_product_delete_cli.php
 *
 * Bugs fixed (2026-10-26):
 *  1. A reversal (void) entry was saved without the original's warehouse_id, so
 *     the per-shop Profit Report kept a voided sale (All Shops netted to 0, the
 *     shop still showed the sale). Reversals now inherit the warehouse; a
 *     backfill fixes the ones already posted.
 *  2. Deleting a product erased its stock movements (and on mobile its batches)
 *     and never looked at stock. Now: refused while any shop holds stock; the
 *     delete keeps every sale, movement, batch and ledger entry.
 *  3/4. Profit Report: changing a date did not reload; the printout showed the
 *     period from the URL instead of the one on screen.
 *  +  Wholesale-above-retail confirmation (assets/js/bms-price-check.js).
 * Runtime parts drive the real helpers in ROLLED-BACK transactions; the
 * endpoint run creates and removes its own product.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/ledger_post.php";
require_once "$root/core/expense_posting.php";      // reverseAccrualEntry
require_once "$root/core/financial_reports.php";    // glProfitLoss
require_once "$root/core/gl_accounts.php";
require_once "$root/core/sales_posting.php";        // posReceiptAccountId
require_once "$root/core/product_delete.php";
require_once "$root/core/stock_ledger.php";         // recordStockMovement
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'cli'; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p = false; if ($p) return; $p = true;
    echo "\nPasses: \033[32m$pass\033[0m   Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ── 1. Source contract — every reversal path carries the warehouse ──────────
section('1. Reversal paths pass the original warehouse_id');
$src = file_get_contents("$root/core/expense_posting.php");
ok(strpos($src, 'SELECT entry_date, project_id, warehouse_id FROM journal_entries') !== false, 'reverseAccrualEntry reads the original warehouse_id');
ok(preg_match("/'_void', \\\$date, \\\$userId, \\\$whId\\)/", $src) === 1, 'reverseAccrualEntry posts the reversal with it');
$src = file_get_contents("$root/core/money_in_posting.php");
ok(strpos($src, "'payment_void', date('Y-m-d'), \$userId, \$whId)") !== false, 'payment void carries it');
$src = file_get_contents("$root/core/payment_source.php");
ok(strpos($src, "'books_transaction_void', date('Y-m-d'), \$userId, \$whId)") !== false, 'books-transaction reversal carries it');
$src = file_get_contents("$root/api/account/reverse_journal.php");
ok(strpos($src, 'reverses_entry_id, project_id, warehouse_id, created_at') !== false, 'manual journal reversal carries it');
ok(is_file("$root/migrations/tenant/2026_10_26_reversal_warehouse_backfill.php")
   && is_file("$root/migrations/2026_10_26_reversal_warehouse_backfill_legacy_db.php"), 'backfill migration exists for tenant + main DB');

// ── 2. Runtime — a voided POS sale nets to zero for its shop ────────────────
section('2. Runtime — voided POS sale nets to zero in the per-shop P&L');
$wh   = (int)$pdo->query("SELECT warehouse_id FROM warehouses ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$rev  = (int)salesRevenueAccountId($pdo);
$cash = (int)posReceiptAccountId($pdo, 'cash');
ok($wh > 0 && $rev > 0 && $cash > 0, "have a warehouse (#$wh), sales revenue (#$rev) and cash (#$cash) account");

$FAKE = 999000777; $amt = 8000.00; $day = date('Y-m-d');
$pdo->beginTransaction();
try {
    $before = glProfitLoss($pdo, $day, $day, null, '', $wh)['total_revenue'];
    $orig = postLedgerEntry($pdo, 'POS sale test', [
        ['account_id' => $cash, 'type' => 'debit',  'amount' => $amt, 'description' => 'test'],
        ['account_id' => $rev,  'type' => 'credit', 'amount' => $amt, 'description' => 'test'],
    ], null, $FAKE, 'pos_sale', $day, 4, $wh);
    $mid = glProfitLoss($pdo, $day, $day, null, '', $wh)['total_revenue'];
    ok(abs(($mid - $before) - $amt) < 0.01, "sale shows in the shop's revenue (+$amt)");

    $r = reverseAccrualEntry($pdo, 'pos_sale', $FAKE, 4);
    ok(!empty($r['reversed']) && !empty($r['entry_id']), 'sale reversed (void)');
    $revWh = $pdo->query("SELECT warehouse_id FROM journal_entries WHERE entry_id = " . (int)$r['entry_id'])->fetchColumn();
    ok((int)$revWh === $wh, "reversal carries the shop (warehouse #$wh)");
    $after = glProfitLoss($pdo, $day, $day, null, '', $wh)['total_revenue'];
    ok(abs($after - $before) < 0.01, 'shop revenue back to where it was — the voided sale no longer counts');

    // ── 3. Backfill repairs a reversal saved the old way (warehouse NULL) ──
    section('3. Runtime — backfill gives old reversals their shop');
    $pdo->exec("UPDATE journal_entries SET warehouse_id = NULL WHERE entry_id = " . (int)$r['entry_id']);
    $broken = glProfitLoss($pdo, $day, $day, null, '', $wh)['total_revenue'];
    ok(abs(($broken - $before) - $amt) < 0.01, 'reproduced the bug: with a NULL-shop reversal the shop still shows the sale');
    $n = backfillReversalWarehouses($pdo);
    ok($n >= 1, "backfill updated $n reversal(s)");
    $fixedWh = $pdo->query("SELECT warehouse_id FROM journal_entries WHERE entry_id = " . (int)$r['entry_id'])->fetchColumn();
    ok((int)$fixedWh === $wh, 'the reversal now has the original shop');
    ok(abs(glProfitLoss($pdo, $day, $day, null, '', $wh)['total_revenue'] - $before) < 0.01, 'shop revenue nets to zero again');
    $chk = $pdo->prepare("SELECT warehouse_id FROM journal_entries WHERE entry_id = ?");
    $n2 = backfillReversalWarehouses($pdo);
    $chk->execute([(int)$r['entry_id']]);
    ok((int)$chk->fetchColumn() === $wh, "second run leaves it as is (idempotent; touched $n2 other row(s))");

    // Manual journal reversal link (reverses_entry_id)
    $man = postLedgerEntry($pdo, 'Manual reversal test', [
        ['account_id' => $rev,  'type' => 'debit',  'amount' => 10, 'description' => 'test'],
        ['account_id' => $cash, 'type' => 'credit', 'amount' => 10, 'description' => 'test'],
    ], null, null, null, $day, 4, null);
    $pdo->prepare("UPDATE journal_entries SET reverses_entry_id = ? WHERE entry_id = ?")->execute([$orig, $man]);
    backfillReversalWarehouses($pdo);
    $chk->execute([$man]);
    ok((int)$chk->fetchColumn() === $wh, 'a reversal linked by reverses_entry_id gets its shop too');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

// ── 4. Product delete — stock blocks it, history is kept ────────────────────
section('4. Product delete rule (core/product_delete.php)');
$src = file_get_contents("$root/api/delete_product.php");
ok(strpos($src, 'DELETE FROM stock_movements') === false, 'web delete no longer erases stock movements');
ok(strpos($src, 'productStockBlocker(') !== false && strpos($src, 'deleteProductKeepHistory(') !== false, 'web delete uses the shared rule');
ok(substr_count($src, '$pdo->rollBack();') >= 6, 'every early exit inside the transaction rolls back');
$src = file_get_contents("$root/api/mobile/products/delete.php");
ok(strpos($src, 'DELETE FROM stock_movements') === false && strpos($src, 'DELETE FROM product_batches') === false, 'mobile delete no longer erases movements or batches');
ok(strpos($src, 'productStockBlocker(') !== false, 'mobile delete uses the same stock rule');

$whName = (string)$pdo->query("SELECT warehouse_name FROM warehouses WHERE warehouse_id = $wh")->fetchColumn();
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO products (product_name, sku, product_code, status, selling_price, cost_price, current_stock, created_at)
                   VALUES ('ZZ Delete Test', 'ZZ-DEL-TST', 'ZZ-DEL-TST', 'active', 2500, 1400, 0, NOW())")->execute();
    $pid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated) VALUES (?, ?, 20, 0, NOW())")->execute([$pid, $wh]);
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, quantity_received, quantity_remaining, unit_cost, selling_price, created_at) VALUES (?, ?, 20, 20, 1400, 2500, NOW())")->execute([$pid, $wh]);
    $batchId = (int)$pdo->lastInsertId();
    $mv = recordStockMovement($pdo, ['product_id' => $pid, 'warehouse_id' => $wh, 'movement_type' => 'purchase_in', 'quantity' => 20,
        'unit_cost' => 1400, 'reference_id' => 0, 'reference_type' => 'manual', 'movement_date' => $day, 'created_by' => 4]);

    $msg = productStockBlocker($pdo, $pid, 'ZZ Delete Test');
    ok($msg !== null, 'stock in a shop → delete refused');
    ok($msg !== null && strpos($msg, $whName . ': 20') !== false, "message names the shop and quantity ($whName: 20)");

    $pdo->prepare("UPDATE product_stocks SET stock_quantity = -2 WHERE product_id = ?")->execute([$pid]);
    ok(productStockBlocker($pdo, $pid, 'ZZ Delete Test') !== null, 'negative (oversold) stock also blocks');

    $pdo->prepare("UPDATE product_stocks SET stock_quantity = 0 WHERE product_id = ?")->execute([$pid]);
    $pdo->prepare("UPDATE products SET current_stock = 3 WHERE product_id = ?")->execute([$pid]);
    $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$pid]);
    ok(productStockBlocker($pdo, $pid, 'ZZ Delete Test') !== null, 'legacy stock on products.current_stock blocks when no shop rows exist');
    $pdo->prepare("UPDATE products SET current_stock = 0 WHERE product_id = ?")->execute([$pid]);
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated) VALUES (?, ?, 0, 0, NOW())")->execute([$pid, $wh]);

    ok(productStockBlocker($pdo, $pid, 'ZZ Delete Test') === null, 'zero stock everywhere → delete allowed');
    deleteProductKeepHistory($pdo, $pid);
    ok(!(int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id = $pid")->fetchColumn(), 'product removed');
    ok((int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_id = " . (int)$mv)->fetchColumn() === 1, 'stock movement history kept');
    ok((int)$pdo->query("SELECT COUNT(*) FROM product_batches WHERE batch_id = $batchId")->fetchColumn() === 1, 'batch history kept');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

// ── 5. Endpoint — the real api/delete_product.php, end to end ───────────────
section('5. Endpoint — api/delete_product.php refuses with stock, deletes at zero');
$pdo->prepare("INSERT INTO products (product_name, sku, product_code, status, selling_price, cost_price, current_stock, created_at)
               VALUES ('ZZ Endpoint Delete', 'ZZ-EP-DEL', 'ZZ-EP-DEL', 'active', 100, 50, 0, NOW())")->execute();
$epPid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated) VALUES (?, ?, 5, 0, NOW())")->execute([$epPid, $wh]);
$pdo->prepare("INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, unit_cost, reference_id, reference_type, movement_date, created_by, created_at)
               VALUES (?, ?, 'purchase_in', 5, 50, 0, 'manual', ?, 4, NOW())")->execute([$epPid, $wh, $day]);
$epMv = (int)$pdo->lastInsertId();

// The endpoint exit()s, so it runs in its own PHP process via a temp harness
// file (escapeshellarg on Windows strips double quotes from inline -r code).
$runEndpoint = function (int $productId) use ($root): array {
    $harness = tempnam(sys_get_temp_dir(), 'bmsdel') . '.php';
    file_put_contents($harness, '<?php $_SERVER["REQUEST_METHOD"]="POST"; $_POST=["product_id"=>' . $productId . '];'
        . 'require ' . var_export("$root/roots.php", true) . ';'
        . '$_SESSION["user_id"]=4; $_SESSION["is_admin"]=true; $_SESSION["role_id"]=1;'
        . 'require ' . var_export("$root/api/delete_product.php", true) . ';');
    $out = shell_exec('"' . PHP_BINARY . '" -d display_errors=0 "' . $harness . '" 2>&1');
    @unlink($harness);
    $json = null;
    if (preg_match('/\{.*\}/s', (string)$out, $m)) $json = json_decode($m[0], true);
    return [$json, $out];
};

try {
    [$res, $raw] = $runEndpoint($epPid);
    ok(is_array($res) && $res['success'] === false && strpos($res['message'] ?? '', ': 5') !== false,
       'with 5 in stock the endpoint refuses and names the quantity' . (is_array($res) ? '' : " (raw: " . substr((string)$raw, 0, 200) . ")"));
    ok((int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id = $epPid")->fetchColumn() === 1, 'product still there after the refusal');

    $pdo->prepare("UPDATE product_stocks SET stock_quantity = 0 WHERE product_id = ?")->execute([$epPid]);
    [$res, $raw] = $runEndpoint($epPid);
    ok(is_array($res) && $res['success'] === true, 'at zero stock the endpoint deletes' . (is_array($res) ? '' : " (raw: " . substr((string)$raw, 0, 200) . ")"));
    ok((int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id = $epPid")->fetchColumn() === 0, 'product gone');
    ok((int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_id = $epMv")->fetchColumn() === 1, 'its stock movement is still on record');
} finally {
    $pdo->prepare("DELETE FROM stock_movements WHERE movement_id = ?")->execute([$epMv]);
    $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$epPid]);
    $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$epPid]);
}

// ── 6. Profit Report page — dates reload, printout follows the screen ───────
section('6. Profit Report page');
$src = file_get_contents("$root/app/constant/reports/pos_profit_report.php");
ok(strpos($src, "\$('#f-project, #f-warehouse, #f-from, #f-to').on('change', loadReport)") !== false, 'changing a date reloads the report');
ok(strpos($src, 'id="print-period"') !== false && strpos($src, "\$('#print-period').html(") !== false, 'print period is set from the dates the figures were loaded for');
ok(strpos($src, 'if (seq !== reqSeq) return;') !== false, 'a slower earlier request cannot overwrite newer figures');

// ── 7. Wholesale-above-retail confirmation ──────────────────────────────────
section('7. Wholesale above retail — confirm, never block');
$js = file_get_contents("$root/assets/js/bms-price-check.js");
ok(strpos($js, 'input[name="wholesale_price"], input[name="selling_price"]') !== false, 'watches both price fields (either order of typing)');
ok(strpos($js, 'w <= 0 || r <= 0 || w <= r) return;') !== false, 'silent when wholesale is empty, zero, equal or lower');
ok(strpos($js, "\$w.data('bmsPriceOk', key)") !== false, '"Yes" keeps the figure and is not asked again for the same pair');
$hdr = file_get_contents("$root/header.php");
ok(strpos($hdr, 'assets/js/bms-price-check.js') !== false && strpos($hdr, 'BMS_PRICE_CHECK_T') !== false, 'loaded on every page with translated labels');
$sw = include "$root/lang/sw.php";
ok(isset($sw['Wholesale price ({w}) is higher than retail price ({r}). Is this correct?']), 'Swahili text present');
