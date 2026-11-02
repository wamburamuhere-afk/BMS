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
foreach (['Take order', 'Tables', 'Kitchen', 'Reservations', 'Who Owes Me', 'Menu items', 'Menu groups', 'Add-on options', 'Receive Stock', 'Suppliers', 'Customers', 'Restaurant Sales'] as $lbl) {
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
$labels = ['Orders', 'Take order', 'Tables', 'Kitchen', 'Reservations', 'Who Owes Me', 'Menu', 'Menu items', 'Menu groups', 'Add-on options', 'Purchases', 'Receive Stock', 'Suppliers', 'Customers'];
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

// [later phases append here]
