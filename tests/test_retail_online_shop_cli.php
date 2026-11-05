<?php
/**
 * Retail online shop (Smart POS) + online-order record (retail_online_shop_plan.md).
 *   php tests/test_retail_online_shop_cli.php
 *
 * A temporary retail shop (+ a second shop, products, stock, a wholesale price) is created,
 * used and removed again. Smart POS is forced on for this process only (get_setting override);
 * the shared database setting is never changed.
 *
 *   1. Static     the rules are in the code
 *   2. Engine     customer rule, catalog (no stock numbers), server pricing, stock cap, limits
 * Exit 0 = pass.   `--cleanup` removes the fixture.
 */

$root = dirname(__DIR__);
ob_start();   // roots.php starts a session; hold output until it has loaded

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function done_exit() { global $pass, $fail; echo "\n" . ($fail === 0 ? "\033[32mALL $pass PASSED\033[0m" : "\033[31m$fail FAILED\033[0m, $pass passed") . "\n"; exit($fail === 0 ? 0 : 1); }
function throwsUser(callable $fn, string $needle = ''): bool {
    try { $fn(); } catch (RoUserError $e) { return $needle === '' || stripos($e->getMessage(), $needle) !== false; }
    return false;
}

// ─── 1. Static ───────────────────────────────────────────────────────────────
echo "\n── 1. Static: the rules are in the code ──\n";
$strip = function (string $src): string { $o = ''; foreach (token_get_all($src) as $t) if (!is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) $o .= is_array($t) ? $t[1] : $t; return $o; };
$core = $strip((string)file_get_contents("$root/core/retail_online.php"));
$cust = $strip((string)file_get_contents("$root/core/online_customer.php"));
ok(!preg_match('/\$_SESSION/', $core), 'retail engine never reads $_SESSION');
$bf = substr($core, strpos($core, 'function rsBuildOrder'), strpos($core, 'function rsCheckLimits') - strpos($core, 'function rsBuildOrder'));
ok(!preg_match('/\$(ln|r)\[\'(price|unit|subtotal|total)\'\]/', $bf), 'rsBuildOrder never reads a price from the request');
$pc = substr($core, strpos($core, 'function rsPublicCatalog'), 2500);
ok(str_contains($pc, "'sold_out'") && !preg_match("/'(available|stock|cost_price|purchase_price|buying_price)'\s*=>/", $pc), 'public catalog returns sold_out yes/no, never a stock number or cost');
ok(str_contains($core, "p.online_hidden = 0") && str_contains($core, "p.is_service = 0"), 'hidden products and services never appear online');
ok(str_contains($cust, "nextCode(\$pdo, 'CUST')") && !str_contains($core, 'ocFindOrCreate'), 'customers are created with a proper code, and never by the public engine');

// ─── Fixture ─────────────────────────────────────────────────────────────────
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Smart POS on for THIS process only (helpers.php wraps its get_setting in function_exists).
function get_setting($key, $default = '') {
    global $pdo;
    static $cache = null;
    if ($key === 'pos_simple_mode') return '1';
    if ($cache === null) {
        $cache = [];
        if ($pdo instanceof PDO) { try { foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings") as $r) $cache[$r['setting_key']] = $r['setting_value']; } catch (Throwable $e) {} }
    }
    return $cache[$key] ?? $default;
}
if (!is_file("$root/includes/config.php")) { ob_end_flush(); echo "  (no includes/config.php — engine checks skipped)\n"; done_exit(); }
require_once "$root/roots.php";
ob_end_flush();
require_once "$root/core/online_shop_schema.php";
onlineShopEnsureSchema($pdo, function () {});
require_once "$root/core/retail_online.php";
require_once "$root/core/online_customer.php";
const FX = 'ZZ RS Test';

function fx_cleanup(PDO $pdo): void {
    $wids = $pdo->query("SELECT warehouse_id FROM warehouses WHERE warehouse_name LIKE '" . FX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($wids as $w) {
        $w = (int)$w;
        $pdo->exec("DELETE FROM product_stocks WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM pos_held_sales WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM restaurant_online_orders WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM warehouses WHERE warehouse_id = $w");
    }
    $pids = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE '" . FX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($pids) {
        $in = implode(',', array_map('intval', $pids));
        $pdo->exec("DELETE FROM product_price_group_prices WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM product_stocks WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
    }
    $pdo->exec("DELETE FROM customers WHERE customer_name LIKE '" . FX . "%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE '" . FX . "%'");
}
if (in_array('--cleanup', $argv, true)) { fx_cleanup($pdo); echo "fixture removed\n"; exit(0); }
fx_cleanup($pdo);

$admin = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$ins = function (string $sql, array $a = []) use ($pdo): int { $pdo->prepare($sql)->execute($a); return (int)$pdo->lastInsertId(); };
$wid  = $ins("INSERT INTO warehouses (warehouse_code, warehouse_name, phone, status, pos_mode) VALUES (?, ?, '0755000111', 'active', 'retail')", ['ZZRS' . random_int(1000, 9999), FX . ' Shop A']);
$wid2 = $ins("INSERT INTO warehouses (warehouse_code, warehouse_name, status, pos_mode) VALUES (?, ?, 'active', 'retail')", ['ZZRT' . random_int(1000, 9999), FX . ' Shop B']);
$widR = $ins("INSERT INTO warehouses (warehouse_code, warehouse_name, status, pos_mode) VALUES (?, ?, 'active', 'restaurant')", ['ZZRU' . random_int(1000, 9999), FX . ' Restaurant Only']);
$cat  = $ins("INSERT INTO categories (category_name) VALUES (?)", [FX . ' Groceries']);
$prod = function (string $name, float $price, string $unit = 'pcs', int $isService = 0, int $track = 1, int $hidden = 0, float $cost = 0) use ($ins, $cat, $admin): int {
    return $ins("INSERT INTO products (product_name, sku, category_id, selling_price, cost_price, purchase_price, unit, is_service, track_inventory, online_hidden, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)", [$name, 'ZZRS-' . random_int(10000, 99999), $cat, $price, $cost, $cost, $unit, $isService, $track, $hidden, $admin]);
};
$stock = function (int $pid, int $w, float $q) use ($pdo) { $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, ?)")->execute([$pid, $w, $q]); };
$pSoap   = $prod(FX . ' Soap', 2000, 'pcs', 0, 1, 0, 1200);  $stock($pSoap, $wid, 5);
$pSugar  = $prod(FX . ' Sugar', 3000, 'kg');                  $stock($pSugar, $wid, 2.5);
$pOut    = $prod(FX . ' Sold Out Tea', 1500);                 $stock($pOut, $wid, 0);
$pHidden = $prod(FX . ' Hidden Item', 900, 'pcs', 0, 1, 1);   $stock($pHidden, $wid, 10);
$pServ   = $prod(FX . ' Delivery Service', 5000, 'pcs', 1, 0); $stock($pServ, $wid, 0);
$pNoTrack= $prod(FX . ' Bag (no count)', 500, 'pcs', 0, 0);   $stock($pNoTrack, $wid, 0);
$pOther  = $prod(FX . ' Other Shop Only', 700);               $stock($pOther, $wid2, 50);
$wsGroup = wholesalePriceGroupId($pdo);
if ($wsGroup) $pdo->prepare("INSERT INTO product_price_group_prices (product_id, price_group_id, price) VALUES (?, ?, 1700)")->execute([$pSoap, $wsGroup]);

// ─── 2. Engine ───────────────────────────────────────────────────────────────
echo "\n── 2. Engine ──\n";
ok(rsSchemaReady($pdo) && rsModuleOnForPublic($pdo), 'schema ready and gate open with Smart POS on');

// Link + gate
ok(rsResolveToken($pdo, str_repeat('a', 32)) === null && rsResolveToken($pdo, 'not-a-token') === null, 'unknown / malformed tokens resolve to nothing');
rsEnsureLink($pdo, $wid);
$tok = (string)$pdo->query("SELECT online_shop_token FROM warehouses WHERE warehouse_id = $wid")->fetchColumn();
ok(roTokenLooksValid($tok), 'shop gets a 128-bit link');
rsEnsureLink($pdo, $wid);
ok((string)$pdo->query("SELECT online_shop_token FROM warehouses WHERE warehouse_id = $wid")->fetchColumn() === $tok, 'ensuring again keeps the same link');
ok(rsResolveToken($pdo, $tok) === null, 'link is closed while "Show my shop online" is off');
$pdo->exec("UPDATE warehouses SET online_shop_enabled = 1 WHERE warehouse_id = $wid");
$ctx = rsResolveToken($pdo, $tok);
ok($ctx && $ctx['warehouse']['warehouse_id'] === $wid && $ctx['warehouse']['ordering'] === false, 'link opens when on; ordering off by default');
$pdo->exec("UPDATE warehouses SET online_shop_ordering = 1, online_shop_pickup = 0, online_shop_delivery = 0 WHERE warehouse_id = $wid");
ok(rsResolveToken($pdo, $tok)['warehouse']['ordering'] === false, 'ordering counts as off when neither pickup nor delivery is on');
$pdo->exec("UPDATE warehouses SET online_shop_pickup = 1 WHERE warehouse_id = $wid");
$ctx = rsResolveToken($pdo, $tok);
ok($ctx['warehouse']['ordering'] === true && $ctx['warehouse']['phone'] === '0755000111', 'ordering on with pickup; shop phone available for the buyer');
rsEnsureLink($pdo, $widR);
$pdo->exec("UPDATE warehouses SET online_shop_enabled = 1 WHERE warehouse_id = $widR");
$tokR = (string)$pdo->query("SELECT online_shop_token FROM warehouses WHERE warehouse_id = $widR")->fetchColumn();
ok(rsResolveToken($pdo, $tokR) === null, 'a restaurant-only shop never opens as a retail shop');
$pdo->exec("UPDATE warehouses SET status = 'inactive' WHERE warehouse_id = $wid");
ok(rsResolveToken($pdo, $tok) === null, 'an inactive shop link is closed');
$pdo->exec("UPDATE warehouses SET status = 'active' WHERE warehouse_id = $wid");

// Catalog
$cat0 = rsPublicCatalog($pdo, $ctx['warehouse']);
$ids  = array_column($cat0['items'], 'id');
$byId = array_column($cat0['items'], null, 'id');
ok(in_array($pSoap, $ids) && in_array($pSugar, $ids) && in_array($pOut, $ids) && in_array($pNoTrack, $ids), 'shop products listed (incl. sold-out and no-count items)');
ok(!in_array($pHidden, $ids) && !in_array($pServ, $ids) && !in_array($pOther, $ids), 'hidden product, service and another shop\'s product are not listed');
ok($byId[$pOut]['sold_out'] === true && $byId[$pSoap]['sold_out'] === false && $byId[$pNoTrack]['sold_out'] === false, 'sold out only when counted stock is 0');
$json = json_encode($cat0);
ok(!preg_match('/"(available|stock|stock_quantity|cost_price|purchase_price)"/', $json) && !str_contains($json, '1200'), 'no stock number or cost anywhere in the public list');
ok($byId[$pSugar]['decimal'] === true && $byId[$pSoap]['decimal'] === false, 'kg product allows decimals, pcs does not');
ok(!isset($byId[$pSoap]['wholesale']) && $cat0['has_wholesale'] === false, 'wholesale price hidden while the wholesale switch is off');
$pdo->exec("UPDATE warehouses SET online_shop_wholesale = 1 WHERE warehouse_id = $wid");
$ctx = rsResolveToken($pdo, $tok);
$cat1 = array_column(rsPublicCatalog($pdo, $ctx['warehouse'])['items'], null, 'id');
ok(!$wsGroup || (($cat1[$pSoap]['wholesale'] ?? 0) == 1700 && !isset($cat1[$pSugar]['wholesale'])), 'wholesale price shown when on (only where it differs)');

// Server pricing + stock
$b = rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pSoap, 'qty' => 2, 'price' => 1], ['id' => $pSugar, 'qty' => '1.5']], 'retail');
ok($b['subtotal'] == 2 * 2000 + 1.5 * 3000 && $b['items'][0]['price'] == 2000, 'server prices from the database (a sent price of 1 is ignored)');
ok($b['items'][1]['quantity'] == 1.5 && $b['items'][0]['online_price_type'] === 'retail', 'decimal kg kept; line marked retail');
$bw = rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pSoap, 'qty' => 1]], 'wholesale');
ok(!$wsGroup || ($bw['items'][0]['price'] == 1700 && $bw['items'][0]['online_price_type'] === 'wholesale'), 'wholesale order priced at the wholesale price');
$pdo->exec("UPDATE warehouses SET online_shop_wholesale = 0 WHERE warehouse_id = $wid");
$ctx = rsResolveToken($pdo, $tok);
$bw2 = rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pSoap, 'qty' => 1]], 'wholesale');
ok($bw2['items'][0]['price'] == 2000 && $bw2['items'][0]['online_price_type'] === 'retail', 'wholesale request falls back to retail when the switch is off');
ok(throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pSoap, 'qty' => 6]], 'retail'), 'Only a few'), 'more than in stock is refused — message never says the number');
ok(throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pSoap, 'qty' => 3], ['id' => $pSoap, 'qty' => 3]], 'retail'), 'Only a few'), 'stock cap counts the same product across lines');
ok(throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pSoap, 'qty' => 1.5]], 'retail'), 'whole numbers'), 'decimals refused for a pcs product');
ok(throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pOut, 'qty' => 1]], 'retail'), 'sold out'), 'sold-out product refused');
ok(throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pHidden, 'qty' => 1]], 'retail'), 'no longer available')
   && throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pOther, 'qty' => 1]], 'retail'), 'no longer available')
   && throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pServ, 'qty' => 1]], 'retail'), 'no longer available'), 'hidden / other shop / service product refused (same message)');
ok(rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pNoTrack, 'qty' => 40]], 'retail')['subtotal'] == 20000, 'a product without stock counting can be ordered');
ok(throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [], 'retail'), 'empty')
   && throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pSoap, 'qty' => 0]], 'retail'))
   && throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pSoap, 'qty' => -1]], 'retail'))
   && throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => $pSoap, 'qty' => '1e2']], 'retail'))
   && throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], [['id' => 'x', 'qty' => 1]], 'retail'))
   && throwsUser(fn() => rsBuildOrder($pdo, $ctx['warehouse'], array_fill(0, 31, ['id' => $pSoap, 'qty' => 1]), 'retail'), 'at most'), 'empty, zero, negative, 1e2, bad id and >30 lines refused');

// Placing + limits + cancel + expiry
$placed = rsPlaceOrder($pdo, $ctx, $b, FX . ' Juma', '0712 345 678', 'Morning please', 'pickup', '', 'retail', str_repeat('c', 64));
$row = $pdo->query("SELECT * FROM restaurant_online_orders WHERE order_id = " . (int)$placed['order_id'])->fetch(PDO::FETCH_ASSOC);
ok($row['status'] === 'pending' && $row['order_type'] === 'shop_pickup' && str_starts_with($row['order_code'], 'S-') && $row['customer_id'] === null,
   'order saved as a waiting request (S- code, no customer created yet)');
ok((int)$pdo->query("SELECT COUNT(*) FROM customers WHERE customer_name = '" . FX . " Juma'")->fetchColumn() === 0, 'sending an order never creates a customer');
$pub = rsPublicOrder($pdo, $wid, $placed['public_ref']);
ok($pub && $pub['code'] === $row['order_code'] && $pub['type'] === 'pickup' && count($pub['lines']) === 2, 'status page data found by its secret ref');
ok(rsPublicOrder($pdo, $wid2, $placed['public_ref']) === null && rsPublicOrder($pdo, $wid, str_repeat('0', 32)) === null, 'order not visible through another shop or a wrong ref');
$client = str_repeat('d', 64);
rsPlaceOrder($pdo, $ctx, $b, FX . ' A', '0711000001', '', 'pickup', '', 'retail', $client);
rsPlaceOrder($pdo, $ctx, $b, FX . ' B', '0711000002', '', 'pickup', '', 'retail', $client);
rsPlaceOrder($pdo, $ctx, $b, FX . ' C', '0711000003', '', 'pickup', '', 'retail', $client);
ok(throwsUser(fn() => rsCheckLimits($pdo, $wid, $client), 'several orders'), 'a 4th open order from the same device is refused');
ok(rsCustomerCancel($pdo, $wid, $placed['public_ref']) && !rsCustomerCancel($pdo, $wid, $placed['public_ref']), 'buyer can cancel a waiting order once');
$c = $pdo->query("SELECT status, cancelled_by FROM restaurant_online_orders WHERE order_id = " . (int)$placed['order_id'])->fetch(PDO::FETCH_ASSOC);
ok($c['status'] === 'cancelled' && $c['cancelled_by'] === 'customer', 'cancel recorded as by the customer');
$d = rsPlaceOrder($pdo, $ctx, $b, FX . ' Del', '0711000009', '', 'delivery', 'Sinza, near the market', 'retail', str_repeat('e', 64));
$dr = $pdo->query("SELECT order_type, delivery_address FROM restaurant_online_orders WHERE order_id = " . (int)$d['order_id'])->fetch(PDO::FETCH_ASSOC);
ok($dr['order_type'] === 'shop_delivery' && $dr['delivery_address'] === 'Sinza, near the market', 'delivery order keeps its address');
$pdo->exec("UPDATE restaurant_online_orders SET created_at = NOW() - INTERVAL 25 HOUR WHERE order_id = " . (int)$d['order_id']);
ok(rsExpireWaiting($pdo, $wid) >= 1 && $pdo->query("SELECT cancelled_by FROM restaurant_online_orders WHERE order_id = " . (int)$d['order_id'])->fetchColumn() === 'system', 'waiting order older than 24 h expires (by system)');

// Customer rule
$existing = $ins("INSERT INTO customers (customer_code, customer_name, phone, customer_type, status, created_at) VALUES (?, ?, ?, 'individual', 'active', NOW())", ['ZZRS-C' . random_int(1000, 9999), FX . ' Asha Said', '+255 712-345-678']);
$r1 = ocFindOrCreate($pdo, '  ' . strtolower(FX) . '  asha   SAID ', '0712345678', $admin);
ok($r1['customer_id'] === $existing && $r1['created'] === false, 'same name + same number (any format, any case/spacing) → existing customer reused');
$r2 = ocFindOrCreate($pdo, FX . ' Asha Juma', '0712345678', $admin);
ok($r2['created'] === true && $r2['customer_id'] !== $existing, 'same phone, different name → new customer');
$r3 = ocFindOrCreate($pdo, FX . ' Asha Said', '0799999999', $admin);
ok($r3['created'] === true && $r3['customer_id'] !== $existing, 'same name, different phone → new customer');
$code = (string)$pdo->query("SELECT customer_code FROM customers WHERE customer_id = " . $r3['customer_id'])->fetchColumn();
ok($code !== '' && $code !== null, 'new customer gets a proper customer code');
$hint = ocPhoneHint($pdo, FX . ' A. Said', '255712345678');
ok(count($hint) >= 1 && in_array($existing, array_column($hint, 'customer_id')), 'staff hint lists customers already using this phone under another name');
ok(ocPhoneHint($pdo, FX . ' Asha Said', '0712345678') === [], 'no hint when an exact match exists (it is reused automatically)');
$r4 = ocFindOrCreate($pdo, FX . ' A. Said', '0712345678', $admin, $existing);
ok($r4['customer_id'] === $existing && $r4['created'] === false, 'staff can link to a customer from the hint');
$threw = false; try { ocFindOrCreate($pdo, FX . ' X', '0712345678', $admin, $r3['customer_id']); } catch (RuntimeException $e) { $threw = true; }
ok($threw, 'linking to a customer who does not use this phone is refused');
ok(ocNormalisePhone('+255 712 345 678') === '0712345678' && ocNormalisePhone('712345678') === '0712345678' && ocNormalisePhone('0712-345-678') === '0712345678', 'phone formats normalise to one number');

fx_cleanup($pdo);
done_exit();
