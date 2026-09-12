<?php
/**
 * Restaurant setup UX — POS Mode field, "Go to Warehouses" fix-button, and
 * the Restaurant hub's popup
 *   php tests/test_restaurant_pos_mode_setup_cli.php
 *
 * Product owner report: hitting the Restaurant sub-hub with no warehouse in
 * Restaurant/Hybrid mode showed a dead-end warning ("Switch a warehouse's
 * POS Mode first") with no actual UI anywhere to switch it — `pos_mode` was
 * only ever read (pos.php, restaurant_scope.php), never written. Also asked
 * for the POS Workspace's "Restaurant" card to open a popup of its 5
 * destinations instead of navigating straight to the full sub-hub page.
 *
 * Fixed:
 *   - warehouses.php's Add/Edit modals + ajax_get_warehouse.php now have a
 *     real POS Mode selector (retail/restaurant/hybrid), wired into both the
 *     add_warehouse and update_warehouse POST handlers.
 *   - Each of the 5 warehouse-scoped restaurant pages' warning now carries a
 *     "Go to Warehouses" button, shown only to a user who can actually act
 *     on it (canEdit('warehouses'), which already covers isAdmin()).
 *   - pos_dashboard.php's "Restaurant" hub card now opens a popup (shared
 *     restaurantSubHubCards() in core/pos_nav.php) instead of navigating to
 *     app/bms/restaurant/index.php directly; that page still works exactly
 *     as before via the same shared list.
 *
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
    foreach (($cfg['get'] ?? []) as $k => $v) { $_GET[$k] = $v; }
    $_SERVER['REQUEST_METHOD'] = 'GET';
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

// Isolated-process probe for restaurantWarehousesForSelect() — run in its own
// PHP process (not the main test process) so $_SESSION['scope'] is always
// freshly lazy-loaded from the DB for the given user, never leaking state
// from an earlier check in this same file.
if (($argv[1] ?? '') === 'wh_probe') {
    chdir($root);
    require_once "$root/roots.php";
    require_once "$root/core/restaurant_scope.php";
    global $pdo;
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) { $_SESSION[$k] = $v; }
    $eligible = restaurantWarehousesForSelect($pdo);
    $found = false;
    foreach ($eligible as $e) { if ((int)$e['warehouse_id'] === (int)$cfg['warehouse_id']) { $found = true; break; } }
    echo json_encode(['found' => $found]);
    exit;
}

require_once "$root/roots.php";
require_once "$root/core/restaurant_scope.php";
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

$adminUid = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
$adminSession = ['user_id' => $adminUid, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'];

function _clean(string $stderr): bool {
    return !preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse error)/', $stderr);
}

function _run(string $root, string $page, array $session, array $features = [], array $get = []): array {
    $cfgFile = tempnam(sys_get_temp_dir(), 'rpm');
    file_put_contents($cfgFile, json_encode(['page' => $page, 'session' => $session, 'features' => $features, 'get' => $get]));
    $out = shell_exec('php ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($cfgFile));
    @unlink($cfgFile);
    $parts = explode('___OUT___', $out);
    return ['stderr' => $parts[0] ?? '', 'html' => $parts[1] ?? ''];
}

try {
    // ── A. warehouses.php + ajax_get_warehouse.php carry a real POS Mode field ──
    section('A. warehouses.php Add/Edit modals + ajax_get_warehouse.php have a POS Mode selector');
    // Must be genuinely 'active' (not merely "not deleted") — warehousesForSelect()
    // requires status = 'active' explicitly, and this fixture DB has at least
    // one stale row with status = '' that "!= 'deleted'" would wrongly admit.
    $whId = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();

    $r = _run($root, 'app/bms/stock/warehouses.php', $adminSession);
    ok(_clean($r['stderr']), 'warehouses.php renders with no PHP warnings' . (!_clean($r['stderr']) ? ' — ' . substr($r['stderr'], 0, 200) : ''));
    ok(strpos($r['html'], 'id="pos_mode"') !== false, "Add Warehouse modal has the pos_mode select");
    foreach (['retail', 'restaurant', 'hybrid'] as $opt) {
        ok(strpos($r['html'], 'value="' . $opt . '"') !== false, "Add modal's pos_mode select offers '$opt'");
    }

    $r2 = _run($root, 'ajax_get_warehouse.php', $adminSession, [], ['id' => $whId]);
    ok(_clean($r2['stderr']), 'ajax_get_warehouse.php renders with no PHP warnings' . (!_clean($r2['stderr']) ? ' — ' . substr($r2['stderr'], 0, 200) : ''));
    ok(strpos($r2['html'], 'id="edit_pos_mode"') !== false, "Edit form (ajax_get_warehouse.php) has the pos_mode select");

    // ── B. The field actually persists, end to end ───────────────────────────
    section('B. POS Mode actually persists on save, and restaurantWarehousesForSelect() picks it up');
    $original = $pdo->prepare("SELECT pos_mode FROM warehouses WHERE warehouse_id = ?");
    $original->execute([$whId]);
    $originalMode = $original->fetchColumn();

    try {
        // Exercises the exact statement update_warehouse now runs (SQL-level,
        // not a full HTTP POST — the request-shape wiring is proven separately
        // above by confirming the field name is 'pos_mode' in both templates).
        $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute(['restaurant', $whId]);
        $check = $pdo->prepare("SELECT pos_mode FROM warehouses WHERE warehouse_id = ?");
        $check->execute([$whId]);
        ok($check->fetchColumn() === 'restaurant', "warehouses.pos_mode actually updates to 'restaurant'");

        // Isolated subprocess so $_SESSION['scope'] is freshly lazy-loaded for
        // this admin, never leaking state from an earlier check in this file.
        $probeCfg = tempnam(sys_get_temp_dir(), 'whprobe');
        file_put_contents($probeCfg, json_encode(['session' => $adminSession, 'warehouse_id' => $whId]));
        $probeOut = shell_exec('php ' . escapeshellarg(__FILE__) . ' wh_probe ' . escapeshellarg($probeCfg));
        @unlink($probeCfg);
        $probeResult = json_decode((string)$probeOut, true);
        ok(is_array($probeResult) && $probeResult['found'] === true,
            "restaurantWarehousesForSelect() includes this warehouse once its pos_mode is 'restaurant'"
            . (is_array($probeResult) ? '' : ' — raw: ' . substr((string)$probeOut, 0, 200)));
    } finally {
        $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$originalMode, $whId]);
        $restored = $pdo->prepare("SELECT pos_mode FROM warehouses WHERE warehouse_id = ?");
        $restored->execute([$whId]);
        ok($restored->fetchColumn() === $originalMode, "warehouse's pos_mode restored to its original value ('$originalMode')");
    }

    // ── C. "Go to Warehouses" fix-button — gated on canEdit('warehouses') ────
    section("C. Restaurant pages' fix-button only appears for a user who can act on it");
    foreach (['floors.php', 'tables.php', 'kitchen.php', 'kitchen_dashboard.php', 'reservations.php'] as $file) {
        $rAdmin = _run($root, "app/bms/restaurant/$file", $adminSession, ['restaurant_pos' => true]);
        ok(_clean($rAdmin['stderr']), "$file renders with no PHP warnings (admin)" . (!_clean($rAdmin['stderr']) ? ' — ' . substr($rAdmin['stderr'], 0, 200) : ''));
        ok(strpos($rAdmin['html'], 'No warehouse in your scope') !== false, "$file shows the scope warning (no restaurant/hybrid warehouse for this admin's scope in this fixture DB)");
        ok(strpos($rAdmin['html'], 'Go to Warehouses') !== false, "$file shows 'Go to Warehouses' for an admin (canEdit('warehouses') is true)");
    }

    // Non-admin, no explicit 'warehouses' edit permission -> button must be
    // absent. A REAL non-admin user id, not the admin's own id with is_admin
    // forced false — isAdmin()'s DB fallback / userCan()'s scope lazy-load
    // both key off the actual user_id, so reusing the admin's id would just
    // re-derive admin status regardless of what this fixture pretends.
    // Likewise, header.php's own bootstrap freshly loads REAL role
    // permissions for this user_id from the DB (overwriting anything preset
    // in $_SESSION['permissions']), so restaurant_pos VIEW must be granted
    // via a real, temporary role_permissions row — not faked in session.
    $nonAdminUid = (int)($pdo->query("SELECT user_id FROM users WHERE role_id != 1 AND is_active = 1 LIMIT 1")->fetchColumn() ?: 0);
    ok($nonAdminUid > 0, 'fixture: a real non-admin, active user exists to test with');
    $nonAdminRoleIdStmt = $pdo->prepare("SELECT role_id FROM users WHERE user_id = ?");
    $nonAdminRoleIdStmt->execute([$nonAdminUid]);
    $nonAdminRoleId = (int)$nonAdminRoleIdStmt->fetchColumn();
    $restaurantPosPermId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'restaurant_pos'")->fetchColumn();

    $existingRow = $pdo->prepare("SELECT * FROM role_permissions WHERE role_id = ? AND permission_id = ?");
    $existingRow->execute([$nonAdminRoleId, $restaurantPosPermId]);
    $priorRow = $existingRow->fetch(PDO::FETCH_ASSOC);

    try {
        if ($priorRow) {
            $pdo->prepare("UPDATE role_permissions SET can_view = 1, can_edit = 0 WHERE role_id = ? AND permission_id = ?")
                ->execute([$nonAdminRoleId, $restaurantPosPermId]);
        } else {
            $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, 1, 0, 0, 0)")
                ->execute([$nonAdminRoleId, $restaurantPosPermId]);
        }

        $viewOnlySession = ['user_id' => $nonAdminUid, 'is_admin' => false, 'user_lang' => 'en'];
        $rViewOnly = _run($root, 'app/bms/restaurant/floors.php', $viewOnlySession, ['restaurant_pos' => true]);
        ok(strpos($rViewOnly['html'], 'No warehouse in your scope') !== false, "floors.php still shows the warning for a view-only session (real role_permissions grant, restaurant_pos view=1)");
        ok(strpos($rViewOnly['html'], 'Go to Warehouses') === false, "floors.php hides 'Go to Warehouses' for a session with no warehouse-edit permission");
    } finally {
        if ($priorRow) {
            $pdo->prepare("UPDATE role_permissions SET can_view = ?, can_edit = ? WHERE role_id = ? AND permission_id = ?")
                ->execute([$priorRow['can_view'], $priorRow['can_edit'], $nonAdminRoleId, $restaurantPosPermId]);
        } else {
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?")
                ->execute([$nonAdminRoleId, $restaurantPosPermId]);
        }
        $left = $pdo->prepare("SELECT can_view FROM role_permissions WHERE role_id = ? AND permission_id = ?");
        $left->execute([$nonAdminRoleId, $restaurantPosPermId]);
        $leftRow = $left->fetch(PDO::FETCH_ASSOC);
        ok($priorRow ? ((int)$leftRow['can_view'] === (int)$priorRow['can_view']) : ($leftRow === false),
            'role_permissions fixture cleaned up — no test data left behind for this real role');
    }

    // ── D. POS Workspace's "Restaurant" card opens a popup, not a direct link ──
    section('D. pos_dashboard.php: Restaurant card is a popup trigger, not a direct link');
    $rDash = _run($root, 'app/bms/pos/pos_dashboard.php', $adminSession, ['pos_advanced' => true, 'restaurant_pos' => true]);
    ok(_clean($rDash['stderr']), 'pos_dashboard.php renders with no PHP warnings' . (!_clean($rDash['stderr']) ? ' — ' . substr($rDash['stderr'], 0, 200) : ''));
    ok(strpos($rDash['html'], 'data-bs-target="#restaurantSubHubModal"') !== false, "the 'Restaurant' hub card triggers the popup modal");
    ok(!preg_match('/href="[^"]*\/restaurant"[^>]*>\s*<div class="card[^"]*pos-hub-card/', $rDash['html']), "the 'Restaurant' card is no longer a plain direct link to restaurant/index.php");
    $sub5 = 0;
    foreach (['Floors &amp; Tables', 'Kitchen Display', 'Modifier Group', 'Reservations', 'Menu Type'] as $label) {
        if (strpos($rDash['html'], $label) !== false) $sub5++;
    }
    ok($sub5 === 5, "the popup modal contains all 5 destination cards (found $sub5/5)");

    $rDashOff = _run($root, 'app/bms/pos/pos_dashboard.php', $adminSession, ['pos_advanced' => false, 'restaurant_pos' => false]);
    ok(strpos($rDashOff['html'], 'id="restaurantSubHubModal"') === false, "the popup modal is genuinely absent when restaurant_pos is off, not just unreachable");

    // ── E. restaurant/index.php still works via the shared function ─────────
    section('E. restaurant/index.php unaffected by the refactor to a shared restaurantSubHubCards()');
    $rIndex = _run($root, 'app/bms/restaurant/index.php', $adminSession, ['restaurant_pos' => true]);
    ok(_clean($rIndex['stderr']), 'restaurant/index.php renders with no PHP warnings' . (!_clean($rIndex['stderr']) ? ' — ' . substr($rIndex['stderr'], 0, 200) : ''));
    $sub5b = 0;
    foreach (['Floors &amp; Tables', 'Kitchen Display', 'Modifier Group', 'Reservations', 'Menu Type'] as $label) {
        if (strpos($rIndex['html'], $label) !== false) $sub5b++;
    }
    ok($sub5b === 5, "restaurant/index.php still shows all 5 sub-hub cards (found $sub5b/5)");

} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

exit($fail === 0 ? 0 : 1);
