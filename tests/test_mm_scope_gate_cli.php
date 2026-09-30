<?php
/**
 * MM agent-scope — Phase 2: central permission gate + nav + redirect
 *   php tests/test_mm_scope_gate_cli.php
 *
 * A. STATIC  — hook present in every canX(); requireViewPermission redirect.
 * B. MATRIX  — canX() results per persona × tenant mode (in-process, rolled back).
 * C. RUNTIME — real pages via php-cgi: nav links shown/hidden, grant-denied pages redirect
 *              to mm_dashboard (committed fixture, removed in finally).
 * Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once __DIR__ . '/mm_scope_fixture.inc.php';
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});
if (session_status() === PHP_SESSION_NONE) @session_start();

// ── A. Static ────────────────────────────────────────────────────────────────
section('A. Static — hook in every permission function');
$perm = file_get_contents("$root/core/permissions.php");
foreach (['canView', 'canCreate', 'canEdit', 'canDelete', 'canReview', 'canApprove', 'canSubmit', 'canReject'] as $fn) {
    $start = strpos($perm, "function $fn(");
    $end   = strpos($perm, "\nfunction ", $start + 10);
    $body  = substr($perm, $start, $end - $start);
    $adminPos = strpos($body, 'isAdmin()');
    $hookPos  = strpos($body, 'mmGrantAllowsPage');
    ok($hookPos !== false, "$fn() calls mmGrantAllowsPage");
    ok($adminPos !== false && $hookPos > $adminPos, "$fn(): hook sits AFTER the admin bypass");
}
$rv = substr($perm, strpos($perm, 'function requireViewPermission('), 600);
ok(strpos($rv, 'mmDenyToDashboard()') !== false, 'requireViewPermission → mmDenyToDashboard on grant denial');
$o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/core/permissions.php") . ' 2>&1', $o, $rc);
ok($rc === 0, 'core/permissions.php lint-clean');

// ── B. Matrix ────────────────────────────────────────────────────────────────
section('B. Permission matrix (in-process, rolled back)');
$mmOps = ['mm_transactions', 'mm_float', 'mm_reports', 'mm_shifts', 'mm_reconciliation', 'mm_compliance', 'mm_commissions'];
$pdo->beginTransaction();
try {
    $fx = mmFixtureCreate($pdo); $U = $fx['users'];

    // MM-only tenant
    mmFixtureTenant(true);
    mmFixtureAs($pdo, $U['NONE']);
    foreach ($mmOps as $k) ok(!canView($k), "MM-only · NONE (full role): canView('$k') = false");
    ok(!canCreate('mm_transactions') && !canEdit('mm_shifts') && !canDelete('mm_transactions'), 'MM-only · NONE: create/edit/delete denied');
    ok(!canView('expenses'), "MM-only · NONE: canView('expenses') = false (non-MM key locked)");
    ok(!canView('users') && !canView('system_settings'), 'MM-only · NONE: users/system_settings denied');
    ok(!canReview('mm_reconciliation') && !canApprove('mm_reconciliation') && !canSubmit('mm_transactions') && !canReject('mm_transactions'),
       'MM-only · NONE: workflow verbs denied');
    ok(canView('mm_dashboard'), 'MM-only · NONE: mm_dashboard allowed');
    ok(canView('my_settings') && canView('profile') && canView('help'), 'MM-only · NONE: personal pages allowed (whitelist)');

    mmFixtureAs($pdo, $U['FULL']);
    foreach ($mmOps as $k) ok(canView($k), "MM-only · FULL: canView('$k') = true");
    ok(canCreate('mm_transactions') && canEdit('mm_shifts'), 'MM-only · FULL: create/edit allowed');
    ok(canView('expenses'), 'MM-only · FULL: expenses follows role (true)');
    ok(canView('mm_commission_rates') && !canCreate('mm_commission_rates') && !canEdit('mm_commission_rates'),
       'MM-only · FULL: commission rates read-only (D3)');
    ok(canView('mm_commissions') && !canCreate('mm_commissions'), 'MM-only · FULL: commission received = admin-only write (D3)');
    ok(!canCreate('mm_agents') && !canEdit('mm_networks'), 'MM-only · FULL: agent/network writes admin-only');

    mmFixtureAs($pdo, $U['NOROLE']);
    ok(!canView('mm_transactions') && !canCreate('mm_transactions'), 'MM-only · NOROLE (grant, empty role): role still narrows');
    ok(canView('mm_dashboard'), 'MM-only · NOROLE: dashboard is always the landing page');

    mmFixtureAs($pdo, $U['DEAD']);
    ok(canView('mm_transactions'), 'MM-only · DEAD: suspended agent grant still counts for viewing history');

    // Revoked mid-shift → only mm_shifts
    $pdo->prepare("INSERT INTO mm_shifts (shift_code, till_id, teller_user_id, opened_at, status) VALUES ('ZZSCOPE-S1', ?, ?, NOW(), 'open')")
        ->execute([$fx['tills']['A1'], $U['NONE']]);
    mmFixtureAs($pdo, $U['NONE']);
    ok(canView('mm_shifts') && canEdit('mm_shifts'), 'MM-only · NONE with own open shift: mm_shifts view+edit (D5 close)');
    ok(!canView('mm_transactions'), 'MM-only · NONE with own open shift: still no transactions');
    $pdo->exec("DELETE FROM mm_shifts WHERE shift_code='ZZSCOPE-S1'");

    // Multi-module tenant
    mmFixtureTenant(false);
    mmFixtureAs($pdo, $U['NONE']);
    ok(!canView('mm_transactions') && !canView('mm_float'), 'Multi · NONE: MM pages still need a grant');
    ok(canView('expenses') && canView('users'), 'Multi · NONE: non-MM modules follow the role (no lock-down)');
    ok(canView('mm_dashboard'), 'Multi · NONE: mm_dashboard follows role (full role → true)');
    mmFixtureAs($pdo, $U['NOROLE']);
    ok(!canView('mm_dashboard'), 'Multi · NOROLE: mm_dashboard not forced open outside MM-only');

    // Admin
    mmFixtureTenant(true);
    mmFixtureAs($pdo, $fx['admin_id']);
    ok(canView('mm_transactions') && canCreate('mm_agents') && canEdit('mm_commission_rates') && canView('users'), 'Admin: everything allowed');
} finally {
    $pdo->rollBack();
    unset($GLOBALS['__bms_features']);
    echo "  (matrix transaction rolled back)\n";
}

// ── C. Runtime ───────────────────────────────────────────────────────────────
section('C. Runtime via php-cgi (committed fixture, cleaned up)');
$fx = mmFixtureCreate($pdo); $U = $fx['users'];
try {
    $navHas = function (string $html, string $key): bool {
        return (bool)preg_match('#href="[^"]*/' . preg_quote($key, '#') . '(\?[^"]*)?"#', $html);
    };

    // NONE, MM-only: personal page renders, nav stripped
    $r = mmFixtureRun('app/constant/settings/my_settings.php', $U['NONE'], true);
    ok($r['location'] === null && strlen($r['out']) > 2000, 'NONE · my_settings renders (no redirect)');
    foreach (['mm_transactions', 'mm_float', 'mm_reports', 'mm_shifts', 'mm_commissions', 'mm_reconciliation',
              'mm_compliance', 'mm_commission_rates', 'expenses', 'mm_agents', 'mm_networks', 'mm_user_agent_grants', 'system_settings'] as $k) {
        ok(!$navHas($r['out'], $k), "NONE · nav hides '$k'");
    }
    ok($navHas($r['out'], 'mm_dashboard'), 'NONE · nav keeps Dashboard');
    ok($navHas($r['out'], 'my_settings') && $navHas($r['out'], 'logout'), 'NONE · account menu keeps My Settings + Logout');

    // FULL, MM-only: operational nav present, admin-only setup absent
    $r = mmFixtureRun('app/constant/settings/my_settings.php', $U['FULL'], true);
    foreach (['mm_transactions', 'mm_float', 'mm_reports', 'mm_shifts', 'mm_reconciliation'] as $k) {
        ok($navHas($r['out'], $k), "FULL · nav shows '$k'");
    }
    foreach (['mm_agents', 'mm_networks', 'mm_user_agent_grants', 'system_settings'] as $k) {
        ok(!$navHas($r['out'], $k), "FULL · nav hides admin-only '$k'");
    }

    // Admin: setup visible
    $r = mmFixtureRun('app/constant/settings/my_settings.php', $fx['admin_id'], true);
    foreach (['mm_agents', 'mm_networks', 'mm_user_agent_grants', 'mm_transactions'] as $k) {
        ok($navHas($r['out'], $k), "Admin · nav shows '$k'");
    }

    // Grant-denied pages → mm_dashboard
    $mmPages = ['mm_transactions', 'mm_float', 'mm_reports', 'mm_shifts', 'mm_reconciliation', 'mm_compliance',
                'mm_commissions', 'mm_commission_rates', 'mm_shift_report', 'mm_transaction_view', 'mm_recon_view'];
    foreach ($mmPages as $p) {
        $r = mmFixtureRun("app/bms/mobile_money/$p.php", $U['NONE'], true);
        ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false && $r['notice'] === 'not_assigned',
           "NONE · $p.php → redirected to mm_dashboard");
    }
    $r = mmFixtureRun('app/constant/accounts/expenses.php', $U['NONE'], true);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'NONE · expenses.php (MM-only) → mm_dashboard');
    foreach (['mm_agents', 'mm_networks'] as $p) {
        $r = mmFixtureRun("app/bms/mobile_money/$p.php", $U['FULL'], true);
        ok($r['location'] !== null, "FULL · admin-only $p.php still denied");
    }
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $U['NONE'], true);
    ok($r['location'] === null, 'NONE · mm_dashboard renders (no redirect loop)');

    // Role still narrows: NOROLE → unauthorized, not dashboard
    $r = mmFixtureRun('app/bms/mobile_money/mm_transactions.php', $U['NOROLE'], true);
    ok($r['location'] !== null && strpos($r['location'], 'unauthorized') !== false && $r['notice'] === null,
       'NOROLE · mm_transactions → unauthorized (role denial, not grant denial)');

    // Granted user reaches the page
    $r = mmFixtureRun('app/bms/mobile_money/mm_transactions.php', $U['FULL'], true);
    ok($r['location'] === null && stripos($r['out'], 'Fatal error') === false, 'FULL · mm_transactions renders');

    // Multi-module: MM page gated, non-MM page open
    $r = mmFixtureRun('app/bms/mobile_money/mm_float.php', $U['NONE'], false);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'Multi · NONE · mm_float → mm_dashboard');
    $r = mmFixtureRun('app/constant/accounts/expenses.php', $U['NONE'], false);
    ok($r['location'] === null, 'Multi · NONE · expenses renders (no lock-down outside MM-only)');

    // API layer follows the same gate
    $r = mmFixtureRun('api/mobile_money/save_transaction.php', $U['NONE'], true, [], ['till_id' => $fx['tills']['A1']]);
    ok(strpos($r['out'], 'Permission denied') !== false, 'NONE · save_transaction API → Permission denied');
    $r = mmFixtureRun('api/mobile_money/open_shift.php', $U['NONE'], true, [], ['till_id' => $fx['tills']['A1']]);
    ok(strpos($r['out'], 'Permission denied') !== false, 'NONE · open_shift API → Permission denied');
} finally {
    mmFixtureDestroy($pdo);
    echo "  (runtime fixture removed)\n";
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'zzscope\\_%'")->fetchColumn()
      + (int)$pdo->query("SELECT COUNT(*) FROM mm_agents WHERE agent_code LIKE 'ZZSCOPE%'")->fetchColumn();
ok($left === 0, 'no fixture rows left behind');
