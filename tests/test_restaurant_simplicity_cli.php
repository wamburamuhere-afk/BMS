<?php
/**
 * tests/test_restaurant_simplicity_cli.php — Restaurant simplicity pass (2026-11-02).
 *
 * Real endpoints, in-process, inside ONE outer transaction that is rolled back at the end
 * (endpoint transactions become savepoints) — nothing is left behind.
 *
 *   A. Save a table order without a cash shift (restaurant shop only); retail holds, and a
 *      table order on a RETAIL shop, still need a shift; paying still needs a shift.
 *   B. Till product list: menu-only filter, add-on count, retail shop unchanged.
 *   C. Table picker truth: a table opened but never ordered is not "occupied".
 *   D. Kitchen Display: explains kitchen-by-mouth and can switch the screen on.
 *   E. Dashboard: profit hint for dishes without a recipe, order labels.
 *   F. Floors page: add several tables at once.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/feature_registry.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$failures = 0; $passes = 0;
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});
function ok(bool $c, string $m): void { global $passes, $failures; if ($c) { $passes++; echo "  \033[32m✅\033[0m $m\n"; } else { $failures++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

class SavepointPDO extends PDO
{
    private int $depth = 0;
    public function startOuter(): void { parent::beginTransaction(); $this->depth = 1; }
    public function endOuter(): void { if ($this->depth > 0) { parent::rollBack(); $this->depth = 0; } }
    public function beginTransaction(): bool { $this->depth++; $this->exec("SAVEPOINT sp{$this->depth}"); return true; }
    public function commit(): bool { $this->exec("RELEASE SAVEPOINT sp{$this->depth}"); $this->depth--; return true; }
    public function rollBack(): bool { $this->exec("ROLLBACK TO SAVEPOINT sp{$this->depth}"); $this->depth--; return true; }
    public function inTransaction(): bool { return $this->depth > 1; }
}
class InputStub
{
    public static string $body = '';
    public $context;
    private int $pos = 0;
    public function stream_open($path, $mode, $options, &$opened) { $this->pos = 0; return true; }
    public function stream_read($n) { $r = substr(self::$body, $this->pos, $n); $this->pos += strlen($r); return $r; }
    public function stream_eof() { return $this->pos >= strlen(self::$body); }
    public function stream_stat() { return []; }
}

$pdo = new SavepointPDO('mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET time_zone = '+03:00'");
$GLOBALS['pdo'] = $pdo;

function call(string $file, string $method, array $data = [], bool $json = false): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = []; $_GET = [];
    if ($method === 'GET') $_GET = $data;
    elseif ($json) { InputStub::$body = json_encode($data); }
    else { $_POST = $data + ['_csrf' => 'test-csrf']; InputStub::$body = ''; }
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'InputStub');
    ob_start();
    try { (function () use ($file) { global $pdo; include $GLOBALS['root'] . '/' . $file; })(); }
    catch (Throwable $e) { echo json_encode(['success' => false, 'message' => 'EXC: ' . $e->getMessage()]); }
    finally { $out = ob_get_clean(); stream_wrapper_restore('php'); }
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($out, 0, 300)];
}
function one(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); }
function val(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$admin = (int)val("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1");
$_SESSION['user_id'] = $admin; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['role_id'] = 1;
$_SESSION['is_admin'] = true; $_SESSION['csrf_token'] = 'test-csrf'; $_SESSION['user_lang'] = 'en';
unset($_SESSION['shift_id']);
$GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true];

// A shop with a stocked item (so a mixed order / a retail sale can be tested).
$drink = one("SELECT ps.product_id, ps.warehouse_id, p.selling_price FROM product_stocks ps JOIN products p ON p.product_id = ps.product_id
               WHERE ps.stock_quantity >= 10 AND p.is_service = 0 AND COALESCE(p.is_combo,0) = 0 AND p.selling_price > 0 AND p.status = 'active'
                 AND p.selling_price >= p.min_selling_price
                 AND NOT EXISTS (SELECT 1 FROM product_serials sn WHERE sn.product_id = p.product_id)
            ORDER BY ps.stock_quantity DESC LIMIT 1");
if (!$drink) { ok(false, 'fixture: a stocked product to sell'); exit; }
$W = (int)$drink['warehouse_id']; $DRINK = (int)$drink['product_id']; $DPRICE = (float)$drink['selling_price'];

$pdo->startOuter();
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);
    $pdo->prepare("UPDATE products SET kitchen_station_id = NULL WHERE product_id = ?")->execute([$DRINK]);
    // No active shift for the test user (rolled back at the end).
    $pdo->prepare("UPDATE cash_register_shifts SET status = 'closed' WHERE user_id = ? AND status = 'active'")->execute([$admin]);
    require_once "$root/core/restaurant_scope.php";
    ok(restaurantShopActive($pdo, $W), 'fixture: shop is an active restaurant shop');
    ok(!val("SELECT 1 FROM cash_register_shifts WHERE user_id = ? AND status = 'active'", [$admin]), 'fixture: the test user has NO open shift');

    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $DISH = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "SP Supu $sfx", 'price' => 3000, 'prep_area' => 'kitchen'], $admin);
    $floor = restaurantDefaultFloorId($pdo, $W);
    $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, ?, 4, 'available'), (?, ?, ?, 4, 'available')")
        ->execute([$W, $floor, "SPA$sfx", $W, $floor, "SPB$sfx"]);
    $T1 = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["SPA$sfx"]);
    $T2 = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["SPB$sfx"]);

    $items = [
        ['product_id' => $DISH, 'product_name' => "SP Supu $sfx", 'quantity' => 2, 'price' => 3000, 'discounted_price' => 3000, 'tax_rate' => 0],
        ['product_id' => $DRINK, 'product_name' => 'drink', 'quantity' => 1, 'price' => $DPRICE, 'discounted_price' => $DPRICE, 'tax_rate' => 0],
    ];
    $hold = function (?int $table, array $its, int $wid = null) use ($W) {
        $d = ['reference' => 'T', 'items' => $its, 'subtotal' => array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $its)), 'tax' => 0];
        if ($table) { $d['table_id'] = $table; $d['warehouse_id'] = $wid ?? $W; }
        return call('api/pos/hold_sale.php', 'POST', $d, true);
    };

    // ───────────────────────────────────────────────────────────────────────
    section('A. Save a table order without a cash shift');
    $a1 = $hold($T1, $items);
    ok(!empty($a1['success']), 'restaurant table order saved with NO shift' . (!empty($a1['success']) ? '' : ' — ' . ($a1['message'] ?? '')));
    ok(!empty($a1['hold_id']) && val("SELECT shift_id FROM pos_held_sales WHERE hold_id = ?", [$a1['hold_id']]) === null, 'the held order stores no shift (NULL)');
    ok(val("SELECT status FROM restaurant_tables WHERE table_id = ?", [$T1]) === 'occupied', 'the table is occupied by the saved order');
    $a1b = $hold($T1, array_slice($items, 0, 1));
    ok(!empty($a1b['success']) && (int)val("SELECT COUNT(*) FROM pos_held_sales WHERE table_id = ? AND status = 'held'", [$T1]) === 1, 're-saving still keeps exactly ONE open order on the table');

    $a2 = $hold(null, [$items[1]]);
    ok(empty($a2['success']) && stripos((string)($a2['message'] ?? ''), 'shift') !== false, 'a plain (retail) hold with no shift is STILL refused: "' . ($a2['message'] ?? '') . '"');

    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W]);
    $a3 = $hold($T2, $items);
    ok(empty($a3['success']) && stripos((string)($a3['message'] ?? ''), 'shift') !== false, 'a table order on a RETAIL shop with no shift is STILL refused (restaurant-only relaxation)');
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);

    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-SIMPLE', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $shiftId = (int)val("SELECT shift_id FROM cash_register_shifts WHERE shift_code = 'TEST-SIMPLE'");
    $a4 = $hold($T2, $items);
    ok(!empty($a4['success']) && (int)val("SELECT shift_id FROM pos_held_sales WHERE hold_id = ?", [$a4['hold_id']]) === $shiftId, 'with a shift open, the held order still records that shift (unchanged behaviour)');
    $a5 = $hold(null, [$items[1]]);
    ok(!empty($a5['success']) && (int)val("SELECT shift_id FROM pos_held_sales WHERE hold_id = ?", [$a5['hold_id']]) === $shiftId, 'a retail hold with a shift is unchanged');
    $pdo->prepare("UPDATE cash_register_shifts SET status = 'closed' WHERE shift_id = ?")->execute([$shiftId]);

    $total = 6000;
    $sale = call('api/pos/process_sale.php', 'POST', [
        'customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => [$items[0]],
        'subtotal' => $total, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $total,
        'payment_method' => 'cash', 'amount_tendered' => $total, 'change_given' => 0, 'amount_paid' => $total,
        'table_id' => $T1, 'sale_type' => 'dine_in',
    ], true);
    ok(empty($sale['success']), 'PAYING with no shift is still refused (money needs a shift): "' . ($sale['message'] ?? '') . '"');

    // ───────────────────────────────────────────────────────────────────────
    section('C. Table picker tells the same truth as the Tables board');
    $T3row = null;
    $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, ?, 4, 'occupied')")->execute([$W, $floor, "SPC$sfx"]);
    $T3 = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["SPC$sfx"]);
    $gt = call('api/restaurant/get_tables.php', 'GET', ['warehouse_id' => $W]);
    $by = []; foreach ($gt['data'] ?? [] as $r) $by[(int)$r['table_id']] = $r;
    ok(!empty($gt['success']) && isset($by[$T3]) && array_key_exists('has_open_order', $by[$T3]), 'get_tables returns has_open_order for each table (additive field)');
    ok((int)($by[$T3]['has_open_order'] ?? 1) === 0 && ($by[$T3]['status'] ?? '') === 'occupied', 'a table stuck "occupied" with no order reports has_open_order = 0 (the picker will show it free)');
    ok((int)($by[$T1]['has_open_order'] ?? 0) === 1, 'a table with a saved order reports has_open_order = 1');
    $bd = call('api/restaurant/get_tables_board.php', 'GET', ['warehouse_id' => $W]);
    $bb = []; foreach ($bd['data'] ?? [] as $r) $bb[(int)$r['table_id']] = $r;
    ok(($bb[$T3]['status'] ?? '') === 'available' && ($bb[$T1]['status'] ?? '') === 'occupied', 'the Tables board agrees: stuck table free, ordered table busy');
    foreach (['table_id', 'warehouse_id', 'floor_id', 'table_number', 'seats', 'status', 'floor_name'] as $k) {
        if (!array_key_exists($k, $by[$T1] ?? [])) { ok(false, "get_tables keeps its existing field $k"); }
    }
    ok(isset($by[$T1]['table_number'], $by[$T1]['floor_name']), 'get_tables keeps every existing field (floors page unaffected)');
    $js = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
    $selFn = substr($js, (int)strpos($js, 'function selectTable('), 2600);
    ok(strpos($selFn, 'update_table_status') === false, 'opening a table no longer marks it occupied by itself');
    ok(strpos($js, 'has_open_order') !== false, 'the picker applies the open-order rule');

    // ───────────────────────────────────────────────────────────────────────
    section('B. Till product list — Menu view and one-tap add');
    // A HYBRID shop lists dishes and retail products; a plain Restaurant shop lists the Menu only (restaurant focus pass).
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'hybrid' WHERE warehouse_id = ?")->execute([$W]);
    $plain = call('api/pos/simple_products.php', 'GET', ['warehouse_id' => $W]);
    $menu  = call('api/pos/simple_products.php', 'GET', ['warehouse_id' => $W, 'menu_only' => 1]);
    $ids = fn($r) => array_map(fn($p) => (int)$p['product_id'], $r['data'] ?? []);
    ok(!empty($plain['success']) && in_array($DISH, $ids($plain), true) && in_array($DRINK, $ids($plain), true), 'hybrid shop, no filter: dishes AND retail products are listed (unchanged)');
    ok(!empty($menu['success']) && in_array($DISH, $ids($menu), true), 'menu_only=1: the Menu dish is listed');
    ok(!in_array($DRINK, $ids($menu), true), 'menu_only=1: a retail product that is not on the Menu is NOT listed');
    $nonMenuInMenu = 0;
    foreach ($ids($menu) as $pid) if (!(int)val("SELECT is_menu_item FROM products WHERE product_id = ?", [$pid])) $nonMenuInMenu++;
    ok($nonMenuInMenu === 0, 'menu_only=1: every listed item really is a Menu item');
    ok(count($ids($menu)) < count($ids($plain)), 'the Menu view is shorter than the full list');

    $mcRow = null; foreach ($menu['data'] ?? [] as $p) if ((int)$p['product_id'] === $DISH) $mcRow = $p;
    ok($mcRow !== null && array_key_exists('modifier_group_count', $mcRow) && (int)$mcRow['modifier_group_count'] === 0, 'a dish with no add-on groups reports modifier_group_count = 0 (one tap)');
    $pdo->prepare("INSERT INTO modifier_groups (name, selection_type, min_select, max_select, is_required, status) VALUES (?, 'multiple', 0, 3, 0, 'active')")->execute(["SP Extras $sfx"]);
    $mg = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_modifier_groups (product_id, group_id) VALUES (?, ?)")->execute([$DISH, $mg]);
    $menu2 = call('api/pos/simple_products.php', 'GET', ['warehouse_id' => $W, 'menu_only' => 1]);
    $mc2 = null; foreach ($menu2['data'] ?? [] as $p) if ((int)$p['product_id'] === $DISH) $mc2 = $p;
    ok($mc2 !== null && (int)$mc2['modifier_group_count'] === 1, 'once the dish has an add-on group, modifier_group_count = 1 (popup still opens)');

    $pdo->prepare("UPDATE products SET is_available = 0 WHERE product_id = ?")->execute([$DISH]);
    $menu3 = call('api/pos/simple_products.php', 'GET', ['warehouse_id' => $W, 'menu_only' => 1]);
    ok(!in_array($DISH, $ids($menu3), true), 'a dish switched off ("sold out today") stays hidden in the Menu view');
    $pdo->prepare("UPDATE products SET is_available = 1 WHERE product_id = ?")->execute([$DISH]);

    // RETAIL shop: the new parameter is ignored and no new field appears.
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W]);
    $rPlain = call('api/pos/simple_products.php', 'GET', ['warehouse_id' => $W]);
    $rMenu  = call('api/pos/simple_products.php', 'GET', ['warehouse_id' => $W, 'menu_only' => 1]);
    ok(!empty($rPlain['success']) && $ids($rPlain) === $ids($rMenu), 'retail shop: menu_only is ignored — identical product list');
    ok(in_array($DRINK, $ids($rMenu), true), 'retail shop: its products are all still listed');
    $anyKey = false; foreach ($rMenu['data'] ?? [] as $p) if (array_key_exists('modifier_group_count', $p)) $anyKey = true;
    ok(!$anyKey, 'retail shop: products carry NO modifier_group_count field (response shape unchanged)');
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);

    // Till script wiring (static).
    $js = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
    $tap = substr($js, (int)strpos($js, 'function posTapProduct('), 2200);
    ok(strpos($tap, 'modifier_group_count') !== false && strpos($tap, 'restaurantNeedsPopup') !== false, 'one-tap: posTapProduct opens the popup only for add-on dishes on a restaurant shop');
    ok(strpos($tap, 'const needsPopup = product.track_serials == 1 || restaurantNeedsPopup || unitCount !== 0;') !== false, 'serial / unit rules for the popup are unchanged');
    ok(strpos($js, 'function posMenuModeOn() { return posRestaurantActive() && (posMenuOnly || posRestaurantOnlyShop()); }') !== false, 'Menu mode is gated on posRestaurantActive() (never on a retail shop)');
    ok(strpos($js, "menu_only: posMenuModeOn() ? 1 : ''") !== false, 'the till only asks for menu_only in Menu mode');
    $scan = substr($js, (int)strpos($js, 'function handleBarcodeScanned('), 2600);
    ok(strpos($scan, 'else if (posMenuModeOn())') !== false && strpos($scan, 'scanNotFound()') !== false, 'scanner: in Menu mode a miss is looked up on the server before "not found"');

    section('B3. Table picker on the till — real bugs found in the browser');
    $pick = substr($js, (int)strpos($js, 'function renderTablePickerFloor('), 1500);
    ok(strpos($pick, "onclick=\"selectTable(\${t.table_id}, \${warehouseId}, \${JSON.stringify(t.table_number).replace(/\"/g, '&quot;')})\"") !== false,
        'picker card escapes the quotes in its onclick (before, the attribute broke and tapping a table did nothing)');
    ok(!preg_match('/onclick="[^"\n]*\$\{JSON\.stringify\((?![^)]*\)\.replace\(\/"\/g)/', $js), 'no double-quoted onclick in the till embeds an unescaped JSON.stringify');
    $selBody = substr($js, (int)strpos($js, 'function selectTable('), 2400);
    ok(strpos($selBody, 'keep whatever the waiter already added') !== false && !preg_match('/\}\s*else\s*\{\s*cart\s*=\s*\[\];\s*\}\s*updateCartDisplay/', $selBody),
        'choosing a table with no open order keeps the items already in the cart');
    ok(strpos($selBody, 'cart = JSON.parse(existing.items_data)') !== false, 'choosing a table WITH an open order still loads that order');

    // ───────────────────────────────────────────────────────────────────────
    section('E. Dashboard — profit hint for dishes sold without a recipe');
    require_once "$root/core/restaurant_dashboard.php";
    $base = restaurantDashboardData($pdo);
    ok(array_key_exists('uncosted_dishes', $base) && is_int($base['uncosted_dishes']), 'dashboard data has an uncosted_dishes count (additive key)');
    foreach (['sales_today', 'month_sales', 'month_ingredients', 'month_expenses', 'month_food_profit', 'orders_today', 'open_tables', 'open_amount', 'recent', 'top_dishes', 'low_drinks'] as $k) {
        if (!array_key_exists($k, $base)) ok(false, "dashboard data still has $k");
    }
    ok(true, 'every existing dashboard figure is still returned');
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-SIMPLE2', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $sale = call('api/pos/process_sale.php', 'POST', [
        'customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => [$items[0]],
        'subtotal' => 6000, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => 6000,
        'payment_method' => 'cash', 'amount_tendered' => 6000, 'change_given' => 0, 'amount_paid' => 6000, 'sale_type' => 'take_away',
    ], true);
    ok(!empty($sale['success']), 'a dish with no recipe is sold' . (!empty($sale['success']) ? '' : ' — ' . ($sale['message'] ?? '')));
    $afterSale = restaurantDashboardData($pdo);
    ok($afterSale['uncosted_dishes'] === $base['uncosted_dishes'] + 1, 'the dish sold with no recipe is counted (' . $base['uncosted_dishes'] . ' → ' . $afterSale['uncosted_dishes'] . ')');
    $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, component_name, unit, qty_per_unit, total_qty) VALUES (?, ?, 'x', 'pcs', 1, 1)")->execute([$DISH, $DRINK]);
    $afterRecipe = restaurantDashboardData($pdo);
    ok($afterRecipe['uncosted_dishes'] === $base['uncosted_dishes'], 'once the dish has a recipe it is no longer counted');
    $pdo->prepare("DELETE FROM product_assembly_components WHERE parent_product_id = ?")->execute([$DISH]);
    // The old Food-profit card was replaced by the profit story panel (includes/restaurant/dash_panel.php); its "Estimated"
    // badge and "no cost yet" nudge carry the same warning, and the profit figure still comes from the ledger.
    $dv = file_get_contents("$root/includes/restaurant/dash_panel.php");
    ok(strpos($dv, "t('Estimated')") !== false && strpos($dv, "\$rdDishes['needs_cost']") !== false, 'the panel still warns when dishes sold have no recipe or purchase cost');
    $pdo->prepare("UPDATE cash_register_shifts SET status = 'closed' WHERE shift_code = 'TEST-SIMPLE2'")->execute();

    // [later phases append here]
} finally {
    $pdo->endOuter();
}

// ───────────────────────────────────────────────────────────────────────────
section('B2. Till markup — Menu switch only when the module is on; scripts parse');
// Renders any page in a subprocess (the phase-1 worker) with the shop in $mode and optional settings,
// then puts the shop mode and every setting back exactly as it was.
$renderPage = function (string $page, array $features, string $mode, array $settings = []) use ($root, $W) {
    $pdo2 = $GLOBALS['pdo'];
    $settings = ['pos_simple_mode' => '1'] + $settings;
    $origMode = $pdo2->query("SELECT pos_mode FROM warehouses WHERE warehouse_id = $W")->fetchColumn();
    $orig = [];
    foreach ($settings as $k => $v) {
        $q = $pdo2->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $q->execute([$k]); $orig[$k] = $q->fetchColumn();
        $pdo2->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
    }
    $pdo2->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$mode, $W]);
    $uid = (int)$pdo2->query("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
    $cfg = tempnam(sys_get_temp_dir(), 'rps');
    file_put_contents($cfg, json_encode(['page' => $page, 'session' => ['user_id' => $uid, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'], 'features' => $features]));
    $out = (string)shell_exec('php ' . escapeshellarg("$root/tests/test_restaurant_phase1_cli.php") . ' worker ' . escapeshellarg($cfg) . ' 2>&1');
    @unlink($cfg);
    $pdo2->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$origMode, $W]);
    foreach ($orig as $k => $v) {
        if ($v === false) $pdo2->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        else $pdo2->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
    }
    return $out;
};
$renderTill = fn(array $features, string $mode, array $settings = []) => $renderPage('app/bms/pos/pos.php', $features, $mode, $settings);
$on = $renderTill(['pos' => true, 'restaurant_pos' => true], 'restaurant');
ok(strpos($on, 'id="posMenuSwitchRow"') !== false && strpos($on, 'id="menuCategoryButtons"') !== false && strpos($on, 'id="posViewMenu"') !== false, 'module on: the till has the Menu | All products switch and the Menu group strip');
ok(preg_match('/class="col-12 mt-2 d-none" id="posMenuSwitchRow"/', $on) === 1, 'the switch starts hidden (the till script reveals it only on a restaurant/hybrid shop)');
ok(!preg_match('/PHP (Warning|Notice|Fatal)|___EXC___/', explode('___OUT___', $on)[0] ?? ''), 'pos.php renders with no PHP warnings');
$off = $renderTill(['pos' => true, 'restaurant_pos' => false], 'retail');
ok(strpos($off, 'id="posMenuSwitchRow"') === false && strpos($off, 'id="menuCategoryButtons"') === false && strpos($off, 'id="posViewMenu"') === false && strpos($off, 'id="posViewAll"') === false, 'module off: no Menu switch markup at all (retail till exactly as before)');
ok(strpos($off, 'id="categoryButtons"') !== false && strpos($off, 'id="productSearch"') !== false, 'module off: the normal category strip and search are still there');
$html = preg_replace('#<!--.*?-->#s', '', explode('___OUT___', $on, 2)[1] ?? '');
preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $mm);
$bad = 0;
foreach ($mm[1] as $i => $jsPart) {
    if (trim($jsPart) === '' || strpos($jsPart, 'application/ld+json') !== false) continue;
    $f = tempnam(sys_get_temp_dir(), 'pjs') . '.js'; file_put_contents($f, $jsPart);
    exec('node --check ' . escapeshellarg($f) . ' 2>&1', $o, $rc); @unlink($f);
    if ($rc !== 0) { $bad++; echo "     script #$i: " . implode(' ', array_slice($o, 0, 3)) . "\n"; }
    $o = [];
}
ok($bad === 0, 'every inline script of the rendered till parses (' . count($mm[1]) . ' scripts)');

// ───────────────────────────────────────────────────────────────────────────
section('D. Kitchen Display — turn the screen on with one click (endpoint runs in a subprocess; it exits)');
$runApi = function (string $file, string $method, array $sessionExtra, array $features, string $startMode) use ($root) {
    $pdo2 = $GLOBALS['pdo'];
    $q = $pdo2->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_kitchen_mode'"); $q->execute(); $orig = $q->fetchColumn();
    $pdo2->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('restaurant_kitchen_mode', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$startMode]);
    $uid = (int)$pdo2->query("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
    $cfg = tempnam(sys_get_temp_dir(), 'rsa');
    $c = ['page' => $file, 'session' => $sessionExtra + ['user_id' => $uid, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en', 'csrf_token' => 'tk'], 'features' => $features];
    if ($method === 'POST') $c['post'] = ['_csrf' => 'tk'];
    file_put_contents($cfg, json_encode($c));
    $out = (string)shell_exec('php ' . escapeshellarg("$root/tests/test_restaurant_phase1_cli.php") . ' worker ' . escapeshellarg($cfg) . ' 2>&1');
    @unlink($cfg);
    $q = $pdo2->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_kitchen_mode'"); $q->execute(); $after = $q->fetchColumn();
    if ($orig === false) $pdo2->exec("DELETE FROM system_settings WHERE setting_key = 'restaurant_kitchen_mode'");
    else $pdo2->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'restaurant_kitchen_mode'")->execute([$orig]);
    $j = json_decode(trim(explode('___OUT___', $out, 2)[1] ?? ''), true);
    return ['json' => is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($out, 0, 200)], 'after' => $after];
};
$ON = ['pos' => true, 'restaurant_pos' => true];
$r = $runApi('api/restaurant/set_kitchen_mode.php', 'GET', [], $ON, 'none');
ok(empty($r['json']['success']) && $r['after'] === 'none', 'a GET is refused and changes nothing');
$r = $runApi('api/restaurant/set_kitchen_mode.php', 'POST', ['is_admin' => false, 'role_id' => 999999, 'role' => 'nobody'], $ON, 'none');
ok(empty($r['json']['success']) && $r['after'] === 'none', 'a user without the Selling Settings permission cannot switch it: "' . ($r['json']['message'] ?? '') . '"');
$r = $runApi('api/restaurant/set_kitchen_mode.php', 'POST', [], ['pos' => true, 'restaurant_pos' => false], 'none');
ok(empty($r['json']['success']) && $r['after'] === 'none', 'with the Restaurant module off the endpoint refuses (retail untouched)');
$r = $runApi('api/restaurant/set_kitchen_mode.php', 'POST', [], $ON, 'none');
ok(!empty($r['json']['success']) && $r['after'] === 'screen', 'an admin turns the kitchen screen on ("none" → "screen")');
$r = $runApi('api/restaurant/set_kitchen_mode.php', 'POST', [], $ON, 'printer');
ok(!empty($r['json']['success']) && $r['after'] === 'screen', 'it only ever sets "screen" (never any other value)');
$src = file_get_contents("$root/api/restaurant/set_kitchen_mode.php");
ok(strpos($src, "save_setting('restaurant_kitchen_mode', 'screen')") !== false && strpos($src, '$_POST') === false, 'the endpoint reads no input — the value is fixed');
ok(strpos($src, "canEdit('pos_config_settings')") !== false && strpos($src, 'csrf_check()') !== false, 'the endpoint is guarded by the Selling Settings permission and a CSRF check');
// ───────────────────────────────────────────────────────────────────────────
section('D2. Kitchen Display page — explains "by mouth", offers the switch');
$none = $renderPage('app/bms/restaurant/kitchen_dashboard.php', ['pos' => true, 'restaurant_pos' => true], 'restaurant', ['restaurant_kitchen_mode' => 'none']);
ok(strpos($none, 'id="kitchenOffNotice"') !== false && strpos($none, 'id="kitchenOnBtn"') !== false, 'kitchen "by mouth": the page says orders are not sent here and shows the "Turn on kitchen screen" button');
foreach (['screen', 'printer'] as $m) {
    $o = $renderPage('app/bms/restaurant/kitchen_dashboard.php', ['pos' => true, 'restaurant_pos' => true], 'restaurant', ['restaurant_kitchen_mode' => $m]);
    ok(strpos($o, 'id="kitchenOffNotice"') === false && strpos($o, 'id="ticketsContainer"') !== false, "kitchen mode \"$m\": no notice, the ticket list is there as before");
}
ok(!preg_match('/PHP (Warning|Notice|Fatal)|___EXC___/', explode('___OUT___', $none)[0] ?? ''), 'the page renders with no PHP warnings');
$kh = preg_replace('#<!--.*?-->#s', '', explode('___OUT___', $none, 2)[1] ?? '');
preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $kh, $km);
$kbad = 0;
foreach ($km[1] as $i => $jsPart) {
    if (trim($jsPart) === '' || strpos($jsPart, 'application/ld+json') !== false) continue;
    $ff = tempnam(sys_get_temp_dir(), 'kjs') . '.js'; file_put_contents($ff, $jsPart);
    exec('node --check ' . escapeshellarg($ff) . ' 2>&1', $oo, $rc); @unlink($ff);
    if ($rc !== 0) { $kbad++; echo "     script #$i: " . implode(' ', array_slice($oo, 0, 3)) . "\n"; }
    $oo = [];
}
ok($kbad === 0, 'every inline script of the kitchen page parses (' . count($km[1]) . ' scripts)');
ok(strpos(file_get_contents("$root/app/bms/restaurant/kitchen_dashboard.php"), '${parseFloat(it.quantity)}${STRINGS.qty}') !== false, 'a ticket line reads "1x Chai", not "1.000x Chai"');

// ───────────────────────────────────────────────────────────────────────────
section('E2. Restaurant dashboard renders');
$dash = $renderPage('includes/restaurant/dashboard.php', ['pos' => true, 'restaurant_pos' => true], 'restaurant');
ok(strpos($dash, 'Where your money went') !== false, 'the restaurant dashboard renders its profit story');
ok(!preg_match('/PHP (Warning|Notice|Fatal|Parse)|___EXC___/', $dash), 'the dashboard renders with no PHP warnings or errors');

// ───────────────────────────────────────────────────────────────────────────
section('F. Floors & Tables — add several tables at once');
$fl = $renderPage('app/bms/restaurant/floors.php', ['pos' => true, 'restaurant_pos' => true], 'restaurant');
ok(strpos($fl, 'id="addSeveralTablesBtn"') !== false && strpos($fl, 'function addSeveralTables()') !== false, 'the page offers "Add several tables"');
$fsrc = file_get_contents("$root/app/bms/restaurant/floors.php");
ok(preg_match('/if \(\$can_edit\): \?>.*id="addSeveralTablesBtn".*<\?php endif; \?>/s', $fsrc) === 1, 'the button is shown only to people who can edit (same rule as Add Table)');
ok(strpos($fsrc, "api/restaurant/quick_setup.php") !== false && strpos($fsrc, "action: 'tables'") !== false, 'it reuses the existing bulk endpoint (no new write path)');
ok(!preg_match('/PHP (Warning|Notice|Fatal|Parse)|___EXC___/', $fl), 'the page renders with no PHP warnings');
$fh = preg_replace('#<!--.*?-->#s', '', explode('___OUT___', $fl, 2)[1] ?? '');
preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $fh, $fm);
$fbad = 0;
foreach ($fm[1] as $i => $jsPart) {
    if (trim($jsPart) === '' || strpos($jsPart, 'application/ld+json') !== false) continue;
    $ff = tempnam(sys_get_temp_dir(), 'fjs') . '.js'; file_put_contents($ff, $jsPart);
    exec('node --check ' . escapeshellarg($ff) . ' 2>&1', $oo, $rc); @unlink($ff);
    if ($rc !== 0) { $fbad++; echo "     script #$i: " . implode(' ', array_slice($oo, 0, 3)) . "\n"; }
    $oo = [];
}
ok($fbad === 0, 'every inline script of the floors page parses (' . count($fm[1]) . ' scripts)');