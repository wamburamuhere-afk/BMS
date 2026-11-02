<?php
/**
 * tests/test_restaurant_focus_cli.php — restaurant focus pass (2026-11-02).
 *
 * A restaurant-only Smart POS company sees only restaurant things. Everything else — full BMS, retail Smart POS,
 * Hybrid and mixed companies — is proven unchanged.
 *
 *   P0. A shop's POS Mode (Retail / Restaurant / Hybrid) exists only in Smart POS, never in full BMS.
 *   P1. Menus: Orders / Menu / Purchases / Customers for a restaurant-only company; Comms gone from Smart POS.
 *   P2. The till for a Restaurant shop (Hybrid and Retail unchanged).
 *   P3. Purchases: receive stock / ingredients / weighted-average cost / pay later.
 *   P4. Reports and Selling Settings.
 *   L.  Language: every new label is translated — Swahili mode shows no English leftovers.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/feature_registry.php";
require_once "$root/core/pos_nav.php";

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

$pdo = $GLOBALS['pdo'];
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
function val(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

/**
 * Runs a page in a subprocess (the phase-1 worker) with the company set up as asked, then puts EVERYTHING back:
 * every shop's POS Mode and the Smart POS (Simple Mode) setting.
 *   $modes   warehouse_id => 'retail'|'restaurant'|'hybrid'
 *   $simple  '1' Smart POS, '0' full BMS
 */
function runWorker(array $cfgExtra, array $features, array $modes, string $simple = '1', string $lang = 'en'): string {
    global $root, $pdo;
    $orig = $pdo->query("SELECT warehouse_id, pos_mode FROM warehouses")->fetchAll(PDO::FETCH_KEY_PAIR);
    $q = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'"); $origSimple = $q->fetchColumn();
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$simple]);
    foreach ($modes as $wid => $m) $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$m, $wid]);
    $uid = (int)$pdo->query("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
    $cfg = tempnam(sys_get_temp_dir(), 'rfw');
    file_put_contents($cfg, json_encode($cfgExtra + ['session' => ['user_id' => $uid, 'is_admin' => true, 'role_id' => 1, 'user_lang' => $lang, 'csrf_token' => 'tk'], 'features' => $features]));
    $out = (string)shell_exec('php ' . escapeshellarg("$root/tests/test_restaurant_phase1_cli.php") . ' worker ' . escapeshellarg($cfg) . ' 2>&1');
    @unlink($cfg);
    foreach ($orig as $wid => $m) $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$m, $wid]);
    if ($origSimple === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    else $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$origSimple]);
    return $out;
}
function body(string $o): string { return explode('___OUT___', $o, 2)[1] ?? ''; }
function clean(string $o): bool { return !preg_match('/PHP (Warning|Notice|Fatal|Parse)|___EXC___/', explode('___OUT___', $o)[0] ?? ''); }

$ON  = ['pos' => true, 'restaurant_pos' => true];
$OFF = ['pos' => true, 'restaurant_pos' => false];
$allWh = array_map('intval', $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN));
if (!$allWh) { ok(false, 'fixture: at least one active shop'); exit; }
$W = $allWh[0];
$retailAll = array_fill_keys($allWh, 'retail');
$onlyRest  = array_fill_keys($allWh, 'restaurant');
$mixed     = [$W => 'restaurant'] + $retailAll;
$hybridAll = array_fill_keys($allWh, 'hybrid');

// ═══════════════════════════════════════════════════════════════════════════
section('P0. POS Mode only in Smart POS — the rule');
$prevFeat = $GLOBALS['__bms_features'] ?? null;
// get_setting() caches per process, so the feature flags are varied here with Smart POS on; the Smart POS /
// full BMS dimension is proven below by rendering the real forms in separate processes.
$pdo->beginTransaction();
$pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
$truth = [];
foreach ([[true, true], [true, false], [false, true], [false, false]] as [$pos, $rest]) {
    $GLOBALS['__bms_features'] = ['pos' => $pos, 'restaurant_pos' => $rest];
    $truth[] = [$pos, $rest, posModeSelectable()];
}
$pdo->rollBack();
$GLOBALS['__bms_features'] = $prevFeat;
foreach ($truth as [$pos, $rest, $got]) {
    $want = $pos && $rest;
    ok($got === $want, 'Smart POS, POS ' . ($pos ? 'on' : 'off') . ', Restaurant POS ' . ($rest ? 'on' : 'off') . ' → POS Mode choice ' . ($want ? 'offered' : 'NOT offered'));
}

$o = runWorker(['page' => 'app/bms/stock/warehouses.php'], $ON, $retailAll, '1');
ok(clean($o) && strpos(body($o), 'id="pos_mode"') !== false && strpos(body($o), 'value="restaurant"') !== false, 'Smart POS + Restaurant POS: the Add Shop form has the POS Mode choice');
$o = runWorker(['page' => 'app/bms/stock/warehouses.php'], $ON, $retailAll, '0');
ok(clean($o) && strpos(body($o), 'id="pos_mode"') === false && strpos(body($o), 'name="pos_mode"') === false, 'full BMS (Simple Mode off) with Restaurant POS on: NO POS Mode choice in the Add Shop form');
$o = runWorker(['page' => 'app/bms/stock/warehouses.php'], $OFF, $retailAll, '1');
ok(clean($o) && strpos(body($o), 'id="pos_mode"') === false, 'Restaurant POS off: no POS Mode choice (as before)');
$whId = $W;
$o = runWorker(['page' => 'ajax_get_warehouse.php', 'get' => ['id' => $whId]], $ON, $retailAll, '1');
ok(strpos(body($o), 'edit_pos_mode') !== false, 'Smart POS: the Edit Shop form (ajax_get_warehouse.php) has the POS Mode choice');
$o = runWorker(['page' => 'ajax_get_warehouse.php', 'get' => ['id' => $whId]], $ON, $retailAll, '0');
ok(strpos(body($o), 'edit_pos_mode') === false, 'full BMS: the Edit Shop form has no POS Mode choice');

// A real "Add Shop" POST, in a subprocess. Smart POS honours the mode; full BMS forces Retail whatever is posted.
$mk = function (string $simple, string $name, string $mode) use ($pdo, $ON, $retailAll) {
    $code = 'RF' . substr(md5($name . microtime(true)), 0, 6);
    $post = ['add_warehouse' => '1', 'csrf_token' => 'tk', 'warehouse_name' => $name, 'warehouse_code' => $code, 'status' => 'active', 'pos_mode' => $mode, 'capacity' => '5'];
    runWorker(['page' => 'app/bms/stock/warehouses.php', 'post' => $post], $ON, $retailAll, $simple);
    $r = $pdo->prepare("SELECT warehouse_id, pos_mode FROM warehouses WHERE warehouse_name = ?"); $r->execute([$name]);
    return $r->fetch(PDO::FETCH_ASSOC) ?: null;
};
$created = [];
$n1 = 'RF Smart ' . substr(md5((string)microtime(true)), 0, 5); $c1 = $mk('1', $n1, 'restaurant'); if ($c1) $created[] = (int)$c1['warehouse_id'];
$n2 = 'RF Full ' . substr(md5((string)microtime(true) . 'x'), 0, 5); $c2 = $mk('0', $n2, 'restaurant'); if ($c2) $created[] = (int)$c2['warehouse_id'];
$n3 = 'RF Hyb ' . substr(md5((string)microtime(true) . 'y'), 0, 5); $c3 = $mk('1', $n3, 'hybrid'); if ($c3) $created[] = (int)$c3['warehouse_id'];
ok($c1 !== null && $c1['pos_mode'] === 'restaurant', 'Smart POS: a new shop posted as Restaurant is saved as Restaurant');
ok($c3 !== null && $c3['pos_mode'] === 'hybrid', 'Smart POS: a new shop posted as Hybrid is saved as Hybrid');
ok($c2 !== null && $c2['pos_mode'] === 'retail', 'full BMS: a posted Restaurant mode is ignored — the shop is saved as Retail');
foreach ($created as $id) {   // leave no shops behind
    $pdo->prepare("DELETE FROM locations WHERE warehouse_id = ?")->execute([$id]);
    foreach (['user_warehouse_access', 'user_scope_warehouses'] as $t) { try { $pdo->prepare("DELETE FROM $t WHERE warehouse_id = ?")->execute([$id]); } catch (Throwable $e) {} }
    $pdo->prepare("DELETE FROM warehouses WHERE warehouse_id = ?")->execute([$id]);
}
ok((int)val("SELECT COUNT(*) FROM warehouses WHERE warehouse_name IN (?, ?, ?)", [$n1, $n2, $n3]) === 0, 'the test shops are removed again');

// Every place that decides this uses the one helper; the old, unguarded condition is gone.
$sites = ['app/bms/stock/warehouses.php' => 3, 'ajax_get_warehouse.php' => 1, 'api/mobile/warehouses/create.php' => 1, 'api/mobile/warehouses/update.php' => 1];
foreach ($sites as $f => $n) {
    $s = file_get_contents("$root/$f");
    ok(substr_count($s, 'posModeSelectable()') >= $n && strpos($s, "tenantFeatureEnabled('restaurant_pos')") === false, "$f: uses posModeSelectable() ({$n} time(s)), no old unguarded condition");
}
ok(strpos(file_get_contents("$root/core/pos_nav.php"), 'tenantFeatureEnabled(\'pos\') && tenantFeatureEnabled(\'restaurant_pos\') && posSimpleModeEnabled()') !== false, 'the helper = POS on + Restaurant POS on + Smart POS on');
$s = file_get_contents("$root/api/mobile/warehouses/update.php");
ok(preg_match('/posModeSelectable\(\)\)\s*\{\s*\$sets\[\] = \'pos_mode = \?\'/', $s) === 1, 'mobile update: pos_mode is only written when the choice is offered (otherwise the stored mode is kept)');
$s = file_get_contents("$root/api/mobile/warehouses/create.php");
ok(preg_match('/if \(!posModeSelectable\(\)\) \{\s*\$pos_mode = \'retail\';/', $s) === 1, 'mobile create: a new shop is Retail unless the choice is offered');

// ═══════════════════════════════════════════════════════════════════════════
section('P1. Menus — restaurant-only company vs everything else');
// The desktop bar only (between the main <ul> and the user-account <ul>), the phone side menu, and the phone reports sheet.
$navOf = function (string $o): string {
    $h = body($o);
    $a = strpos($h, '<ul class="navbar-nav me-auto">'); $b = strpos($h, 'User Account (right side');
    return ($a !== false && $b !== false && $b > $a) ? preg_replace('#<!--.*?-->#s', '', substr($h, $a, $b - $a)) : '';
};
// the phone side menu's group headings, e.g. ['Orders', 'Menu', 'Purchases', 'Customers']
$sideOf = function (string $o): array {
    preg_match_all('#<div class="bn-group">([^<]*)</div>#', preg_replace('#<!--.*?-->#s', '', body($o)), $m);
    return array_map('html_entity_decode', $m[1]);
};
$page = ['page' => 'app/dashboard.php'];
$rest = runWorker($page, $ON, $onlyRest, '1');
$nav = $navOf($rest);
ok(clean($rest) && $nav !== '', 'restaurant-only company: the dashboard renders with a desktop menu, no PHP warnings');
foreach (['rfOrdersDropdown', 'rfMenuDropdown', 'rfBuyDropdown'] as $id) ok(strpos($nav, "id=\"$id\"") !== false, "restaurant-only: the $id menu is there");
foreach (['Take order', 'Tables', 'Kitchen', 'Reservations', 'Who Owes Me', 'Menu items', 'Menu groups', 'Add-on options', 'Buy stock & ingredients', 'Suppliers', 'Customers', 'Restaurant Sales'] as $lbl) {
    ok(strpos($nav, '> ' . $lbl . '</a>') !== false || strpos($nav, '>' . $lbl . '</a>') !== false || strpos($nav, $lbl . '</a>') !== false, "restaurant-only menu has: $lbl");
}
foreach (['aria-labelledby="coreDropdown"', 'My Business', 'Shop Products', 'simpleShopDropdown', 'id="restaurantDropdown"', 'communicationDropdown', 'Comms', 'Campaigns', 'Service', 'Inventory Report'] as $gone) {
    ok(strpos($nav, $gone) === false, "restaurant-only menu does NOT have: $gone");
}
ok(preg_match('#>\s*(?:<i[^>]*></i>)?\s*Sell\s*</a>#', $nav) === 0, 'restaurant-only menu has no stand-alone "Sell" link (Orders → Take order replaces it)');
$posRep = strpos($nav, 'Restaurant Sales'); $posSales = strpos($nav, 'Sales Report');
ok($posRep !== false && ($posSales === false || $posRep < $posSales), 'Reports: Restaurant Sales comes first');
ok(preg_match('#getUrl|href="[^"]*warehouses"#', $nav) === 1 || strpos($nav, 'warehouses') !== false, 'Settings holds the Shops page');
$side = $sideOf($rest);
foreach (['Orders', 'Menu', 'Purchases'] as $g) ok(in_array($g, $side, true), "phone side menu has the group: $g");
ok(!in_array('Sales', $side, true) && !in_array('My Business', $side, true), 'phone side menu has no retail Sales / My Business groups');
$sheet = body($rest); $sa = strpos($sheet, 'id="bmsReportsSheet"');
ok($sa !== false && strpos(substr($sheet, $sa, 3500), 'Inventory Report') === false, 'phone Reports sheet has no Inventory Report');

// Everything that is NOT restaurant-only keeps its menu.
$legacyMustHave = ['aria-labelledby="coreDropdown"', '/products"', '/suppliers"', '/customers"'];   // the links (their labels follow the company's Shop / Warehouse wording)
$cases = [
  'retail Smart POS (every shop Retail)'    => [$retailAll, '1', true],
  'mixed company (one Restaurant shop)'     => [$mixed, '1', true],
  'every shop Hybrid'                       => [$hybridAll, '1', true],
];
foreach ($cases as $name => [$modes, $simple]) {
    $o = runWorker($page, $ON, $modes, $simple); $n = $navOf($o);
    ok(clean($o) && $n !== '', "$name: renders");
    $miss = array_filter($legacyMustHave, fn($x) => strpos($n, $x) === false);
    ok(!$miss, "$name: My Business keeps its Customers, Suppliers and Products links (" . ($miss ? 'missing ' . implode(', ', $miss) : 'all present') . ')');
    ok(strpos($n, 'rfOrdersDropdown') === false && strpos($n, 'rfMenuDropdown') === false && strpos($n, 'rfBuyDropdown') === false, "$name: none of the restaurant-only menus");
    ok(strpos($n, 'simpleShopDropdown') !== false || strpos($n, '>Shop<') !== false || strpos($n, 'Shop</a>') !== false || strpos($n, 'Receive Stock') !== false, "$name: the Shop menu (Shops / Receive Stock) is still there");
    ok(strpos($n, 'communicationDropdown') === false && strpos($n, 'Campaigns') === false, "$name: Comms is gone from Smart POS");
    $sd = $sideOf($o);
    ok(!in_array('Purchases', $sd, true) && !in_array('Orders', $sd, true), "$name: the phone side menu has no restaurant-only groups");
}
$o = runWorker($page, $ON, $mixed, '1'); $n = $navOf($o);
ok(strpos($n, 'restaurantDropdown') !== false, 'mixed company: the usual Restaurant dropdown is still there');
$o = runWorker($page, $ON, $retailAll, '0'); $n = $navOf($o);
ok(clean($o) && $n !== '' && strpos($n, 'rfOrdersDropdown') === false, 'full BMS (Simple Mode off): no restaurant-only menus');
ok(strpos($n, 'communicationDropdown') !== false, 'full BMS: the Comms menu is still there (only Smart POS loses it)');
$o = runWorker($page, $OFF, $onlyRest, '1'); $n = $navOf($o);
ok(clean($o) && strpos($n, 'rfOrdersDropdown') === false, 'Restaurant POS off: no restaurant-only menus even if every shop is Restaurant');

// ── Language: Swahili mode shows Swahili labels only — no English leftovers from this pass ──────────────────────
$sw = require "$root/lang/sw.php";
$swRest = runWorker($page, $ON, $onlyRest, '1', 'sw'); $swNav = $navOf($swRest);
ok($swNav !== '', 'restaurant-only company in Swahili renders');
$labels = ['Orders', 'Take order', 'Tables', 'Kitchen', 'Reservations', 'Who Owes Me', 'Menu', 'Menu items', 'Menu groups', 'Add-on options', 'Purchases', 'Buy stock & ingredients', 'Suppliers', 'Customers'];
foreach ($labels as $k) {
    $tr = $sw[$k] ?? null;
    $hasSw = $tr !== null && strpos($swNav, $tr) !== false;
    $leak = ($tr !== null && $tr !== $k) && preg_match('#</i>\s*' . preg_quote($k, '#') . '\s*</a>#', $swNav) === 1;
    ok($hasSw && !$leak, "Swahili: '$k' shows as '" . ($tr ?? '(no translation!)') . "'" . ($leak ? ' — but the English label is also shown' : ''));
}
$enRest = runWorker($page, $ON, $onlyRest, '1', 'en'); $enNav = $navOf($enRest);
ok(strpos($enNav, 'Take order') !== false && strpos($enNav, ($sw['Take order'] ?? '§§')) === false, 'English: the same menu shows English only (no Swahili mixed in)');
$leaks = [];
foreach (['Menu items', 'Menu groups', 'Add-on options', 'Purchases', 'Orders', 'Take order'] as $k) { if (!isset($sw[$k]) || $sw[$k] === $k) $leaks[] = $k; }
ok(!$leaks, 'every new menu label has its own Swahili text' . ($leaks ? ' — missing: ' . implode(', ', $leaks) : ''));

// ═══════════════════════════════════════════════════════════════════════════
section('P2. The till for a Restaurant shop — server side (real endpoint, rolled back)');
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
class InputStub { public static string $body = ''; public $context; private int $pos = 0;
    public function stream_open($p, $m, $o, &$op) { $this->pos = 0; return true; }
    public function stream_read($n) { $r = substr(self::$body, $this->pos, $n); $this->pos += strlen($r); return $r; }
    public function stream_eof() { return $this->pos >= strlen(self::$body); } public function stream_stat() { return []; } }
$pdo = new SavepointPDO('mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->exec("SET time_zone = '+03:00'"); $GLOBALS['pdo'] = $pdo;
function callApi(string $file, array $get): array {
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = $get; $_POST = [];
    ob_start();
    try { (function () use ($file) { global $pdo; include $GLOBALS['root'] . '/' . $file; })(); } catch (Throwable $e) { echo json_encode(['success' => false, 'message' => 'EXC: ' . $e->getMessage()]); }
    $out = ob_get_clean(); $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($out, 0, 200)];
}
$admin = (int)val("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1");
$_SESSION['user_id'] = $admin; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true; $_SESSION['user_lang'] = 'en';
$GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true];
require_once "$root/core/restaurant_scope.php";
$stk = $pdo->query("SELECT ps.product_id, ps.warehouse_id FROM product_stocks ps JOIN products p ON p.product_id = ps.product_id WHERE ps.stock_quantity >= 1 AND p.is_service = 0 AND p.status = 'active' AND COALESCE(p.is_menu_item,0) = 0 ORDER BY ps.stock_quantity DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$stk) { ok(false, 'fixture: a stocked non-menu product'); exit; }
$W2 = (int)$stk['warehouse_id']; $RETAILP = (int)$stk['product_id'];
$pdo->startOuter();
try {
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $DISH = restaurantCreateMenuItem($pdo, $W2, ['type' => 'dish', 'name' => "RF Pilau $sfx", 'price' => 3000, 'prep_area' => 'kitchen'], $admin);
    $ids = fn($r) => array_map(fn($p) => (int)$p['product_id'], $r['data'] ?? []);
    $set = fn($m) => $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$m, $W2]);

    $set('restaurant');
    ok(restaurantShopIsRestaurantOnly($pdo, $W2) === true, 'a plain Restaurant shop is recognised');
    $r1 = callApi('api/pos/simple_products.php', ['warehouse_id' => $W2]);
    ok(!empty($r1['success']) && in_array($DISH, $ids($r1), true) && !in_array($RETAILP, $ids($r1), true), 'Restaurant shop: with NO filter asked, only the Menu comes back (the retail/ingredient product is not offered)');
    $r1b = callApi('api/pos/simple_products.php', ['warehouse_id' => $W2, 'menu_only' => 0]);
    ok(!in_array($RETAILP, $ids($r1b), true), 'Restaurant shop: asking for "all products" does not bring them back');
    $ok1 = true; foreach ($ids($r1) as $pid) if (!(int)val("SELECT is_menu_item FROM products WHERE product_id = ?", [$pid])) $ok1 = false;
    ok($ok1, 'Restaurant shop: every product returned is a Menu item');
    $r1c = callApi('api/pos/simple_products.php', ['warehouse_id' => $W2, 'search' => (string)val("SELECT product_name FROM products WHERE product_id = ?", [$RETAILP])]);
    ok(!in_array($RETAILP, $ids($r1c), true), 'Restaurant shop: searching for the retail product by name still does not offer it');

    $set('hybrid');
    ok(restaurantShopIsRestaurantOnly($pdo, $W2) === false, 'a Hybrid shop is NOT a Restaurant-only shop');
    $r2 = callApi('api/pos/simple_products.php', ['warehouse_id' => $W2]);
    ok(in_array($DISH, $ids($r2), true) && in_array($RETAILP, $ids($r2), true), 'Hybrid shop: dishes AND retail products, as before');
    $set('retail');
    ok(restaurantShopIsRestaurantOnly($pdo, $W2) === false, 'a Retail shop is NOT a Restaurant-only shop');
    $r3 = callApi('api/pos/simple_products.php', ['warehouse_id' => $W2]);
    ok(in_array($RETAILP, $ids($r3), true), 'Retail shop: its products are listed exactly as before');
    $kt = false; foreach ($r3['data'] ?? [] as $p) if (array_key_exists('modifier_group_count', $p)) $kt = true;
    ok(!$kt, 'Retail shop: no new fields in the product list');
    $set('restaurant');
    $GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => false];
    ok(restaurantShopIsRestaurantOnly($pdo, $W2) === false, 'Restaurant POS off: not a Restaurant-only shop');
    $GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true];
} finally {
    $pdo->endOuter();
}

section('P2b. The till page — what each kind of shop gets');
$tillOf = fn($modes, $simple = '1', $feat = null) => runWorker(['page' => 'app/bms/pos/pos.php'], $feat ?? $ON, $modes, $simple);
$o = $tillOf([$W => 'restaurant'] + $retailAll); $b = body($o);
ok(clean($o) && preg_match('/const POS_REST_ONLY = \{"' . $W . '":true\};/', $b) === 1, 'a Restaurant shop is listed in POS_REST_ONLY');
foreach (['mixed: the other shops are not' => [[$W => 'restaurant'] + $retailAll, true]] as $lbl => [$m, $_]) {
    ok(preg_match('/const POS_REST_ONLY = \{[^}]*\};/', $b, $mm) === 1 && substr_count($mm[0], 'true') === 1, 'only the Restaurant shop is listed — the retail shops are not');
}
foreach (['Hybrid shop' => [[$W => 'hybrid'] + $retailAll, '1', $ON], 'Retail shops' => [$retailAll, '1', $ON], 'full BMS (Simple Mode off)' => [[$W => 'restaurant'] + $retailAll, '0', $ON], 'Restaurant POS off' => [[$W => 'restaurant'] + $retailAll, '1', $OFF]] as $lbl => [$m, $sm, $ft]) {
    $o = runWorker(['page' => 'app/bms/pos/pos.php'], $ft, $m, $sm);
    ok(clean($o) && strpos(body($o), 'const POS_REST_ONLY = {};') !== false, "$lbl: POS_REST_ONLY is empty — no Menu-only till");
}
$till = file_get_contents("$root/app/bms/pos/pos_scripts_new.php"); $tillPhp = file_get_contents("$root/app/bms/pos/pos.php");
ok(strpos($till, 'function posRestaurantOnlyShop() { return !!POS_REST_ONLY[') !== false && strpos($till, 'function posMenuModeOn() { return posRestaurantActive() && (posMenuOnly || posRestaurantOnlyShop()); }') !== false, 'Menu view is forced only for a Restaurant-only shop (and only where the restaurant is active)');
ok(strpos($till, "\$('#posMenuSwitchGroup').toggleClass('pos-rest-hide', restOnly);") !== false, 'the Menu | All products choice is hidden for a Restaurant-only shop');
foreach (['.pos-restock-btn', '#addProductDropdown', '#scannerReadyBadge', 'button[onclick="holdSale()"]', 'button[onclick="showHeldSales()"]', '#loyaltyPointsSection', '#posPriceGroupId'] as $sel) {
    ok(strpos($till, $sel) !== false, "Restaurant-only till hides: $sel");
}
ok(strpos($till, 'if (posRestaurantOnlyShop()) return;   // a Restaurant shop sells from its Menu') !== false, 'barcode scanning does nothing on a Restaurant-only shop');
ok(strpos($till, 'if (posMenuModeOn() && !posRestaurantOnlyShop() && !searchTerm') !== false && strpos($till, 'PT.menuEmpty') !== false, 'an empty Menu on a Restaurant shop says so — it never falls back to retail products');
ok(strpos($till, 'const allLabel = PT.allShort;') !== false && strpos($till, 'allShort: ') !== false, 'the first Menu group chip reads "All" (not "All Products")');
ok(strpos($tillPhp, '.pos-rest-hide { display: none !important; }') !== false, 'the hiding class exists and is only ever added by the till script for a Restaurant-only shop');
$apm = substr($till, (int)strpos($till, 'function applyPosModeUi('), 3200);
ok(substr_count($apm, "toggleClass('pos-rest-hide', restOnly)") >= 3 && strpos($apm, "#loyaltyPointsSection').removeClass") === false, 'controls are hidden with their own class; the loyalty box\'s own d-none (set by the loyalty code) is never touched');

// The hiding rule must be GLOBAL. (It was first put inside a phone-only @media block, so on a desktop the retail
// controls stayed visible — caught in the browser. Brace depth at the rule must be 0.)
$css = $tillPhp; $pos = strpos($css, '.pos-rest-hide { display: none !important; }');
$depth = null;
if ($pos !== false) {
    $head = preg_replace('#/\*.*?\*/#s', '', substr($css, 0, $pos));          // comments out
    $st = strrpos($head, '<style');                                            // from the start of the style block holding the rule
    $seg = $st === false ? $head : substr($head, $st);
    $depth = substr_count($seg, '{') - substr_count($seg, '}');
}
ok($pos !== false && $depth === 0, 'the .pos-rest-hide rule is a global rule (not inside a media query): brace depth ' . var_export($depth, true));
// Every inline script of the rendered till parses (restaurant-only + retail)
foreach (['Restaurant-only shop' => [$W => 'restaurant'] + $retailAll, 'Retail shops' => $retailAll] as $lbl => $m) {
    $o = $tillOf($m); $h = preg_replace('#<!--.*?-->#s', '', body($o)); preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $h, $mm); $bad = 0;
    foreach ($mm[1] as $js) { if (trim($js) === '' || strpos($js, 'application/ld+json') !== false) continue; $tmp = tempnam(sys_get_temp_dir(), 'tjs') . '.js'; file_put_contents($tmp, $js); exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $oo, $rc); @unlink($tmp); if ($rc !== 0) { $bad++; echo '     ' . implode(' ', array_slice($oo, 0, 2)) . "\n"; } $oo = []; }
    ok($bad === 0, "$lbl: every inline script of the till parses (" . count($mm[1]) . ' scripts)');
}

// ═══════════════════════════════════════════════════════════════════════════
section('P3. Purchases — weighted-average cost and ingredients (core logic, real code, rolled back)');
require_once "$root/core/restaurant_purchases.php";
require_once "$root/core/restaurant_insights.php";
require_once "$root/core/financial_reports.php";
function near3(float $a, float $b, float $e = 0.011): bool { return abs($a - $b) <= $e; }
ok(near3(restaurantWeightedAvgCost(10, 600, 10, 700), 650), 'weighted average: 10 on hand at 600 + 10 bought at 700 → cost 650');
ok(near3(restaurantWeightedAvgCost(10, 400, 5, 500), 433.33), 'weighted average: 10 at 400 + 5 at 500 → 433.33');
ok(near3(restaurantWeightedAvgCost(0, 999, 4, 700), 700), 'nothing on hand → the new price becomes the cost');
ok(near3(restaurantWeightedAvgCost(-3, 999, 4, 700), 700), 'negative stock (an ingredient used beyond what was recorded) counts as nothing on hand');
ok(near3(restaurantWeightedAvgCost(10, 600, 0, 700), 600), 'buying nothing leaves the cost alone');

$cashAcc = (int)(cashBankAccounts($pdo)[0]['account_id'] ?? 0);
$callJson = function (string $file, array $data): array {
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = []; $_POST = []; InputStub::$body = json_encode($data);
    stream_wrapper_unregister('php'); stream_wrapper_register('php', 'InputStub'); ob_start();
    try { (function () use ($file) { global $pdo; include $GLOBALS['root'] . '/' . $file; })(); } catch (Throwable $e) { echo json_encode(['success' => false, 'message' => 'EXC: ' . $e->getMessage()]); }
    finally { $o = ob_get_clean(); stream_wrapper_restore('php'); }
    $j = json_decode($o, true); return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($o, 0, 200)];
};
$one = function (string $sql, array $p = []) use ($pdo) { $x = $pdo->prepare($sql); $x->execute($p); return $x->fetch(PDO::FETCH_ASSOC); };
$throws = function (callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); } };

$pdo->startOuter();
try {
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true];
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W2]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    // an ingredient (no selling price, cost 400, 10 kg), a bought Menu drink (cost 600, 10), a dish, and the plain retail product from before
    $pdo->prepare("INSERT INTO products (product_name, unit, unit_of_measure, cost_price, purchase_price, selling_price, min_selling_price, is_service, track_inventory, warehouse_id, is_menu_item, status, created_by, stock_quantity, current_stock)
                   VALUES (?, 'kg', 'kg', 400, 400, 0, 0, 0, 1, ?, 0, 'active', ?, 10, 10)")->execute(["RF Rice $sfx", $W2, $admin]);
    $RICE = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, 10)")->execute([$RICE, $W2]);
    $SODA = restaurantCreateMenuItem($pdo, $W2, ['type' => 'bought', 'name' => "RF Soda $sfx", 'price' => 1500, 'cost' => 600, 'opening' => 10, 'prep_area' => 'none'], $admin);
    $DISH2 = restaurantCreateMenuItem($pdo, $W2, ['type' => 'dish', 'name' => "RF Pilau $sfx", 'price' => 5000, 'prep_area' => 'kitchen'], $admin);
    $pdo->prepare("INSERT INTO products (product_name, unit, unit_of_measure, cost_price, purchase_price, selling_price, min_selling_price, is_service, track_inventory, warehouse_id, is_menu_item, status, created_by) VALUES (?, 'pcs', 'pcs', 300, 300, 900, 0, 0, 1, ?, 0, 'active', ?)")->execute(["RF Retail Bread $sfx", $W2, $admin]);
    $RETAILP = (int)$pdo->lastInsertId();   // an ordinary retail product: has a selling price, not on the Menu, not in any recipe
    $inv = inventoryAccountId($pdo);
    $buy = fn(array $o) => restaurantBuyStock($pdo, $o + ['warehouse_id' => $W2, 'date' => date('Y-m-d'), 'paid_from_account_id' => $cashAcc], $admin);
    ok(near3(restaurantStockOnHand($pdo, $SODA), 10) && near3((float)val("SELECT cost_price FROM products WHERE product_id = ?", [$SODA]), 600), 'fixture: Soda 10 on hand at cost 600');

    // ── a drink: weighted-average cost, stock, batch, ledger ────────────────────────────────────────────────────────
    $r = $buy(['product_id' => $SODA, 'quantity' => 10, 'unit_cost' => 700]);
    ok(near3($r['old_cost'], 600) && near3($r['new_cost'], 650) && near3($r['total'], 7000), 'buy 10 Soda at 700: cost 600 → 650 (average), total 7,000');
    ok(near3((float)val("SELECT cost_price FROM products WHERE product_id = ?", [$SODA]), 650) && near3((float)val("SELECT purchase_price FROM products WHERE product_id = ?", [$SODA]), 700), 'the item now carries cost 650 and "last price paid" 700');
    ok(near3(restaurantStockOnHand($pdo, $SODA), 20) && near3((float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$SODA, $W2]), 20), 'stock went from 10 to 20');
    ok((float)val("SELECT selling_price FROM products WHERE product_id = ?", [$SODA]) == 1500.0, 'the selling price was not touched');
    $b = $one("SELECT quantity_received, unit_cost FROM product_batches WHERE batch_id = ?", [$r['batch_id']]);
    ok($b && near3((float)$b['quantity_received'], 10) && near3((float)$b['unit_cost'], 700), 'a stock batch was written (10 at 700)');
    $je = $one("SELECT entry_id, status FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ? ORDER BY entry_id DESC LIMIT 1", [$r['transaction_id']]);
    $legs = []; if ($je) { $ls = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ?"); $ls->execute([$je['entry_id']]); foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) $legs[$l['type']][(int)$l['account_id']] = (float)$l['amount']; }
    ok($je && $je['status'] === 'posted' && isset($legs['debit'][$inv]) && near3($legs['debit'][$inv], 7000) && isset($legs['credit'][$cashAcc]) && near3($legs['credit'][$cashAcc], 7000), 'ledger: Dr Inventory 7,000 / Cr the account it was paid from 7,000, posted' . ($je && $legs ? '' : ' [entry=' . json_encode($je) . ' legs=' . json_encode($legs) . ' inv=' . $inv . ' cash=' . $cashAcc . ']'));
    $mv = $one("SELECT notes FROM stock_movements WHERE movement_id = ?", [$r['movement_id']]);
    ok($mv && strpos((string)$mv['notes'], 'Restaurant purchase:') !== false, 'the stock movement is tagged as a restaurant purchase');
    $bal = assertLedgerBalanced($pdo);
    ok(!empty($bal['ledger_balanced']), 'the ledger is still balanced');
    $r1b = restaurantBuyStock($pdo, ['product_id' => $SODA, 'warehouse_id' => $W2, 'quantity' => 20, 'unit_cost' => 800, 'date' => date('Y-m-d')], $admin);
    ok(near3($r1b['new_cost'], 725) && near3($r1b['total'], 16000), 'no "paid from" given in Smart POS → it defaults to the cash account; average of 20 at 650 + 20 at 800 = 725');

    // ── an ingredient: no selling price needed; averages; zero / negative stock ───────────────────────────────────
    $r2 = $buy(['product_id' => $RICE, 'quantity' => 5, 'unit_cost' => 500]);
    ok(near3($r2['new_cost'], 433.33) && near3(restaurantStockOnHand($pdo, $RICE), 15), 'an ingredient with NO selling price can be bought: 10 kg at 400 + 5 kg at 500 → cost 433.33, 15 kg on hand');
    $pdo->prepare("UPDATE product_stocks SET stock_quantity = -3 WHERE product_id = ? AND warehouse_id = ?")->execute([$RICE, $W2]);
    $r3 = $buy(['product_id' => $RICE, 'quantity' => 4, 'unit_cost' => 600]);
    ok(near3($r3['new_cost'], 600), 'negative stock counts as nothing: the new price becomes the cost');
    $pdo->prepare("INSERT INTO products (product_name, unit, unit_of_measure, cost_price, purchase_price, selling_price, min_selling_price, is_service, track_inventory, warehouse_id, is_menu_item, status, created_by) VALUES (?, 'lt', 'lt', 0, 0, 0, 0, 0, 1, ?, 0, 'active', ?)")->execute(["RF Oil $sfx", $W2, $admin]);
    $OIL = (int)$pdo->lastInsertId();
    $r4 = $buy(['product_id' => $OIL, 'quantity' => 3, 'unit_cost' => 5500, 'expiry_date' => date('Y-m-d', strtotime('+90 days'))]);
    ok(near3($r4['new_cost'], 5500), 'a brand-new ingredient (no stock, no cost) takes the price paid');
    ok(val("SELECT expiry_date FROM product_batches WHERE batch_id = ?", [$r4['batch_id']]) === date('Y-m-d', strtotime('+90 days')), 'the optional expiry date is stored on the batch');
    $supp = (int)val("SELECT supplier_id FROM suppliers WHERE status = 'active' LIMIT 1");
    if ($supp) { $r5 = $buy(['product_id' => $OIL, 'quantity' => 1, 'unit_cost' => 5600, 'supplier_id' => $supp]); ok((int)val("SELECT supplier_id FROM product_batches WHERE batch_id = ?", [$r5['batch_id']]) === $supp, 'the optional supplier is stored on the batch'); }

    // ── refusals: nothing changes ─────────────────────────────────────────────────────────────────────────────
    $costBefore = (float)val("SELECT cost_price FROM products WHERE product_id = ?", [$SODA]); $stockBefore = restaurantStockOnHand($pdo, $SODA);
    $cases = [
        'a plain retail product (selling price, not on the Menu, not in a recipe)' => ['product_id' => $RETAILP, 'quantity' => 1, 'unit_cost' => 100],
        'a dish (not something you count)'                                         => ['product_id' => $DISH2, 'quantity' => 1, 'unit_cost' => 100],
        'quantity zero'                                                           => ['product_id' => $SODA, 'quantity' => 0, 'unit_cost' => 100],
        'a negative price'                                                        => ['product_id' => $SODA, 'quantity' => 1, 'unit_cost' => -5],
        'a date in the future'                                                    => ['product_id' => $SODA, 'quantity' => 1, 'unit_cost' => 100, 'date' => date('Y-m-d', strtotime('+3 days'))],
        'an expiry before the purchase date'                                      => ['product_id' => $SODA, 'quantity' => 1, 'unit_cost' => 100, 'expiry_date' => date('Y-m-d', strtotime('-5 days'))],
        'a "paid from" that is not a cash or bank account'                        => ['product_id' => $SODA, 'quantity' => 1, 'unit_cost' => 100, 'paid_from_account_id' => $inv],
        'a supplier that does not exist'                                          => ['product_id' => $SODA, 'quantity' => 1, 'unit_cost' => 100, 'supplier_id' => 99999999],
        'no item chosen'                                                          => ['product_id' => 0, 'quantity' => 1, 'unit_cost' => 100],
    ];
    foreach ($cases as $why => $o) { $m = $throws(fn() => $buy($o)); ok($m !== null && $m !== '', "refused — $why" . ($m ? ": \"$m\"" : '')); }
    ok(near3((float)val("SELECT cost_price FROM products WHERE product_id = ?", [$SODA]), $costBefore) && near3(restaurantStockOnHand($pdo, $SODA), $stockBefore), 'every refusal left cost and stock exactly as they were');
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W2]);
    $m = $throws(fn() => $buy(['product_id' => $SODA, 'quantity' => 1, 'unit_cost' => 100]));
    ok($m !== null, 'refused on a Retail shop — "' . $m . '"');
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W2]);

    // ── the point of it all: the dish's profit per plate follows the price you actually pay ────────────────────────
    $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, unit, qty_per_unit, total_qty) VALUES (?, ?, 'kg', 2, 2)")->execute([$DISH2, $RICE]);
    $pdo->prepare("UPDATE products SET is_combo = 1 WHERE product_id = ?")->execute([$DISH2]);
    $pdo->prepare("UPDATE cash_register_shifts SET status = 'closed' WHERE user_id = ? AND status = 'active'")->execute([$admin]);
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-RF3', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W2]);
    $sale = $callJson('api/pos/process_sale.php', ['customer_id' => null, 'warehouse_id' => $W2, 'project_id' => null,
        'items' => [['product_id' => $DISH2, 'product_name' => 'x', 'quantity' => 1, 'price' => 5000, 'discounted_price' => 5000, 'tax_rate' => 0]],
        'subtotal' => 5000, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => 5000, 'payment_method' => 'cash', 'amount_tendered' => 5000, 'change_given' => 0, 'amount_paid' => 5000, 'sale_type' => 'dine_in'], true);
    ok(!empty($sale['success']), 'fixture: the dish is sold' . (!empty($sale['success']) ? '' : ' — ' . ($sale['message'] ?? '')));
    $dishCost = function () use ($pdo, $W2, $DISH2) { foreach (rdDishEarnings($pdo, [$W2], date('Y-m-d'), date('Y-m-d'))['items'] as $it) if ($it['product_id'] === $DISH2) return $it['cost']; return null; };
    $c0 = $dishCost(); $ricePrice0 = (float)val("SELECT cost_price FROM products WHERE product_id = ?", [$RICE]);
    ok($c0 !== null && near3($c0, 2 * $ricePrice0), "dashboard: the dish costs 2 × the ingredient's cost (2 × $ricePrice0 = " . $c0 . ')');
    $rb = $buy(['product_id' => $RICE, 'quantity' => 10, 'unit_cost' => 1000]);
    $c1 = $dishCost();
    ok($c1 !== null && $c1 > $c0 && near3($c1, 2 * $rb['new_cost']), 'buy the ingredient dearer and the dish\'s cost (so its profit per plate) moves with it: ' . $c0 . ' → ' . $c1);
    $bal = assertLedgerBalanced($pdo);
    ok(!empty($bal['ledger_balanced']) && !empty($bal['bs_balanced']), 'the ledger and balance sheet are still balanced after all of it');
} finally {
    $pdo->endOuter();
}

// ═══════════════════════════════════════════════════════════════════════════
section('P3b. Purchases — search, history, endpoint gates, page, links');
$pdo->startOuter();
try {
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true];
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W2]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $pdo->prepare("INSERT INTO products (product_name, unit, unit_of_measure, cost_price, purchase_price, selling_price, min_selling_price, is_service, track_inventory, warehouse_id, is_menu_item, status, created_by) VALUES (?, 'kg', 'kg', 250, 250, 0, 0, 0, 1, ?, 0, 'active', ?)")->execute(["RFS Flour $sfx", $W2, $admin]);
    $FLOUR = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO products (product_name, unit, unit_of_measure, cost_price, purchase_price, selling_price, min_selling_price, is_service, track_inventory, warehouse_id, is_menu_item, status, created_by) VALUES (?, 'pcs', 'pcs', 300, 300, 900, 0, 0, 1, ?, 0, 'active', ?)")->execute(["RFS Bread $sfx", $W2, $admin]);
    $BREAD = (int)$pdo->lastInsertId();
    $JUICE = restaurantCreateMenuItem($pdo, $W2, ['type' => 'bought', 'name' => "RFS Juice $sfx", 'price' => 2000, 'cost' => 900, 'opening' => 5, 'prep_area' => 'none'], $admin);
    $DISHX = restaurantCreateMenuItem($pdo, $W2, ['type' => 'dish', 'name' => "RFS Dish $sfx", 'price' => 4000, 'prep_area' => 'kitchen'], $admin);
    $ids3 = fn($r) => array_map(fn($x) => (int)$x['id'], $r['results'] ?? []);

    $sr = callApi('api/restaurant/search_stock_items.php', ['warehouse_id' => $W2, 'q' => "RFS"]);
    ok(in_array($FLOUR, $ids3($sr), true) && in_array($JUICE, $ids3($sr), true), 'search: an ingredient (no selling price) and a bought Menu drink are offered');
    ok(!in_array($BREAD, $ids3($sr), true) && !in_array($DISHX, $ids3($sr), true), 'search: ordinary retail stock and a dish are NOT offered');
    $row = null; foreach ($sr['results'] as $x) if ($x['id'] === $FLOUR) $row = $x;
    ok($row && $row['unit'] === 'kg' && near3($row['cost_price'], 250) && strpos($row['text'], 'RFS Flour') !== false && strpos($row['text'], '(kg)') !== false, 'search: each result carries its unit, current cost and stock');
    $sr2 = callApi('api/restaurant/search_stock_items.php', ['warehouse_id' => $W2, 'q' => "Juice $sfx"]);
    ok($ids3($sr2) === [$JUICE], 'search: the name filter narrows the list');
    $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, unit, qty_per_unit, total_qty) VALUES (?, ?, 'pcs', 1, 1)")->execute([$DISHX, $BREAD]);
    $sr3 = callApi('api/restaurant/search_stock_items.php', ['warehouse_id' => $W2, 'q' => "RFS Bread"]);
    ok(in_array($BREAD, $ids3($sr3), true), 'search: once a retail item is used in a recipe it counts as an ingredient and is offered');

    $b1 = restaurantBuyStock($pdo, ['product_id' => $FLOUR, 'warehouse_id' => $W2, 'quantity' => 20, 'unit_cost' => 300, 'date' => date('Y-m-d'), 'paid_from_account_id' => $cashAcc], $admin);
    $gp = callApi('api/restaurant/get_purchases.php', ['warehouse_id' => $W2]);
    $first = $gp['data'][0] ?? null;
    ok(!empty($gp['success']) && $first && $first['item'] === "RFS Flour $sfx" && near3($first['quantity'], 20) && near3($first['unit_cost'], 300) && near3($first['total'], 6000) && $first['reference'] === $b1['reference'], 'history: the purchase is listed (item, 20 kg, 300 each, total 6,000, its reference)');
    ok(count($gp['data']) <= 100 && is_array($gp['data']), 'history: at most the latest 100');
} finally {
    $pdo->endOuter();
}

// Endpoint gates (subprocess — the endpoints exit on refusal)
$bsApi = 'api/restaurant/buy_stock.php';
$post = ['_csrf' => 'tk', 'product_id' => 1, 'warehouse_id' => $W, 'quantity' => 1, 'unit_cost' => 100];
$jsonOf = fn($o) => (function ($o) { $j = json_decode(trim(explode('___OUT___', $o, 2)[1] ?? ''), true); return is_array($j) ? $j : ['_raw' => substr($o, 0, 200)]; })($o);
$j = $jsonOf(runWorker(['page' => $bsApi, 'post' => $post], $ON, [$W => 'restaurant'] + $retailAll, '1'));
ok(empty($j['success']), 'buy_stock: a bogus item is refused (' . ($j['message'] ?? '?') . ')');
$j = $jsonOf(runWorker(['page' => $bsApi, 'post' => $post], $OFF, [$W => 'restaurant'] + $retailAll, '1'));
ok(empty($j['success']), 'buy_stock: Restaurant POS off → refused');
$j = $jsonOf(runWorker(['page' => $bsApi, 'post' => $post], $ON, [$W => 'restaurant'] + $retailAll, '0'));
ok(empty($j['success']) && stripos($j['message'] ?? '', 'restaurant') !== false, 'buy_stock: full BMS (Smart POS off) → refused: "' . ($j['message'] ?? '') . '"');
$j = $jsonOf(runWorker(['page' => $bsApi, 'post' => $post], $ON, $retailAll, '1'));
ok(empty($j['success']) && stripos($j['message'] ?? '', 'restaurant') !== false, 'buy_stock: a Retail shop → refused: "' . ($j['message'] ?? '') . '"');
$j = $jsonOf(runWorker(['page' => $bsApi, 'post' => $post, 'session' => ['user_id' => 999999, 'is_admin' => false, 'role_id' => 999999, 'user_lang' => 'en', 'csrf_token' => 'tk']], $ON, [$W => 'restaurant'] + $retailAll, '1'));
ok(empty($j['success']), 'buy_stock: a user with no permissions → refused');
$j = $jsonOf(runWorker(['page' => $bsApi], $ON, [$W => 'restaurant'] + $retailAll, '1'));
ok(empty($j['success']), 'buy_stock: a GET is refused');
$srcB = file_get_contents("$root/$bsApi");
ok(strpos($srcB, 'csrf_check()') !== false && strpos($srcB, "hasPermission('pos_restock')") !== false && strpos($srcB, 'restaurantBuyStock(') !== false && strpos($srcB, 'userCan(\'warehouse\'') !== false, 'buy_stock is guarded by CSRF, the stock permission and shop scope, and uses the one core function');
foreach (['search_stock_items.php', 'get_purchases.php'] as $rf) {
    $x = file_get_contents("$root/api/restaurant/$rf");
    ok(strpos($x, 'restaurantSchemaReady($pdo)') !== false && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', preg_replace('#//.*|/\*.*?\*/#s', '', $x)), "$rf: read-only and checks the restaurant schema");
}
$j = $jsonOf(runWorker(['page' => 'api/restaurant/search_stock_items.php', 'get' => ['warehouse_id' => $W]], $ON, $retailAll, '1'));
ok(($j['results'] ?? ['x']) === [], 'search: a Retail shop gets nothing');

// The retail Receive Stock cannot take an ingredient and does not touch the cost — which is why Purchases exists
$jq = $jsonOf(runWorker(['page' => 'api/pos/quick_restock.php', 'post' => ['_csrf' => 'tk', 'product_id' => 1, 'quantity' => 1, 'buying_price' => 100, 'selling_price' => 0, 'paid_from_account_id' => 1]], $ON, $retailAll, '1'));
ok(empty($jq['success']) && stripos($jq['message'] ?? '', 'Retail price is required') !== false, 'retail Receive Stock still refuses an item with no selling price (an ingredient): "' . ($jq['message'] ?? '') . '"');
$qr = file_get_contents("$root/api/pos/quick_restock.php");
ok(strpos($qr, 'restaurant') === false && strpos($qr, 'cost_price') === false, 'retail Receive Stock (api/pos/quick_restock.php) is untouched — it does not mention restaurants or the cost price');

// Page + links
$pg = runWorker(['page' => 'app/bms/restaurant/purchases.php'], $ON, $onlyRest, '1');
ok(clean($pg) && strpos(body($pg), 'id="rpForm"') !== false && strpos(body($pg), 'id="rpItem"') !== false && strpos(body($pg), 'Two ways to buy') !== false && strpos(body($pg), 'Stock items') !== false && strpos(body($pg), 'Groceries') !== false, 'Purchases page: the stock form, the groceries option and the explanation');
ok(strpos(body($pg), 'id="rbiModal"') !== false, 'Purchases page: the existing Buy Ingredients window is included for groceries');
$h = preg_replace('#<!--.*?-->#s', '', body($pg)); preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $h, $mm); $bad = 0;
foreach ($mm[1] as $js) { if (trim($js) === '' || strpos($js, 'application/ld+json') !== false) continue; $tmp = tempnam(sys_get_temp_dir(), 'pjs') . '.js'; file_put_contents($tmp, $js); exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $oo, $rc); @unlink($tmp); if ($rc !== 0) { $bad++; echo '     ' . implode(' ', array_slice($oo, 0, 2)) . "\n"; } $oo = []; }
ok($bad === 0, 'Purchases page: every inline script parses (' . count($mm[1]) . ' scripts)');
$pgSw = runWorker(['page' => 'app/bms/restaurant/purchases.php'], $ON, $onlyRest, '1', 'sw'); $bsw = body($pgSw);
foreach (['Stock items' => 'Bidhaa za stoo', 'Groceries' => 'Manunuzi ya sokoni', 'Recent purchases' => 'Manunuzi ya hivi karibuni', 'Save purchase' => 'Hifadhi ununuzi', 'Paid from' => 'Imelipwa kutoka'] as $en => $swt) {
    ok(strpos($bsw, $swt) !== false && strpos($bsw, '>' . $en) === false && strpos($bsw, '> ' . $en) === false, "Swahili page: '$en' is '$swt' — no English left in its place");
}
$pgRetail = runWorker(['page' => 'app/bms/restaurant/purchases.php'], $ON, $retailAll, '1');
ok(clean($pgRetail) && strpos(body($pgRetail), 'id="rpForm"') === false, 'a retail company opening the page by link sees no purchase form');
$menuSrc = file_get_contents("$root/app/bms/restaurant/menu.php");
ok(strpos($menuSrc, 'RM.buyUrl}?item=') !== false && strpos($menuSrc, "buyStock: <?= json_encode(t('Buy stock & ingredients')) ?>") !== false, 'the Menu offers "Buy stock & ingredients" on a bought item (not on a dish)');
$hdr = file_get_contents("$root/header.php");
ok(substr_count($hdr, "getUrl('restaurant/purchases')") === 2, 'the Purchases menu (desktop and phone) opens the new page');
ok(strpos(file_get_contents("$root/includes/restaurant/dashboard.php"), "getUrl('restaurant/purchases')") !== false && strpos(file_get_contents("$root/includes/restaurant/dash_panel.php"), "getUrl('restaurant/purchases')") !== false, 'the dashboard\'s low-stock links open Purchases');

// ═══════════════════════════════════════════════════════════════════════════
section('P4. Selling Settings — Restaurant first; retail-only options hidden for a restaurant-only company');
$sp = 'app/constant/settings/pos_config_settings.php';
$strip = fn($h) => preg_replace('#<!--.*?-->#s', '', $h);
$pr = body(runWorker(['page' => $sp], $ON, $onlyRest, '1'));
$pRet = body(runWorker(['page' => $sp], $ON, $retailAll, '1'));
$pMix = body(runWorker(['page' => $sp], $ON, $mixed, '1'));
$pHyb = body(runWorker(['page' => $sp], $ON, $hybridAll, '1'));
$pFull = body(runWorker(['page' => $sp], $ON, $onlyRest, '0'));
$posOf = fn($h, $needle) => ($p = strpos($h, $needle)) === false ? -1 : $p;
ok(strpos($pr, 'restaurant_kitchen_mode') !== false && $posOf($pr, 'restaurant_kitchen_mode') < $posOf($pr, 'pos_products_display_limit'), 'restaurant-only: the Restaurant options come first, before Display');
ok(strpos($pr, 'pos_old_sale_max_days') === false && strpos($pr, 'Record Old Sale') === false, 'restaurant-only: Record Old Sale is hidden');
ok(strpos($pr, 'pos_loyalty_enabled') === false && strpos($pr, 'Loyalty Program') === false && strpos($pr, 'Loyalty points are part of') === false, 'restaurant-only: Loyalty (and its upsell note) is hidden');
ok(strpos($pr, 'pos_discount_type') !== false && strpos($pr, 'pos_receipt_width') !== false && strpos($pr, 'name="_csrf"') !== false && substr_count($pr, 'restaurant_kitchen_mode') >= 3 && substr_count($pr, 'id="rt_del"') === 1, 'restaurant-only: discounts, receipt printing, kitchen & order types all still there, each once');
foreach (['retail company' => $pRet, 'mixed company' => $pMix, 'Hybrid shops' => $pHyb, 'full BMS (Smart POS off)' => $pFull] as $nm => $h) {
    ok(strpos($h, 'pos_old_sale_max_days') !== false && strpos($h, 'Loyalty Program') !== false, "$nm: Record Old Sale and Loyalty still shown (unchanged)");
    ok(strpos($h, 'pos_products_display_limit') !== false && strpos($h, 'pos_discount_type') !== false, "$nm: Display and Discount options still shown");
}
ok(strpos($pFull, 'restaurant_kitchen_mode') === false, 'full BMS (Smart POS off): still no Restaurant section');
ok(strpos($pRet, 'restaurant_kitchen_mode') !== false && strpos($pRet, 'restaurant_kitchen_mode') > strpos($pRet, 'pos_old_sale_max_days'), 'retail company in Smart POS: Restaurant section where it always was (after Record Old Sale)');
ok(strpos($pMix, 'restaurant_kitchen_mode') !== false && $posOf($pMix, 'restaurant_kitchen_mode') > $posOf($pMix, 'pos_old_sale_max_days'), 'mixed company: Restaurant section stays where it was (after Record Old Sale)');
ok(strpos($pHyb, 'restaurant_kitchen_mode') !== false && $posOf($pHyb, 'restaurant_kitchen_mode') > $posOf($pHyb, 'pos_old_sale_max_days'), 'Hybrid: Restaurant section stays where it was');
// saving from the trimmed page must not reset the hidden settings
$get = fn($k) => $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = " . $pdo->quote($k))->fetchColumn();
$put = function ($k, $v) use ($pdo) { if ($v === false) $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]); else $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]); };
$keys4 = ['pos_old_sale_max_days', 'pos_loyalty_enabled', 'pos_loyalty_spend_per_point', 'restaurant_kitchen_mode', 'pos_discount_type', 'pos_receipt_width', 'pos_products_display_limit', 'pos_products_display_limit_mobile', 'pos_auto_print_receipt', 'restaurant_order_take_away', 'restaurant_order_delivery']; $orig4 = []; foreach ($keys4 as $k4) $orig4[$k4] = $get($k4);
try {
    $put('pos_old_sale_max_days', '45'); $put('pos_loyalty_enabled', '1'); $put('pos_loyalty_spend_per_point', '777');
    $base = ['_csrf' => 'tk', 'pos_discount_type' => 'fixed', 'pos_products_display_limit' => '30', 'pos_products_display_limit_mobile' => '10', 'pos_receipt_width' => '58', 'restaurant_kitchen_mode' => 'screen', 'restaurant_order_take_away' => '1'];
    runWorker(['page' => $sp, 'post' => $base], $ON, $onlyRest, '1');
    ok($get('pos_old_sale_max_days') === '45' && $get('pos_loyalty_enabled') === '1' && $get('pos_loyalty_spend_per_point') === '777', 'saving the trimmed (restaurant-only) page keeps Record Old Sale days and Loyalty exactly as they were');
    ok($get('restaurant_kitchen_mode') === 'screen' && $get('pos_receipt_width') === '58' && $get('pos_discount_type') === 'fixed', 'saving the trimmed page still saves what it shows (kitchen mode, receipt width, discount)');
    runWorker(['page' => $sp, 'post' => $base + ['pos_old_sale_max_days' => '60']], $ON, $retailAll, '1');
    ok($get('pos_old_sale_max_days') === '60', 'retail company: saving Record Old Sale days works exactly as before');
} finally {
    foreach ($orig4 as $k4 => $v4) $put($k4, $v4);
}

// [later phases append here]
