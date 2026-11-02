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

    // [later phases append here]
} finally {
    $pdo->endOuter();
}
