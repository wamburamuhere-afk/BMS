<?php
/**
 * tests/test_pos_simple_mode_nav_hide_cli.php
 *
 * Regression cover for the 2026-09-16 fix: five UI entry points that only
 * make sense for warehouse-based, multi-location inventory must be hidden
 * when a tenant runs Simple POS (posSimpleModeEnabled(), toggled from
 * Superadmin > Tenant > Point of Sale > More > Simple POS), and stay visible
 * otherwise:
 *
 *   1. header.php  — "Adjustments" nav link (-> stock_adjustments)
 *   2. header.php  — "Valuation" nav link (-> inventory_valuation)
 *   3. header.php  — "Locations" nav link (-> locations)
 *   4. app/constant/reports/inventory_report.php — "Stock Adjustments" tab
 *   5. app/bms/stock/warehouses.php — "Manage Locations" row action
 *
 * LIVE, real HTTP against the actual pages (not a source-reading test) —
 * toggles the real system_settings.pos_simple_mode row for this tenant
 * temporarily, the same way tests/test_pos_simple_mode_cli.php already
 * does, and unconditionally restores the original value on a shutdown
 * handler so a failure never leaves the tenant's live setting changed.
 *
 * Run: php tests/test_pos_simple_mode_nav_hide_cli.php
 * Self-skips the HTTP section if the local server is not reachable
 * (set BMS_TEST_URL to override the default http://dev.bms.local).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m)  { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function skip($m)    { echo "  \033[33m⏭\033[0m  $m\n"; }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$base  = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$probe = "$root/_possimplenav_probe.php";

// ── Snapshot + guaranteed restore of the real tenant setting ───────────────
$hadRow        = (bool)$pdo->query("SELECT 1 FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetch();
$originalValue = $hadRow
    ? $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn()
    : null;

register_shutdown_function(function () use ($pdo, $hadRow, $originalValue, $probe) {
    if ($hadRow) {
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")
            ->execute([$originalValue]);
    } else {
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    }
    if (is_file($probe)) @unlink($probe);
});

try {
    // ── Reachability ─────────────────────────────────────────────────────
    section('0. Local server reachability');
    $reachable = false;
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;
    ok($reachable, "server reachable at $base");
    if (!$reachable) {
        skip('HTTP section skipped — set BMS_TEST_URL to override');
        exit(0);
    }

    // ── A real admin session over HTTP ──────────────────────────────────
    // autoEnforcePermission() runs BEFORE header.php on every page tested
    // here, so $_SESSION['is_admin']/['role_id'] (normally set by header.php
    // itself, on the *previous* page load in real usage) must be seeded
    // directly — a bare user_id is not enough to pass the permission gate
    // on the very first request of a session.
    section('1. Establish a real admin session');
    $adminUid = (int)$pdo->query("
        SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id
        WHERE r.is_admin = 1 LIMIT 1
    ")->fetchColumn();
    ok($adminUid > 0, "found a real admin user (user_id=$adminUid)");

    $adminRoleId = (int)$pdo->query("SELECT role_id FROM users WHERE user_id = " . (int)$adminUid)->fetchColumn();

    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
if (($_GET['act'] ?? '') === 'login') {
    $_SESSION['user_id']  = (int)$_GET['uid'];
    $_SESSION['role_id']  = (int)$_GET['role_id'];
    $_SESSION['is_admin'] = true;
    loadUserPermissions((int)$_GET['role_id']);
    echo 'ok';
}
PHP);

    $sid = 'possimplenav' . bin2hex(random_bytes(8));
    $get = function (string $path) use ($base, $sid) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 20, 'ignore_errors' => true, 'header' => "Cookie: PHPSESSID=$sid",
        ]]);
        return (string)@file_get_contents("$base/$path", false, $ctx);
    };

    $loginBody = $get('_possimplenav_probe?act=login&uid=' . $adminUid . '&role_id=' . $adminRoleId);
    ok(trim($loginBody) === 'ok', 'admin session established via probe');

    // ── Helper: fetch + assert a set of substrings are present/absent ──────
    $assertNav = function (string $label, string $html) use (&$get) {
        // Sanity — page actually rendered as itself, not an error/redirect.
        ok(strlen($html) > 5000, "$label: page rendered fully (" . strlen($html) . " bytes)");
    };

    foreach ([
        ['ON',  '1', false],   // Simple POS ON  -> hidden (present = false)
        ['OFF', '0', true],    // Simple POS OFF -> visible (present = true)
    ] as [$modeLabel, $settingValue, $expectPresent]) {

        section("2. Simple POS $modeLabel — save_setting('pos_simple_mode', '$settingValue')");
        ok(save_setting('pos_simple_mode', $settingValue), "setting saved ($settingValue)");
        // Not asserting get_setting() here: it caches in a `static` array for
        // the life of the process (helpers.php), so a second read in this
        // same CLI run would see the first call's cached value regardless of
        // what's in the DB now — a real artifact of this test script, not of
        // the app (every real HTTP request is its own fresh PHP process).
        // The actual proof is the fetched pages below, each a fresh request.

        $whHtml  = $get('warehouses');
        $invHtml = $get('inventory_report');
        $assertNav("warehouses.php ($modeLabel)", $whHtml);
        $assertNav("inventory_report.php ($modeLabel)", $invHtml);

        $verb = $expectPresent ? 'shows' : 'hides';

        // 1. Adjustments nav link — "Marekebisho" (sw) / "Adjustments" (en)
        //    doesn't otherwise appear anywhere on warehouses.php.
        $hasAdjNav = str_contains($whHtml, 'Marekebisho') || str_contains($whHtml, '>Adjustments<');
        ok($hasAdjNav === $expectPresent, "header.php $verb the Adjustments nav link ($modeLabel)");

        // 2. Valuation nav link — "Uthamini" doesn't otherwise appear on
        //    warehouses.php.
        $hasValNav = str_contains($whHtml, 'Uthamini') || str_contains($whHtml, '>Valuation<');
        ok($hasValNav === $expectPresent, "header.php $verb the Valuation nav link ($modeLabel)");

        // 3. Locations nav link — matched precisely by icon+label adjacency
        //    so the page's own "Maeneo" stat card / table column (unrelated,
        //    always present) can't produce a false positive.
        $hasLocNav = preg_match('/bi-geo-alt"><\/i>\s*(Maeneo|Locations)</', $whHtml) === 1;
        ok($hasLocNav === $expectPresent, "header.php $verb the Locations nav link ($modeLabel)");

        // 4. Inventory Report "Stock Adjustments" tab — matched by its
        //    unique data-view attribute, not the label text (which also
        //    appears, inertly, inside the tab's own always-hidden
        //    <div id="view-adjustments"> when the button is gone).
        $hasAdjTab = str_contains($invHtml, 'data-view="adjustments"');
        ok($hasAdjTab === $expectPresent, "inventory_report.php $verb the Stock Adjustments tab ($modeLabel)");

        // 5. warehouses.php "Manage Locations" row action — matched by the
        //    onclick call site, not the JS function definition (which stays
        //    defined either way, just unused when hidden).
        $hasManageLoc = str_contains($whHtml, 'onclick="manageLocations(');
        ok($hasManageLoc === $expectPresent, "warehouses.php $verb the Manage Locations row action ($modeLabel)");

        // Regression: unaffected content must stay exactly as it always was.
        ok(str_contains($whHtml, 'onclick="transferStock(') || str_contains($whHtml, '>Transfer Stock<') || str_contains($whHtml, 'Hamisha Ghala'),
           "warehouses.php: Transfer Stock action unaffected ($modeLabel)");
        ok(preg_match('/data-view="(snapshot|movements|transfers)"/', $invHtml) === 1,
           "inventory_report.php: other tabs (Snapshot/Movements/Transfers) unaffected ($modeLabel)");
    }

} catch (Throwable $e) {
    echo "\n\033[31mFATAL: {$e->getMessage()}\033[0m\n";
    $fail++;
}

echo "\n\033[1m═══ Result ═══\033[0m\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "\033[32m✅ All $total checks passed.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $fail / $total check(s) failed.\033[0m\n\n";
    exit(1);
}
