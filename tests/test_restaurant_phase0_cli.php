<?php
/**
 * tests/test_restaurant_phase0_cli.php — restaurant_plan.md Phase 0.
 *
 *   A. Gate helpers: restaurantModuleOn / restaurantShopActive / restaurantOnlyCompany
 *      are false whenever Restaurant POS or Smart POS is off (retail = today).
 *   B. Tables need no floor: a real save_table.php POST with no floor creates the
 *      shop's default area and saves the table.
 *   C. floors.php / tables.php render clean; area picker optional; the empty-area
 *      message no longer says "No floors yet" next to an existing floor.
 *   D. Superadmin warning: tenantRestaurantModeShops() reads a real tenant DB;
 *      the dialog JS is valid and only warns when switching Restaurant POS ON.
 *   E. Swahili wording: changed keys have the new words, and none of them is
 *      used on a retail screen; shared retail keys are unchanged.
 *
 * Real local DB; every fixture change is restored. Exit 0 = all pass.
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
    $_SERVER['REQUEST_METHOD'] = !empty($cfg['post']) ? 'POST' : 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    if (($cfg['mode'] ?? '') === 'helpers') {
        require_once "$root/core/restaurant_scope.php";
        global $pdo;
        echo "\n___OUT___\n" . json_encode([
            'module' => restaurantModuleOn($pdo),
            'shop'   => restaurantShopActive($pdo, (int)$cfg['wid']),
            'only'   => restaurantOnlyCompany($pdo),
        ]);
        exit;
    }
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
require_once "$root/core/tenant_admin.php";
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
    $f = tempnam(sys_get_temp_dir(), 'rp0');
    file_put_contents($f, json_encode($cfg));
    $out = (string)shell_exec('php ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $p = explode('___OUT___', $out, 2);
    return ['stderr' => $p[0] ?? '', 'out' => trim($p[1] ?? '')];
}
function _clean(string $s): bool { return !preg_match('/PHP (Warning|Notice|Fatal|Parse error)|___EXC___/', $s); }

$adminUid = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 AND role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$admin = ['user_id' => $adminUid, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'];
$on  = ['pos' => true, 'restaurant_pos' => true];
$off = ['pos' => true, 'restaurant_pos' => false];

$active = $pdo->query("SELECT warehouse_id, pos_mode FROM warehouses WHERE status = 'active' ORDER BY warehouse_id")->fetchAll(PDO::FETCH_ASSOC);
$origModes = array_column($active, 'pos_mode', 'warehouse_id');
// Prefer a shop with no floor yet, so B really exercises "default area created".
$whId = (int)($pdo->query("SELECT w.warehouse_id FROM warehouses w
                            WHERE w.status = 'active' AND NOT EXISTS (SELECT 1 FROM restaurant_floors f WHERE f.warehouse_id = w.warehouse_id)
                            ORDER BY w.warehouse_id LIMIT 1")->fetchColumn() ?: $active[0]['warehouse_id']);
$origSimple = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$setSimple = function (string $v) use ($pdo) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
};
$setMode = function (int $wid, string $m) use ($pdo) {
    $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$m, $wid]);
};
$helpers = function (array $features) use ($admin, $whId) {
    $r = _run(['mode' => 'helpers', 'session' => $admin, 'features' => $features, 'wid' => $whId]);
    return json_decode($r['out'], true) ?: [];
};

$createdTableIds = []; $createdFloorIds = [];
try {
    section('A. Gate helpers — retail unless Restaurant POS AND Smart POS are on');
    $setMode($whId, 'restaurant');
    $setSimple('1');
    $h = $helpers($off);
    ok(($h['module'] ?? true) === false && ($h['shop'] ?? true) === false, 'Restaurant POS off → module and shop gates false (shop stored as restaurant)');
    $setSimple('0');
    $h = $helpers($on);
    ok(($h['module'] ?? true) === false && ($h['shop'] ?? true) === false, 'Smart POS off → gates false');
    $setSimple('1');
    $h = $helpers($on);
    ok(($h['module'] ?? false) === true && ($h['shop'] ?? false) === true, 'both on + shop Restaurant → restaurantShopActive true');
    $setMode($whId, 'retail');
    $h = $helpers($on);
    ok(($h['shop'] ?? true) === false, 'a Retail shop stays retail with the module on');
    if (count($active) > 1) {
        $setMode($whId, 'restaurant');
        $h = $helpers($on);
        ok(($h['only'] ?? true) === false, 'mixed shops → restaurantOnlyCompany false (retail dashboard kept)');
    }
    foreach ($active as $w) $setMode((int)$w['warehouse_id'], 'restaurant');
    $h = $helpers($on);
    ok(($h['only'] ?? false) === true, 'every active shop Restaurant → restaurantOnlyCompany true');
    $setMode($whId, 'hybrid');
    $h = $helpers($on);
    ok(($h['only'] ?? true) === false, 'a Hybrid shop is not restaurant-only');
    $h = $helpers($off);
    ok(($h['only'] ?? true) === false, 'Restaurant POS off → never restaurant-only');

    section('B. Add Table with no floor → default area created, table saved');
    $setMode($whId, 'restaurant');
    $before = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_floors WHERE warehouse_id = $whId")->fetchColumn();
    $csrf = bin2hex(random_bytes(8));
    $num = 'T0-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $r = _run(['page' => 'api/restaurant/save_table.php', 'session' => $admin + ['csrf_token' => $csrf], 'features' => $on,
               'post' => ['_csrf' => $csrf, 'warehouse_id' => $whId, 'floor_id' => '', 'table_number' => $num, 'seats' => 4]]);
    $res = json_decode($r['out'], true);
    ok(is_array($res) && !empty($res['success']), 'save_table.php succeeds with no floor' . (is_array($res) ? '' : ' — ' . substr($r['out'] . $r['stderr'], 0, 300)));
    $row = $pdo->prepare("SELECT t.table_id, t.floor_id, t.seats, f.name FROM restaurant_tables t JOIN restaurant_floors f ON f.floor_id = t.floor_id WHERE t.warehouse_id = ? AND t.table_number = ?");
    $row->execute([$whId, $num]);
    $t = $row->fetch(PDO::FETCH_ASSOC);
    ok($t !== false && (int)$t['seats'] === 4, 'table row saved with 4 seats (not "44")');
    if ($t) {
        $createdTableIds[] = (int)$t['table_id'];
        if ($before === 0) {
            ok($t['name'] === 'Main Area', "shop had no floor → default area 'Main Area' created and used");
            $createdFloorIds[] = (int)$t['floor_id'];
        } else {
            ok((int)$t['floor_id'] > 0, 'shop already had a floor → its first floor used, none created');
        }
    }
    $after = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_floors WHERE warehouse_id = $whId")->fetchColumn();
    ok($after === max($before, 1), 'at most one default area exists');
    $num2 = $num . 'b';
    _run(['page' => 'api/restaurant/save_table.php', 'session' => $admin + ['csrf_token' => $csrf], 'features' => $on,
          'post' => ['_csrf' => $csrf, 'warehouse_id' => $whId, 'floor_id' => '', 'table_number' => $num2, 'seats' => 2]]);
    $row->execute([$whId, $num2]);
    $t2 = $row->fetch(PDO::FETCH_ASSOC);
    if ($t2) $createdTableIds[] = (int)$t2['table_id'];
    ok($t2 && $t && (int)$t2['floor_id'] === (int)$t['floor_id'], 'a second table reuses the same default area (no duplicate area)');

    // Creation path, direct: a warehouse with no floor at all.
    require_once "$root/core/restaurant_scope.php";
    $bare = (int)$pdo->query("SELECT w.warehouse_id FROM warehouses w
                               WHERE NOT EXISTS (SELECT 1 FROM restaurant_floors f WHERE f.warehouse_id = w.warehouse_id)
                               ORDER BY w.warehouse_id LIMIT 1")->fetchColumn();
    if ($bare > 0) {
        $fid = restaurantDefaultFloorId($pdo, $bare);
        $createdFloorIds[] = $fid;
        $nm = $pdo->query("SELECT name FROM restaurant_floors WHERE floor_id = $fid")->fetchColumn();
        ok($fid > 0 && $nm === 'Main Area', "shop with no floor → 'Main Area' created (warehouse #$bare)");
        ok(restaurantDefaultFloorId($pdo, $bare) === $fid, 'calling again reuses it — no duplicate area');
    } else {
        ok(false, 'fixture: no warehouse without a floor to test creation');
    }

    section('C. floors.php / tables.php render clean');
    foreach (['app/bms/restaurant/floors.php', 'app/bms/restaurant/tables.php'] as $page) {
        $r = _run(['page' => $page, 'session' => $admin, 'features' => $on]);
        ok(_clean($r['stderr']), "$page renders with no PHP warnings" . (_clean($r['stderr']) ? '' : ' — ' . substr($r['stderr'], 0, 300)));
        ok(strpos($r['out'], 'name="floor_id" id="f-table-floor" required') === false && strpos($r['out'], 'id="f-table-floor"') !== false, "$page: area picker present and optional");
    }
    $r = _run(['page' => 'app/bms/restaurant/floors.php', 'session' => $admin, 'features' => $on]);
    ok(strpos($r['out'], "STRINGS.noTablesOnFloor + '</div>'") !== false, 'floors.php: empty area shows "No tables in this area yet." (not "No floors yet")');

    section('D. Superadmin warning before switching Restaurant POS on');
    $tenants = getControlPdo()->query("SELECT id FROM tenants WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $read = null; $tid = 0;
    foreach ($tenants as $id) { $read = tenantRestaurantModeShops((int)$id); if ($read !== null) { $tid = (int)$id; break; } }
    ok(is_array($read), "tenantRestaurantModeShops() reads a real tenant DB (tenant #$tid) and returns a list");
    if (is_array($read)) {
        $okShape = true;
        foreach ($read as $s) if (!isset($s['warehouse_name']) || !in_array($s['pos_mode'], ['restaurant', 'hybrid'], true)) $okShape = false;
        ok($okShape, 'every listed shop is Restaurant/Hybrid with a name');
    }
    ok(tenantRestaurantModeShops(999999999) === null, 'unknown tenant → null (endpoint answers with an error, save not blocked)');
    $src = file_get_contents("$root/app/superadmin/tenant_view.php");
    ok(strpos($src, 'confirmRestaurantShops(result.value.restaurantPos && !RESTAURANT_POS.enabled, function () {') !== false, 'dialog only warns when Restaurant POS goes from off to on');
    ok(strpos($src, "// Lookup failed — never block the save over a warning.\n        proceed();") !== false || strpos($src, "// Lookup failed — never block the save over a warning.\r\n        proceed();") !== false, 'a failed lookup never blocks the save');
    $a = strpos($src, 'function openPosMoreModal'); $b = strpos($src, 'function doSuspend');
    $js = tempnam(sys_get_temp_dir(), 'sajs') . '.js';
    file_put_contents($js, substr($src, $a, $b - $a));
    exec('node --check ' . escapeshellarg($js) . ' 2>&1', $o, $code);
    @unlink($js);
    ok($code === 0, 'superadmin POS dialog JavaScript parses' . ($code === 0 ? '' : ' — ' . implode(' ', $o)));
    $ep = file_get_contents("$root/actions/superadmin_tenant_restaurant_shops.php");
    ok(strpos($ep, 'assertSuperadminHost();') !== false && strpos($ep, 'csrf_check();') !== false && strpos($ep, 'currentSuperadmin() === null') !== false, 'endpoint guarded: superadmin host, session, CSRF');

    section('E. Swahili wording — restaurant-only keys changed, retail untouched');
    $sw = require "$root/lang/sw.php";
    $expect = [
        'Restaurant' => 'Mgahawa', 'Floor' => 'Eneo', 'Add Floor' => 'Ongeza Eneo', 'Kitchen Display' => 'Skrini ya Jikoni',
        'Modifier Group' => 'Vikundi vya Nyongeza', 'Occupied' => 'Ina Oda', 'Preparing' => 'Inapikwa',
        'Dine-in: Table %s' => 'Kulia Hapa: Meza %s', 'Back to POS Hub' => 'Rudi Mauzo', 'Reservations' => 'Kuhifadhi Meza',
        'No tables in this area yet.' => 'Hakuna meza kwenye eneo hili bado.',
    ];
    foreach ($expect as $k => $v) ok(($sw[$k] ?? null) === $v, "sw '$k' → '$v'");
    foreach (['Available' => 'Zilizopo', 'Reserved' => 'Imehifadhiwa', 'optional' => 'si lazima'] as $k => $v) {
        ok(($sw[$k] ?? null) === $v, "shared retail key '$k' unchanged ('$v')");
    }
    // Every changed key must only appear in restaurant-context files.
    $restaurantFiles = '#^(app/bms/restaurant/|api/restaurant/|core/pos_nav\.php|core/restaurant_scope\.php|app/bms/pos/pos\.php|app/bms/pos/pos_scripts_new\.php|app/bms/pos/pos_modals_new\.php|app/bms/pos/pos_dashboard\.php|app/bms/stock/warehouses\.php|ajax_get_warehouse\.php|app/bms/product/product_edit\.php|header\.php|app/superadmin/tenant_view\.php)#';
    $changed = ['Restaurant', 'Restaurant Hub', 'Floors & Tables', 'Floor', 'Add Floor', 'Edit Floor', 'All Floors', 'Kitchen Display',
        'Kitchen Stations', 'Add Station', 'Edit Station', 'Station', 'Modifier Group', 'Menu Type', 'No active kitchen tickets.',
        'Back to POS Hub', 'Occupied', 'Preparing', 'Dine-in: Table %s', 'Reservations', 'New Reservation'];
    $leaks = [];
    foreach ($changed as $k) {
        $out = []; $code = 0;
        exec('git -C ' . escapeshellarg($root) . ' grep -l -F -e ' . escapeshellarg("'$k'") . ' -- "*.php"', $out, $code);
        if ($code > 1) { $leaks[] = "git grep failed for '$k'"; continue; }
        if ($k === 'Restaurant') ok(count($out) > 3, "leak check really searches (found 'Restaurant' in " . count($out) . ' files)');
        foreach ($out as $f) {
            $f = str_replace('\\', '/', $f);
            if (preg_match('#^(lang/|tests/)#', $f)) continue;
            if (!preg_match($restaurantFiles, $f)) $leaks[] = "$k in $f";
        }
    }
    ok(empty($leaks), 'no changed key is used on a retail screen' . (empty($leaks) ? '' : ' — ' . implode('; ', $leaks)));
} finally {
    if ($createdTableIds) $pdo->exec("DELETE FROM restaurant_tables WHERE table_id IN (" . implode(',', array_map('intval', $createdTableIds)) . ")");
    if ($createdFloorIds) $pdo->exec("DELETE FROM restaurant_floors WHERE floor_id IN (" . implode(',', array_map('intval', $createdFloorIds)) . ")");
    foreach ($origModes as $wid => $m) $setMode((int)$wid, (string)$m);
    if ($origSimple === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    else $setSimple((string)$origSimple);
    $now = $pdo->query("SELECT warehouse_id, pos_mode FROM warehouses WHERE status = 'active'")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok($now == $origModes, 'fixtures restored: every shop POS Mode back to its original value');
}
exit($fail === 0 ? 0 : 1);
