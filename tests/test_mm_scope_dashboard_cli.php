<?php
/**
 * MM agent-scope — Phase 3: MM Dashboard + My Agents + agent view
 *   php tests/test_mm_scope_dashboard_cli.php
 *
 * Runtime via php-cgi against a committed fixture (removed in finally):
 * today's transactions A1=1,000 · A2=500 · B1=2,000 · C1=4,000 · D1=8,000  (company total 15,500).
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

section('Static + lint');
$dash = file_get_contents("$root/app/bms/mobile_money/mm_dashboard.php");
$view = file_get_contents("$root/app/bms/mobile_money/mm_agent_view.php");
ok(substr_count($dash, '$scopeTxn') >= 5, 'dashboard: transaction queries carry $scopeTxn');
ok(strpos($dash, "mmScopeSql('t.till_id', 'till', 'can_open_shift')") !== false, 'dashboard: Open Shift uses grant engine');
ok(strpos($dash, 'You are not assigned to any agent yet') !== false, 'dashboard: empty state present');
ok(strpos($dash, "mm_scope_notice") !== false, 'dashboard: consumes scope notice');
ok(strpos($view, 'mmAgentInScope($id)') !== false && strpos($view, "getUrl('unauthorized')") === false, 'agent view: grant-scoped, no blanket admin-only');
foreach (['mm_dashboard', 'mm_agent_view'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/app/bms/mobile_money/$f.php") . ' 2>&1', $o, $rc);
    ok($rc === 0, "$f.php lint-clean");
}

$fx = mmFixtureCreate($pdo); $U = $fx['users']; $A = $fx['agents'];
try {
    foreach (['A1' => 1000, 'A2' => 500, 'B1' => 2000, 'C1' => 4000, 'D1' => 8000] as $k => $amt) {
        mmFixtureTxn($pdo, $fx, $k, $amt, $fx['admin_id']);
    }
    mmFixtureTxn($pdo, $fx, 'A1', 99999, $fx['admin_id'], 'void');     // void never counts

    $kpi = function (string $html): ?string {   // "Today's Transactions" card volume line
        return preg_match('#<i class="bi bi-cash-stack"></i>\s*([\d,]+) TZS#', $html, $m) ? $m[1] : null;
    };

    section('Admin — company-wide');
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $fx['admin_id'], true);
    ok($r['location'] === null && stripos($r['out'], 'Fatal error') === false, 'admin dashboard renders');
    ok($kpi($r['out']) === '15,500', "admin today's volume = 15,500 (got " . var_export($kpi($r['out']), true) . ')');
    ok(strpos($r['out'], 'Top 5 Agents') !== false && strpos($r['out'], '>My Agents<') === false, 'admin sees Top 5, no My Agents block');

    section('FULL (agents A + B)');
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $U['FULL'], true);
    ok($kpi($r['out']) === '3,500', "FULL today's volume = 3,500 (A1+A2+B1) (got " . var_export($kpi($r['out']), true) . ')');
    ok(strpos($r['out'], 'ZZSCOPE Agent A') !== false && strpos($r['out'], 'ZZSCOPE Agent B') !== false, 'My Agents lists A and B');
    ok(strpos($r['out'], 'ZZSCOPE Agent C') === false && strpos($r['out'], 'ZZSCOPE Agent D') === false, 'C and D never shown');
    ok(strpos($r['out'], 'My Agents — This Month') !== false, 'Top-5 block relabelled for scoped user');
    ok(strpos($r['out'], 'mm_transactions') !== false && strpos($r['out'], 'New Transaction') !== false, 'New Transaction quick action shown');
    ok(preg_match('#mm_agent_view\?id=' . $A['A'] . '"#', $r['out']) === 1, 'My Agents card links to agent view');

    section('TILL (A1 only)');
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $U['TILL'], true);
    ok($kpi($r['out']) === '1,000', "TILL today's volume = 1,000 (A2 not leaked) (got " . var_export($kpi($r['out']), true) . ')');

    section('DEAD (suspended C + closed D)');
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $U['DEAD'], true);
    ok($kpi($r['out']) === '4,000', "DEAD today's volume = 4,000 (C history only; closed D excluded) (got " . var_export($kpi($r['out']), true) . ')');
    ok(strpos($r['out'], 'Suspended — history only') !== false, 'suspended agent flagged');
    ok(strpos($r['out'], 'New Transaction') === false, 'no New Transaction for suspended-only user');
    ok(strpos($r['out'], 'No tills assigned') !== false, 'Open Shift disabled (no live till)');

    section('NONE (no grant, full role)');
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $U['NONE'], true);
    ok($r['location'] === null, 'NONE dashboard renders (no redirect)');
    ok(strpos($r['out'], 'You are not assigned to any agent yet') !== false, 'empty state shown');
    foreach (["Today's Transactions", 'dailyChart', 'Top 5 Agents', 'New Transaction', 'Float Top-up', '15,500', '3,500'] as $needle) {
        ok(strpos($r['out'], $needle) === false, "NONE: '$needle' not rendered");
    }
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $U['NONE'], true, [], [], ['mm_scope_notice' => 'not_assigned']);
    ok(strpos($r['out'], 'That page is not available to you') !== false, 'redirect notice displayed once');

    // Revoked mid-shift: empty state offers "Close my open shift"
    $pdo->prepare("INSERT INTO mm_shifts (shift_code, till_id, teller_user_id, opened_at, status) VALUES ('ZZSCOPE-S9', ?, ?, NOW(), 'open')")
        ->execute([$fx['tills']['A1'], $U['NONE']]);
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $U['NONE'], true);
    ok(strpos($r['out'], 'Close my open shift') !== false, 'revoked teller sees Close-my-shift button (D5)');
    $pdo->exec("DELETE FROM mm_shifts WHERE shift_code='ZZSCOPE-S9'");

    section('Agent view');
    $r = mmFixtureRun('app/bms/mobile_money/mm_agent_view.php', $U['FULL'], true, ['id' => $A['A']]);
    ok($r['location'] === null && strpos($r['out'], 'ZZSCOPE Agent A') !== false, 'FULL views own agent A');
    ok(strpos($r['out'], 'ZZSCOPE-A1') !== false && strpos($r['out'], 'ZZSCOPE-A2') !== false, 'FULL sees A1 and A2 tills');
    ok(strpos($r['out'], 'id="addTillModal"') === false && strpos($r['out'], 'id="editAgentModal"') === false, 'read-only: no Add Till / Edit Agent');
    $r = mmFixtureRun('app/bms/mobile_money/mm_agent_view.php', $U['TILL'], true, ['id' => $A['A']]);
    ok(strpos($r['out'], 'ZZSCOPE-A1') !== false && strpos($r['out'], 'ZZSCOPE-A2') === false, 'TILL sees only till A1');
    $r = mmFixtureRun('app/bms/mobile_money/mm_agent_view.php', $U['FULL'], true, ['id' => $A['C']]);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'FULL → agent C (not granted) redirected');
    $r = mmFixtureRun('app/bms/mobile_money/mm_agent_view.php', $U['NONE'], true, ['id' => $A['A']]);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'NONE → agent view redirected');
    $r = mmFixtureRun('app/bms/mobile_money/mm_agent_view.php', $U['DEAD'], true, ['id' => $A['D']]);
    ok($r['location'] !== null, 'DEAD → closed agent D redirected');
    $r = mmFixtureRun('app/bms/mobile_money/mm_agent_view.php', $fx['admin_id'], true, ['id' => $A['B']]);
    ok($r['location'] === null && strpos($r['out'], 'id="addTillModal"') !== false, 'admin: any agent, full edit tools');
} finally {
    mmFixtureDestroy($pdo);
    echo "  (runtime fixture removed)\n";
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM mm_transactions WHERE txn_code LIKE 'ZZSCOPE%'")->fetchColumn()
      + (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'zzscope\\_%'")->fetchColumn();
ok($left === 0, 'no fixture rows left behind');
