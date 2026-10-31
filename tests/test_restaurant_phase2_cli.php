<?php
/**
 * tests/test_restaurant_phase2_cli.php — restaurant_plan.md Phase 2 (Take Order + Tables).
 *
 * Real endpoints, in-process, inside ONE outer transaction that is rolled back at the end
 * (endpoint transactions become savepoints) — nothing is left behind.
 *
 *   A. A Menu dish (no-stock, no recipe) with a note goes to its kitchen area; a drink with
 *      no preparation does not. (Before: send_to_kitchen skipped every no-stock line.)
 *   B. One open order per table: a new save replaces the old held order; re-sending to the
 *      kitchen updates the same ticket (no duplicates).
 *   C. Tables board: table shows its open order (amount, items); others free.
 *   D. Move order: to a free table moves the order and its ticket; onto a busy table refused.
 *   E. Pay the table: the dish with no recipe sells (Levanda's trap), sale_type dine_in, note
 *      kept on the sale line, the table's open order closed, table free, ledger balanced.
 *   F. Take away sale stores sale_type take_away.
 *   G. Retail safety: a plain hold (no table) is never touched; a sale with no note leaves the
 *      line description empty; the till's restaurant bar only renders when the module is on.
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

// A shop with a stocked drink (so a mixed order can be tested).
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
    $pdo->prepare("UPDATE products SET kitchen_station_id = NULL WHERE product_id = ?")->execute([$DRINK]); // drink: no preparation
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-REST', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    require_once "$root/core/restaurant_scope.php";
    ok(restaurantShopActive($pdo, $W), 'fixture: shop is an active restaurant shop');

    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $DISH = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "P2 Supu $sfx", 'price' => 3000, 'prep_area' => 'kitchen'], $admin);
    $KITCHEN = (int)val("SELECT kitchen_station_id FROM products WHERE product_id = ?", [$DISH]);
    $floor = restaurantDefaultFloorId($pdo, $W);
    $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, ?, 4, 'available'), (?, ?, ?, 4, 'available')")
        ->execute([$W, $floor, "P2A$sfx", $W, $floor, "P2B$sfx"]);
    $T1 = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["P2A$sfx"]);
    $T2 = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["P2B$sfx"]);

    $items = [
        ['product_id' => $DISH, 'product_name' => "P2 Supu $sfx", 'quantity' => 2, 'price' => 3000, 'discounted_price' => 3000, 'tax_rate' => 0, 'note' => 'bila pilipili'],
        ['product_id' => $DRINK, 'product_name' => 'drink', 'quantity' => 1, 'price' => $DPRICE, 'discounted_price' => $DPRICE, 'tax_rate' => 0],
    ];
    $hold = function (int $table, array $its) use ($W) {
        return call('api/pos/hold_sale.php', 'POST', ['reference' => 'T', 'items' => $its, 'subtotal' => array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $its)), 'tax' => 0, 'warehouse_id' => $W, 'table_id' => $table], true);
    };

    // A plain retail hold (no table) that must never be touched.
    $retailHold = call('api/pos/hold_sale.php', 'POST', ['reference' => 'walk-in', 'items' => [$items[1]], 'subtotal' => $DPRICE, 'tax' => 0], true);
    ok(!empty($retailHold['success']), 'fixture: a plain (retail) hold saved');

    section('A. Dish → kitchen, drink stays out');
    $h1 = $hold($T1, $items);
    ok(!empty($h1['success']), 'table order saved' . (!empty($h1['success']) ? '' : ' — ' . ($h1['message'] ?? '')));
    $k1 = call('api/restaurant/send_to_kitchen.php', 'POST', ['hold_id' => $h1['hold_id'], 'warehouse_id' => $W]);
    ok(!empty($k1['success']), 'sent to kitchen' . (!empty($k1['success']) ? '' : ' — ' . ($k1['message'] ?? '')));
    $tk = one("SELECT * FROM kitchen_tickets WHERE table_id = ? AND station_id = ? AND status IN ('queued','preparing')", [$T1, $KITCHEN]);
    ok((bool)$tk, 'a kitchen ticket exists for the dish (no-stock Menu dish no longer skipped)');
    $ti = $tk ? one("SELECT * FROM kitchen_ticket_items WHERE ticket_id = ? AND product_id = ?", [$tk['ticket_id'], $DISH]) : null;
    ok($ti && (float)$ti['quantity'] == 2.0 && strpos((string)$ti['modifiers_summary'], 'bila pilipili') !== false, 'ticket line: 2 × dish with the note "bila pilipili"');
    ok((int)val("SELECT COUNT(*) FROM kitchen_ticket_items kti JOIN kitchen_tickets kt ON kt.ticket_id = kti.ticket_id WHERE kt.table_id = ? AND kti.product_id = ?", [$T1, $DRINK]) === 0, 'drink with no preparation is not sent to the kitchen');

    section('B. One open order per table, one ticket');
    $items2 = $items; $items2[0]['quantity'] = 3;
    $h2 = $hold($T1, $items2);
    ok(!empty($h2['success']) && (int)val("SELECT COUNT(*) FROM pos_held_sales WHERE table_id = ? AND status = 'held'", [$T1]) === 1, 'second save leaves exactly ONE open order on the table');
    ok(val("SELECT status FROM pos_held_sales WHERE hold_id = ?", [$h1['hold_id']]) === 'cancelled', 'the previous held order is replaced (cancelled)');
    call('api/restaurant/send_to_kitchen.php', 'POST', ['hold_id' => $h2['hold_id'], 'warehouse_id' => $W]);
    ok((int)val("SELECT COUNT(*) FROM kitchen_tickets WHERE table_id = ? AND station_id = ? AND status IN ('queued','preparing')", [$T1, $KITCHEN]) === 1, 're-sent order updates the same ticket — no duplicate in the kitchen');
    ok((float)val("SELECT kti.quantity FROM kitchen_ticket_items kti JOIN kitchen_tickets kt ON kt.ticket_id = kti.ticket_id WHERE kt.table_id = ? AND kti.product_id = ?", [$T1, $DISH]) == 3.0, 'ticket shows the new quantity (3)');
    ok(val("SELECT status FROM pos_held_sales WHERE hold_id = ?", [$retailHold['hold_id']]) === 'held', 'the plain retail hold is untouched');

    section('C. Tables board');
    $b = call('api/restaurant/get_tables_board.php', 'GET', ['warehouse_id' => $W]);
    $byId = []; foreach ($b['data'] ?? [] as $r) $byId[$r['table_id']] = $r;
    ok(($byId[$T1]['status'] ?? '') === 'occupied' && abs(($byId[$T1]['total'] ?? 0) - (9000 + $DPRICE)) < 0.01 && (float)($byId[$T1]['item_count'] ?? 0) == 4.0, 'busy table shows its open order: amount and 4 items');
    ok(($byId[$T2]['status'] ?? '') === 'available' && $byId[$T2]['hold_id'] === null, 'other table is free');

    section('D. Move order');
    $m = call('api/restaurant/move_table_order.php', 'POST', ['warehouse_id' => $W, 'from_table_id' => $T1, 'to_table_id' => $T2]);
    ok(!empty($m['success']), 'order moved to a free table' . (!empty($m['success']) ? '' : ' — ' . ($m['message'] ?? '')));
    ok((int)val("SELECT table_id FROM pos_held_sales WHERE hold_id = ?", [$h2['hold_id']]) === $T2, 'open order now on the new table');
    ok((int)val("SELECT COUNT(*) FROM kitchen_tickets WHERE table_id = ? AND status IN ('queued','preparing')", [$T2]) === 1, 'its kitchen ticket moved too');
    ok(val("SELECT status FROM restaurant_tables WHERE table_id = ?", [$T1]) === 'available' && val("SELECT status FROM restaurant_tables WHERE table_id = ?", [$T2]) === 'occupied', 'old table free, new table occupied');
    $hBusy = $hold($T1, [$items[1]]);
    $m2 = call('api/restaurant/move_table_order.php', 'POST', ['warehouse_id' => $W, 'from_table_id' => $T2, 'to_table_id' => $T1]);
    ok(empty($m2['success']), 'moving onto a table that has an order is refused');

    section('E. Pay the table — dish with no recipe sells');
    $total = 9000 + $DPRICE;
    $sale = call('api/pos/process_sale.php', 'POST', [
        'customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => $items2,
        'subtotal' => $total, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $total,
        'payment_method' => 'cash', 'amount_tendered' => $total, 'change_given' => 0, 'amount_paid' => $total,
        'table_id' => $T2, 'sale_type' => 'dine_in',
    ], true);
    ok(!empty($sale['success']), 'sale completed' . (!empty($sale['success']) ? '' : ' — ' . ($sale['message'] ?? '')));
    $sid = (int)($sale['sale_id'] ?? 0);
    ok(val("SELECT sale_type FROM pos_sales WHERE sale_id = ?", [$sid]) === 'dine_in' && (int)val("SELECT table_id FROM pos_sales WHERE sale_id = ?", [$sid]) === $T2, 'sale is dine-in on the table');
    ok(val("SELECT description FROM pos_sale_items WHERE sale_id = ? AND product_id = ?", [$sid, $DISH]) === 'bila pilipili', 'the kitchen note is kept on the sale line');
    ok(val("SELECT description FROM pos_sale_items WHERE sale_id = ? AND product_id = ?", [$sid, $DRINK]) === null, 'a line with no note keeps an empty description (unchanged insert)');
    ok((int)val("SELECT COUNT(*) FROM pos_held_sales WHERE table_id = ? AND status = 'held'", [$T2]) === 0, "the table's open order is closed after payment");
    ok(val("SELECT status FROM restaurant_tables WHERE table_id = ?", [$T2]) === 'available', 'table is free again');
    require_once "$root/core/financial_reports.php";
    $bal = assertLedgerBalanced($pdo);
    ok(!empty($bal['ledger_balanced']) && !empty($bal['bs_balanced']), 'ledger balanced after the restaurant sale');

    section('F. Take away');
    $ta = call('api/pos/process_sale.php', 'POST', [
        'customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => [$items[0]],
        'subtotal' => 6000, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => 6000,
        'payment_method' => 'cash', 'amount_tendered' => 6000, 'change_given' => 0, 'amount_paid' => 6000, 'sale_type' => 'take_away',
    ], true);
    ok(!empty($ta['success']) && val("SELECT sale_type FROM pos_sales WHERE sale_id = ?", [(int)($ta['sale_id'] ?? 0)]) === 'take_away', 'take-away sale stored as take_away');
} finally {
    $pdo->endOuter();
}

section('G. Till renders (restaurant bar only with the module on)');
$render = function (array $features) use ($root, $W) {
    $pdo2 = $GLOBALS['pdo'];
    $origSimple = $pdo2->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
    $origMode = $pdo2->query("SELECT pos_mode FROM warehouses WHERE warehouse_id = $W")->fetchColumn();
    $pdo2->exec("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = $W");
    $uid = (int)$pdo2->query("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
    $cfg = tempnam(sys_get_temp_dir(), 'rp2');
    file_put_contents($cfg, json_encode(['page' => 'app/bms/pos/pos.php', 'session' => ['user_id' => $uid, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'], 'features' => $features]));
    $out = (string)shell_exec('php ' . escapeshellarg("$root/tests/test_restaurant_phase1_cli.php") . ' worker ' . escapeshellarg($cfg) . ' 2>&1');
    @unlink($cfg);
    $pdo2->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$origMode, $W]);
    return $out;
};
$on = $render(['pos' => true, 'restaurant_pos' => true]);
ok(strpos($on, 'id="restaurantOrderBar"') !== false && strpos($on, 'id="rsSaveTableBtn"') !== false, 'module on: till has the order bar (Dine in / Take away / Delivery, Save to table)');
ok(strpos($on, 'const POS_OPEN_TABLE = null;') !== false, 'POS_OPEN_TABLE is null without ?table=');
ok(!preg_match('/PHP (Warning|Notice|Fatal)|___EXC___/', explode('___OUT___', $on)[0] ?? ''), 'pos.php renders with no PHP warnings');
$off = $render(['pos' => true, 'restaurant_pos' => false]);
ok(strpos($off, 'id="restaurantOrderBar"') === false, 'module off: no restaurant bar in the till (retail exactly as before)');
// Inline scripts of the rendered till must parse.
$html = preg_replace('#<!--.*?-->#s', '', explode('___OUT___', $on, 2)[1] ?? ''); // ignore commented-out markup
preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $mm);
$bad = 0;
foreach ($mm[1] as $i => $js) {
    if (trim($js) === '' || strpos($js, 'application/ld+json') !== false) continue;
    $f = tempnam(sys_get_temp_dir(), 'pjs') . '.js'; file_put_contents($f, $js);
    exec('node --check ' . escapeshellarg($f) . ' 2>&1', $o, $rc); @unlink($f);
    if ($rc !== 0) { $bad++; echo "     script #$i: " . implode(' ', array_slice($o, 0, 3)) . "\n"; }
    $o = [];
}
ok($bad === 0, 'every inline script of the rendered till parses (' . count($mm[1]) . ' scripts)');
