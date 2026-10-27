<?php
/**
 * Mobile API upgrade — Phase 7: product / stock / expense / POS parity — CLI regression
 *   php tests/test_mobile_parity_cli.php
 *
 *   - "Selling below the buying price" (assets/js/bms-price-check.js) for API
 *     callers: products/create, products/update and pos/quick_restock return a
 *     non-blocking `warnings` list (core/price_check.php) — saved as typed;
 *   - products/delete: refused only while a shop holds stock; history kept;
 *   - expenses/create: optional warehouse_id (the shop's profit), like the web;
 *   - dashboard chart fill_gaps=1 returns every day of the range;
 *   - subscription services stay out of the POS (static: spPosExcludeSql /
 *     spPlatformMappedNames) — they exist only on BJP's own install.
 *
 * Real HTTP with a Bearer token on a throwaway company.
 */

$root = dirname(__DIR__);
require_once "$root/tests/helpers/mobile_http.inc.php";
require_once "$root/core/price_check.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function check(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    mhStopAll();   // before exit(1), which would skip mhStart's own cleanup
    if ($fail > 0) exit(1);
});

section('1. The rule (core/price_check.php)');
$codes = fn($w) => array_column($w, 'code');
check($codes(priceCheckWarnings(1000000, 100000)) === ['below_buying_retail'], 'retail 100,000 below buying 1,000,000 → warning');
check(str_contains(priceCheckWarnings(1000000, 100000)[0]['message'], '100,000') , 'message names the figures');
check($codes(priceCheckWarnings(100, 150, 90)) === ['below_buying_wholesale'], 'wholesale below buying → warning');
check($codes(priceCheckWarnings(100, 150, 200)) === ['wholesale_above_retail'], 'wholesale above retail → warning');
check(priceCheckWarnings(100, 150, 120) === [] && priceCheckWarnings(0, 50) === [] && priceCheckWarnings(100, 100) === [], 'normal, zero or equal → nothing');
foreach (['core/price_check.php', 'api/mobile/products/create.php', 'api/mobile/products/update.php', 'api/pos/quick_restock.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "lint $f");
}
check(str_contains(file_get_contents("$root/api/pos/simple_products.php"), 'spPosExcludeSql') && str_contains(file_get_contents("$root/api/pos/process_sale.php"), 'spPlatformMappedNames'),
    'POS list hides subscription services; process_sale refuses them (BJP install only)');

$m = mhStart('artpar');
if (!$m) { echo "  \033[33m⚠ multi-tenancy is off here — skipping live checks\033[0m\n"; exit; }
$tok = mhLogin($m);
[, $w] = mhCall($m, 'POST', '/api/mobile/warehouses/create.php', ['warehouse_name' => 'Main Shop'], $tok);
$wh = (int)($w['warehouse_id'] ?? 0);
check($tok && $wh > 0, 'owner signed in, shop created');

section('2. Warnings from the app endpoints (never blocking)');
[$code, $p] = mhCall($m, 'POST', '/api/mobile/products/create.php', [
    'product_name' => 'Rice (typo)', 'selling_price' => 100000, 'cost_price' => 1000000,
    'initial_stocks' => [['warehouse_id' => $wh, 'quantity' => 5]],
], $tok, true);
$pid = (int)($p['product_id'] ?? 0);
check($code === 200 && $pid > 0 && $codes($p['warnings'] ?? []) === ['below_buying_retail'], "create: saved AND warned below_buying_retail (HTTP $code)");

[$code, $u] = mhCall($m, 'POST', '/api/mobile/products/update.php', ['product_id' => $pid, 'cost_price' => 90000], $tok, true);
check($code === 200 && ($u['warnings'] ?? null) === [], 'update fixing the cost → saved, no warning');
[$code, $u] = mhCall($m, 'POST', '/api/mobile/products/update.php', ['product_id' => $pid, 'product_name' => 'Rice 5kg'], $tok, true);
check($code === 200 && ($u['warnings'] ?? null) === [], 'update without prices → no warning');

[$code, $r] = mhCall($m, 'POST', '/api/pos/quick_restock.php', [
    'product_id' => $pid, 'warehouse_id' => $wh, 'quantity' => 3, 'buying_price' => 120000, 'selling_price' => 100000,
], $tok);
check($code === 200 && !empty($r['success']) && $codes($r['warnings'] ?? []) === ['below_buying_retail'],
    "restock: saved AND warned (HTTP $code" . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : '') . ')');

section('3. Product delete rule');
[$code, $d] = mhCall($m, 'POST', '/api/mobile/products/delete.php', ['product_id' => $pid], $tok);
check($code === 409 && str_contains(strtolower((string)($d['message'] ?? '')), 'stock'), "refused while a shop holds stock (409): " . ($d['message'] ?? ''));
mhCall($m, 'POST', '/api/mobile/stock/adjust.php', ['product_id' => $pid, 'warehouse_id' => $wh, 'quantity' => 8, 'movement_type' => 'adjustment_out', 'reason' => 'count'], $tok);
$tp = mhTenantPdo($m);
$moves = (int)$tp->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = $pid")->fetchColumn();
[$code, $d] = mhCall($m, 'POST', '/api/mobile/products/delete.php', ['product_id' => $pid], $tok);
$after = (int)$tp->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = $pid")->fetchColumn();
check($code === 200 && !empty($d['success']), "no stock left → deleted (HTTP $code)");
check($moves > 0 && $after === $moves, "stock history kept ($after movements)");

section('4. Expense shop + chart');
[$code, $e] = mhCall($m, 'POST', '/api/mobile/expenses/create.php', ['amount' => 5000, 'description' => 'Transport', 'warehouse_id' => $wh, 'expense_date' => date('Y-m-d')], $tok);
$eid = (int)($e['expense_id'] ?? $e['id'] ?? 0);
$ewh = $eid ? $tp->query("SELECT warehouse_id FROM expenses WHERE expense_id = $eid")->fetchColumn() : null;
check(in_array($code, [200, 201], true) && (int)$ewh === $wh, "expense saved on its shop (HTTP $code" . ($eid ? '' : ' — ' . ($e['message'] ?? '')) . ')');

[$code, $c1] = mhCall($m, 'GET', '/api/pos/get_simple_dashboard_chart.php', ['period' => 'daily'], $tok);
[$code2, $c2] = mhCall($m, 'GET', '/api/pos/get_simple_dashboard_chart.php', ['period' => 'daily', 'fill_gaps' => 1], $tok);
check($code2 === 200 && count($c2['data'] ?? []) === 30 && count($c2['data']) >= count($c1['data'] ?? []), 'fill_gaps=1 → all 30 days (empty days as 0)');
check(array_key_exists('net_profit', $c2['data'][0] ?? []) && array_key_exists('operating_expenses', $c2['data'][0] ?? []), 'rows carry operating_expenses and net_profit');
