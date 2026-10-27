<?php
/**
 * Mobile API upgrade — Phase 3: Record Old Sale from the Flutter app — CLI regression
 *   php tests/test_mobile_old_sale_cli.php
 *
 * The server already accepted old sales (process_sale old_sale + sold_at,
 * old_sale_context.php, void_sale.php — core/pos_old_sales.php). The app could
 * not tell who may use it or how far back: me.php now returns
 * permissions.pos_past_sales and pos_settings.old_sale_max_days, and the app's
 * POS Settings can save pos_old_sale_max_days (1–365) like the web page.
 *
 * Everything below is real HTTP with a Bearer token on a throwaway company.
 */

$root = dirname(__DIR__);
require_once "$root/tests/helpers/mobile_http.inc.php";

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

section('1. Static');
foreach (['api/mobile/me.php', 'api/pos/save_pos_setting.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "lint $f");
}

$m = mhStart('artos');
if (!$m) { echo "  \033[33m⚠ multi-tenancy is off here — skipping live checks\033[0m\n"; exit; }
$tok = mhLogin($m);
check((bool)$tok, 'owner signs in from the app');

section('2. me.php tells the app');
[$code, $me] = mhCall($m, 'GET', '/api/mobile/me.php', [], $tok);
check(($me['permissions']['pos_past_sales'] ?? null) === true, 'owner: permissions.pos_past_sales = true');
check(($me['pos_settings']['old_sale_max_days'] ?? null) === 30, 'pos_settings.old_sale_max_days = 30 (default)');
// A new company has no shop yet — the owner creates one from the app.
[$code, $w] = mhCall($m, 'POST', '/api/mobile/warehouses/create.php', ['warehouse_name' => 'Main Shop'], $tok);
$wh = (int)($w['warehouse_id'] ?? 0);
check($code === 200 && $wh > 0, "the owner creates a shop from the app (HTTP $code)");

section('3. POS Settings: days back');
[$code] = mhCall($m, 'POST', '/api/pos/save_pos_setting.php', ['key' => 'pos_old_sale_max_days', 'value' => '10'], $tok);
[, $me] = mhCall($m, 'GET', '/api/mobile/me.php', [], $tok);
check($code === 200 && ($me['pos_settings']['old_sale_max_days'] ?? null) === 10, 'saving 10 → me.php shows 10');
[$c0] = mhCall($m, 'POST', '/api/pos/save_pos_setting.php', ['key' => 'pos_old_sale_max_days', 'value' => '0'], $tok);
[$c400] = mhCall($m, 'POST', '/api/pos/save_pos_setting.php', ['key' => 'pos_old_sale_max_days', 'value' => '400'], $tok);
check($c0 === 422 && $c400 === 422, '0 and 400 refused (422), like the web 1–365');

section('4. An old sale from the app');
[$code, $p] = mhCall($m, 'POST', '/api/mobile/products/create.php', [
    'product_name' => 'Rice 5kg', 'selling_price' => 15000, 'cost_price' => 12000,
    'initial_stocks' => [['warehouse_id' => $wh, 'quantity' => 20]],
], $tok, true);
$pid = (int)($p['product_id'] ?? 0);
check($code === 200 && $pid > 0, "product with stock created (HTTP $code)");

$yday = date('Y-m-d', strtotime('-1 day'));
[$code, $ctx] = mhCall($m, 'GET', '/api/pos/old_sale_context.php', ['sold_at' => "$yday 10:00", 'warehouse_id' => $wh, 'total' => 30000], $tok);
check($code === 200 && !empty($ctx['success']), "old_sale_context previews the shift (HTTP $code)");

$sale = fn(array $extra) => $extra + [
    'customer_id' => null, 'warehouse_id' => $wh, 'project_id' => null,
    'items' => [['product_id' => $pid, 'quantity' => 2, 'price' => 15000, 'discounted_price' => 15000, 'tax_rate' => 0]],
    'subtotal' => 30000, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => 30000,
    'payment_method' => 'cash', 'amount_tendered' => 30000, 'change_given' => 0, 'amount_paid' => 30000,
];
[$code, $s] = mhCall($m, 'POST', '/api/pos/process_sale.php', $sale(['old_sale' => 1, 'sold_at' => "$yday 10:00"]), $tok, true);
$saleId = (int)($s['sale_id'] ?? 0);
check($code === 200 && $saleId > 0, "old sale saved (HTTP $code)" . ($code !== 200 ? ' — ' . ($s['message'] ?? '') : ''));
check(str_contains((string)($s['receipt_number'] ?? ''), date('Ymd', strtotime($yday))), "receipt numbered on the sale's day (" . ($s['receipt_number'] ?? '?') . ')');

$tp = mhTenantPdo($m);
$row = $tp->query("SELECT sale_date, is_past_entry, shift_id FROM pos_sales WHERE sale_id = $saleId")->fetch() ?: [];
check(substr((string)($row['sale_date'] ?? ''), 0, 10) === $yday && (int)($row['is_past_entry'] ?? 0) === 1, 'stored on that day, marked as an old sale');

[$code, $list] = mhCall($m, 'GET', '/api/pos/get_sales.php', ['date_from' => $yday, 'date_to' => date('Y-m-d')], $tok);
$found = array_values(array_filter($list['sales'] ?? $list['data'] ?? [], fn($r) => (int)($r['sale_id'] ?? 0) === $saleId));
check($found && (int)$found[0]['is_past_entry'] === 1, 'get_sales returns is_past_entry = 1');

[$code, $z] = mhCall($m, 'GET', '/api/pos/get_z_report.php', ['shift_id' => (int)($row['shift_id'] ?? 0)], $tok);
$rl = array_column($z['recorded_later'] ?? [], 'sale_id');
check($code === 200 && in_array($saleId, $rl, true), 'Z-report lists it under recorded_later (like the web)');
check(($z['is_backdated_shift'] ?? null) === true, 'no shift that day → Z-report says the shift is back-dated');

[$cf, $jf] = mhCall($m, 'POST', '/api/pos/process_sale.php', $sale(['old_sale' => 1, 'sold_at' => date('Y-m-d H:i', strtotime('+2 hours'))]), $tok, true);
[$co, $jo] = mhCall($m, 'POST', '/api/pos/process_sale.php', $sale(['old_sale' => 1, 'sold_at' => date('Y-m-d', strtotime('-20 days')) . ' 10:00']), $tok, true);
check($cf === 422 && $co === 422, "future → 422, beyond the 10-day limit → 422 ($cf / $co)");

[$code, $v] = mhCall($m, 'POST', '/api/pos/void_sale.php', ['sale_id' => $saleId, 'reason' => 'entered twice'], $tok);
$st = $tp->query("SELECT sale_status FROM pos_sales WHERE sale_id = $saleId")->fetchColumn();
check($code === 200 && $st === 'voided', "the old sale can be voided from the app (HTTP $code)");

section('5. A user without the permission');
$staffRole = (int)$tp->query("SELECT role_id FROM roles WHERE is_admin = 0 AND role_id NOT IN (SELECT role_id FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.page_key = 'pos_past_sales') ORDER BY role_id LIMIT 1")->fetchColumn();
[$code, $u] = mhCall($m, 'POST', '/api/mobile/users/create.php', [
    'username' => 'cashier1', 'first_name' => 'Cash', 'last_name' => 'Ier', 'email' => 'cashier1@test.test', 'role_id' => $staffRole,
    'password' => 'Password1', 'confirm_password' => 'Password1', 'warehouse_ids' => [$wh],
], $tok);
$ctok = mhLogin($m, 'cashier1');
check($code === 200 && (bool)$ctok, "a staff user is created and signs in (HTTP $code)");
[, $cme] = mhCall($m, 'GET', '/api/mobile/me.php', [], $ctok);
check(($cme['permissions']['pos_past_sales'] ?? null) === false, 'staff: permissions.pos_past_sales = false');
[$c1] = mhCall($m, 'GET', '/api/pos/old_sale_context.php', ['sold_at' => "$yday 10:00"], $ctok);
[$c2] = mhCall($m, 'POST', '/api/pos/process_sale.php', $sale(['old_sale' => 1, 'sold_at' => "$yday 11:00"]), $ctok, true);
check($c1 === 403 && $c2 === 403, "staff: preview and old sale refused (403 / 403, got $c1 / $c2)");
