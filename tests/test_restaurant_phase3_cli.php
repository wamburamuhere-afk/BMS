<?php
/**
 * tests/test_restaurant_phase3_cli.php — restaurant_plan.md Phase 3 (how orders reach the kitchen).
 *
 *   A. Selling Settings: the Restaurant section renders + saves only when the restaurant
 *      module is on; with it off a posted value is ignored (retail settings untouched).
 *   B. restaurantKitchenMode(): 'none' (by mouth) by default; bad values fall back to 'none'.
 *   C. A paid take-away sale reaches the kitchen: ticket with sale_id, label "Take away · RCP…",
 *      the note; calling again does not duplicate.  A table order's ticket is labelled "Table …".
 *   D. Kitchen screen API returns order_label.
 *   E. Kitchen slip: label, dish + note, a no-preparation drink left out; refused for a retail shop.
 *   F. Till: POS_KITCHEN_MODE / POS_ORDER_TYPES follow the settings; module off keeps today's behaviour.
 *
 * C–E run in-process inside one rolled-back transaction; A/F use subprocess renders and restore
 * every setting. Exit 0 = all pass.
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

function raw(string $file, string $method, array $data = [], bool $json = false): string {
    $_SERVER['REQUEST_METHOD'] = $method; $_POST = []; $_GET = [];
    if ($method === 'GET') $_GET = $data;
    elseif ($json) InputStub::$body = json_encode($data);
    else { $_POST = $data + ['_csrf' => 'test-csrf']; InputStub::$body = ''; }
    stream_wrapper_unregister('php'); stream_wrapper_register('php', 'InputStub');
    ob_start();
    try { (function () use ($file) { global $pdo; include $GLOBALS['root'] . '/' . $file; })(); }
    catch (Throwable $e) { echo json_encode(['success' => false, 'message' => 'EXC: ' . $e->getMessage()]); }
    finally { $out = ob_get_clean(); stream_wrapper_restore('php'); }
    return $out;
}
function call(string $file, string $method, array $data = [], bool $json = false): array {
    $out = raw($file, $method, $data, $json);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($out, 0, 300)];
}
function val(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function worker(array $cfg): string {
    global $root;
    $f = tempnam(sys_get_temp_dir(), 'rp3'); file_put_contents($f, json_encode($cfg));
    $out = (string)shell_exec('php ' . escapeshellarg("$root/tests/test_restaurant_phase1_cli.php") . ' worker ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return $out;
}

$admin = (int)val("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1");
$_SESSION['user_id'] = $admin; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['role_id'] = 1;
$_SESSION['is_admin'] = true; $_SESSION['csrf_token'] = 'test-csrf'; $_SESSION['user_lang'] = 'en';
$GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true];
$sess = ['user_id' => $admin, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en', 'csrf_token' => 'test-csrf'];
$ON = ['pos' => true, 'restaurant_pos' => true]; $OFF = ['pos' => true, 'restaurant_pos' => false];

$drink = $pdo->query("SELECT ps.product_id, ps.warehouse_id, p.selling_price FROM product_stocks ps JOIN products p ON p.product_id = ps.product_id
               WHERE ps.stock_quantity >= 10 AND p.is_service = 0 AND COALESCE(p.is_combo,0) = 0 AND p.selling_price > 0 AND p.status = 'active'
                 AND p.selling_price >= p.min_selling_price
                 AND NOT EXISTS (SELECT 1 FROM product_serials sn WHERE sn.product_id = p.product_id)
            ORDER BY ps.stock_quantity DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$drink) { ok(false, 'fixture: a stocked product to sell'); exit; }
$W = (int)$drink['warehouse_id']; $DRINK = (int)$drink['product_id']; $DPRICE = (float)$drink['selling_price'];

// Settings snapshot (restored at the end).
$keys = ['restaurant_kitchen_mode', 'restaurant_order_take_away', 'restaurant_order_delivery', 'pos_simple_mode'];
$snap = [];
foreach ($keys as $k) { $snap[$k] = val("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$k]); }
$origMode = val("SELECT pos_mode FROM warehouses WHERE warehouse_id = ?", [$W]);
$setSetting = function (string $k, ?string $v) use ($pdo) {
    if ($v === null || $v === false) { $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]); return; }
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
};

try {
    // ── A/B/F: committed settings + subprocess renders ────────────────────────
    $setSetting('pos_simple_mode', '1');
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);

    section('B. Kitchen mode default');
    require_once "$root/core/restaurant_scope.php";
    $setSetting('restaurant_kitchen_mode', null);
    $probe = tempnam(sys_get_temp_dir(), 'rkm') . '.php';
    file_put_contents($probe, '<?php require ' . var_export("$root/roots.php", true) . '; require ' . var_export("$root/core/restaurant_scope.php", true) . '; echo restaurantKitchenMode();');
    $mode = trim((string)shell_exec('php ' . escapeshellarg($probe)));
    ok($mode === 'none', "no setting → 'none' (by mouth)  [$mode]");
    $setSetting('restaurant_kitchen_mode', 'bogus');
    $mode = trim((string)shell_exec('php ' . escapeshellarg($probe)));
    ok($mode === 'none', "invalid value → 'none'  [$mode]");
    @unlink($probe);

    section('A. Selling Settings — Restaurant section');
    $out = worker(['page' => 'app/constant/settings/pos_config_settings.php', 'session' => $sess, 'features' => $ON]);
    ok(strpos($out, 'name="restaurant_kitchen_mode"') !== false && strpos($out, 'name="restaurant_order_delivery"') !== false, 'module on: Restaurant section shown (kitchen + order types)');
    ok(!preg_match('/PHP (Warning|Notice|Fatal)|___EXC___/', explode('___OUT___', $out)[0] ?? ''), 'settings page renders with no PHP warnings');
    $out = worker(['page' => 'app/constant/settings/pos_config_settings.php', 'session' => $sess, 'features' => $OFF]);
    ok(strpos($out, 'restaurant_kitchen_mode') === false, 'module off: no Restaurant section (retail settings page unchanged)');
    $base = ['_csrf' => 'test-csrf', 'save_pos' => '1', 'pos_discount_type' => get_setting('pos_discount_type', 'percentage'),
             'pos_products_display_limit' => get_setting('pos_products_display_limit', '20'), 'pos_products_display_limit_mobile' => get_setting('pos_products_display_limit_mobile', '10'),
             'pos_receipt_width' => get_setting('pos_receipt_width', '80'), 'pos_old_sale_max_days' => get_setting('pos_old_sale_max_days', '30')];
    if (get_setting('pos_auto_print_receipt', '0') === '1') $base['pos_auto_print_receipt'] = '1';
    $setSetting('restaurant_kitchen_mode', 'none');
    worker(['page' => 'app/constant/settings/pos_config_settings.php', 'session' => $sess, 'features' => $ON, 'post' => $base + ['restaurant_kitchen_mode' => 'printer', 'restaurant_order_take_away' => '1']]);
    ok(val("SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_kitchen_mode'") === 'printer', 'module on: kitchen mode saved (printer)');
    ok(val("SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_order_delivery'") === '0', 'Delivery unticked → saved off');
    worker(['page' => 'app/constant/settings/pos_config_settings.php', 'session' => $sess, 'features' => $OFF, 'post' => $base + ['restaurant_kitchen_mode' => 'screen']]);
    ok(val("SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_kitchen_mode'") === 'printer', 'module off: a posted kitchen mode is ignored');

    section('F. Till follows the settings');
    $out = worker(['page' => 'app/bms/pos/pos.php', 'session' => $sess, 'features' => $ON]);
    ok(strpos($out, 'const POS_KITCHEN_MODE = "printer";') !== false && strpos($out, 'const POS_ORDER_TYPES = ["dine_in","take_away"];') !== false, 'till gets kitchen mode "printer" and order types without Delivery');
    $out = worker(['page' => 'app/bms/pos/pos.php', 'session' => $sess, 'features' => $OFF]);
    ok(strpos($out, 'const POS_KITCHEN_MODE = "screen";') !== false && strpos($out, 'id="restaurantOrderBar"') === false, 'module off: today\'s behaviour (fallback, no restaurant bar)');

    // ── C/D/E: in-process, rolled back ─────────────────────────────────────────
    $pdo->startOuter();
    try {
        $pdo->prepare("UPDATE products SET kitchen_station_id = NULL WHERE product_id = ?")->execute([$DRINK]);
        $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-P3', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
        $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
        $DISH = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "P3 Chips $sfx", 'price' => 2500, 'prep_area' => 'kitchen'], $admin);
        $floor = restaurantDefaultFloorId($pdo, $W);
        $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, ?, 4, 'available')")->execute([$W, $floor, "P3T$sfx"]);
        $T = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["P3T$sfx"]);
        $dishLine = ['product_id' => $DISH, 'product_name' => 'chips', 'quantity' => 2, 'price' => 2500, 'discounted_price' => 2500, 'tax_rate' => 0, 'note' => 'na mayai'];
        $drinkLine = ['product_id' => $DRINK, 'product_name' => 'drink', 'quantity' => 1, 'price' => $DPRICE, 'discounted_price' => $DPRICE, 'tax_rate' => 0];

        section('C. Take-away reaches the kitchen; table ticket labelled');
        $tot = 5000 + $DPRICE;
        $sale = call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => [$dishLine, $drinkLine],
            'subtotal' => $tot, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $tot,
            'payment_method' => 'cash', 'amount_tendered' => $tot, 'change_given' => 0, 'amount_paid' => $tot, 'sale_type' => 'take_away'], true);
        ok(!empty($sale['success']), 'take-away sale paid' . (!empty($sale['success']) ? '' : ' — ' . ($sale['message'] ?? '')));
        $sid = (int)($sale['sale_id'] ?? 0);
        $k = call('api/restaurant/send_to_kitchen.php', 'POST', ['sale_id' => $sid, 'warehouse_id' => $W]);
        ok(!empty($k['success']), 'paid sale sent to the kitchen' . (!empty($k['success']) ? '' : ' — ' . ($k['message'] ?? '')));
        $tk = $pdo->query("SELECT * FROM kitchen_tickets WHERE sale_id = $sid")->fetchAll(PDO::FETCH_ASSOC);
        $rcp = val("SELECT receipt_number FROM pos_sales WHERE sale_id = ?", [$sid]);
        ok(count($tk) === 1 && $tk[0]['order_label'] === "Take away · $rcp" && $tk[0]['table_id'] === null, "one ticket labelled \"Take away · $rcp\"");
        $line = $tk ? $pdo->query("SELECT * FROM kitchen_ticket_items WHERE ticket_id = " . (int)$tk[0]['ticket_id'])->fetchAll(PDO::FETCH_ASSOC) : [];
        ok(count($line) === 1 && (int)$line[0]['product_id'] === $DISH && strpos((string)$line[0]['modifiers_summary'], 'na mayai') !== false, 'only the dish (with its note) — the drink stays out');
        call('api/restaurant/send_to_kitchen.php', 'POST', ['sale_id' => $sid, 'warehouse_id' => $W]);
        ok((int)val("SELECT COUNT(*) FROM kitchen_tickets WHERE sale_id = ?", [$sid]) === 1, 'sending the same sale again does not duplicate');

        $h = call('api/pos/hold_sale.php', 'POST', ['reference' => 'x', 'items' => [$dishLine], 'subtotal' => 5000, 'tax' => 0, 'warehouse_id' => $W, 'table_id' => $T], true);
        call('api/restaurant/send_to_kitchen.php', 'POST', ['hold_id' => $h['hold_id'] ?? 0, 'warehouse_id' => $W]);
        ok(val("SELECT order_label FROM kitchen_tickets WHERE table_id = ? ORDER BY ticket_id DESC LIMIT 1", [$T]) === "Table P3T$sfx", 'table order ticket labelled "Table …"');

        section('D. Kitchen screen API');
        $g = call('api/restaurant/get_kitchen_tickets.php', 'GET', ['warehouse_id' => $W]);
        $labels = array_column($g['data'] ?? [], 'order_label');
        ok(in_array("Take away · $rcp", $labels, true) && in_array("Table P3T$sfx", $labels, true), 'kitchen screen receives both labels');

        section('E. Kitchen slip');
        $html = raw('api/restaurant/kitchen_slip.php', 'GET', ['sale_id' => $sid, 'warehouse_id' => $W]);
        ok(strpos($html, "Take away · $rcp") !== false && strpos($html, "P3 Chips $sfx") !== false && strpos($html, 'na mayai') !== false, 'slip shows the label, the dish and its note');
        $dname = (string)val("SELECT product_name FROM products WHERE product_id = ?", [$DRINK]);
        ok(strpos($html, htmlspecialchars($dname)) === false, 'drink with no preparation is not on the slip');
        ok(strpos($html, 'window.print()') !== false && preg_match('/@page \{ size: (58|80)mm/', $html) === 1, 'slip prints itself at the receipt paper width');
        $html2 = raw('api/restaurant/kitchen_slip.php', 'GET', ['hold_id' => $h['hold_id'] ?? 0, 'warehouse_id' => $W]);
        ok(strpos($html2, "Table P3T$sfx") !== false, 'table order slip shows the table');
    } finally {
        $pdo->endOuter();
    }
    // A retail shop is refused (subprocess sees the committed DB).
    $retail = (int)val("SELECT warehouse_id FROM warehouses WHERE status = 'active' AND warehouse_id <> ? AND COALESCE(pos_mode,'retail') = 'retail' LIMIT 1", [$W]);
    if ($retail) {
        $o = worker(['page' => 'api/restaurant/kitchen_slip.php', 'session' => $sess, 'features' => $ON, 'get' => ['sale_id' => 1, 'warehouse_id' => $retail]]);
        ok(strpos($o, 'window.print()') === false, 'kitchen slip refused for a retail shop');
    }
} finally {
    foreach ($snap as $k => $v) $setSetting($k, $v === false ? null : $v);
    $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$origMode, $W]);
    $after = [];
    foreach ($keys as $k) $after[$k] = val("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$k]);
    ok($after == $snap, 'settings restored exactly');
}
