<?php
/**
 * tests/test_roles_matrix_shop_mode_cli.php — Roles & Permissions matrix follows the company's shop mode.
 *
 *   php tests/test_roles_matrix_shop_mode_cli.php
 *
 *   1. the decision (pure): retail / mixed / restaurant for every shop mix
 *   2. Restaurant POS off: nothing changes for a retail company (rows, names, texts)
 *   3. live on this DB (rolled back): all-retail shops → restaurant rows hidden, grants kept
 *   4. live: one Restaurant shop + Retail shops → everything shows, restaurant rows in their own tab,
 *      shared rows keep the retail text and get a restaurant note
 *   5. live: every active shop Restaurant → same rows as mixed (nothing a restaurant uses is hidden),
 *      shared rows in restaurant words
 *   6. the roles list and the rows agree (rofsHasRestaurantShop)
 *   7. Mobile Money rows never show, their grants survive a save
 *   8. real save across a mode change keeps restaurant grants
 *   9. every restaurant text has a Swahili translation
 *
 * CLI ONLY. Every DB write is inside a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/role_permission_ui.php';
require_once __DIR__ . '/../core/restaurant_order_flow_schema.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function withFeatures(?array $on): void {
    if ($on === null) { unset($GLOBALS['__bms_features']); return; }
    $f = [];
    foreach (allFeatureKeys() as $k) $f[$k] = in_array($k, $on, true);
    $GLOBALS['__bms_features'] = $f;
}
function keysOf(array $rows): array { return array_map(fn($r) => (string)$r['page_key'], $rows); }
function tabsOf(array $rows): array {
    $t = [];
    foreach ($rows as $r) $t[rolePermissionTabName((string)$r['page_key'], $r['module_name'])][] = (string)$r['page_key'];
    return $t;
}

$allPerms = $pdo->query("SELECT permission_id, page_key, COALESCE(is_hidden,0) AS is_hidden FROM permissions")->fetchAll(PDO::FETCH_ASSOC);
$idByKey = [];
foreach ($allPerms as $p) $idByKey[$p['page_key']] = (int)$p['permission_id'];
$restKeys   = rolePermissionRestaurantKeys();
$sharedKeys = ['pos', 'pos_restock', 'pos_config_settings', 'pos_advanced', 'categories', 'warehouses', 'products'];
$smartPos   = get_setting('pos_simple_mode', '0') === '1';

echo "\nBMS — Roles & Permissions matrix follows the shop mode\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. The decision');
$S = fn($any, $tot, $rest) => ['any_rest' => $any, 'active_total' => $tot, 'active_rest' => $rest];
ok('no Restaurant/Hybrid shop → retail',                 rolePermissionShopModeFrom($S(0, 3, 0), true) === 'retail');
ok('one Restaurant shop among Retail ones → mixed',      rolePermissionShopModeFrom($S(1, 3, 1), true) === 'mixed');
ok('Hybrid shops only → mixed (Hybrid sells retail too)', rolePermissionShopModeFrom($S(2, 2, 0), true) === 'mixed');
ok('every active shop Restaurant (Smart POS) → restaurant', rolePermissionShopModeFrom($S(2, 2, 2), true) === 'restaurant');
ok('every shop Restaurant but full BMS → mixed (no restaurant wording outside Smart POS)', rolePermissionShopModeFrom($S(2, 2, 2), false) === 'mixed');
ok('Restaurant shop only among deleted shops, no active shop → mixed', rolePermissionShopModeFrom($S(1, 0, 0), true) === 'mixed');
ok('cannot read the shops → mixed (as the page was before)', rolePermissionShopModeFrom(null, true) === 'mixed');
ok('restaurant keys come from the Restaurant POS module', in_array('restaurant_orders', $restKeys, true) && in_array('kitchen_display', $restKeys, true) && in_array('restaurant_pos', $restKeys, true));
ok('no POS row counted as a restaurant row', array_filter($restKeys, fn($k) => str_starts_with($k, 'pos')) === []);

// ─────────────────────────────────────────────────────────────────────────────
section('2. Restaurant POS off — a retail company sees no change');
withFeatures(['pos', 'warehouses']);
ok('mode is retail', rolePermissionShopMode($pdo, true) === 'retail');
$m = loadRolePermissionMatrix($pdo);
$vis = keysOf($m['visible']);
// What the matrix showed before this change: rolePermissionVisible() alone.
$before = [];
foreach ($pdo->query("SELECT page_key FROM permissions WHERE COALESCE(is_hidden,0) = 0")->fetchAll(PDO::FETCH_COLUMN) as $pk) {
    if (rolePermissionVisible((string)$pk)) $before[] = (string)$pk;
}
sort($vis); sort($before);
ok('same rows as rolePermissionVisible() alone', $vis === $before);
ok('no restaurant row', array_intersect($vis, $restKeys) === []);
$texts = array_filter($vis, fn($pk) => rolePermissionShopText($pk, 'retail') !== null);
ok('no row reworded (names and descriptions as stored)', $texts === []);
$tabs = tabsOf($m['visible']);
ok('no Restaurant tab', !isset($tabs['Restaurant']));
foreach (['pos', 'pos_restock', 'pos_config_settings', 'pos_price_override', 'pos_discount_override', 'pos_past_sales'] as $pk) {
    if (isset($idByKey[$pk])) ok("'$pk' still in the Point of Sale tab", in_array($pk, $tabs['Point of Sale'] ?? [], true));
}

// ─────────────────────────────────────────────────────────────────────────────
$engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouses'")->fetchColumn();
$hasPosMode = (bool)$pdo->query("SHOW COLUMNS FROM warehouses LIKE 'pos_mode'")->fetch();
ok('warehouses is transactional and has pos_mode (needed for the live checks)', strtoupper((string)$engine) === 'INNODB' && $hasPosMode);
if (strtoupper((string)$engine) !== 'INNODB' || !$hasPosMode) {
    withFeatures(null);
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}

withFeatures(['pos', 'warehouses', 'restaurant_pos']);
$pdo->beginTransaction();
try {
    $ids = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id")->fetchAll(PDO::FETCH_COLUMN);
    ok('at least two active shops to build the scenarios', count($ids) >= 2);
    $setAll = fn(string $mode) => $pdo->exec("UPDATE warehouses SET pos_mode = " . $pdo->quote($mode));
    $setOne = function (int $id, string $mode) use ($pdo) {
        $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$mode, $id]);
    };

    // ─────────────────────────────────────────────────────────────────────────
    section('3. Restaurant POS on, every shop Retail');
    $setAll('retail');
    ok('mode is retail', rolePermissionShopMode($pdo, true) === 'retail');
    $mRetail = loadRolePermissionMatrix($pdo);
    $visR = keysOf($mRetail['visible']);
    ok('no restaurant row shown', array_intersect($visR, $restKeys) === []);
    $presKeys = array_map(fn($id) => array_search($id, $idByKey, true), $mRetail['preserved_ids']);
    foreach ($restKeys as $rk) {
        if (isset($idByKey[$rk])) ok("'$rk' kept (preserved), not dropped", in_array($rk, $presKeys, true));
    }
    $visR2 = $visR; sort($visR2);
    withFeatures(['pos', 'warehouses']);
    $visOff = keysOf(loadRolePermissionMatrix($pdo)['visible']); sort($visOff);
    withFeatures(['pos', 'warehouses', 'restaurant_pos']);
    ok('exactly the rows of a company without Restaurant POS', $visR2 === $visOff);
    ok('roles list agrees: no restaurant shop → ready-made roles left out', rofsHasRestaurantShop($pdo) === false);

    // ─────────────────────────────────────────────────────────────────────────
    section('4. One Restaurant shop, the rest Retail (mixed)');
    $setOne((int)$ids[0], 'restaurant');
    ok('mode is mixed', rolePermissionShopMode($pdo, true) === 'mixed');
    ok('roles list agrees: a restaurant shop → ready-made roles shown', rofsHasRestaurantShop($pdo) === true);
    $mMixed = loadRolePermissionMatrix($pdo);
    $visM = keysOf($mMixed['visible']);
    foreach ($restKeys as $rk) {
        if (isset($idByKey[$rk])) ok("'$rk' shown", in_array($rk, $visM, true));
    }
    ok('every retail row still shown', array_diff($visR, $visM) === []);
    $tabsM = tabsOf($mMixed['visible']);
    ok('restaurant rows sit in their own Restaurant tab', isset($tabsM['Restaurant'])
        && array_diff(array_intersect($visM, $restKeys), $tabsM['Restaurant']) === []);
    ok('the Restaurant tab holds nothing else', array_diff($tabsM['Restaurant'] ?? [], $restKeys) === []);
    ok('no restaurant row left in the Point of Sale tab', array_intersect($tabsM['Point of Sale'] ?? [], $restKeys) === []);
    foreach ($sharedKeys as $pk) {
        $t = rolePermissionShopText($pk, 'mixed');
        ok("'$pk': retail name and text kept, restaurant meaning added as a note", $t !== null && $t['replace'] === false && $t['name'] === null && $t['text'] !== '');
    }
    $rp = rolePermissionShopText('restaurant_pos', 'mixed');
    ok('Restaurant POS row: old text (kitchen now has its own row) replaced', $rp !== null && $rp['replace'] === true && !str_contains($rp['text'], 'Kitchen Display'));
    foreach (['pos_price_override', 'pos_discount_override', 'pos_past_sales', 'customers', 'expenses'] as $pk) {
        ok("'$pk' untouched (same meaning in both)", rolePermissionShopText($pk, 'mixed') === null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    section('5. Every active shop Restaurant');
    $setAll('restaurant');
    $expect = $smartPos ? 'restaurant' : 'mixed';
    ok("mode is $expect (Smart POS " . ($smartPos ? 'on' : 'off') . ')', rolePermissionShopMode($pdo, true) === $expect);
    $mRest = loadRolePermissionMatrix($pdo);
    $visX = keysOf($mRest['visible']); sort($visX);
    $visM2 = $visM; sort($visM2);
    ok('same rows as mixed — nothing a restaurant still uses is hidden', $visX === $visM2);
    foreach ($sharedKeys as $pk) {
        $t = rolePermissionShopText($pk, 'restaurant');
        ok("'$pk' described in restaurant words", $t !== null && $t['replace'] === true && $t['text'] !== '');
    }
    ok('POS row explains waiter (View) vs cashier (Create)', str_contains(rolePermissionShopText('pos', 'restaurant')['text'], 'waiter')
        && str_contains(rolePermissionShopText('pos', 'restaurant')['text'], 'cashier'));
    ok('Restock row says Purchases', str_contains(rolePermissionShopText('pos_restock', 'restaurant')['text'], 'Purchases'));
    ok('POS Advanced row kept: it also controls kitchen printers and tills', in_array('pos_advanced', $restKeys, true) === false
        && str_contains(rolePermissionShopText('pos_advanced', 'restaurant')['text'], 'printers'));

    // A deleted Retail shop must not stop a restaurant-only company from being one.
    $pdo->exec("UPDATE warehouses SET pos_mode = 'retail' WHERE status = 'deleted'");
    ok('deleted Retail shops ignored', rolePermissionShopMode($pdo, true) === $expect);

    // ─────────────────────────────────────────────────────────────────────────
    section('6. Hybrid');
    $setAll('hybrid');
    ok('Hybrid shops → mixed', rolePermissionShopMode($pdo, true) === 'mixed');
    ok('roles list agrees', rofsHasRestaurantShop($pdo) === true);

    // ─────────────────────────────────────────────────────────────────────────
    section('7. Mobile Money rows');
    $mmKeys = array_values(array_filter(array_keys($idByKey), fn($k) => str_starts_with($k, 'mm_')));
    $visH = keysOf(loadRolePermissionMatrix($pdo)['visible']);
    ok('no Mobile Money row shown', array_intersect($visH, $mmKeys) === []);
    ok('every mm_ row in the database is on the retired list', array_diff($mmKeys, rolePermissionRetiredKeys()) === []);

    // ─────────────────────────────────────────────────────────────────────────
    section('8. Real save across a mode change');
    $need = ['restaurant_orders', 'pos', 'kitchen_display'];
    ok('fixture rows exist', count(array_filter($need, fn($k) => isset($idByKey[$k]))) === count($need));
    $pdo->prepare("INSERT INTO roles (role_name, description, created_at) VALUES (?, 'test', NOW())")
        ->execute(['ZZTEST shop mode ' . uniqid()]);
    $rid = (int)$pdo->lastInsertId();
    $row = function (string $pk) use ($pdo, $rid, $idByKey) {
        $s = $pdo->prepare("SELECT * FROM role_permissions WHERE role_id = ? AND permission_id = ?");
        $s->execute([$rid, $idByKey[$pk]]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    if (!empty($mmKeys)) {
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view) VALUES (?, ?, 1)")->execute([$rid, $idByKey[$mmKeys[0]]]);
    }

    $setOne((int)$ids[0], 'restaurant');
    rolePermissionShopMode($pdo, true);   // mixed
    saveRolePermissionGrants($pdo, $rid, [
        $idByKey['pos']               => ['view' => 'on'],
        $idByKey['restaurant_orders'] => ['view' => 'on', 'create' => 'on', 'approve' => 'on'],
        $idByKey['kitchen_display']   => ['view' => 'on'],
    ], loadRolePermissionMatrix($pdo));
    $r = $row('restaurant_orders');
    ok('mixed: restaurant grant saved with approve (it has a workflow)', count($r) === 1 && (int)$r[0]['can_create'] === 1 && (int)$r[0]['can_approve'] === 1);

    $setAll('retail');
    ok('switched to retail', rolePermissionShopMode($pdo, true) === 'retail');
    saveRolePermissionGrants($pdo, $rid, [$idByKey['pos'] => ['view' => 'on', 'create' => 'on']], loadRolePermissionMatrix($pdo));
    ok('retail save keeps the hidden restaurant grants', count($row('restaurant_orders')) === 1 && count($row('kitchen_display')) === 1);
    ok('retail save still updates the POS row', (int)($row('pos')[0]['can_create'] ?? 0) === 1);
    saveRolePermissionGrants($pdo, $rid, [
        $idByKey['restaurant_orders'] => ['view' => 'on'],    // crafted POST for a row not on screen
    ], loadRolePermissionMatrix($pdo));
    $r = $row('restaurant_orders');
    ok('a crafted POST cannot change a hidden restaurant grant', count($r) === 1 && (int)$r[0]['can_approve'] === 1);
    if (!empty($mmKeys)) ok('Mobile Money grant survives the save', count($row($mmKeys[0])) === 1);

    $setOne((int)$ids[0], 'restaurant');
    rolePermissionShopMode($pdo, true);
    ok('back to mixed: the restaurant grant is there again', count($row('restaurant_orders')) === 1);
} finally {
    $pdo->rollBack();
    rolePermissionShopMode($pdo, true);
}

// ─────────────────────────────────────────────────────────────────────────────
section('9. Swahili');
$sw = require __DIR__ . '/../lang/sw.php';
$strings = ['In a restaurant shop:', 'Restaurant'];
foreach (array_merge($sharedKeys, ['restaurant_pos']) as $pk) {
    foreach (['mixed', 'restaurant'] as $mode) {
        $t = rolePermissionShopText($pk, $mode);
        if (!$t) continue;
        $strings[] = $t['text'];
        if ($t['name']) $strings[] = $t['name'];
    }
}
foreach (array_unique($strings) as $s) {
    ok('translated: ' . mb_strimwidth($s, 0, 60, '…'), isset($sw[$s]) && $sw[$s] !== '' && $sw[$s] !== $s);
}

// ─────────────────────────────────────────────────────────────────────────────
section('10. The page uses it');
$page = file_get_contents(__DIR__ . '/../app/constant/settings/user_roles.php');
ok('user_roles.php reads the shop mode once', substr_count($page, 'rolePermissionShopMode($pdo)') === 1);
ok('user_roles.php words each row by shop mode', str_contains($page, 'rolePermissionShopText('));
ok('ready-made Restaurant Manager no longer lists a right that does not exist (pos_dashboard)',
   !isset(rofsReadyRoles()['Restaurant Manager'][1]['pos_dashboard']));

withFeatures(null);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
