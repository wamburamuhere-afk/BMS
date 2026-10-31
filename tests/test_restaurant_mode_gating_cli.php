<?php
/**
 * tests/test_restaurant_mode_gating_cli.php
 *
 * Restaurant mode gating (2026-10-31):
 *   A. pos.php — POS_WAREHOUSE_MODES is built BEFORE it is printed. It used to
 *      be built further down inside the warehouse <select>, after the <script>
 *      had already printed it, so it was always {} and every shop behaved as
 *      retail (Select Table / Send to Kitchen never appeared).
 *   B. With Restaurant POS OFF the map stays empty — the till is today's retail
 *      till exactly, even for a shop whose stored pos_mode is 'restaurant'.
 *   C. header.php — the "Restaurant" menu link shows only when Restaurant POS is on.
 *   D. warehouses.php update — a posted pos_mode is ignored (stored value kept)
 *      when Restaurant POS is off, and saved when it is on.
 *   E. Mobile warehouses create/update carry the same gate.
 *
 * Runs against the real local DB; every fixture change is restored.
 * Exit 0 = all pass.
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

function _run(string $page, array $session, array $features = [], array $post = []): array {
    $cfgFile = tempnam(sys_get_temp_dir(), 'rmg');
    file_put_contents($cfgFile, json_encode(['page' => $page, 'session' => $session, 'features' => $features, 'post' => $post]));
    $out = shell_exec('php ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($cfgFile) . ' 2>&1');
    @unlink($cfgFile);
    $parts = explode('___OUT___', (string)$out);
    return ['stderr' => $parts[0] ?? '', 'html' => $parts[1] ?? ''];
}
function _clean(string $stderr): bool {
    return !preg_match('/PHP (Warning|Notice|Fatal|Parse error)|___EXC___/', $stderr);
}
function _modes(string $html): ?array {
    if (!preg_match('/const POS_WAREHOUSE_MODES = (.*?);/', $html, $m)) return null;
    $v = json_decode($m[1], true);
    return is_array($v) ? $v : null;
}

$adminUid = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 AND role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$admin = ['user_id' => $adminUid, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'];
$on  = ['pos' => true, 'restaurant_pos' => true];
$off = ['pos' => true, 'restaurant_pos' => false];

$wh = $pdo->query("SELECT * FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$whId = (int)$wh['warehouse_id'];
$origMode = $wh['pos_mode'];
$setMode = function (string $m) use ($pdo, $whId) {
    $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$m, $whId]);
};
$getMode = function () use ($pdo, $whId) {
    $s = $pdo->prepare("SELECT pos_mode FROM warehouses WHERE warehouse_id = ?");
    $s->execute([$whId]);
    return $s->fetchColumn();
};

try {
    section('A. pos.php — Restaurant POS ON: the real mode reaches the till');
    $setMode('restaurant');
    $r = _run('app/bms/pos/pos.php', $admin, $on);
    ok(_clean($r['stderr']), 'pos.php renders with no PHP warnings/exceptions' . (_clean($r['stderr']) ? '' : ' — ' . substr($r['stderr'], 0, 300)));
    $modes = _modes($r['html']);
    ok($modes !== null, 'POS_WAREHOUSE_MODES is printed as JSON');
    ok(($modes[$whId] ?? null) === 'restaurant', "POS_WAREHOUSE_MODES[$whId] is 'restaurant' (was always {} before the fix)");
    ok(strpos($r['html'], 'const POS_RESTAURANT_ENABLED = true') !== false, 'POS_RESTAURANT_ENABLED is true');
    ok(strpos($r['html'], 'id="restaurantTableBtn"') !== false, 'Select Table button is in the page (JS shows it for a restaurant shop)');
    ok(strpos($r['html'], 'id="posWarehouseId"') !== false && strpos($r['html'], 'value="' . $whId . '"') !== false, 'warehouse <select> still lists the shop');

    $setMode('retail');
    $r = _run('app/bms/pos/pos.php', $admin, $on);
    $modes = _modes($r['html']);
    ok(($modes[$whId] ?? 'retail') === 'retail', 'a retail shop stays retail with Restaurant POS on');

    section('B. pos.php — Restaurant POS OFF: today\'s retail till exactly');
    $setMode('restaurant');
    $r = _run('app/bms/pos/pos.php', $admin, $off);
    ok(_clean($r['stderr']), 'pos.php renders with no PHP warnings/exceptions (off)' . (_clean($r['stderr']) ? '' : ' — ' . substr($r['stderr'], 0, 300)));
    ok(_modes($r['html']) === [], 'POS_WAREHOUSE_MODES is empty even though the stored mode is restaurant');
    ok(strpos($r['html'], 'const POS_RESTAURANT_ENABLED = false') !== false, 'POS_RESTAURANT_ENABLED is false');

    section('C. header.php — Restaurant menu link');
    $rOn = _run('app/bms/pos/pos_dashboard.php', $admin, $on);
    ok(preg_match('#href="[^"]*/restaurant"[^>]*>\s*<i class="bi bi-cup-hot"></i>#', $rOn['html']) === 1, 'Restaurant link in the top menu when Restaurant POS is on');
    ok(strpos($rOn['html'], 'list-group-item-action" href="' ) !== false && preg_match('#list-group-item-action" href="[^"]*/restaurant"><i class="bi bi-cup-hot"></i>#', $rOn['html']) === 1, 'Restaurant link in the phone side menu when on');
    $rOff = _run('app/bms/pos/pos_dashboard.php', $admin, $off);
    ok(_clean($rOff['stderr']), 'page renders with no PHP warnings (off)');
    ok(strpos($rOff['html'], 'bi-cup-hot') === false, 'no Restaurant link anywhere in the menu when Restaurant POS is off');

    section('D. warehouses.php update — POS Mode only changes when Restaurant POS is on');
    $csrf = bin2hex(random_bytes(8));
    $post = [
        'csrf_token' => $csrf, 'update_warehouse' => 1, 'warehouse_id' => $whId,
        'warehouse_name' => $wh['warehouse_name'], 'warehouse_code' => $wh['warehouse_code'],
        'address' => $wh['address'] ?? '', 'city' => $wh['city'] ?? '', 'phone' => $wh['phone'] ?? '',
        'email' => $wh['email'] ?? '', 'capacity' => $wh['capacity'] ?? 0, 'status' => $wh['status'],
        'project_id' => $wh['project_id'] ?? '', 'notes' => $wh['notes'] ?? '',
    ];
    if (!empty($wh['is_primary'])) $post['is_primary'] = 1;
    $sess = $admin + ['csrf_token' => $csrf];

    $setMode('retail');
    _run('app/bms/stock/warehouses.php', $sess, $off, $post + ['pos_mode' => 'restaurant']);
    ok($getMode() === 'retail', 'OFF: posting pos_mode=restaurant leaves the shop retail');

    _run('app/bms/stock/warehouses.php', $sess, $on, $post + ['pos_mode' => 'hybrid']);
    ok($getMode() === 'hybrid', 'ON: posting pos_mode=hybrid saves it');

    _run('app/bms/stock/warehouses.php', $sess, $off, $post + ['pos_mode' => 'retail']);
    ok($getMode() === 'hybrid', 'OFF: a posted pos_mode never changes the stored value (stays hybrid)');

    $rForm = _run('app/bms/stock/warehouses.php', $admin, $off);
    ok(strpos($rForm['html'], 'id="pos_mode"') === false, 'OFF: the Add Shop form has no POS Mode field');
    $rForm = _run('app/bms/stock/warehouses.php', $admin, $on);
    ok(strpos($rForm['html'], 'id="pos_mode"') !== false, 'ON: the Add Shop form has the POS Mode field');

    section('E. Mobile warehouses API carries the same gate');
    $c = file_get_contents("$root/api/mobile/warehouses/create.php");
    ok(preg_match("/if \(!\(tenantFeatureEnabled\('pos'\) && tenantFeatureEnabled\('restaurant_pos'\)\)\) \{\s*\\\$pos_mode = 'retail';/", $c) === 1, 'create.php forces retail when Restaurant POS is off');
    $u = file_get_contents("$root/api/mobile/warehouses/update.php");
    ok(strpos($u, "&& tenantFeatureEnabled('pos') && tenantFeatureEnabled('restaurant_pos')) {") !== false, 'update.php only writes pos_mode when Restaurant POS is on');
} finally {
    $setMode($origMode);
    ok($getMode() === $origMode, "fixture restored: warehouse $whId pos_mode back to '$origMode'");
}
exit($fail === 0 ? 0 : 1);
