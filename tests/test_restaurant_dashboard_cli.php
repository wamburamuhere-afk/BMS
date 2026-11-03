<?php
/**
 * tests/test_restaurant_dashboard_cli.php — restaurant dashboard pass (2026-11-02).
 *
 *   A. Pure logic: periods, % change, menu-engineering classes.
 *   B. Ledger reconciliation: every money figure equals glProfitLoss (the Income Statement) for the same scope + dates.
 *   C. Scope: a view limited to other shops never sees this restaurant's sales; combined split adds up.
 *   D. Profit per plate: recipe cost for a dish, purchase cost for a bought item, "needs cost" never guessed.
 *   E. Hero (today is never compared with a full day), busy hours, attention list.
 *   F. Endpoint (api/restaurant/dashboard_panel.php) in a subprocess: gates, scopes, trend, panel HTML.
 *   G. Pages: restaurant-only, mixed (All | Restaurant | Shop), module off / retail-only = nothing new.
 *
 * Real code, in-process, inside ONE outer transaction that is rolled back (endpoint transactions become savepoints).
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
function near(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) <= $eps; }

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
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';   // buildUrl() needs a host; a CLI run has none

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
require_once "$root/core/restaurant_insights.php";
require_once "$root/core/financial_reports.php";

// ───────────────────────────────────────────────────────────────────────────
section('A. Pure logic');
$p = rdPeriod('daily', '2026-11-02');
ok(count($p['keys']) === 30 && $p['keys'][29] === '2026-11-02' && $p['keys'][0] === '2026-10-04' && $p['start'] === '2026-10-04', 'daily = the last 30 days, ending today');
$p = rdPeriod('weekly', '2026-11-02');
$allMon = true; foreach ($p['keys'] as $k) if (date('N', strtotime($k)) !== '1') $allMon = false;
ok(count($p['keys']) === 12 && $allMon && $p['keys'][11] === '2026-11-02' && $p['start'] === $p['keys'][0], 'weekly = 12 weeks, each starting on a Monday');
$p = rdPeriod('monthly', '2026-11-02');
ok(count($p['keys']) === 12 && $p['keys'][0] === '2025-12' && $p['keys'][11] === '2026-11' && $p['start'] === '2025-12-01', 'monthly = 12 months, from the 1st');
ok(count(rdPeriod('bogus')['keys']) === 30, 'an unknown period falls back to daily');
ok(rdPct(110, 100) === 10.0 && rdPct(50, 100) === -50.0 && rdPct(5, 0) === null && rdPct(0, 0) === null, 'percent change; nothing to compare = null (no fake 100%)');

$cls = rdClassifyDishes([
    ['qty' => 50, 'profit_per_plate' => 5000], ['qty' => 5, 'profit_per_plate' => 6000],
    ['qty' => 60, 'profit_per_plate' => 500], ['qty' => 4, 'profit_per_plate' => 400], ['qty' => 20, 'profit_per_plate' => null],
]);
ok([$cls[0]['class'], $cls[1]['class'], $cls[2]['class'], $cls[3]['class'], $cls[4]['class']] === ['star', 'gem', 'popular', 'review', null],
    'menu engineering: Star / Hidden gem / Popular-small-profit / Review; an item with no cost gets no class');
$few = rdClassifyDishes([['qty' => 5, 'profit_per_plate' => 100], ['qty' => 9, 'profit_per_plate' => 300]]);
ok($few[0]['class'] === null && $few[1]['class'] === null, 'fewer than 3 costed items → no classes (nothing is claimed on thin data)');

// ───────────────────────────────────────────────────────────────────────────
$drink = one("SELECT ps.product_id, ps.warehouse_id, p.selling_price FROM product_stocks ps JOIN products p ON p.product_id = ps.product_id
               WHERE ps.stock_quantity >= 10 AND p.is_service = 0 AND COALESCE(p.is_combo,0) = 0 AND p.selling_price > 0 AND p.status = 'active'
                 AND p.selling_price >= p.min_selling_price AND NOT EXISTS (SELECT 1 FROM product_serials sn WHERE sn.product_id = p.product_id)
            ORDER BY ps.stock_quantity DESC LIMIT 1");
if (!$drink) { ok(false, 'fixture: a stocked product to sell'); exit; }
$W = (int)$drink['warehouse_id'];
$today = date('Y-m-d');

$pdo->startOuter();
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);
    $pdo->prepare("UPDATE cash_register_shifts SET status = 'closed' WHERE user_id = ? AND status = 'active'")->execute([$admin]);
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-DASH', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    require_once "$root/core/restaurant_scope.php";
    ok(restaurantShopActive($pdo, $W), 'fixture: an active restaurant shop');

    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    // an ingredient with a known cost, a dish with a recipe (2 × 400 = 800), a dish with NO recipe, a bought drink (cost 700)
    $pdo->prepare("INSERT INTO products (product_name, unit, unit_of_measure, cost_price, purchase_price, selling_price, min_selling_price, is_service, track_inventory, warehouse_id, is_menu_item, status, created_by)
                   VALUES (?, 'kg', 'kg', 400, 400, 0, 0, 0, 1, ?, 0, 'active', ?)")->execute(["DB Ingredient $sfx", $W, $admin]);
    $ING = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, 1000)")->execute([$ING, $W]);
    $A = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "DB Pilau $sfx", 'price' => 3000, 'prep_area' => 'kitchen'], $admin);
    $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, unit, qty_per_unit, total_qty) VALUES (?, ?, 'kg', 2, 2)")->execute([$A, $ING]);
    $pdo->prepare("UPDATE products SET is_combo = 1 WHERE product_id = ?")->execute([$A]);
    $B = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "DB Juice $sfx", 'price' => 2000, 'prep_area' => 'kitchen'], $admin);
    $C = restaurantCreateMenuItem($pdo, $W, ['type' => 'bought', 'name' => "DB Soda $sfx", 'price' => 1500, 'cost' => 700, 'opening' => 50, 'prep_area' => 'none'], $admin);

    $ids = [$W];
    $scopeR = rdJeScope($ids, 'restaurant');
    $before = ['hero' => rdHero($pdo, $scopeR, $ids), 'hours' => rdOrdersByHour($pdo, $ids, 30), 'dish' => rdDishEarnings($pdo, $ids, $today, $today)];

    $line = fn($pid, $q, $p) => ['product_id' => $pid, 'product_name' => 'x', 'quantity' => $q, 'price' => $p, 'discounted_price' => $p, 'tax_rate' => 0];
    $pay = function (array $its, string $type) use ($W) {
        $t = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $its));
        return call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => $its, 'subtotal' => $t,
            'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $t, 'payment_method' => 'cash', 'amount_tendered' => $t,
            'change_given' => 0, 'amount_paid' => $t, 'sale_type' => $type], true);
    };
    $s1 = $pay([$line($A, 3, 3000)], 'dine_in');
    $s2 = $pay([$line($A, 1, 3000), $line($C, 2, 1500)], 'take_away');
    $s3 = $pay([$line($B, 2, 2000)], 'delivery');
    $okSales = !empty($s1['success']) && !empty($s2['success']) && !empty($s3['success']);
    ok($okSales, 'fixture: three real sales (dish with recipe, dish + drink, dish with no recipe)' . ($okSales ? '' : ' — ' . ($s1['message'] ?? '') . ' ' . ($s2['message'] ?? '') . ' ' . ($s3['message'] ?? '')));

    // ───────────────────────────────────────────────────────────────────────
    section('B. Ledger reconciliation — the dashboard equals the Income Statement');
    $tr = rdTrend($pdo, 'daily', $scopeR);
    $pl = glProfitLoss($pdo, $tr['keys'][0], $today, null, $scopeR);
    $plSales = round($pl['total_revenue'] + $pl['total_other_income'], 2);
    $plExp = round($pl['total_expense'] + $pl['total_finance_cost'], 2);
    ok(near(array_sum($tr['sales']), $plSales), '30-day sales total = Income Statement (revenue + other income): ' . $plSales);
    ok(near(array_sum($tr['cost']), (float)$pl['total_cogs']), '30-day food cost = Income Statement cost of sales');
    ok(near(array_sum($tr['expenses']), $plExp), '30-day expenses = Income Statement expenses');
    ok(near(array_sum($tr['profit']), $plSales - (float)$pl['total_cogs'] - $plExp), '30-day profit = sales − cost − expenses');
    $last = count($tr['keys']) - 1;
    ok($tr['keys'][$last] === $today && $tr['sales'][$last] >= 14500 - 0.01, "today's bar includes the three sales (≥ 14,500)");
    foreach (['weekly', 'monthly'] as $per) {
        $t2 = rdTrend($pdo, $per, $scopeR); $p2 = rdPeriod($per);
        $pl2 = glProfitLoss($pdo, $p2['start'], $p2['end'], null, $scopeR);
        ok(near(array_sum($t2['sales']), round($pl2['total_revenue'] + $pl2['total_other_income'], 2)) && count($t2['keys']) === 12, "$per series: 12 buckets and the sales total matches the Income Statement");
    }
    $story = rdProfitStory($pdo, $scopeR);
    $plM = glProfitLoss($pdo, date('Y-m-01'), $today, null, $scopeR);
    ok(near($story['cur']['sales'], round($plM['total_revenue'] + $plM['total_other_income'], 2)) && near($story['cur']['cost'], (float)$plM['total_cogs']), 'profit story: this month sales and food cost = Income Statement');
    ok(near($story['cur']['profit'], $story['cur']['sales'] - $story['cur']['cost'] - $story['cur']['expenses']), 'profit story: profit = sales − food cost − expenses');
    if ($story['cur']['sales'] > 0) ok(near($story['per100']['cost'] + $story['per100']['expenses'] + $story['per100']['profit'], 100, 0.3), '"for every 100 you sell" adds up to 100 (' . $story['per100']['cost'] . ' + ' . $story['per100']['expenses'] . ' + ' . $story['per100']['profit'] . ')');

    // ───────────────────────────────────────────────────────────────────────
    section('C. Scope — only what belongs to the view');
    $none = rdLedgerBuckets($pdo, $today, $today, "DATE_FORMAT(je.entry_date, '%Y-%m-%d')", rdJeScope([0], 'restaurant'));
    ok($none === [], 'a view limited to other shops sees none of this restaurant\'s sales');
    $combined = rdTrend($pdo, 'daily', rdJeScope($ids, 'company'), $ids);
    $okSplit = true; foreach ($combined['keys'] as $i => $_) if (!near($combined['profit_r'][$i] + $combined['profit_o'][$i], $combined['profit'][$i])) $okSplit = false;
    ok($okSplit, 'combined: restaurant profit + shops & other profit = company profit, every day');
    ok(near($combined['sales_r'][$last], $tr['sales'][$last]), 'combined: the restaurant\'s sales today = the restaurant view\'s sales today');
    $area = rdAreaTotals($pdo, $ids, date('Y-m-01'), $today);
    ok(near($area['restaurant']['profit'] + $area['other']['profit'], $area['total']['profit']) && near($area['restaurant']['sales'] + $area['other']['sales'], $area['total']['sales']), 'area totals add up to the company total');

    // ───────────────────────────────────────────────────────────────────────
    section('D. Profit per plate — recipe cost, purchase cost, never guessed');
    $de = rdDishEarnings($pdo, $ids, $today, $today);
    $by = []; foreach ($de['items'] as $it) $by[$it['product_id']] = $it;
    $a0 = null; foreach ($before['dish']['items'] as $it) if ($it['product_id'] === $A) $a0 = $it;
    ok(isset($by[$A]) && near($by[$A]['qty'], 4) && near((float)$by[$A]['cost'], 800) && near((float)$by[$A]['profit_per_plate'], 2200) && near((float)$by[$A]['est_profit'], 8800) && $by[$A]['kind'] === 'recipe',
        'dish with a recipe: 4 plates, cost 800 (2 × 400), profit 2,200 a plate, estimated profit 8,800');
    ok(isset($by[$C]) && near((float)$by[$C]['cost'], 700) && near((float)$by[$C]['profit_per_plate'], 800) && near((float)$by[$C]['est_profit'], 1600) && $by[$C]['kind'] === 'bought',
        'bought drink: uses its purchase cost (700), profit 800 a plate, estimated profit 1,600');
    ok(isset($by[$B]) && $by[$B]['profit_per_plate'] === null && $by[$B]['cost'] === null && $by[$B]['est_profit'] === null, 'dish with no recipe: cost and profit are NOT guessed (null)');
    ok(in_array($B, array_column($de['needs_cost'], 'product_id'), true) && !in_array($A, array_column($de['needs_cost'], 'product_id'), true), 'it is listed under "needs a cost"; the costed ones are not');
    // (other dishes may already have been sold today in this database, so check the ranking rule, not a fixed list)
    $be = $de['best_earners']; $est = array_column($be, 'est_profit');
    $sortedDesc = $est === array_values($est); $prev = INF; foreach ($est as $e) { if ($e > $prev) $sortedDesc = false; $prev = $e; }
    $noUncosted = !in_array($B, array_column($be, 'product_id'), true) && count(array_filter($be, fn($x) => $x['profit_per_plate'] === null)) === 0;
    $aIn = in_array($A, array_column($be, 'product_id'), true);
    $aOk = $aIn || (count($be) === 5 && min($est) >= 8800 - 0.01);   // either listed, or displaced only by dishes that earn at least as much
    ok($sortedDesc && $noUncosted && $aOk, 'best earners: ranked by estimated profit, only costed items, and the dish is there unless 5 others earn more');
    $ai = array_search($A, array_column($be, 'product_id'), true); $ci = array_search($C, array_column($be, 'product_id'), true);
    ok($ai === false || $ci === false || $ai < $ci, 'the dish (8,800) ranks above the drink (1,600)');
    $price = $by[$A]['price'];
    ok(near($price, 3000) && near((float)$by[$A]['margin'], 73.3, 0.1), 'price is what was actually charged; margin = profit ÷ price (73.3%)');
    $pdo->prepare("UPDATE products SET cost_price = 500 WHERE product_id = ?")->execute([$ING]);
    $de2 = rdDishEarnings($pdo, $ids, $today, $today); $by2 = []; foreach ($de2['items'] as $it) $by2[$it['product_id']] = $it;
    ok(near((float)$by2[$A]['cost'], 1000) && near((float)$by2[$A]['profit_per_plate'], 2000), 'change an ingredient\'s cost and the dish\'s profit per plate follows (2 × 500)');
    $pdo->prepare("UPDATE products SET cost_price = 400 WHERE product_id = ?")->execute([$ING]);

    // ───────────────────────────────────────────────────────────────────────
    section('E. Hero, busy hours, attention');
    $hero = rdHero($pdo, $scopeR, $ids);
    ok(near($hero['sales']['today'] - $before['hero']['sales']['today'], 14500 - 0, 0.01) || $hero['sales']['today'] >= 14500 - 0.01, "hero: sales today include the three sales");
    ok($hero['orders']['today'] - $before['hero']['orders']['today'] === 3, 'hero: orders today +3');
    ok(!array_key_exists('vs_yesterday', $hero['sales']) && !array_key_exists('vs_last_week', $hero['sales']) && array_key_exists('trend7', $hero['sales']) && array_key_exists('yesterday', $hero['sales']),
        'hero never compares today with a full day: it shows yesterday and a 7-day trend of complete days');
    ok(near($hero['avg_bill']['today'], $hero['orders']['today'] > 0 ? $hero['sales']['today'] / $hero['orders']['today'] : 0, 0.02), 'average bill = sales ÷ orders');
    $hours = rdOrdersByHour($pdo, $ids, 30);
    ok($hours['total'] - $before['hours']['total'] === 3 && count($hours['hours']) === 24 && $hours['peak'] !== null, 'busy hours: 24 slots, +3 orders, a peak hour is named');
    ok(array_sum($hours['hours']) === $hours['total'], 'busy hours: the hours add up to the total');

    $T = (int)val("SELECT table_id FROM restaurant_tables WHERE warehouse_id = ? LIMIT 1", [$W]);
    if (!$T) { $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, ?, 4, 'available')")->execute([$W, restaurantDefaultFloorId($pdo, $W), "DBT$sfx"]); $T = (int)$pdo->lastInsertId(); }
    $h = call('api/pos/hold_sale.php', 'POST', ['reference' => 'T', 'items' => [$line($A, 1, 3000)], 'subtotal' => 3000, 'tax' => 0, 'warehouse_id' => $W, 'table_id' => $T], true);
    $pdo->prepare("UPDATE pos_held_sales SET held_at = NOW() - INTERVAL 3 HOUR WHERE hold_id = ?")->execute([$h['hold_id'] ?? 0]);
    $att = rdAttention($pdo, $ids, 2, 4);
    $types = array_column($att, 'type');
    ok(count($att) <= 3 && $types[0] === 'old_bills' && in_array('low_stock', $types, true), 'attention: an old open bill comes first, the list is capped at 3 (' . implode(', ', $types) . ')');
    $att2 = rdAttention($pdo, $ids, 2, 0);
    ok(in_array('no_cost', array_column($att2, 'type'), true) && !in_array('low_stock', array_column($att2, 'type'), true), 'attention: items with no cost are flagged; nothing invented when stock is fine');

    // ───────────────────────────────────────────────────────────────────────
    section('F2. Panel markup (in-process render)');
    $rdScope = 'restaurant';
    ob_start(); include "$root/includes/restaurant/dash_panel.php"; $html = ob_get_clean();
    ok(strpos($html, 'class="rdp"') !== false && strpos($html, 'Where your money went') !== false && strpos($html, 'What earns you money') !== false && strpos($html, 'Busy hours') !== false, 'panel has hero, profit story, earners and busy hours');
    $topName = $de['best_earners'][0]['name'] ?? null;
    ok($topName !== null && strpos($html, htmlspecialchars($topName)) !== false && strpos($html, 'Estimated') !== false, 'best earners lists the top earner; the profit card is marked Estimated while an item has no cost');
    ok(strpos($html, 'Needs your attention') !== false, 'the attention card shows');
    $rdScope = 'combined';
    ob_start(); include "$root/includes/restaurant/dash_panel.php"; $htmlC = ob_get_clean();
    ok(strpos($htmlC, 'data-scope="combined"') !== false && strpos($htmlC, 'Company profit this month') !== false && strpos($htmlC, 'Where your money went') === false, 'combined panel: company / restaurant / shops & other profit, no restaurant-only blocks');
    ok(!preg_match('/PHP (Warning|Notice|Fatal|Parse)|Undefined/', $html . $htmlC), 'no PHP warnings in either panel');
} finally {
    $pdo->endOuter();
}

// ───────────────────────────────────────────────────────────────────────────
// Subprocess helpers (the endpoint and pages call exit / need a real session). Shop modes are put back afterwards.
function runWorker(array $cfgExtra, array $features, array $modes, string $settingKey = '', string $settingVal = ''): string {
    global $root, $pdo;
    $orig = $pdo->query("SELECT warehouse_id, pos_mode FROM warehouses")->fetchAll(PDO::FETCH_KEY_PAIR);
    $origSimple = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    foreach ($modes as $wid => $m) $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$m, $wid]);
    $uid = (int)$pdo->query("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
    $cfg = tempnam(sys_get_temp_dir(), 'rdw');
    file_put_contents($cfg, json_encode($cfgExtra + ['session' => ['user_id' => $uid, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'], 'features' => $features]));
    $out = (string)shell_exec('php ' . escapeshellarg("$root/tests/test_restaurant_phase1_cli.php") . ' worker ' . escapeshellarg($cfg) . ' 2>&1');
    @unlink($cfg);
    foreach ($orig as $wid => $m) $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$m, $wid]);
    if ($origSimple === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    else $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$origSimple]);
    return $out;
}
function outJson(string $o): array { $j = json_decode(trim(explode('___OUT___', $o, 2)[1] ?? ''), true); return is_array($j) ? $j : ['_raw' => substr($o, 0, 300)]; }
$ON = ['pos' => true, 'restaurant_pos' => true]; $OFF = ['pos' => true, 'restaurant_pos' => false];
$allWh = array_map('intval', $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN));
$retailAll = array_fill_keys($allWh, 'retail');
$mixed = [$W => 'restaurant'] + $retailAll;
$onlyRest = array_fill_keys($allWh, 'restaurant');
$api = 'api/restaurant/dashboard_panel.php';

section('F. Endpoint (subprocess)');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'panel', 'scope' => 'combined']], $ON, $mixed));
ok(!empty($j['success']) && strpos($j['html'] ?? '', 'data-scope="combined"') !== false, 'mixed company: scope=combined returns the panel HTML');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'panel', 'scope' => 'restaurant']], $ON, $mixed));
ok(!empty($j['success']) && strpos($j['html'] ?? '', 'Where your money went') !== false, 'mixed company: scope=restaurant returns the restaurant panel');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'panel', 'scope' => 'company']], $ON, $mixed));
ok(empty($j['success']), 'scope=company is refused for a mixed company (it is only for a restaurant-only company)');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'panel', 'scope' => 'restaurant']], $ON, $onlyRest));
ok(empty($j['success']), 'scope=restaurant is refused for a restaurant-only company');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'panel', 'scope' => 'company']], $ON, $onlyRest));
ok(!empty($j['success']) && strpos($j['html'] ?? '', 'data-scope="company"') !== false, 'restaurant-only company: scope=company returns the panel');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'trend', 'scope' => 'restaurant', 'period' => 'weekly']], $ON, $mixed));
ok(!empty($j['success']) && count($j['trend']['keys'] ?? []) === 12 && count($j['trend']['profit'] ?? []) === 12, 'trend: weekly returns 12 buckets with sales, costs and profit');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'trend', 'scope' => 'combined', 'period' => 'monthly']], $ON, $mixed));
ok(!empty($j['success']) && isset($j['trend']['profit_r'], $j['trend']['profit_o']) && count($j['trend']['keys']) === 12, 'trend: combined returns the restaurant / shops split');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'trend', 'scope' => 'restaurant', 'period' => 'nonsense']], $ON, $mixed));
ok(!empty($j['success']) && count($j['trend']['keys'] ?? []) === 30, 'trend: an unknown period falls back to daily (never an error or SQL)');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'panel', 'scope' => 'combined']], $OFF, $mixed));
ok(empty($j['success']), 'Restaurant module off: the endpoint refuses');
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'panel', 'scope' => 'restaurant'], 'session' => ['user_id' => 999999, 'is_admin' => false, 'role_id' => 999999]], $ON, $mixed));
ok(empty($j['success']), 'a user with no permissions is refused');
$src = file_get_contents("$root/$api");
ok(strpos($src, 'hasReportsAccess()') !== false && strpos($src, "canView('restaurant_pos')") !== false && strpos($src, 'restaurantSchemaReady($pdo)') !== false, 'the endpoint is gated: Restaurant POS + schema + reports access');
ok(!preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', preg_replace('#//.*|/\*.*?\*/#s', '', $src)), 'the endpoint is read-only (no INSERT / UPDATE / DELETE)');

// ───────────────────────────────────────────────────────────────────────────
section('G. Pages');
$clean = fn($o) => !preg_match('/PHP (Warning|Notice|Fatal|Parse)|___EXC___/', explode('___OUT___', $o)[0] ?? '');
$o = runWorker(['page' => 'app/dashboard.php'], $ON, $onlyRest);
ok($clean($o) && strpos($o, 'class="rd2-acts"') !== false && strpos($o, 'class="rdp rdp-l2"') !== false && strpos($o, 'Quick Links') === false, 'restaurant-only company: restaurant dashboard with the new panel, no shop Quick Links');
ok(strpos($o, 'class="rd2-orders') !== false && strpos($o, 'class="rd2-chart') !== false && strpos($o, 'data-pt="att"') !== false && strpos($o, 'class="rd2-dishes') !== false, 'restaurant-only (redesign 2026-11-03): latest orders, the Sales | Orders chart, needs attention and top dishes are on the page');
ok(strpos($o, 'Best sellers today') === false, 'restaurant-only: the old "Best sellers today" card is replaced by Best earners / Best sellers');
$o = runWorker(['page' => 'app/dashboard.php'], $ON, $mixed);
ok($clean($o) && strpos($o, 'Quick Links') !== false && strpos($o, 'Restaurant today') !== false && strpos($o, 'id="rdMixedSwitch"') !== false && strpos($o, 'data-v="combined"') !== false && strpos($o, 'data-v="shop"') !== false,
    'mixed company: shop dashboard + "Restaurant today" + the All | Restaurant | Shop switch');
ok(strpos($o, 'class="rd-actions') === false, 'mixed company: the shop dashboard is not replaced');
$o = runWorker(['page' => 'app/dashboard.php'], $OFF, $mixed);
ok($clean($o) && strpos($o, 'Quick Links') !== false && strpos($o, 'rdMixed') === false && strpos($o, 'class="rdp"') === false && strpos($o, 'Restaurant today') === false, 'Restaurant module off: nothing restaurant on the dashboard');
$o = runWorker(['page' => 'app/dashboard.php'], $ON, $retailAll);
ok($clean($o) && strpos($o, 'Quick Links') !== false && strpos($o, 'rdMixed') === false && strpos($o, 'class="rdp"') === false && strpos($o, 'Restaurant today') === false, 'module on but every shop retail (a retail-only company): nothing restaurant on the dashboard');
$retail = $o;
ok(strpos(file_get_contents("$root/app/dashboard.php"), 'restaurant_insights') === false && strpos(file_get_contents("$root/app/dashboard.php"), 'dash_panel') === false, 'app/dashboard.php (the retail dashboard) has no reference to the new files');
$parse = function (string $html) {
    $h = preg_replace('#<!--.*?-->#s', '', explode('___OUT___', $html, 2)[1] ?? '');
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $h, $m); $bad = 0;
    foreach ($m[1] as $i => $js) {
        if (trim($js) === '' || strpos($js, 'application/ld+json') !== false) continue;
        $f = tempnam(sys_get_temp_dir(), 'djs') . '.js'; file_put_contents($f, $js);
        exec('node --check ' . escapeshellarg($f) . ' 2>&1', $oo, $rc); @unlink($f);
        if ($rc !== 0) { $bad++; echo "     script #$i: " . implode(' ', array_slice($oo, 0, 3)) . "\n"; } $oo = [];
    }
    return [$bad, count($m[1])];
};
[$bad, $n] = $parse(runWorker(['page' => 'app/dashboard.php'], $ON, $onlyRest));
ok($bad === 0, "every inline script of the restaurant-only dashboard parses ($n scripts)");
[$bad, $n] = $parse(runWorker(['page' => 'app/dashboard.php'], $ON, $mixed));
ok($bad === 0, "every inline script of the mixed dashboard parses ($n scripts)");
$j = outJson(runWorker(['page' => $api, 'get' => ['part' => 'panel', 'scope' => 'restaurant']], $ON, $mixed));
$ph = '<?php ?>' . '___OUT___' . ($j['html'] ?? '');
[$bad, $n] = $parse($ph);
ok($bad === 0, "every inline script of the loaded panel parses ($n scripts)");
