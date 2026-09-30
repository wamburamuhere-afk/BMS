<?php
/**
 * MM agent-scope — Phase 6: edge cases
 *   php tests/test_mm_scope_edges_cli.php
 *
 * Grant revoked between requests · agent closed after granting · till suspended ·
 * inconsistent grant row (till of another agent) · duplicate agent-wide grants ·
 * multi-module tenant data scoping (D1) · admin unchanged. Runtime via php-cgi,
 * committed fixture removed in finally. Exit 0 = pass.
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
function apiJ(string $file, int $uid, array $post): array
{
    $j = json_decode(trim(mmFixtureRun("api/mobile_money/$file", $uid, true, [], $post)['out']), true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'NON-JSON'];
}

$jeBefore = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$fx = mmFixtureCreate($pdo); $U = $fx['users']; $T = $fx['tills']; $A = $fx['agents']; $ADMIN = $fx['admin_id'];
try {
    $act = mmFixtureSeedActivity($pdo, $fx);
    $today = date('Y-m-d');
    $txn = fn(int $till) => ['till_id' => $till, 'txn_type' => 'cash_in', 'amount' => 1000, 'txn_date' => $today];

    section('Grant revoked between two requests (no stale session cache)');
    $r = mmFixtureRun('app/bms/mobile_money/mm_transactions.php', $U['TILL'], true);
    ok($r['location'] === null && strpos($r['out'], 'ZZSCOPE-TX-A1') !== false, 'TILL sees A1 before revocation');
    $pdo->prepare("DELETE FROM mm_user_agent_grants WHERE user_id=?")->execute([$U['TILL']]);
    $r = mmFixtureRun('app/bms/mobile_money/mm_transactions.php', $U['TILL'], true);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'next request: page redirects to dashboard');
    $j = apiJ('save_transaction.php', $U['TILL'], $txn($T['A1']));
    ok(empty($j['success']), 'next request: API refuses (' . ($j['message'] ?? '') . ')');
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $U['TILL'], true);
    ok(strpos($r['out'], 'You are not assigned to any agent yet') !== false, 'dashboard now shows the empty state');

    section('Agent closed after the grant was given');
    $pdo->prepare("UPDATE mm_agents SET status='closed' WHERE agent_id=?")->execute([$A['B']]);
    $r = mmFixtureRun('app/bms/mobile_money/mm_transactions.php', $U['FULL'], true);
    ok(strpos($r['out'], 'ZZSCOPE-TX-B1') === false && strpos($r['out'], 'ZZSCOPE-TX-A1') !== false, 'FULL: closed agent B vanishes, A remains');
    ok(empty(apiJ('save_transaction.php', $U['FULL'], $txn($T['B1']))['success']), 'FULL: cannot record on closed agent B');
    $pdo->prepare("UPDATE mm_agents SET status='active' WHERE agent_id=?")->execute([$A['B']]);

    section('Till suspended (agent still active)');
    $pdo->prepare("UPDATE mm_tills SET status='suspended' WHERE till_id=?")->execute([$T['A2']]);
    $r = mmFixtureRun('app/bms/mobile_money/mm_float.php', $U['FULL'], true);
    ok(strpos($r['out'], 'ZZSCOPE-FM-A2') !== false, 'FULL still sees suspended A2 history');
    $j = apiJ('save_float_movement.php', $U['FULL'], ['till_id' => $T['A2'], 'movement_type' => 'float_topup', 'amount' => 10, 'movement_date' => $today]);
    ok(empty($j['success']), 'FULL cannot move float on suspended A2 (' . ($j['message'] ?? '') . ')');
    $j = apiJ('open_shift.php', $U['FULL'], ['till_id' => $T['A2']]);
    ok(empty($j['success']), 'FULL cannot open shift on suspended A2');
    $pdo->prepare("UPDATE mm_tills SET status='active' WHERE till_id=?")->execute([$T['A2']]);

    section('Inconsistent grant row: till_id belongs to another agent');
    // Grant says agent A but points at till B1 — must grant nothing (not B1, not all of A).
    $pdo->prepare("INSERT INTO mm_user_agent_grants (user_id, agent_id, till_id, can_open_shift, can_record_transactions, can_close_shift, can_reconcile)
                   VALUES (?, ?, ?, 1, 1, 1, 1)")->execute([$U['TILL'], $A['A'], $T['B1']]);
    $r = mmFixtureRun('app/bms/mobile_money/mm_transactions.php', $U['TILL'], true);
    ok($r['location'] !== null || (strpos($r['out'], 'ZZSCOPE-TX-B1') === false && strpos($r['out'], 'ZZSCOPE-TX-A1') === false),
       'mismatched grant leaks neither B1 nor agent A tills');
    ok(empty(apiJ('save_transaction.php', $U['TILL'], $txn($T['B1']))['success']), 'mismatched grant cannot record on B1');
    $pdo->prepare("DELETE FROM mm_user_agent_grants WHERE user_id=?")->execute([$U['TILL']]);

    section('Duplicate agent-wide grants');
    $pdo->prepare("INSERT INTO mm_user_agent_grants (user_id, agent_id, till_id, can_open_shift, can_record_transactions, can_close_shift, can_reconcile)
                   VALUES (?, ?, NULL, 1, 1, 1, 0)")->execute([$U['FULL'], $A['A']]);
    $r = mmFixtureRun('app/bms/mobile_money/mm_transactions.php', $U['FULL'], true);
    ok($r['location'] === null && strpos($r['out'], 'ZZSCOPE-TX-A1') !== false && strpos($r['out'], 'ZZSCOPE-TX-C1') === false,
       'duplicate grant rows: still exactly the granted scope');

    section('Multi-module tenant (D1): data still scoped, no lock-down');
    $r = mmFixtureRun('app/bms/mobile_money/mm_transactions.php', $U['FULL'], false);
    ok(strpos($r['out'], 'ZZSCOPE-TX-A1') !== false && strpos($r['out'], 'ZZSCOPE-TX-C1') === false
       && strpos($r['out'], 'ZZSCOPE-TX-D1') === false, 'multi-module FULL: own agents only');
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $U['NONE'], false);
    ok($r['location'] === null && strpos($r['out'], 'You are not assigned to any agent yet') !== false, 'multi-module NONE: dashboard empty state');
    $r = mmFixtureRun('app/bms/mobile_money/mm_reports.php', $U['NONE'], false, ['report' => 'txn_summary']);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'multi-module NONE: reports redirected');

    section('Admin unchanged');
    $r = mmFixtureRun('app/bms/mobile_money/mm_transactions.php', $ADMIN, true);
    $all = true;
    foreach (['A1', 'A2', 'B1', 'C1', 'D1'] as $k) $all = $all && strpos($r['out'], "ZZSCOPE-TX-$k") !== false;
    ok($all, 'admin sees every till (incl. suspended C and closed D)');
    $j = apiJ('save_transaction.php', $ADMIN, $txn($T['C1']));
    ok(strpos(strtolower($j['message'] ?? ''), 'not granted') === false, 'admin never gets a grant denial');
} finally {
    mmFixtureDestroy($pdo);
    echo "  (runtime fixture removed)\n";
}
ok((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'zzscope\\_%'")->fetchColumn() === 0, 'no fixture users left');
ok((int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeBefore, 'journal_entries count restored');
