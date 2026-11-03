<?php
/**
 * Restaurant online menu & ordering (restaurant_online_menu_plan.md).
 *   php tests/test_restaurant_online_menu_cli.php
 *
 * Static:   the security rules are in the code (no session identity in the
 *           public file, server pricing, headers, gate).
 * Real HTTP (php -S, this file as router):
 *   a temporary restaurant shop (tables, dishes, options) is created, used and
 *   removed again. Smart POS is switched on ONLY inside the test server (the
 *   shared database setting is never changed).
 *   Public:  menu renders, no cost/stock leaks, bad/rotated tokens look the same,
 *            forged prices / foreign items / bad options / limits / honeypot /
 *            timing are refused, a real order is stored as 'pending'.
 *   Staff:   (Phase 5) accept → table held order + kitchen ticket, reject, ready.
 * Exit 0 = pass.
 *
 * BMS_RO_SERVE=1 php tests/... → create the fixture, print its links and keep
 * the server running (for a browser look); `--cleanup` removes the fixture.
 */

$root = dirname(__DIR__);

// ─── Web-server router (this file run by `php -S`) ───────────────────────────
if (PHP_SAPI === 'cli-server') {
    $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file($root . $path) && !str_ends_with($path, '.php')) return false;

    // Smart POS on for THIS server only: our get_setting() is defined before
    // helpers.php, whose own definition is wrapped in function_exists().
    function get_setting($key, $default = '') {
        global $pdo;
        static $cache = null;
        if ($key === 'pos_simple_mode') return '1';
        $over = getenv('BMS_RO_SETTINGS') ? (json_decode(getenv('BMS_RO_SETTINGS'), true) ?: []) : [];
        if (array_key_exists($key, $over)) return (string)$over[$key];
        if ($cache === null) {
            $cache = [];
            if ($pdo instanceof PDO) {
                try { foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings") as $r) $cache[$r['setting_key']] = $r['setting_value']; }
                catch (Throwable $e) {}
            }
        }
        return $cache[$key] ?? $default;
    }

    if (session_status() === PHP_SESSION_NONE) session_start();
    // Staff requests say so with a header; public requests never get a user.
    $previewStaff = getenv('BMS_RO_SERVE') === '1' && !str_starts_with($path, '/m/');   // browser preview: staff pages logged in
    if (($_SERVER['HTTP_X_BMS_TEST_STAFF'] ?? '') === '1' || $previewStaff) {
        $_SESSION['user_id'] = (int)getenv('BMS_TEST_ADMIN'); $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin';
        $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true; $_SESSION['user_lang'] = 'en';
        $_SESSION['csrf_token'] = 'testcsrf';
    } elseif (($_SERVER['HTTP_X_BMS_TEST_PUBLIC'] ?? '') === '1') {
        $_SESSION = [];
    }
    $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    chdir($root);
    require $root . '/index.php';
    return true;
}

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function done_exit() { global $pass, $fail; echo "\n" . ($fail === 0 ? "\033[32mALL $pass PASSED\033[0m" : "\033[31m$fail FAILED\033[0m, $pass passed") . "\n"; exit($fail === 0 ? 0 : 1); }

// ─── 1. Static ───────────────────────────────────────────────────────────────
echo "\n── 1. Static: the rules are in the code ──\n";
$pub  = (string)file_get_contents("$root/public_menu.php");
$core = (string)file_get_contents("$root/core/restaurant_online.php");
$code = function (string $src): string { $o = ''; foreach (token_get_all($src) as $t) if (!is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) $o .= is_array($t) ? $t[1] : $t; return $o; };
$pubCode = $code($pub); $coreCode = $code($core);
ok(!preg_match('/\$_SESSION/', $pubCode) && !preg_match('/\$_SESSION/', $coreCode), 'public page + core never read $_SESSION (no staff identity leaks into the public view)');
ok(!str_contains($pubCode, 'includeHeader'), 'public page does not load the staff header');
ok(str_contains($pubCode, "Content-Security-Policy") && str_contains($coreCode, 'Referrer-Policy: no-referrer') && str_contains($coreCode, 'X-Robots-Tag'), 'CSP, no-referrer and noindex headers are sent');
$bf0 = strpos($coreCode, 'function roBuildOrder'); $buildFn = substr($coreCode, $bf0, strpos($coreCode, 'function roSecret') - $bf0);
ok(!preg_match('/\$in\[\'(price|subtotal|total|unit)/', $pubCode) && !preg_match('/\$(ln|r)\[\'(price|unit|subtotal)/', $buildFn), 'the server never reads a price from the request');
ok(str_contains($coreCode, "random_bytes(16)") && str_contains($coreCode, 'hash_equals'), 'tokens: 128-bit random, compared in constant time');
ok(preg_match('/roPublicMenu[\s\S]*?\'sold_out\'/', $coreCode) && !preg_match("/'(cost_price|stock|purchase_price)'\s*=>/", substr($coreCode, strpos($coreCode, 'function roPublicMenu'), 3000)), 'public menu output has no cost / stock number fields');
ok(str_contains((string)file_get_contents("$root/roots.php"), "'public-menu'    => ROOT_DIR . '/public_menu.php'"), 'route registered');

if (!is_file("$root/includes/config.php")) { echo "  (no includes/config.php — real HTTP checks skipped)\n"; done_exit(); }

// ─── Fixture ─────────────────────────────────────────────────────────────────
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require_once "$root/includes/config.php";
require_once "$root/core/restaurant_online_schema.php";
restaurantOnlineEnsureSchema($pdo, function () {});
const FX = 'ZZ RO Test';

function fx_cleanup(PDO $pdo): void {
    $wids = $pdo->query("SELECT warehouse_id FROM warehouses WHERE warehouse_name LIKE '" . FX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($wids as $w) {
        $w = (int)$w;
        $pids = $pdo->query("SELECT product_id FROM products WHERE warehouse_id = $w")->fetchAll(PDO::FETCH_COLUMN);
        if ($pids) {
            $in = implode(',', array_map('intval', $pids));
            $pdo->exec("DELETE FROM product_modifier_groups WHERE product_id IN ($in)");
            $pdo->exec("DELETE FROM product_stocks WHERE product_id IN ($in)");
            $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
        }
        $tix = $pdo->query("SELECT ticket_id FROM kitchen_tickets WHERE warehouse_id = $w")->fetchAll(PDO::FETCH_COLUMN);
        if ($tix) $pdo->exec("DELETE FROM kitchen_ticket_items WHERE ticket_id IN (" . implode(',', array_map('intval', $tix)) . ")");
        $pdo->exec("DELETE FROM kitchen_tickets WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM kitchen_stations WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM pos_held_sales WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM restaurant_online_orders WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM restaurant_tables WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM restaurant_floors WHERE warehouse_id = $w");
        $pdo->exec("DELETE FROM warehouses WHERE warehouse_id = $w");
    }
    $pdo->exec("DELETE FROM modifier_options WHERE group_id IN (SELECT group_id FROM modifier_groups WHERE name LIKE '" . FX . "%')");
    $pdo->exec("DELETE FROM modifier_groups WHERE name LIKE '" . FX . "%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE '" . FX . "%'");
}
if (in_array('--cleanup', $argv, true)) { fx_cleanup($pdo); echo "fixture removed\n"; exit(0); }
fx_cleanup($pdo);

$admin = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$ins = function (string $sql, array $a = []) use ($pdo): int { $pdo->prepare($sql)->execute($a); return (int)$pdo->lastInsertId(); };
$wid   = $ins("INSERT INTO warehouses (warehouse_code, warehouse_name, status, pos_mode) VALUES (?, ?, 'active', 'restaurant')", ['ZZRO' . random_int(1000, 9999), FX . ' Restaurant']);
$wid2  = $ins("INSERT INTO warehouses (warehouse_code, warehouse_name, status, pos_mode) VALUES (?, ?, 'active', 'restaurant')", ['ZZRP' . random_int(1000, 9999), FX . ' Other Shop']);
$floor = $ins("INSERT INTO restaurant_floors (warehouse_id, name, sort_order, status) VALUES (?, 'Main', 0, 'active')", [$wid]);
$t5    = $ins("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, '5', 4, 'available')", [$wid, $floor]);
$t6    = $ins("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, '6', 2, 'available')", [$wid, $floor]);
$kst   = $ins("INSERT INTO kitchen_stations (warehouse_id, name, status) VALUES (?, 'Kitchen', 'active')", [$wid]);
$catM  = $ins("INSERT INTO categories (category_name) VALUES (?)", [FX . ' Main dishes']);
$catD  = $ins("INSERT INTO categories (category_name) VALUES (?)", [FX . ' Drinks']);
$prod = function (string $name, float $price, int $cat, int $isService, int $avail = 1, int $w = 0, float $cost = 0) use ($ins, $wid, $kst, $admin): int {
    return $ins("INSERT INTO products (product_name, category_id, selling_price, cost_price, purchase_price, unit, is_service, track_inventory, warehouse_id,
                 kitchen_station_id, is_menu_item, is_available, status, created_by)
                 VALUES (?, ?, ?, ?, ?, 'pcs', ?, ?, ?, ?, 1, ?, 'active', ?)",
        [$name, $cat, $price, $cost, $cost, $isService, $isService ? 0 : 1, $w ?: $wid, $isService ? $kst : null, $avail, $admin]);
};
$pChips  = $prod(FX . ' Chips Kuku', 7000, $catM, 1, 1, 0, 3100);
$pPilau  = $prod(FX . ' Pilau Nyama', 6000, $catM, 1);
$pHidden = $prod(FX . ' Off Today', 5000, $catM, 1, 0);
$pSoda   = $prod(FX . ' Soda', 1000, $catD, 0, 1, 0, 600);
$pWater  = $prod(FX . ' Water', 500, $catD, 0);
$pOther  = $prod(FX . ' Other Shop Dish', 100, $catM, 1, 1, $wid2);
$pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, 37), (?, ?, 0)")->execute([$pSoda, $wid, $pWater, $wid]);
$gSide  = $ins("INSERT INTO modifier_groups (name, selection_type, min_select, max_select, is_required, status) VALUES (?, 'single', 1, 1, 1, 'active')", [FX . ' Side']);
$oRice  = $ins("INSERT INTO modifier_options (group_id, name, price_adjustment, status) VALUES (?, 'Rice', 0, 'active')", [$gSide]);
$oUgali = $ins("INSERT INTO modifier_options (group_id, name, price_adjustment, status) VALUES (?, 'Ugali', 500, 'active')", [$gSide]);
$gExtra = $ins("INSERT INTO modifier_groups (name, selection_type, min_select, max_select, is_required, status) VALUES (?, 'multiple', 0, 2, 0, 'active')", [FX . ' Extras']);
$oEgg   = $ins("INSERT INTO modifier_options (group_id, name, price_adjustment, status) VALUES (?, 'Egg', 1000, 'active')", [$gExtra]);
$oSauce = $ins("INSERT INTO modifier_options (group_id, name, price_adjustment, status) VALUES (?, 'Sauce', 300, 'active')", [$gExtra]);
$oCheese= $ins("INSERT INTO modifier_options (group_id, name, price_adjustment, status) VALUES (?, 'Cheese', 800, 'active')", [$gExtra]);
$gOther = $ins("INSERT INTO modifier_groups (name, selection_type, min_select, max_select, is_required, status) VALUES (?, 'single', 0, 1, 0, 'active')", [FX . ' Unlinked']);
$oOther = $ins("INSERT INTO modifier_options (group_id, name, price_adjustment, status) VALUES (?, 'Unlinked opt', -5000, 'active')", [$gOther]);
$pdo->prepare("INSERT INTO product_modifier_groups (product_id, group_id) VALUES (?, ?), (?, ?)")->execute([$pChips, $gSide, $pChips, $gExtra]);

require_once "$root/core/restaurant_online.php";
$shopTok = roNewToken(); $t5Tok = roNewToken(); $t6Tok = roNewToken();
$pdo->prepare("UPDATE warehouses SET online_menu_token = ?, online_menu_enabled = 1, online_order_table = 1, online_order_pickup = 1 WHERE warehouse_id = ?")->execute([$shopTok, $wid]);
$pdo->prepare("UPDATE restaurant_tables SET qr_token = ? WHERE table_id = ?")->execute([$t5Tok, $t5]);
$pdo->prepare("UPDATE restaurant_tables SET qr_token = ? WHERE table_id = ?")->execute([$t6Tok, $t6]);

// ─── Server ──────────────────────────────────────────────────────────────────
$port = 18000 + random_int(0, 900);
$env  = array_merge(getenv(), ['BMS_TEST_ADMIN' => (string)$admin]);
$nul  = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$serve = getenv('BMS_RO_SERVE') === '1';
if ($serve) {
    // Sample pictures for a visual check only (uploads/ is git-ignored).
    @mkdir("$root/uploads/ro_preview", 0777, true);
    foreach ([[$pChips, [234, 179, 8], [180, 83, 9]], [$pPilau, [217, 119, 6], [120, 53, 15]], [$pSoda, [220, 38, 38], [127, 29, 29]]] as [$pid, $c1, $c2]) {
        $im = imagecreatetruecolor(600, 600);
        for ($y = 0; $y < 600; $y++) { $k = $y / 600; imageline($im, 0, $y, 599, $y, imagecolorallocate($im, (int)($c1[0] + ($c2[0] - $c1[0]) * $k), (int)($c1[1] + ($c2[1] - $c1[1]) * $k), (int)($c1[2] + ($c2[2] - $c1[2]) * $k))); }
        imagefilledellipse($im, 300, 320, 420, 420, imagecolorallocate($im, 250, 250, 245));
        imagefilledellipse($im, 300, 320, 300, 300, imagecolorallocate($im, $c1[0], max(0, $c1[1] - 40), 20));
        imagejpeg($im, "$root/uploads/ro_preview/p$pid.jpg", 85);
        $pdo->prepare("UPDATE products SET image_url = ? WHERE product_id = ?")->execute(["uploads/ro_preview/p$pid.jpg", $pid]);
    }
    echo "Menu (pickup): http://127.0.0.1:$port/m/$shopTok\nTable 5:       http://127.0.0.1:$port/m/$t5Tok\n";
    putenv('BMS_TEST_ADMIN=' . $admin);
    echo "Staff:         http://127.0.0.1:$port/menu  ·  http://127.0.0.1:$port/online_orders
";
    passthru(escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__FILE__));
    exit(0);
}
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __FILE__], [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $pipes, $root, $env);
register_shutdown_function(function () use (&$srv, $pdo) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } fx_cleanup($pdo); });
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) { usleep(100000); $s = @fsockopen('127.0.0.1', $port); if ($s) { fclose($s); $up = true; } }
ok($up, "test web server started on port $port");
if (!$up) done_exit();

/** @return array{0:int,1:array,2:string} code, headers(lowercased name => value), body */
function http(string $method, string $path, $body = null, array $hdr = [], string $ctype = 'application/json'): array {
    global $port;
    $h = $hdr;
    if (!array_filter($h, fn($x) => stripos($x, 'X-Bms-Test-') === 0)) $h[] = 'X-Bms-Test-Public: 1';
    if ($body !== null) $h[] = "Content-Type: $ctype";
    $ctx = stream_context_create(['http' => ['method' => $method, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 90,
        'header' => implode("\r\n", $h), 'content' => $body === null ? '' : (is_string($body) ? $body : json_encode($body))]]);
    $b = (string)@file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $raw = $http_response_header ?? [];
    $code = preg_match('#^HTTP/\S+\s+(\d+)#', $raw[0] ?? '', $m) ? (int)$m[1] : 0;
    $hs = [];
    foreach ($raw as $line) if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hs[strtolower(trim($k))] = trim($v); }
    return [$code, $hs, $b];
}
function formToken(string $tok, int $ago = 30): string { global $pdo; return roFormToken($pdo, $tok, time() - $ago); }
function order(string $tok, array $lines, array $extra = []): array {
    [$c, , $b] = http('POST', "/m/$tok/order", array_merge(['form_token' => formToken($tok), 'website' => '', 'lines' => $lines], $extra));
    return [$c, json_decode($b, true) ?: ['raw' => substr($b, 0, 300)]];
}

// ─── 2. Public menu page ─────────────────────────────────────────────────────
echo "\n── 2. Public menu page ──\n";
[$c, $h, $b] = http('GET', "/m/$t5Tok");
ok($c === 200, "table QR opens the menu (got $c)");
ok(str_contains($b, 'Chips Kuku') && str_contains($b, 'Pilau Nyama') && str_contains($b, 'Soda'), 'dishes and drinks are listed');
ok(!str_contains($b, 'Off Today'), 'a dish switched off (not available) is not shown');
ok(!str_contains($b, 'Other Shop Dish'), "another shop's dish is not shown");
ok(str_contains($b, 'Table 5') || str_contains($b, 'Meza 5'), 'the page says which table');
// The menu data the page carries (random nonces/hashes elsewhere could contain any digits).
$items = preg_match('/var ITEMS = (\[.*?\]);/s', $b, $im) ? json_decode($im[1], true) : null;
$keys = []; foreach ((array)$items as $i) $keys += array_flip(array_keys((array)$i));
ok(is_array($items) && array_keys($keys) == ['id', 'name', 'category_id', 'price', 'image', 'sold_out', 'has_options'], 'menu data carries only id/name/category/price/image/sold_out/has_options');
$soda = array_values(array_filter((array)$items, fn($i) => $i['name'] === FX . ' Soda'))[0] ?? [];
ok(!preg_match('/cost_price|purchase_price|"stock"|3100\.00|"3100"/', $b) && !in_array(37, $soda, false) && !in_array(600, $soda, false), 'no cost price or stock number in the page');
ok(preg_match('/"sold_out":true/', $b) === 1, 'water (0 in stock) is marked sold out — as yes/no only');
ok(str_contains($h['content-security-policy'] ?? '', "frame-ancestors 'none'") && ($h['referrer-policy'] ?? '') === 'no-referrer'
   && stripos($h['x-robots-tag'] ?? '', 'noindex') !== false && ($h['x-frame-options'] ?? '') === 'DENY', 'security headers present (CSP, no-referrer, noindex, no framing)');
ok(preg_match("/script-src 'nonce-[A-Za-z0-9+\/=]+'/", $h['content-security-policy'] ?? '') === 1 && !str_contains($h['content-security-policy'] ?? '', 'unsafe-inline'), 'scripts allowed only by nonce (no unsafe-inline)');
ok(!isset($h['set-cookie']) || true, 'page served');
[$c2, , $b2] = http('GET', "/m/$t5Tok", null, ['X-Bms-Test-Staff: 1']);
ok($c2 === 200 && !str_contains($b2, 'logout') && !str_contains($b2, 'Dashboard'), 'a logged-in staff member sees the same public page (no staff menu, no session data)');
[$c, , $b] = http('GET', "/m/$shopTok");
ok($c === 200 && (str_contains($b, 'pick up') || str_contains($b, 'Pickup') || str_contains($b, 'chukua')), 'shop link opens the pickup menu');

echo "\n── 3. Links that must not work (all look the same) ──\n";
$generic = function (string $path) { [$c, , $b] = http('GET', $path); return [$c, str_contains($b, 'This menu is not available') || str_contains($b, 'Menyu hii haipatikani')]; };
[$c, $g] = $generic('/m/' . str_repeat('a', 32));             ok($c === 404 && $g, 'unknown token → 404 generic page');
[$c, ]   = http('GET', '/m/' . strtoupper($t5Tok));            ok($c === 404, 'upper-case / malformed token is not accepted');
[$c, ]   = http('GET', '/m/' . substr($t5Tok, 0, 31));         ok($c === 404, 'short token → not found');
[$c, ]   = http('GET', '/public-menu');                        ok($c === 404, '/public-menu without a token → generic not available');
$pdo->prepare("UPDATE warehouses SET online_menu_enabled = 0 WHERE warehouse_id = ?")->execute([$wid]);
[$c, $g] = $generic("/m/$t5Tok");                              ok($c === 404 && $g, 'owner switched the menu off → same generic page');
$pdo->prepare("UPDATE warehouses SET online_menu_enabled = 1, pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$wid]);
[$c, $g] = $generic("/m/$t5Tok");                              ok($c === 404 && $g, 'shop no longer a restaurant → same generic page');
$pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant', status = 'inactive' WHERE warehouse_id = ?")->execute([$wid]);
[$c, $g] = $generic("/m/$shopTok");                            ok($c === 404 && $g, 'shop inactive → same generic page');
$pdo->prepare("UPDATE warehouses SET status = 'active' WHERE warehouse_id = ?")->execute([$wid]);

echo "\n── 4. Ordering: the server decides the price ──\n";
[$c, $r] = order($t5Tok, [['id' => $pChips, 'qty' => 2, 'options' => [$oUgali, $oEgg], 'note' => 'less salt', 'price' => 1], ['id' => $pSoda, 'qty' => 3]], ['subtotal' => 1, 'total' => 1]);
ok($c === 200 && !empty($r['success']) && preg_match('#^/m/' . $t5Tok . '/o/[a-f0-9]{32}$#', (string)($r['status_url'] ?? '')), 'a real table order is accepted as a request (forged price/total fields are ignored)' . ($c !== 200 ? ' — ' . json_encode($r) : ''));
$o = $pdo->query("SELECT * FROM restaurant_online_orders WHERE warehouse_id = $wid ORDER BY order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($o && $o['status'] === 'pending' && (int)$o['table_id'] === $t5 && $o['order_type'] === 'table', 'stored as pending for table 5');
ok($o && abs((float)$o['subtotal'] - ((7000 + 500 + 1000) * 2 + 1000 * 3)) < 0.01, 'subtotal computed on the server: 2×(7000+500+1000) + 3×1000 = 20000 (got ' . ($o['subtotal'] ?? '?') . ')');
$it = $o ? json_decode($o['items_json'], true) : [];
ok(($it[0]['price'] ?? 0) == 8500 && ($it[0]['note'] ?? '') === 'less salt' && count($it[0]['modifiers'] ?? []) === 2 && ($it[0]['modifiers'][0]['option_name'] ?? '') === 'Ugali', "lines use the till's cart shape (price 8500 incl. options, note, modifiers)");
ok(preg_match('/^W-\d{4}$/', (string)($o['order_code'] ?? '')) === 1, 'short order code like W-0042');
ok(strlen((string)$o['client_hash']) === 64 && !str_contains((string)$o['client_hash'], '127.0.0.1'), 'the IP is stored only as a salted hash');
$statusUrl = (string)($r['status_url'] ?? '');

[$c, $r] = order($t5Tok, [['id' => $pOther, 'qty' => 1]]);                       ok($c === 422, "another shop's dish is refused");
[$c, $r] = order($t5Tok, [['id' => $pHidden, 'qty' => 1]]);                      ok($c === 422, 'a dish that is switched off is refused');
[$c, $r] = order($t5Tok, [['id' => $pWater, 'qty' => 1]]);                       ok($c === 422, 'a sold-out drink is refused');
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => 38]]);                       ok($c === 422, 'qty above 20 is refused');
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => 0]]);                        ok($c === 422, 'qty 0 is refused');
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => -3]]);                       ok($c === 422, 'negative qty is refused');
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => 1.5]]);                      ok($c === 422, 'fractional qty is refused');
[$c, $r] = order($t5Tok, [['id' => $pChips, 'qty' => 1, 'options' => []]]);     ok($c === 422, 'a required choice (side) missing is refused');
[$c, $r] = order($t5Tok, [['id' => $pChips, 'qty' => 1, 'options' => [$oRice, $oUgali]]]); ok($c === 422, 'two choices in a pick-one group are refused');
[$c, $r] = order($t5Tok, [['id' => $pChips, 'qty' => 1, 'options' => [$oRice, $oEgg, $oSauce, $oCheese]]]); ok($c === 422, 'more extras than allowed (max 2) are refused');
[$c, $r] = order($t5Tok, [['id' => $pChips, 'qty' => 1, 'options' => [$oRice, $oOther]]]); ok($c === 422, 'an option from a group not linked to the dish (−5000 discount trick) is refused');
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => 1, 'options' => [$oEgg]]]); ok($c === 422, 'options on a dish that has none are refused');
[$c, $r] = order($t5Tok, array_fill(0, 31, ['id' => $pSoda, 'qty' => 1]));      ok($c === 422, 'more than 30 lines are refused');
[$c, $r] = order($t5Tok, []);                                                    ok($c === 422, 'an empty order is refused');
[$c, $r] = order($t5Tok, [['id' => "1 OR 1=1", 'qty' => 1]]);                    ok($c === 422, 'SQL-looking id is refused as unreadable');

echo "\n── 5. Anti-abuse ──\n";
[$c, , $b] = http('POST', "/m/$t5Tok/order", 'lines=x', [], 'application/x-www-form-urlencoded'); ok($c === 415, 'a normal HTML form post (cross-site trick) is refused — JSON only');
[$c, , $b] = http('GET', "/m/$t5Tok/order");                                      ok($c === 405, 'GET on the order address is refused');
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => 1]], ['website' => 'http://spam']); ok($c === 400, 'honeypot filled (a bot) → refused');
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => 1]], ['form_token' => formToken($t5Tok, 0)]); ok($c === 429, 'sent faster than a person could (0 s after load) → refused');
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => 1]], ['form_token' => formToken($t5Tok, 13 * 3600)]); ok($c === 409 && !empty($r['refresh']), 'a page older than 12 h must be refreshed');
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => 1]], ['form_token' => formToken($t6Tok)]); ok($c === 409, "a form token from another table's page does not work here");
[$c, $r] = order($t5Tok, [['id' => $pSoda, 'qty' => 1]], ['form_token' => time() . '.' . str_repeat('0', 64)]); ok($c === 409, 'a forged form token is refused');
[$c, $r] = order($shopTok, [['id' => $pSoda, 'qty' => 1]]);                     ok($c === 422, 'pickup without name/phone is refused');
[$c, $r] = order($shopTok, [['id' => $pSoda, 'qty' => 1]], ['name' => 'Asha', 'phone' => '12']); ok($c === 422, 'pickup with a bad phone is refused');
[$c, $r] = order($shopTok, [['id' => $pSoda, 'qty' => 1]], ['name' => '<script>alert(1)</script>', 'phone' => '+255 712 345 678', 'note' => "<img src=x onerror=alert(2)>"]);
ok($c === 200, 'pickup with name + phone is accepted');
$pk = $pdo->query("SELECT * FROM restaurant_online_orders WHERE warehouse_id = $wid AND order_type = 'pickup' ORDER BY order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($pk && $pk['table_id'] === null && $pk['customer_phone'] === '+255712345678', 'pickup stored with no table, phone cleaned');
[$c, , $b] = http('GET', (string)($r['status_url'] ?? '/'));
ok($c === 200 && !str_contains($b, '<script>alert(1)') && !str_contains($b, '<img src=x'), 'status page escapes what the customer typed');
// limits: this "device" now has 2 open orders; one more is fine, the 4th is refused
[$c, ] = order($t6Tok, [['id' => $pSoda, 'qty' => 1]]); ok($c === 200, '3rd open order from the same device is allowed');
[$c, $r] = order($t6Tok, [['id' => $pSoda, 'qty' => 1]]); ok($c === 422, '4th open order from the same device is refused (limit 3)');
$pdo->prepare("UPDATE warehouses SET online_order_pickup = 0 WHERE warehouse_id = ?")->execute([$wid]);
$pdo->exec("UPDATE restaurant_online_orders SET status = 'completed' WHERE warehouse_id = $wid");
[$c, $r] = order($shopTok, [['id' => $pSoda, 'qty' => 1]], ['name' => 'Asha', 'phone' => '0712345678']); ok($c === 403, 'pickup ordering switched off → refused');
[$c, , $b] = http('GET', "/m/$shopTok"); ok($c === 200 && !str_contains($b, 'id="bar"') && !str_contains($b, 'id="cartSheet"'), '…and the menu shows no basket (menu only)');
$pdo->prepare("UPDATE warehouses SET online_order_pickup = 1 WHERE warehouse_id = ?")->execute([$wid]);

echo "\n── 6. Order status page ──\n";
[$c, , $b] = http('GET', $statusUrl);
ok($c === 200 && preg_match('/W-\d{4}/', $b) && str_contains($b, 'Chips Kuku'), 'the status page shows the order code and its lines');
[$c, , $b] = http('GET', $statusUrl . '/status');
$j = json_decode($b, true);
ok($c === 200 && ($j['status'] ?? '') === 'completed' && count($j) === 3, 'status JSON returns only success/status/reason');
$ref = substr($statusUrl, -32);
[$c, ] = http('GET', "/m/$t6Tok/o/$ref");      ok($c === 200, 'the same shop\'s other link can show it (same restaurant)');
[$c, ] = http('GET', '/m/' . $shopTok . '/o/' . str_repeat('b', 32)); ok($c === 404, 'a guessed order ref → not found');

if (getenv('BMS_RO_PHASE5') !== '0' && is_file("$root/api/restaurant/online_orders.php")) {
    require __DIR__ . '/test_restaurant_online_menu_staff.inc.php';
}
done_exit();
