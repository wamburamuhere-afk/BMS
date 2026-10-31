<?php
/**
 * tests/test_restaurant_phase1_cli.php — restaurant_plan.md Phase 1 (Menu, checklist, focused menus).
 *
 *   A. Migration: products.is_menu_item / is_available exist (tenant + legacy + template).
 *   B. save_menu_item.php (real POSTs): dish, bought item with opening stock (stock + batch +
 *      balanced ledger), existing product (menu flags only — price untouched), edit, duplicate name.
 *   C. update_menu_item.php: sold out → hidden from the till's product list for that restaurant
 *      shop; available → back; remove → off the menu, product kept.
 *   D. Retail safety: a retail shop's till list ignores is_available; menu endpoints refuse a
 *      retail shop; with Restaurant POS off they refuse entirely.
 *   E. quick_setup.php: tables T1…Tn (re-run skips), quick menu (duplicates skipped).
 *   F. Renders: menu.php, restaurant hub (Menu card + Getting started), header dropdown,
 *      bottom bar Menu only for a restaurant-only company.
 *
 * Real local DB; every row created is removed and every setting restored. Exit 0 = all pass.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    chdir($root);
    require_once "$root/roots.php";
    require_once "$root/core/feature_registry.php";
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) { $_SESSION[$k] = $v; }
    $GLOBALS['__bms_features'] = $cfg['features'] ?? [];
    foreach (($cfg['post'] ?? []) as $k => $v) { $_POST[$k] = $v; }
    foreach (($cfg['get'] ?? []) as $k => $v) { $_GET[$k] = $v; }
    $_SERVER['REQUEST_METHOD'] = !empty($cfg['post']) ? 'POST' : 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $captured = '';
    register_shutdown_function(function () use (&$captured) {
        $buf = ob_get_contents();
        if ($buf !== false) { $captured .= $buf; @ob_end_clean(); }
        echo "\n___OUT___\n" . $captured;
    });
    ob_start();
    try { require "$root/" . $cfg['page']; } catch (Throwable $e) { $captured .= "\n___EXC___" . $e->getMessage(); }
    exit;
}

require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});
function _run(array $cfg): array {
    $f = tempnam(sys_get_temp_dir(), 'rp1');
    file_put_contents($f, json_encode($cfg));
    $out = (string)shell_exec('php ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $p = explode('___OUT___', $out, 2);
    return ['stderr' => $p[0] ?? '', 'out' => trim($p[1] ?? '')];
}
function _json(array $r) { return json_decode($r['out'], true); }
function _clean(string $s): bool { return !preg_match('/PHP (Warning|Notice|Fatal|Parse error)|___EXC___/', $s); }

$adminUid = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 AND role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$csrf = bin2hex(random_bytes(8));
$admin = ['user_id' => $adminUid, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en', 'csrf_token' => $csrf];
$on  = ['pos' => true, 'restaurant_pos' => true];
$off = ['pos' => true, 'restaurant_pos' => false];
$sfx = substr(bin2hex(random_bytes(3)), 0, 6);

$active = $pdo->query("SELECT warehouse_id, pos_mode FROM warehouses WHERE status = 'active' ORDER BY warehouse_id")->fetchAll(PDO::FETCH_ASSOC);
$origModes = array_column($active, 'pos_mode', 'warehouse_id');
$rid = (int)$active[0]['warehouse_id'];                     // restaurant shop under test
// The retail shop under test should hold stock, so the "filter never applied" check really runs.
$retailId = (int)($pdo->query("SELECT w.warehouse_id FROM warehouses w JOIN product_stocks ps ON ps.warehouse_id = w.warehouse_id
                                JOIN products p ON p.product_id = ps.product_id AND p.status = 'active' AND p.is_service = 0
                               WHERE w.status = 'active' AND w.warehouse_id <> $rid LIMIT 1")->fetchColumn()
             ?: (count($active) > 1 ? (int)$active[1]['warehouse_id'] : 0));
$origSimple = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$setSimple = function (string $v) use ($pdo) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
};
$setMode = function (int $wid, string $m) use ($pdo) { $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$m, $wid]); };
$post = function (string $page, array $data, array $features) use ($admin, $csrf) {
    return _json(_run(['page' => $page, 'session' => $admin, 'features' => $features, 'post' => ['_csrf' => $csrf] + $data]));
};
$tillIds = function (int $wid, array $features) use ($admin) {
    $r = _json(_run(['page' => 'api/pos/simple_products.php', 'session' => $admin, 'features' => $features, 'get' => ['warehouse_id' => $wid]]));
    $rows = $r['products'] ?? $r['data'] ?? [];
    return array_map('intval', array_column($rows, 'product_id'));
};
$prod = function (int $id) use ($pdo) {
    $st = $pdo->prepare("SELECT * FROM products WHERE product_id = ?"); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC);
};

$created = []; $createdStations = []; $createdTables = []; $createdFloors = []; $flagged = [];
$stationsBefore = $pdo->query("SELECT COALESCE(MAX(station_id),0) FROM kitchen_stations")->fetchColumn();
$tablesBefore   = $pdo->query("SELECT COALESCE(MAX(table_id),0) FROM restaurant_tables")->fetchColumn();
$floorsBefore   = $pdo->query("SELECT COALESCE(MAX(floor_id),0) FROM restaurant_floors")->fetchColumn();

try {
    $setSimple('1');
    $setMode($rid, 'restaurant');
    if ($retailId) $setMode($retailId, 'retail');

    section('A. Migration columns');
    foreach (['is_menu_item' => '0', 'is_available' => '1'] as $col => $def) {
        $c = $pdo->query("SHOW COLUMNS FROM products LIKE '$col'")->fetch(PDO::FETCH_ASSOC);
        ok($c && $c['Default'] === $def && $c['Null'] === 'NO', "products.$col exists, NOT NULL default $def (legacy DB)");
    }
    $tpl = file_get_contents("$root/schema/tenant_schema_template.sql");
    ok(strpos($tpl, "`is_menu_item` tinyint(1) NOT NULL DEFAULT '0'") !== false && strpos($tpl, "`is_available` tinyint(1) NOT NULL DEFAULT '1'") !== false, 'new-company template carries both columns');
    $mig = shell_exec('php ' . escapeshellarg("$root/migrations/2026_10_31_restaurant_menu_columns_legacy_db.php") . ' 2>&1');
    ok(strpos($mig, 'Migration complete.') !== false && strpos($mig, '+ Added') === false, 'legacy migration re-run is a no-op (idempotent)');

    section('B. save_menu_item.php');
    $dishName = "Test Chai $sfx";
    $r = $post('api/restaurant/save_menu_item.php', ['warehouse_id' => $rid, 'item_type' => 'dish', 'product_name' => $dishName, 'selling_price' => 500, 'new_category' => "Test Breakfast $sfx", 'prep_area' => 'kitchen', 'is_available' => '1'], $on);
    ok(!empty($r['success']), 'dish saved' . (!empty($r['success']) ? '' : ' — ' . json_encode($r)));
    $dishId = (int)($r['product_id'] ?? 0); if ($dishId) $created[] = $dishId;
    $d = $dishId ? $prod($dishId) : [];
    ok($d && (int)$d['is_service'] === 1 && (int)$d['is_menu_item'] === 1 && (int)$d['warehouse_id'] === $rid && (float)$d['selling_price'] == 500.0, 'dish = no-stock item on this shop, on the menu, price 500');
    $stName = $d ? $pdo->query("SELECT name FROM kitchen_stations WHERE station_id = " . (int)$d['kitchen_station_id'])->fetchColumn() : null;
    ok($stName === 'Kitchen', "dish prepared at the shop's 'Kitchen' area (created on first use)");
    $catName = $d ? $pdo->query("SELECT category_name FROM categories WHERE category_id = " . (int)$d['category_id'])->fetchColumn() : null;
    ok($catName === "Test Breakfast $sfx", 'new group created and linked');

    $sodaName = "Test Soda $sfx";
    $r = $post('api/restaurant/save_menu_item.php', ['warehouse_id' => $rid, 'item_type' => 'bought', 'product_name' => $sodaName, 'selling_price' => 700, 'cost_price' => 450, 'opening_stock' => 24, 'prep_area' => 'none', 'is_available' => '1'], $on);
    ok(!empty($r['success']), 'bought item with opening stock saved' . (!empty($r['success']) ? '' : ' — ' . json_encode($r)));
    $sodaId = (int)($r['product_id'] ?? 0); if ($sodaId) $created[] = $sodaId;
    $s = $sodaId ? $prod($sodaId) : [];
    ok($s && (int)$s['is_service'] === 0 && $s['kitchen_station_id'] === null && (float)$s['cost_price'] == 450.0, 'bought item: stock product, no preparation, cost 450');
    $qty = (float)$pdo->query("SELECT COALESCE(SUM(stock_quantity),0) FROM product_stocks WHERE product_id = $sodaId AND warehouse_id = $rid")->fetchColumn();
    ok($qty == 24.0, 'opening stock 24 lands in this shop');
    $batches = (int)$pdo->query("SELECT COUNT(*) FROM product_batches WHERE product_id = $sodaId")->fetchColumn();
    ok($batches === 1, 'opening stock is a real batch (same path as Add Product)');
    $je = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries je JOIN stock_movements sm ON sm.movement_id = je.entity_id
                             WHERE je.entity_type = 'stock_adjustment' AND sm.product_id = $sodaId AND je.status = 'posted'")->fetchColumn();
    ok($je === 1, 'opening stock posted to the ledger (Dr Inventory / Cr Opening Balance)');
    $bal = assertLedgerBalanced($pdo);
    ok(!empty($bal['ledger_balanced']) && !empty($bal['bs_balanced']), 'ledger still balanced (Σ Dr = Σ Cr)');

    $r = $post('api/restaurant/save_menu_item.php', ['warehouse_id' => $rid, 'item_type' => 'dish', 'product_name' => strtoupper($dishName) . ' ', 'selling_price' => 600], $on);
    ok(empty($r['success']) && stripos((string)($r['message'] ?? ''), 'already exists') !== false, 'duplicate name (case/space-insensitive) refused');
    $r = $post('api/restaurant/save_menu_item.php', ['warehouse_id' => $rid, 'item_type' => 'dish', 'product_name' => "No Price $sfx", 'selling_price' => 0], $on);
    ok(empty($r['success']), 'zero price refused');

    // Existing product → menu flags only.
    $exName = "Test Existing $sfx";
    $pdo->prepare("INSERT INTO products (product_name, selling_price, cost_price, status, created_by) VALUES (?, 999, 111, 'active', ?)")->execute([$exName, $adminUid]);
    $exId = (int)$pdo->lastInsertId(); $created[] = $exId;
    $r = $post('api/restaurant/save_menu_item.php', ['warehouse_id' => $rid, 'item_type' => 'existing', 'existing_product_id' => $exId, 'is_available' => '1'], $on);
    $e = $prod($exId);
    ok(!empty($r['success']) && (int)$e['is_menu_item'] === 1 && (float)$e['selling_price'] == 999.0 && (float)$e['cost_price'] == 111.0, 'existing product put on the menu; price/cost untouched');

    // Edit.
    $r = $post('api/restaurant/save_menu_item.php', ['warehouse_id' => $rid, 'product_id' => $dishId, 'item_type' => 'dish', 'product_name' => "$dishName Kubwa", 'selling_price' => 800, 'prep_area' => 'bar', 'is_available' => '1'], $on);
    $d = $prod($dishId);
    $stName = $pdo->query("SELECT name FROM kitchen_stations WHERE station_id = " . (int)$d['kitchen_station_id'])->fetchColumn();
    ok(!empty($r['success']) && $d['product_name'] === "$dishName Kubwa" && (float)$d['selling_price'] == 800.0 && $stName === 'Bar', 'edit saves name, price and area (Bar)');

    $g = _json(_run(['page' => 'api/restaurant/get_menu_items.php', 'session' => $admin, 'features' => $on, 'get' => ['warehouse_id' => $rid]]));
    $ids = array_map('intval', array_column($g['data'] ?? [], 'product_id'));
    ok(in_array($dishId, $ids, true) && in_array($sodaId, $ids, true) && in_array($exId, $ids, true), 'get_menu_items lists dish, soda and the existing product');
    $row = array_values(array_filter($g['data'] ?? [], fn($x) => (int)$x['product_id'] === $dishId))[0] ?? [];
    ok(($row['station_key'] ?? null) === 'bar', 'menu row carries station_key for the edit form');

    section('C. Sold out today / remove');
    ok(in_array($dishId, $tillIds($rid, $on), true), 'dish shows on the restaurant till');
    $r = $post('api/restaurant/update_menu_item.php', ['warehouse_id' => $rid, 'product_id' => $dishId, 'action' => 'unavailable'], $on);
    ok(!empty($r['success']) && (int)$prod($dishId)['is_available'] === 0, 'marked sold out');
    ok(!in_array($dishId, $tillIds($rid, $on), true), 'sold-out dish hidden from the restaurant till');
    $post('api/restaurant/update_menu_item.php', ['warehouse_id' => $rid, 'product_id' => $dishId, 'action' => 'available'], $on);
    ok(in_array($dishId, $tillIds($rid, $on), true), 'available again → back on the till');
    $r = $post('api/restaurant/update_menu_item.php', ['warehouse_id' => $rid, 'product_id' => $exId, 'action' => 'remove'], $on);
    $e = $prod($exId);
    ok(!empty($r['success']) && (int)$e['is_menu_item'] === 0 && $e['status'] === 'active', 'remove takes it off the menu; the product is kept');

    section('D. Retail safety');
    if ($retailId) {
        $retailProd = (int)$pdo->query("SELECT p.product_id FROM products p JOIN product_stocks ps ON ps.product_id = p.product_id
                                         WHERE ps.warehouse_id = $retailId AND p.status = 'active' AND p.is_service = 0 LIMIT 1")->fetchColumn();
        if ($retailProd) {
            $flagged[$retailProd] = (int)$prod($retailProd)['is_available'];
            $pdo->exec("UPDATE products SET is_available = 0 WHERE product_id = $retailProd");
            ok(in_array($retailProd, $tillIds($retailId, $on), true), 'a retail shop still lists a product even if its is_available is 0 (filter never applied)');
        }
        $r = $post('api/restaurant/save_menu_item.php', ['warehouse_id' => $retailId, 'item_type' => 'dish', 'product_name' => "Retail $sfx", 'selling_price' => 100], $on);
        ok(empty($r['success']), 'menu save refused for a retail shop');
        $g = _json(_run(['page' => 'api/restaurant/get_menu_items.php', 'session' => $admin, 'features' => $on, 'get' => ['warehouse_id' => $retailId]]));
        ok(empty($g['success']), 'menu list refused for a retail shop');
    }
    $pdo->exec("UPDATE products SET is_available = 0 WHERE product_id = $dishId");
    ok(in_array($dishId, $tillIds($rid, $off), true), 'Restaurant POS off → till ignores is_available (today\'s behaviour)');
    $pdo->exec("UPDATE products SET is_available = 1 WHERE product_id = $dishId");
    $r = $post('api/restaurant/save_menu_item.php', ['warehouse_id' => $rid, 'item_type' => 'dish', 'product_name' => "Off $sfx", 'selling_price' => 100], $off);
    ok(empty($r['success']), 'Restaurant POS off → menu save refused');
    $setSimple('0');
    $r = $post('api/restaurant/save_menu_item.php', ['warehouse_id' => $rid, 'item_type' => 'dish', 'product_name' => "NoSimple $sfx", 'selling_price' => 100], $on);
    ok(empty($r['success']), 'Smart POS off → menu save refused');
    $setSimple('1');

    section('E. Quick start');
    $before = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_tables WHERE warehouse_id = $rid")->fetchColumn();
    $r = $post('api/restaurant/quick_setup.php', ['warehouse_id' => $rid, 'action' => 'tables', 'count' => 3], $on);
    ok(!empty($r['success']), 'quick tables: ' . ($r['message'] ?? json_encode($r)));
    $made1 = (int)($r['created'] ?? -1);
    $r2 = $post('api/restaurant/quick_setup.php', ['warehouse_id' => $rid, 'action' => 'tables', 'count' => 3], $on);
    ok((int)($r2['created'] ?? -1) === 0, 're-run creates nothing (T1…T3 already there)');
    $after = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_tables WHERE warehouse_id = $rid")->fetchColumn();
    ok($after === $before + $made1, 'table count grew by exactly the created number');
    $items = json_encode([['name' => "QS Mandazi $sfx", 'price' => 200, 'type' => 'dish'], ['name' => "QS Maji $sfx", 'price' => 500, 'type' => 'bought', 'stock' => 0], ['name' => $sodaName, 'price' => 700, 'type' => 'bought']]);
    $r = $post('api/restaurant/quick_setup.php', ['warehouse_id' => $rid, 'action' => 'menu', 'items' => $items], $on);
    ok(!empty($r['success']) && (int)$r['created'] === 2 && in_array($sodaName, $r['skipped'] ?? [], true), 'quick menu adds 2, skips the existing name');
    foreach ([$pdo->query("SELECT product_id FROM products WHERE product_name IN ('QS Mandazi $sfx','QS Maji $sfx')")->fetchAll(PDO::FETCH_COLUMN)] as $ids) foreach ($ids as $i) $created[] = (int)$i;
    $r = $post('api/restaurant/quick_setup.php', ['warehouse_id' => $rid, 'action' => 'tables', 'count' => 0], $on);
    ok(empty($r['success']), 'quick tables refuses 0');

    section('F. Renders');
    $r = _run(['page' => 'app/bms/restaurant/menu.php', 'session' => $admin, 'features' => $on]);
    ok(_clean($r['stderr']), 'menu.php renders with no PHP warnings' . (_clean($r['stderr']) ? '' : ' — ' . substr($r['stderr'], 0, 300)));
    ok(strpos($r['out'], 'id="menuTable"') !== false && strpos($r['out'], 'id="cardView"') !== false && strpos($r['out'], 'id="itemModal"') !== false, 'menu.php: table, phone cards and Add Item window');
    $r = _run(['page' => 'app/bms/restaurant/menu.php', 'session' => $admin, 'features' => $off]);
    ok(strpos($r['out'], 'id="menuTable"') === false, 'menu.php with Restaurant POS off shows no menu');
    $r = _run(['page' => 'app/bms/restaurant/index.php', 'session' => $admin, 'features' => $on]);
    ok(_clean($r['stderr']) && strpos($r['out'], 'restaurant/menu') !== false, 'hub renders and has the Menu card');
    $hasTables = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_tables WHERE warehouse_id = $rid")->fetchColumn() > 0;
    ok(strpos($r['out'], 'id="rgsCard"') !== false || $hasTables, 'Getting started shows while steps remain');
    ok(strpos($r['out'], 'id="restaurantDropdown"') !== false, 'header: Restaurant dropdown (Menu, Tables, Kitchen…) when the module is on');
    if ($retailId) {
        ok(strpos($r['out'], 'class="bi bi-journal-richtext"></i><span>') === false, 'mixed company: bottom bar keeps Products (no Menu tab)');
        foreach ($active as $w) $setMode((int)$w['warehouse_id'], 'restaurant');
        $r = _run(['page' => 'app/bms/restaurant/index.php', 'session' => $admin, 'features' => $on]);
        ok(strpos($r['out'], 'class="bi bi-journal-richtext"></i><span>') !== false, 'restaurant-only company: bottom bar shows Menu');
        foreach ($origModes as $wid => $m) $setMode((int)$wid, (string)$m);
        $setMode($rid, 'restaurant');
    }
    $r = _run(['page' => 'app/bms/pos/pos_dashboard.php', 'session' => $admin, 'features' => $off]);
    ok(strpos($r['out'], 'restaurantDropdown') === false && strpos($r['out'], 'restaurant/menu') === false, 'Restaurant POS off: no restaurant menu anywhere');
} finally {
    foreach ($created as $id) {
        $mv = $pdo->query("SELECT movement_id FROM stock_movements WHERE product_id = $id")->fetchAll(PDO::FETCH_COLUMN);
        if ($mv) {
            $in = implode(',', array_map('intval', $mv));
            $je = $pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type = 'stock_adjustment' AND entity_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            if ($je) { $jin = implode(',', array_map('intval', $je)); $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id IN ($jin)"); $pdo->exec("DELETE FROM journal_entries WHERE entry_id IN ($jin)"); }
            $pdo->exec("DELETE FROM stock_movements WHERE movement_id IN ($in)");
        }
        $pdo->exec("DELETE FROM product_batches WHERE product_id = $id");
        $pdo->exec("DELETE FROM product_stocks WHERE product_id = $id");
        $pdo->exec("DELETE FROM products WHERE product_id = $id");
    }
    foreach ($flagged as $id => $v) $pdo->exec("UPDATE products SET is_available = " . (int)$v . " WHERE product_id = " . (int)$id);
    $pdo->exec("DELETE FROM categories WHERE category_name = 'Test Breakfast $sfx'");
    $pdo->exec("DELETE FROM restaurant_tables WHERE table_id > " . (int)$tablesBefore);
    $pdo->exec("DELETE FROM restaurant_floors WHERE floor_id > " . (int)$floorsBefore);
    $pdo->exec("DELETE FROM kitchen_stations WHERE station_id > " . (int)$stationsBefore);
    foreach ($origModes as $wid => $m) $setMode((int)$wid, (string)$m);
    if ($origSimple === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'"); else $setSimple((string)$origSimple);
    $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_name LIKE '%$sfx%'")->fetchColumn();
    ok($left === 0, 'fixtures removed: no test product left behind');
    $bal = assertLedgerBalanced($pdo);
    ok(!empty($bal['ledger_balanced']) && !empty($bal['bs_balanced']), 'ledger balanced after cleanup');
}
exit($fail === 0 ? 0 : 1);
