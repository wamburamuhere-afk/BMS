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

// ─── Web-server router (this file run by `php -S`) ───────────────────────────
if (PHP_SAPI === 'cli-server') {
    $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file($root . $path) && !str_ends_with($path, '.php')) return false;
    // Smart POS on for THIS server only (helpers.php wraps its own get_setting in function_exists).
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
    if (session_status() === PHP_SESSION_NONE) session_start();
    // Staff requests name their user in a header; public requests never get one.
    $uid = (int)($_SERVER['HTTP_X_BMS_TEST_USER'] ?? 0);
    if ($uid > 0) {
        $_SESSION['user_id'] = $uid; $_SESSION['username'] = 'zzrs' . $uid; $_SESSION['role'] = 'admin';
        $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true; $_SESSION['user_lang'] = 'en'; $_SESSION['csrf_token'] = 'testcsrf';
    } else {
        $_SESSION = [];
    }
    $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    chdir($root);
    $direct = $root . $path;
    if (str_ends_with($path, '.php') && is_file($direct) && str_starts_with($path, '/api/')) { require $direct; return true; }
    require $root . '/index.php';
    return true;
}

// Placing an order fires the "new order" alert: park every test email (never sent) and remove
// this run's alerts at the end (local SMTP is real).
putenv('BMS_NOTIF_HOLD_EMAIL=1');
$T0 = date('Y-m-d H:i:s');
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
// A tenant migration must never load roots.php (it reconnects $pdo to the main database): walk every
// file the migration pulls in. (The 2026-11-03 restaurant alerts migration did, so tenants never got them.)
$walk = function (string $file, array &$seen) use (&$walk, $root): void {
    $file = realpath($file) ?: $file;
    if (isset($seen[$file]) || !is_file($file)) return;
    $seen[$file] = true;
    preg_match_all("#require(?:_once)?\s*\(?\s*__DIR__\s*\.\s*'([^']+)'#", (string)file_get_contents($file), $m);
    foreach ($m[1] as $rel) $walk(dirname($file) . $rel, $seen);
};
foreach (['migrations/tenant/2026_11_05_online_shop_and_order_record.php', 'migrations/tenant/2026_11_03_restaurant_notifications.php'] as $mig) {
    $seen = [];
    $walk("$root/$mig", $seen);
    $bad = array_filter(array_keys($seen), fn($f) => preg_match('#[\\\\/](roots|notify)\.php$#', $f) || str_ends_with(str_replace('\\', '/', $f), 'includes/config.php'));
    ok(!$bad, basename($mig) . ' never loads roots.php / notify.php (stays on the tenant database)' . ($bad ? ' — ' . implode(', ', array_map('basename', $bad)) : ''));
}
// Found in the browser click-through (2026-11-05):
$share = (string)file_get_contents("$root/includes/retail/online_shop_share.php");
$menuShare = (string)file_get_contents("$root/includes/restaurant/online_menu_share.php");
ok(str_contains($share, 'id="shareShopModal"') && str_contains($share, 'data-no-autoclose="true"') && str_contains($menuShare, 'data-no-autoclose="true"'),
   'Share shop / Share menu windows stay open while switches save (footer auto-close opted out)');
ok(str_contains($share, "document.execCommand('copy')") && str_contains($share, 'Promise.race([navigator.clipboard.writeText') && !str_contains($menuShare, '.finally(() => Swal.fire'),
   'Copy really copies (direct copy first, timed clipboard backup) and says "copied" only when it did');
foreach (['public_shop.php', 'public_menu.php'] as $pf) {
    $ps = (string)file_get_contents("$root/$pf");
    ok(preg_match('/\n\.add\{position:absolute;z-index:3;/', $ps) === 1 && preg_match('/\n\.badge-x\{position:absolute;z-index:2;/', $ps) === 1,
       "$pf: the + button stays above the letter shown when a photo fails (tap adds, not opens)");
}
ok(str_contains((string)file_get_contents("$root/app/bms/pos/pos_scripts_new.php"), "No shop picked on the till yet: pick the order's own shop"),
   'loading an online order on a till with no shop picked selects the order\'s shop');

// ─── Fixture ─────────────────────────────────────────────────────────────────
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Smart POS on for THIS process only (helpers.php wraps its get_setting in function_exists).
// Declared inside an if so PHP does not compile it up front next to the test server's own copy.
if (!function_exists('get_setting')) {
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
    // Sales left by an interrupted run (normally removed by fx_cleanup_sales).
    if ($wids && function_exists('fx_cleanup_sales')) {
        fx_cleanup_sales($pdo, $pdo->query("SELECT sale_id FROM pos_sales WHERE warehouse_id IN (" . implode(',', array_map('intval', $wids)) . ")")->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($wids as $w) {
        $w = (int)$w;
        $pdo->exec("DELETE FROM product_stocks WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM stock_movements WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM pos_held_sales WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM restaurant_online_orders WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM restaurant_tables WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM restaurant_floors WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM warehouses WHERE warehouse_id = $w");
    }
    $uids = $pdo->query("SELECT user_id FROM users WHERE last_name LIKE '" . FX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($uids) {
        $in = implode(',', array_map('intval', $uids));
        $pdo->exec("DELETE FROM pos_held_sales WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM cash_register_shifts WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM users WHERE user_id IN ($in)");
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
    // This run's alerts (bell + parked emails) for online orders.
    $pdo->prepare("DELETE FROM notification_outbox WHERE created_at >= ? AND (event_key LIKE 'retail.%' OR event_key LIKE 'restaurant.online_order%')")->execute([$GLOBALS['T0']]);
    $pdo->prepare("DELETE FROM notifications WHERE created_at >= ? AND (event_key LIKE 'retail.%' OR event_key LIKE 'restaurant.online_order%')")->execute([$GLOBALS['T0']]);
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

// ─── Test server (real HTTP through the real endpoints) ─────────────────────
$port = random_int(8700, 8799);
$env  = array_merge(getenv(), ['BMS_NOTIF_HOLD_EMAIL' => '1']);
$srv  = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __FILE__], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, $root, $env);
register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); } });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
/** @return array{0:int, 1:mixed} [HTTP code, decoded JSON or raw body] */
function http(string $method, string $path, array $data = [], int $user = 0, bool $json = false): array {
    global $port;
    $url = "http://127.0.0.1:$port$path";
    $hdr = ["X-BMS-Test-User: $user"];
    $body = '';
    if ($method === 'GET' && $data) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
    if ($method === 'POST') {
        if ($json) { $body = json_encode($data); $hdr[] = 'Content-Type: application/json'; }
        else { $body = http_build_query($data + ['_csrf' => 'testcsrf']); $hdr[] = 'Content-Type: application/x-www-form-urlencoded'; }
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $hdr), 'content' => $body, 'ignore_errors' => true, 'timeout' => 60, 'follow_location' => 0]]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    $GLOBALS['LAST_HDR'] = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
        elseif (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $GLOBALS['LAST_HDR'][strtolower(trim($k))] = trim($v); }
    }
    $dec = json_decode((string)$raw, true);
    return [$code, is_array($dec) ? $dec : (string)$raw];
}

// Sales made here touch stock, the ledger, the drawer and the bank register — remove all of it.
$saleIds = [];
function fx_cleanup_sales(PDO $pdo, array $ids): void {
    foreach (array_unique(array_filter(array_map('intval', $ids))) as $sid) {
        $rn = (string)$pdo->query("SELECT receipt_number FROM pos_sales WHERE sale_id = $sid")->fetchColumn();
        $je = $pdo->query("SELECT entry_id FROM journal_entries WHERE entity_id = $sid AND entity_type IN ('pos_sale','pos_cogs','pos_sale_void','pos_cogs_void')")->fetchAll(PDO::FETCH_COLUMN);
        if ($je) {
            $in = implode(',', array_map('intval', $je));
            $rev = $pdo->query("SELECT entry_id FROM journal_entries WHERE reverses_entry_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            $all = implode(',', array_map('intval', array_merge($je, $rev)));
            $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id IN ($all)");
            $pdo->exec("DELETE FROM journal_entries WHERE entry_id IN ($all)");
        }
        foreach (['pos_sale_items', 'pos_sale_payments', 'pos_payments', 'cash_register_transactions', 'customer_loyalty_transactions'] as $t) {
            try { $pdo->exec("DELETE FROM $t WHERE sale_id = $sid"); } catch (Throwable $e) {}
        }
        $pdo->exec("DELETE FROM stock_movements WHERE reference_id = $sid AND reference_type LIKE 'pos%'");
        if ($rn !== '') $pdo->prepare("DELETE FROM bank_transactions WHERE reference_number = ?")->execute([$rn]);
        $pdo->exec("DELETE FROM pos_sales WHERE sale_id = $sid");
    }
}

// ─── 2b. Page dressing: banner, Popular, labels, footer (from data BMS already holds) ──
echo "\n── 2b. Shop page look (banner, Popular, footer) ──\n";
ok(roDisplayName('PAMPERS') === 'Pampers' && roDisplayName('apple punch 300ml') === 'Apple Punch 300ml'
   && roDisplayName('UGALI NYAMA CHOMA') === 'Ugali Nyama Choma' && roDisplayName('Maji ya Afya') === 'Maji ya Afya',
   'names typed all-caps / all-small show in Title Case; a word with a digit and a mixed-case name stay as typed');
ok(roWhatsAppNumber('0755 000 111') === '255755000111' && roWhatsAppNumber('+255 755 000 111') === '255755000111' && roWhatsAppNumber('12') === '',
   'WhatsApp uses the shop phone (07.. → 2557..); a non-phone gives no WhatsApp button');
ok(roPublicDescription('<b>Bar</b> soap   200 g') === 'Bar soap 200 g' && mb_strlen(roPublicDescription(str_repeat('a', 500))) === 238,
   'description: tags stripped, spaces folded, long text shortened');

$pdo->prepare("UPDATE warehouses SET location = ?, address = ? WHERE warehouse_id = ?")->execute(['Mbezi Msakuzi', 'Plot 12, Mbezi Msakuzi, Dar es Salaam', $wid]);
$pdo->prepare("UPDATE products SET description = ? WHERE product_id = ?")->execute(['<b>Bar</b> soap   200 g', $pSoap]);
$pdo->exec("UPDATE products SET created_at = NOW() - INTERVAL 40 DAY WHERE product_id = $pSugar");
$ctxD = rsResolveToken($pdo, $tok);
ok($ctxD['warehouse']['location'] === 'Mbezi Msakuzi' && $ctxD['warehouse']['address'] === 'Plot 12, Mbezi Msakuzi, Dar es Salaam', 'the shop link carries the shop\'s location and address');
$catD = array_column(rsPublicCatalog($pdo, $ctxD['warehouse'])['items'], null, 'id');
ok($catD[$pSoap]['desc'] === 'Bar soap 200 g' && $catD[$pSugar]['desc'] === '', 'product description reaches the page (empty when none)');
ok($catD[$pSoap]['is_new'] === true && $catD[$pSugar]['is_new'] === false, '"New" = added in the last 14 days');

// Popular: completed POS sales of THIS shop, last 30 days, returns/cancelled/old/other shop ignored.
$popSales = [];
$popSale = function (int $w, array $lines, string $status = 'completed', int $daysAgo = 1, int $isReturn = 0) use ($pdo, $admin, &$popSales): void {
    $pdo->prepare("INSERT INTO pos_sales (shift_id, customer_id, customer_name, warehouse_id, receipt_number, grand_total, payment_method, payment_status, sale_status, sale_date, is_return_sale, user_id, created_at)
                   VALUES (0, NULL, ?, ?, ?, 0, 'cash', 'paid', ?, NOW() - INTERVAL $daysAgo DAY, ?, ?, NOW())")
        ->execute([FX . ' popular', $w, 'ZZRSP-' . random_int(100000, 999999), $status, $isReturn, $admin]);
    $sid = (int)$pdo->lastInsertId(); $popSales[] = $sid;
    foreach ($lines as [$pid, $q, $ret]) {
        $pdo->prepare("INSERT INTO pos_sale_items (sale_id, product_id, product_name, quantity, returned_quantity, unit_price, tax_rate, tax_amount, discount_rate, discount_amount, line_total)
                       VALUES (?, ?, 'x', ?, ?, 1, 0, 0, 0, 0, ?)")->execute([$sid, $pid, $q, $ret, $q]);
    }
};
$visible = array_keys(array_filter($catD, fn($i) => !$i['sold_out']));
$popSale($wid, [[$pSoap, 5, 0], [$pSugar, 2, 0]]);
ok(roPopularIds($pdo, $wid, $visible) === [], 'Popular hides while fewer than 3 products have sold');
$popSale($wid, [[$pNoTrack, 9, 0], [$pOut, 50, 0]]);
$popSale($wid, [[$pSugar, 40, 0]], 'cancelled');            // cancelled: ignored
$popSale($wid, [[$pSugar, 40, 0]], 'completed', 45);       // older than 30 days: ignored
$popSale($wid, [[$pSugar, 40, 0]], 'completed', 1, 1);     // a return sale: ignored
$popSale($wid2, [[$pSugar, 40, 0]]);                       // another shop: ignored
$popSale($wid, [[$pSoap, 6, 4]]);                          // 4 of 6 returned → counts 2
$pop = roPopularIds($pdo, $wid, $visible);
ok($pop === [$pNoTrack, $pSoap, $pSugar], 'Popular = this shop\'s best sellers of the last 30 days, returned units taken off, best first');
ok(!in_array($pOut, $pop, true), 'a sold-out product never shows in Popular');

// The real page through the real router.
$pdo->exec("UPDATE warehouses SET online_show_payment = 0 WHERE warehouse_id = $wid");
[$c, $h] = http('GET', "/s/$tok");
$h = (string)$h;
ok($c === 200 && str_contains($h, 'class="banner"') && str_contains($h, 'Mbezi Msakuzi') && str_contains($h, 'Plot 12, Mbezi Msakuzi, Dar es Salaam'),
   'page: banner with the shop\'s place, address in the footer');
ok(str_contains($h, 'var POPULAR = ' . json_encode($pop) . ';'), 'page: the Popular list is sent as product ids only (no numbers)');
ok(str_contains($h, 'https://wa.me/255755000111') && str_contains($h, 'tel:0755000111'), 'page: WhatsApp and Call use the shop phone');
ok(str_contains($h, 'Powered by BJP Technologies © ' . date('Y') . ', All Rights Reserved.') && preg_match('#Online shop by</span>|Online shop by <b>#', $h) === 1,
   'page: footer says "Online shop by <company>" and "Powered by BJP Technologies © year, All Rights Reserved."');
ok(!str_contains($h, 'Nothing is charged online') && !str_contains($h, 'Open in Maps') && !preg_match('/opens at|closes at|Open now/i', $h),
   'page: old one-line footer gone; no map button and no opening hours');
ok(!str_contains($h, 'class="sf-pay"'), 'page: no payment names while "show payment" is off');
$pdo->exec("UPDATE warehouses SET online_show_payment = 1 WHERE warehouse_id = $wid");
$names = roPaymentMethodNames($pdo);
[, $h2] = http('GET', "/s/$tok");
$h2 = (string)$h2;
ok(!$names || (str_contains($h2, 'class="sf-pay"') && array_reduce($names, fn($a, $n) => $a && str_contains($h2, '<span>' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '</span>'), true)),
   'page: with "show payment" on, the footer lists the method names from Payment Settings' . ($names ? '' : ' (none set here — skipped)'));
ok(!preg_match('/"(available|stock|stock_quantity)"\s*:/', $h2), 'page: still no stock number anywhere');
$pdo->exec("UPDATE warehouses SET online_show_payment = 0 WHERE warehouse_id = $wid");
fx_cleanup_sales($pdo, $popSales);

// ─── 3. The record: accept → held → receipt → void → not collected (restaurant + shared) ──
echo "\n── 3. Online order record (restaurant pickup + shared rules) ──\n";
$mkUser = fn(string $tag) => $ins("INSERT INTO users (username, password, email, role, role_id, is_admin, first_name, last_name, is_active) VALUES (?, 'x', '', 'admin', 1, 1, 'Zz', ?, 1)",
                                 ['zzrs_' . $tag . random_int(1000, 9999), FX . ' ' . $tag]);
$uA = $mkUser('accepter'); $uB = $mkUser('cashier');
$shiftB = $ins("INSERT INTO cash_register_shifts (shift_code, user_id, warehouse_id, status, starting_cash, start_time) VALUES (?, ?, NULL, 'active', 0, NOW())", ['ZZRS-' . random_int(10000, 99999), $uB]);
$widRest = $ins("INSERT INTO warehouses (warehouse_code, warehouse_name, status, pos_mode, online_menu_enabled, online_order_pickup) VALUES (?, ?, 'active', 'restaurant', 1, 1)",
                ['ZZRV' . random_int(1000, 9999), FX . ' Restaurant Pickup']);
$pRs = $prod(FX . ' Juice Box', 2000); $stock($pRs, $widRest, 20); $stock($pRs, $wid, 20);
$line = fn(int $pid, float $q, float $price) => ['product_id' => $pid, 'product_name' => 'x', 'sku' => '', 'price' => $price, 'quantity' => $q, 'tax_rate' => 0,
    'min_selling_price' => 0, 'discount_type' => 'percentage', 'discount_value' => 0, 'discount_percent' => 0, 'discounted_price' => $price];
$mkOrder = function (int $w, string $type, string $name, string $phone, array $items, string $status = 'pending', ?int $table = null) use ($ins): int {
    $sub = array_sum(array_map(fn($l) => $l['price'] * $l['quantity'], $items));
    return $ins("INSERT INTO restaurant_online_orders (warehouse_id, table_id, order_type, order_code, public_ref, customer_name, customer_phone, items_json, subtotal, status, client_hash, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [$w, $table, $type, 'W-T' . random_int(100, 999), bin2hex(random_bytes(16)), $name, $phone, json_encode($items), $sub, $status, str_repeat('f', 64)]);
};
$order = fn(int $id) => $pdo->query("SELECT * FROM restaurant_online_orders WHERE order_id = $id")->fetch(PDO::FETCH_ASSOC);
$sale  = function (int $w, array $items, int $user, ?int $cust = null, string $method = 'cash', int $priceGroup = 0) use (&$saleIds) {
    $tot = array_sum(array_map(fn($l) => $l['price'] * $l['quantity'], $items));
    [$c, $r] = http('POST', '/api/pos/process_sale.php', [
        'price_group_id' => $priceGroup ?: null,
        'customer_id' => $cust, 'warehouse_id' => $w, 'project_id' => null, 'items' => $items, 'subtotal' => $tot, 'discount_percentage' => 0,
        'discount_amount' => 0, 'tax' => 0, 'total' => $tot, 'payment_method' => $method, 'amount_tendered' => $method === 'credit' ? 0 : $tot,
        'change_given' => 0, 'amount_paid' => $method === 'credit' ? 0 : $tot, 'due_date' => $method === 'credit' ? date('Y-m-d', strtotime('+7 days')) : null,
    ], $user, true);
    if (!empty($r['sale_id'])) $saleIds[] = (int)$r['sale_id'];
    return [$c, $r];
};
$RAPI = '/api/restaurant/online_orders.php';

$existingN = $ins("INSERT INTO customers (customer_code, customer_name, phone, customer_type, status, created_at) VALUES (?, ?, ?, 'individual', 'active', NOW())", ['ZZRS-N' . random_int(1000, 9999), FX . ' N. Hassan', '+255 788 111 222']);
$o1 = $mkOrder($widRest, 'pickup', FX . ' Neema', '0788111222', [$line($pRs, 2, 2000)]);
[$c, $r] = http('GET', $RAPI, ['action' => 'customer_hint', 'order_id' => $o1], $uA);
ok($c === 200 && in_array($existingN, array_column($r['data'] ?? [], 'customer_id')), 'Accept screen hint: this phone already belongs to another customer name');
[$c, $r] = http('POST', $RAPI, ['action' => 'accept', 'order_id' => $o1, 'link_customer_id' => 0], $uA);
$O = $order($o1);
$hold = $pdo->query("SELECT * FROM pos_held_sales WHERE online_order_id = $o1")->fetch(PDO::FETCH_ASSOC);
ok($c === 200 && !empty($r['success']) && $O['status'] === 'accepted' && $r['customer_created'] === true, 'accept (keep the rule) → new customer created, order accepted');
$cust1 = (int)$O['customer_id'];
ok($cust1 > 0 && $cust1 !== $existingN && (string)$pdo->query("SELECT customer_name FROM customers WHERE customer_id = $cust1")->fetchColumn() === FX . ' Neema', 'order linked to the new customer (not the different-name one)');
ok($hold && (int)$hold['customer_id'] === $cust1 && (int)$hold['user_id'] === $uA && $hold['status'] === 'held' && str_starts_with($hold['hold_reference'], 'ONLINE '), 'held order made for the till, linked to the customer and the online order');
$items1 = json_decode($hold['items_data'], true);
ok($items1 && (int)$items1[0]['online_order_id'] === $o1, 'held lines carry their online order id');

[$c, $r] = http('GET', '/api/pos/get_held_sales.php', [], $uB);
$seen = array_values(array_filter($r['data'] ?? [], fn($h) => (int)$h['hold_id'] === (int)$hold['hold_id']));
ok($seen && $seen[0]['warehouse_name'] === FX . ' Restaurant Pickup', 'another cashier sees the accepted online order in Held orders (with its shop)');
[$c, $r] = http('POST', '/api/pos/delete_held_sale.php', ['hold_id' => (int)$hold['hold_id']], $uB, true);
ok(empty($r['success']) && stripos((string)($r['message'] ?? ''), 'online order') !== false, 'the bin on the till cannot throw an online order away (cancel it on Online orders)');
[$c, $r] = http('POST', '/api/pos/delete_held_sale.php', ['hold_id' => (int)$hold['hold_id'], 'loaded' => 1], $uB, true);
ok(!empty($r['success']) && $pdo->query("SELECT status FROM pos_held_sales WHERE hold_id = " . (int)$hold['hold_id'])->fetchColumn() === 'loaded', 'loading it onto the till keeps the record (status loaded)');
[$c, $r] = http('POST', '/api/pos/delete_held_sale.php', ['hold_id' => (int)$hold['hold_id'], 'loaded' => 1], $uB, true);
ok(empty($r['success']) && stripos((string)($r['message'] ?? ''), 'already opened') !== false, 'a second till cannot open the same online order');

[$c, $r] = $sale($widRest, $items1, $uB, $cust1);
$s1 = (int)($r['sale_id'] ?? 0);
$O = $order($o1);
ok($c === 200 && $s1 > 0 && in_array($O['order_code'], $r['online_orders'] ?? [], true), 'cashier completes the sale; the reply names the online order' . ($c !== 200 ? ' — ' . json_encode($r) : ''));
ok($O['status'] === 'completed' && (int)$O['sale_id'] === $s1 && $O['completed_at'] !== null, 'the online order closed itself and is linked to the receipt');
[$c, $r] = http('GET', '/api/pos/get_sales.php', ['start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d')], $uB);
$srow = array_values(array_filter($r['data'] ?? [], fn($x) => (int)$x['sale_id'] === $s1));
ok($srow && $srow[0]['online_orders'] === $O['order_code'], 'Sales history shows the online order on that receipt');
[$c, $html] = http('GET', '/api/pos/print_receipt.php', ['id' => $s1], $uB);
ok(is_string($html) && str_contains($html, 'Online order:') && str_contains($html, $O['order_code']), 'the printed receipt shows "Online order: ' . $O['order_code'] . '"');
[$c, $r] = $sale($widRest, $items1, $uB, $cust1);
ok($c === 422 && stripos((string)($r['message'] ?? ''), 'already paid on receipt') !== false, 'paying the same online order again is refused (names the receipt)');

$o2 = $mkOrder($widRest, 'pickup', FX . ' Wrong Shop', '0700000222', [$line($pRs, 1, 2000)]);
http('POST', $RAPI, ['action' => 'accept', 'order_id' => $o2], $uA);
$stockA0 = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id = $pRs AND warehouse_id = $wid")->fetchColumn();
$items2 = json_decode((string)$pdo->query("SELECT items_data FROM pos_held_sales WHERE online_order_id = $o2")->fetchColumn(), true);
[$c, $r] = $sale($wid, $items2, $uB);
ok($c === 422 && stripos((string)($r['message'] ?? ''), 'another shop') !== false, 'an online order cannot be sold from another shop\'s till');
ok((float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id = $pRs AND warehouse_id = $wid")->fetchColumn() === $stockA0, '…and that other shop\'s stock was not touched');

[$c, $r] = http('POST', '/api/pos/void_sale.php', ['sale_id' => $s1, 'reason' => 'ZZ test void'], $uB);
$O = $order($o1);
$reheld = $pdo->query("SELECT * FROM pos_held_sales WHERE online_order_id = $o1 AND status = 'held'")->fetch(PDO::FETCH_ASSOC);
ok($c === 200 && !empty($r['success']) && $O['status'] === 'accepted' && $O['sale_id'] === null, 'voiding the receipt puts the online order back to Accepted');
ok($reheld && (int)$reheld['customer_id'] === $cust1, '…and back into Held orders on the till, with its customer');
[$c, $r] = http('POST', $RAPI, ['action' => 'cancel', 'order_id' => $o1, 'reason' => 'Did not come'], $uA);
$O = $order($o1);
ok($c === 200 && $O['status'] === 'cancelled' && $O['cancelled_by'] === 'staff' && $O['reject_reason'] === 'Did not come', 'Cancel – not collected: order cancelled by staff with the reason');
ok($pdo->query("SELECT status FROM pos_held_sales WHERE hold_id = " . (int)$reheld['hold_id'])->fetchColumn() === 'cancelled', '…and its held order left the till');

$o3 = $mkOrder($widRest, 'pickup', FX . ' Credit Buyer', '0700000333', [$line($pRs, 1, 2000)]);
http('POST', $RAPI, ['action' => 'accept', 'order_id' => $o3], $uA);
$O3 = $order($o3);
$items3 = json_decode((string)$pdo->query("SELECT items_data FROM pos_held_sales WHERE online_order_id = $o3")->fetchColumn(), true);
[$c, $r] = $sale($widRest, $items3, $uB, (int)$O3['customer_id'], 'credit');
$O3 = $order($o3);
ok($c === 200 && $O3['status'] === 'completed' && (int)$O3['sale_id'] === (int)($r['sale_id'] ?? -1) && ($r['payment_status'] ?? '') === 'pending', 'a credit sale for the linked customer also closes and links the order' . ($c !== 200 ? ' — ' . json_encode($r) : ''));

$floor = $ins("INSERT INTO restaurant_floors (warehouse_id, name, sort_order, status) VALUES (?, 'Main', 0, 'active')", [$widRest]);
$tbl = $ins("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, 'Z9', 2, 'available')", [$widRest, $floor]);
$oT = $mkOrder($widRest, 'table', '', '', [$line($pRs, 1, 2000)], 'accepted', $tbl);
[$c, $r] = http('POST', $RAPI, ['action' => 'cancel', 'order_id' => $oT], $uA);
ok($c === 422 && $order($oT)['status'] === 'accepted', 'a table order cannot be "not collected" (it closes with its table bill)');
$oShop = $mkOrder($widRest, 'shop_pickup', FX . ' Shop Buyer', '0700000444', [$line($pRs, 1, 2000)]);
[$c, $r] = http('GET', $RAPI, ['action' => 'list', 'warehouse_id' => $widRest], $uA);
ok($c === 200 && !in_array($oShop, array_column($r['data'] ?? [], 'order_id')), 'restaurant Online orders never lists a retail shop order');
[$c, $r] = http('POST', $RAPI, ['action' => 'accept', 'order_id' => $oShop], $uA);
ok($c === 404 && $order($oShop)['status'] === 'pending', '…and cannot accept one');
$oOld = $mkOrder($widRest, 'pickup', FX . ' Late', '0700000555', [$line($pRs, 1, 2000)], 'accepted');
$oOldT = $mkOrder($widRest, 'table', '', '', [$line($pRs, 1, 2000)], 'accepted', $tbl);
$pdo->exec("UPDATE restaurant_online_orders SET created_at = NOW() - INTERVAL 13 HOUR, accepted_at = NOW() - INTERVAL 13 HOUR WHERE order_id IN ($oOld, $oOldT)");
http('GET', $RAPI, ['action' => 'list', 'warehouse_id' => $widRest], $uA);
ok($order($oOld)['status'] === 'accepted' && $order($oOldT)['status'] === 'completed', 'after 12 h an unpaid pickup order stays open (never closed silently); a table order is tidied');
require_once "$root/core/restaurant_notify.php";
if (rnModuleOn($pdo)) {
    $sum = rnRunFrequentChecks($pdo);
    ok(($sum['online_order_uncollected'] ?? 0) >= 1, 'reminder: an accepted pickup order not paid after 3 h alerts staff');
} else {
    echo "  (restaurant module not entitled on this database — reminder check skipped)\n";
}
$audits = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'online_order_%' AND created_at >= '" . $T0 . "'")->fetchColumn();
ok($audits >= 4, "every transition is in the audit log ($audits rows)");

// ─── 4. Share shop (staff API) + the public shop page (buyer) ───────────────
echo "\n── 4. Share shop + public shop page ──\n";
$SAPI = '/api/stock/online_shop.php';
$pdo->exec("UPDATE warehouses SET online_shop_enabled = 0, online_shop_ordering = 0, online_shop_pickup = 1, online_shop_delivery = 0, online_shop_wholesale = 0, online_show_payment = 0 WHERE warehouse_id = $wid");
[$c, $r] = http('GET', $SAPI, ['warehouse_id' => $widR], $uA);
ok($c === 200 && empty($r['success']), 'a restaurant-only shop cannot get a retail online shop (use Share menu there)');
[$c, $r] = http('GET', $SAPI, ['warehouse_id' => $wid], $uA);
ok($c === 200 && !empty($r['success']) && $r['data']['enabled'] === false && $r['data']['pickup'] === true, 'Share shop opens: off by default, Pickup preset');
[$c, $r] = http('POST', $SAPI, ['action' => 'save', 'warehouse_id' => $wid, 'enabled' => 1, 'ordering' => 1, 'pickup' => 0, 'delivery' => 0], $uA);
ok($c === 422 && stripos((string)($r['message'] ?? ''), 'Pickup, Delivery') !== false, 'ordering with neither Pickup nor Delivery is refused');
[$c, $r] = http('POST', $SAPI, ['action' => 'save', 'warehouse_id' => $wid, 'enabled' => 1, 'ordering' => 0, 'pickup' => 1, 'delivery' => 0], $uA);
$link = (string)($r['data']['link'] ?? '');
ok($c === 200 && preg_match('#/s/([a-f0-9]{32})$#', $link, $lm) === 1, 'switching it on gives a link /s/<32 hex> (always visible to copy again)');
$tok = $lm[1] ?? $tok;
$S = "/s/$tok";

[$c, $html] = http('GET', $S);
$H = $GLOBALS['LAST_HDR'];
ok($c === 200 && is_string($html) && str_contains($html, FX . ' Soap') && str_contains($html, FX . ' Sugar'), 'public shop page shows this shop\'s products (no login)');
ok(str_contains($H['content-security-policy'] ?? '', "frame-ancestors 'none'") && ($H['referrer-policy'] ?? '') === 'no-referrer'
   && str_contains($H['x-robots-tag'] ?? '', 'noindex') && ($H['x-frame-options'] ?? '') === 'DENY', 'CSP, no-referrer, noindex and no-framing headers sent');
preg_match('/var ITEMS = (\[.*?\]);/s', $html, $im);
$pubItems = json_decode($im[1] ?? '[]', true) ?: [];
ok($pubItems && !preg_grep('/available|stock|cost/i', array_merge(...array_map('array_keys', $pubItems))), 'the page data has Available / Sold out only — no stock or cost field');
ok(!str_contains($html, FX . ' Hidden Item') && !str_contains($html, FX . ' Delivery Service') && !str_contains($html, FX . ' Other Shop Only'), 'hidden product, service and other shop\'s product are not on the page');
ok(!str_contains($html, 'id="cartBtn"') && str_contains($html, 'Online ordering is not available'), 'ordering off: products only, no basket');
[$c, $r] = http('POST', "$S/order", ['lines' => [['id' => $pSoap, 'qty' => 1]]], 0, true);
ok($c === 403, 'ordering off: the order endpoint refuses');

[$c, $b1] = http('GET', '/s/' . str_repeat('0', 32));
[$c2, $b2] = http('GET', '/s/' . str_repeat('a', 32) . '/o/' . str_repeat('b', 32));
ok($c === 404 && $c2 === 404 && str_contains((string)$b1, 'This shop is not available'), 'unknown link → the same generic "not available" page');

http('POST', $SAPI, ['action' => 'save', 'warehouse_id' => $wid, 'enabled' => 1, 'ordering' => 1, 'pickup' => 1, 'delivery' => 1, 'wholesale' => 0], $uA);
[$c, $html] = http('GET', $S);
ok(str_contains($html, 'id="cartBtn"') && str_contains($html, 'Delivery available'), 'ordering on: basket, pickup and delivery offered');
preg_match('/var FORM_TOKEN = "([0-9]+\.[a-f0-9]{64})"/', $html, $fm);
$ft = $fm[1] ?? '';
$base = ['form_token' => $ft, 'website' => '', 'name' => FX . ' Buyer One', 'phone' => '0744 123 456', 'note' => '', 'fulfil' => 'pickup', 'address' => '', 'price_type' => 'retail',
         'lines' => [['id' => $pSoap, 'qty' => 2, 'price' => 1], ['id' => $pSugar, 'qty' => 0.5]]];
[$c, $r] = http('POST', "$S/order", $base, 0, true);
ok($c === 429, 'an order sent faster than a person could (form token < 2 s) is refused');
sleep(2);
[$c, $r] = http('POST', "$S/order", ['x' => 1], 0, false);
ok($c === 415, 'a non-JSON post (cross-site form) is refused');
[$c, $r] = http('POST', "$S/order", array_merge($base, ['website' => 'spam']), 0, true);
ok($c === 400, 'honeypot filled → refused');
[$c, $r] = http('POST', "$S/order", array_merge($base, ['name' => 'A']), 0, true);
ok($c === 422 && stripos($r['message'] ?? '', 'name') !== false, 'name is required');
[$c, $r] = http('POST', "$S/order", array_merge($base, ['phone' => '12']), 0, true);
ok($c === 422 && stripos($r['message'] ?? '', 'phone') !== false, 'a valid phone is required');
[$c, $r] = http('POST', "$S/order", array_merge($base, ['fulfil' => 'delivery', 'address' => '']), 0, true);
ok($c === 422 && stripos($r['message'] ?? '', 'address') !== false, 'delivery needs an address');
[$c, $r] = http('POST', "$S/order", array_merge($base, ['lines' => [['id' => $pSoap, 'qty' => 50]]]), 0, true);
ok($c === 422 && stripos($r['message'] ?? '', 'Only a few') !== false && !str_contains($r['message'], '5'), 'too many → "Only a few left" (the number is never said)');
[$c, $r] = http('POST', "$S/order", array_merge($base, ['price_type' => 'wholesale']), 0, true);
$ordA = $pdo->query("SELECT * FROM restaurant_online_orders WHERE warehouse_id = $wid AND customer_name = '" . FX . " Buyer One' ORDER BY order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($c === 200 && preg_match('#^/s/[a-f0-9]{32}/o/[a-f0-9]{32}$#', $r['status_url'] ?? '') === 1 && str_starts_with($r['order_code'] ?? '', 'S-'), 'a real order is accepted as a request (S- code, status page link)');
ok($ordA && $ordA['status'] === 'pending' && (float)$ordA['subtotal'] === 2 * 2000 + 0.5 * 3000.0 && $ordA['price_type'] === 'retail',
   'priced on the server (sent price ignored); wholesale request priced retail while the switch is off');
$statusUrl = (string)$r['status_url'];
[$c, $r2] = http('GET', "$statusUrl/status");
ok($c === 200 && ($r2['status'] ?? '') === 'pending', 'status JSON for the buyer');
[$c, $page] = http('GET', $statusUrl);
ok($c === 200 && str_contains($page, $ordA['order_code']) && str_contains($page, 'Cancel my order') && str_contains($page, 'Call the shop'), 'order page: code, cancel button, call the shop');
$outN = (int)$pdo->query("SELECT COUNT(*) FROM notification_outbox WHERE event_key = 'retail.online_order_new' AND entity_id = " . (int)$ordA['order_id'])->fetchColumn();
$bellN = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key = 'retail.online_order_new' AND action_url LIKE '%order=" . (int)$ordA['order_id'] . "'")->fetchColumn();
ok($bellN >= 1 && $outN >= 1, "new order alert: bell ($bellN) + email queued ($outN, held in the test)");
[$c, $r] = http('POST', "$statusUrl/cancel", [], 0, true);
[$c2, $r2] = http('POST', "$statusUrl/cancel", [], 0, true);
$ordA = $order((int)$ordA['order_id']);
ok($c === 200 && $c2 === 409 && $ordA['status'] === 'cancelled' && $ordA['cancelled_by'] === 'customer', 'buyer cancels while waiting (once); recorded as by the customer');

$pdo->exec("DELETE FROM restaurant_online_orders WHERE client_hash <> '" . str_repeat('f', 64) . "' AND warehouse_id = $wid AND status IN ('pending','cancelled')");   // free the limit for the next order
[$c, $r] = http('POST', "$S/order", array_merge($base, ['fulfil' => 'delivery', 'address' => 'Mbezi Beach, near the school', 'name' => FX . ' Buyer Del']), 0, true);
$ordD = $pdo->query("SELECT * FROM restaurant_online_orders WHERE warehouse_id = $wid AND customer_name = '" . FX . " Buyer Del'")->fetch(PDO::FETCH_ASSOC);
ok($c === 200 && $ordD && $ordD['order_type'] === 'shop_delivery' && $ordD['delivery_address'] === 'Mbezi Beach, near the school', 'delivery order saved with its address');

if ($wsGroup) {
    http('POST', $SAPI, ['action' => 'save', 'warehouse_id' => $wid, 'enabled' => 1, 'ordering' => 1, 'pickup' => 1, 'delivery' => 1, 'wholesale' => 1], $uA);
    [$c, $html] = http('GET', $S);
    ok(str_contains($html, 'data-pt="wholesale"'), 'wholesale switch on: the page offers Retail / Wholesale');
    $pdo->exec("DELETE FROM restaurant_online_orders WHERE warehouse_id = $wid AND status = 'pending'");
    [$c, $r] = http('POST', "$S/order", array_merge($base, ['price_type' => 'wholesale', 'name' => FX . ' Trader', 'lines' => [['id' => $pSoap, 'qty' => 2]]]), 0, true);
    $ordW = $pdo->query("SELECT * FROM restaurant_online_orders WHERE warehouse_id = $wid AND customer_name = '" . FX . " Trader'")->fetch(PDO::FETCH_ASSOC);
    ok($c === 200 && $ordW && $ordW['price_type'] === 'wholesale' && (float)$ordW['subtotal'] === 3400.0, 'wholesale order priced at the wholesale price and marked wholesale');
}

http('POST', '/api/stock/online_shop_product.php', ['product_id' => $pSugar, 'hidden' => 1], $uA);
[$c, $html] = http('GET', $S);
ok(!str_contains($html, FX . ' Sugar'), 'hiding a product from the Products page removes it from the online shop');
http('POST', '/api/stock/online_shop_product.php', ['product_id' => $pSugar, 'hidden' => 0], $uA);
[$c, $r] = http('POST', '/api/stock/online_shop_product.php', ['product_id' => $pServ, 'hidden' => 0], $uA);
ok($c === 422, 'a service can never be shown online');

$pdo->prepare("UPDATE warehouses SET public_catalog_token_hash = ? WHERE warehouse_id = ?")->execute([hash('sha256', 'zz-old-catalog-token'), $wid]);
[$c, $r] = http('GET', '/shop_catalog', ['token' => 'zz-old-catalog-token']);
ok($c === 301 && str_ends_with($GLOBALS['LAST_HDR']['location'] ?? '', "/s/$tok"), 'an old posted catalog link sends the buyer on to the online shop');
[$c, $r] = http('GET', '/shop_catalog', ['token' => 'nope']);
ok($c === 404 && str_contains((string)$r, 'not available'), 'an unknown old link shows the generic page');

[$c, $r] = http('POST', $SAPI, ['action' => 'rotate', 'warehouse_id' => $wid], $uA);
$newTok = preg_match('#/s/([a-f0-9]{32})$#', (string)($r['data']['link'] ?? ''), $nm) ? $nm[1] : '';
[$c1] = http('GET', $S);
[$c2] = http('GET', "/s/$newTok");
ok($newTok !== '' && $newTok !== $tok && $c1 === 404 && $c2 === 200, 'Make new link: the old link stops working at once, the new one works');
http('POST', $SAPI, ['action' => 'save', 'warehouse_id' => $wid, 'enabled' => 0, 'ordering' => 1, 'pickup' => 1], $uA);
[$c] = http('GET', "/s/$newTok");
ok($c === 404, 'switching the shop off closes the link');
$aud = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('online_shop_settings','online_shop_new_link') AND entity_id = $wid AND created_at >= '$T0'")->fetchColumn();
ok($aud >= 3, "settings changes and new links are in the audit log ($aud)");

// ─── 5. Online orders inbox (retail staff) → till → receipt ─────────────────
echo "\n── 5. Shop Online orders → till → receipt ──\n";
$OAPI = '/api/stock/shop_online_orders.php';
http('POST', $SAPI, ['action' => 'save', 'warehouse_id' => $wid, 'enabled' => 1, 'ordering' => 1, 'pickup' => 1, 'delivery' => 1, 'wholesale' => 1], $uA);
$tok2 = preg_replace('#.*/s/#', '', (string)$pdo->query("SELECT CONCAT('/s/', online_shop_token) FROM warehouses WHERE warehouse_id = $wid")->fetchColumn());
$S2 = "/s/$tok2";
$pdo->exec("DELETE FROM restaurant_online_orders WHERE warehouse_id = $wid");
$placeOrder = function (array $over) use ($S2, $pSoap): array {
    [, $html] = http('GET', $S2);
    preg_match('/var FORM_TOKEN = "([0-9]+\.[a-f0-9]{64})"/', (string)$html, $fm);
    sleep(2);
    return http('POST', "$S2/order", array_merge(['form_token' => $fm[1] ?? '', 'website' => '', 'name' => FX . ' Retail Buyer', 'phone' => '0766 555 444',
        'note' => 'Morning', 'fulfil' => 'pickup', 'address' => '', 'price_type' => 'retail', 'lines' => [['id' => $pSoap, 'qty' => 2]]], $over), 0, true);
};
$existingR = $ins("INSERT INTO customers (customer_code, customer_name, phone, customer_type, status, created_at) VALUES (?, ?, ?, 'individual', 'active', NOW())",
                  ['ZZRS-R' . random_int(1000, 9999), FX . ' Retail B.', '+255766555444']);
[$c, $r] = $placeOrder(['price_type' => 'wholesale', 'lines' => [['id' => $pSoap, 'qty' => 2]]]);
$R1 = $pdo->query("SELECT * FROM restaurant_online_orders WHERE warehouse_id = $wid AND status = 'pending' ORDER BY order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($c === 200 && $R1 && $R1['price_type'] === 'wholesale', 'buyer sends a wholesale order from the online shop');
[$c, $r] = http('GET', $OAPI, ['action' => 'count'], $uA);
ok($c === 200 && ($r['pending'] ?? 0) >= 1 && ($r['latest']['code'] ?? '') === $R1['order_code'], 'staff alert count sees the new order');
[$c, $r] = http('GET', $OAPI, ['action' => 'list', 'warehouse_id' => $wid], $uA);
$lst = array_values(array_filter($r['data'] ?? [], fn($o) => $o['order_id'] === (int)$R1['order_id']));
ok($lst && $lst[0]['price_type'] === 'wholesale' && $lst[0]['type'] === 'pickup' && $lst[0]['phone'] === '0766555444', 'Online orders lists it (wholesale badge, pickup, phone)');
[$c, $r] = http('POST', $OAPI, ['action' => 'accept', 'order_id' => (int)$oT], $uA);
ok($c === 404, 'the shop screen cannot handle a restaurant order');

$pdo->exec("UPDATE product_stocks SET stock_quantity = 1 WHERE product_id = $pSoap AND warehouse_id = $wid");
[$c, $r] = http('POST', $OAPI, ['action' => 'accept', 'order_id' => (int)$R1['order_id']], $uA);
ok($c === 422 && stripos($r['message'] ?? '', 'Not enough stock') !== false && str_contains($r['message'], 'only 1 left') && $order((int)$R1['order_id'])['status'] === 'pending',
   'accept re-checks stock: sold at the till meanwhile → refused, staff see how many are left');
$pdo->exec("UPDATE product_stocks SET stock_quantity = 5 WHERE product_id = $pSoap AND warehouse_id = $wid");
[$c, $r] = http('GET', $OAPI, ['action' => 'customer_hint', 'order_id' => (int)$R1['order_id']], $uA);
ok($c === 200 && in_array($existingR, array_column($r['data'] ?? [], 'customer_id')), 'Accept hint: the phone already belongs to a customer with another name');
[$c, $r] = http('POST', $OAPI, ['action' => 'accept', 'order_id' => (int)$R1['order_id'], 'link_customer_id' => $existingR], $uA);
$R1 = $order((int)$R1['order_id']);
$hR = $pdo->query("SELECT * FROM pos_held_sales WHERE online_order_id = " . (int)$R1['order_id'] . " AND status = 'held'")->fetch(PDO::FETCH_ASSOC);
ok($c === 200 && $R1['status'] === 'accepted' && (int)$R1['customer_id'] === $existingR && $r['customer_created'] === false, 'staff chose "link to this customer" → order linked to the existing customer');
ok($hR && (int)$hR['customer_id'] === $existingR && (int)$hR['warehouse_id'] === $wid, 'held order on the till for that shop and customer');
[$c, $r] = http('POST', $OAPI, ['action' => 'ready', 'order_id' => (int)$R1['order_id']], $uA);
$pubR1 = rsPublicOrder($pdo, $wid, $R1['public_ref']);
ok($c === 200 && $pubR1['status'] === 'ready', 'Ready → the buyer\'s page shows it is ready to collect');

http('POST', '/api/pos/delete_held_sale.php', ['hold_id' => (int)$hR['hold_id'], 'loaded' => 1], $uB, true);
$itemsR = json_decode($hR['items_data'], true);
$stock0 = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id = $pSoap AND warehouse_id = $wid")->fetchColumn();
[$c, $r] = $sale($wid, $itemsR, $uB, $existingR, 'cash', (int)$wsGroup);
$R1 = $order((int)$R1['order_id']);
$sR = (int)($r['sale_id'] ?? 0);
$sRow = $pdo->query("SELECT grand_total, customer_id FROM pos_sales WHERE sale_id = $sR")->fetch(PDO::FETCH_ASSOC);
ok($c === 200 && $R1['status'] === 'completed' && (int)$R1['sale_id'] === $sR, 'cashier completes it on the Wholesale list → the order closes with its receipt' . ($c !== 200 ? ' — ' . json_encode($r) : ''));
ok($sRow && abs((float)$sRow['grand_total'] - 3400) < 0.01 && (int)$sRow['customer_id'] === $existingR, 'receipt total = the wholesale order total, on the linked customer');
ok(abs((float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id = $pSoap AND warehouse_id = $wid")->fetchColumn() - ($stock0 - 2)) < 0.001, 'stock goes down only now, at the sale (by the ordered 2)');
$je = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type = 'pos_sale' AND entity_id = $sR AND status = 'posted'")->fetchColumn();
echo "  (ledger: $je posted entr" . ($je === 1 ? 'y' : 'ies') . " for the receipt — follows this company's posting setting, as every till sale does)\n";
[$c, $r] = http('GET', $OAPI, ['action' => 'list', 'warehouse_id' => $wid], $uA);
$lst = array_values(array_filter($r['data'] ?? [], fn($o) => $o['order_id'] === (int)$R1['order_id']));
ok($lst && $lst[0]['status'] === 'completed' && $lst[0]['receipt'] !== '', 'Done today shows it paid with its receipt number');

$pdo->exec("DELETE FROM restaurant_online_orders WHERE warehouse_id = $wid AND status IN ('pending','cancelled','rejected')");
[$c, $r] = $placeOrder(['name' => FX . ' Reject Me', 'phone' => '0766000001']);
$R2 = $pdo->query("SELECT * FROM restaurant_online_orders WHERE warehouse_id = $wid AND customer_name = '" . FX . " Reject Me'")->fetch(PDO::FETCH_ASSOC);
[$c, $r] = http('POST', $OAPI, ['action' => 'reject', 'order_id' => (int)$R2['order_id'], 'reason' => 'Out of soap today'], $uA);
[$c2, $st] = http('GET', "$S2/o/{$R2['public_ref']}/status");
ok($c === 200 && ($st['status'] ?? '') === 'rejected' && ($st['reason'] ?? '') === 'Out of soap today', 'Reject → the buyer sees the reason');
ok((int)$pdo->query("SELECT COUNT(*) FROM customers WHERE customer_name = '" . FX . " Reject Me'")->fetchColumn() === 0, 'a rejected order never creates a customer');

[$c, $r] = $placeOrder(['name' => FX . ' Never Came', 'phone' => '0766000002', 'fulfil' => 'delivery', 'address' => 'Tegeta']);
$R3 = $pdo->query("SELECT * FROM restaurant_online_orders WHERE warehouse_id = $wid AND customer_name = '" . FX . " Never Came'")->fetch(PDO::FETCH_ASSOC);
[$c, $r] = http('POST', $OAPI, ['action' => 'accept', 'order_id' => (int)$R3['order_id']], $uA);
[$c, $r] = http('POST', $OAPI, ['action' => 'ready', 'order_id' => (int)$R3['order_id']], $uA);
ok($c === 200 && stripos($r['message'] ?? '', 'on the way') !== false, 'delivery order: Ready says "on the way"');
$pdo->exec("UPDATE restaurant_online_orders SET accepted_at = NOW() - INTERVAL 4 HOUR WHERE order_id = " . (int)$R3['order_id']);
require_once "$root/core/retail_online_notify.php";
$sum = rsnRunFrequentChecks($pdo);
ok(($sum['online_order_uncollected'] ?? 0) >= 1, 'reminder: accepted but not paid after 3 h alerts staff');
[$c, $r] = http('POST', $OAPI, ['action' => 'cancel', 'order_id' => (int)$R3['order_id'], 'reason' => 'Customer not reachable'], $uA);
$R3 = $order((int)$R3['order_id']);
ok($c === 200 && $R3['status'] === 'cancelled' && $R3['cancelled_by'] === 'staff'
   && $pdo->query("SELECT status FROM pos_held_sales WHERE online_order_id = " . (int)$R3['order_id'])->fetchColumn() === 'cancelled', 'Not collected → cancelled by staff, removed from the till');

$pdo->exec("DELETE FROM restaurant_online_orders WHERE warehouse_id = $wid AND status IN ('pending','cancelled','rejected')");
[$c, $r] = $placeOrder(['name' => FX . ' Slow Shop', 'phone' => '0766000003']);
$R4 = $pdo->query("SELECT * FROM restaurant_online_orders WHERE warehouse_id = $wid AND customer_name = '" . FX . " Slow Shop'")->fetch(PDO::FETCH_ASSOC);
$pdo->exec("UPDATE restaurant_online_orders SET created_at = NOW() - INTERVAL 6 MINUTE WHERE order_id = " . (int)$R4['order_id']);
$sum = rsnRunFrequentChecks($pdo);
ok(($sum['online_order_waiting'] ?? 0) >= 1, 'reminder: an order not accepted after 5 minutes alerts staff');
$pdo->exec("UPDATE restaurant_online_orders SET created_at = NOW() - INTERVAL 25 HOUR WHERE order_id = " . (int)$R4['order_id']);
$sum = rsnRunFrequentChecks($pdo);
[$c, $st] = http('GET', "$S2/o/{$R4['public_ref']}/status");
ok(($sum['expired'] ?? 0) >= 1 && ($st['status'] ?? '') === 'cancelled' && ($st['cancelled_by'] ?? '') === 'system', 'after 24 h a waiting order expires and the buyer is told');

[$c, $page] = http('GET', '/shop_online_orders', [], $uA);
ok($c === 200 && is_string($page) && str_contains($page, 'Online orders') && str_contains($page, 'shop_online_orders.php') && str_contains($page, 'openShareShop'), 'Online orders page opens for staff (with Share shop)');
[$c, $page] = http('GET', '/dashboard', [], $uA);
ok(is_string($page) && str_contains($page, 'shop_online_orders'), 'the menu links to Online orders once a shop is online');
[$c, $page] = http('GET', '/products', [], $uA);
ok(is_string($page) && str_contains($page, 'openShareShop()') && str_contains($page, 'id="shareShopModal"'), 'Products page has the Share shop button and window');
[$c, $page] = http('GET', "/warehouse_view/$wid", [], $uA);
ok(is_string($page) && str_contains($page, 'openShareShop(' . $wid . ')') && !str_contains($page, 'scGenerateBtn'), 'shop page: Online shop box with Share shop (old Generate Link gone)');

// ─── Cleanup ─────────────────────────────────────────────────────────────────
$pdo->prepare("DELETE FROM audit_logs WHERE action IN ('online_shop_settings','online_shop_new_link') AND created_at >= ?")->execute([$T0]);
if (is_resource($srv)) proc_terminate($srv);
fx_cleanup_sales($pdo, $saleIds);
$pdo->exec("DELETE FROM restaurant_tables WHERE warehouse_id = $widRest");
$pdo->prepare("DELETE FROM cash_register_shifts WHERE shift_id = ?")->execute([$shiftB]);
$pdo->exec("DELETE FROM pos_held_sales WHERE user_id IN ($uA, $uB)");
$pdo->exec("DELETE FROM stock_movements WHERE warehouse_id IN (SELECT warehouse_id FROM warehouses WHERE warehouse_name LIKE '" . FX . "%')");
$pdo->prepare("DELETE FROM audit_logs WHERE action LIKE 'online_order_%' AND created_at >= ?")->execute([$T0]);
$pdo->exec("DELETE FROM users WHERE user_id IN ($uA, $uB)");
fx_cleanup($pdo);
done_exit();
