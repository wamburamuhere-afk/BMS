<?php
/**
 * MM agent-scope — Phase 5: server-side grant enforcement on every MM API
 *   php tests/test_mm_scope_api_cli.php
 *
 * Forged POST/GET requests (via php-cgi, real CSRF token) from each persona against own
 * and foreign tills. "passes the gate" = response is not a grant/permission denial.
 * Committed fixture incl. any ledger entries posted, removed in finally.
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

function api(string $file, int $uid, array $post = [], array $get = []): array
{
    $r = mmFixtureRun("api/mobile_money/$file", $uid, true, $get, $post);
    $j = json_decode(trim($r['out']), true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'NON-JSON: ' . substr(trim($r['out']), 0, 160)];
}
function denied(array $j): bool
{
    $m = strtolower($j['message'] ?? '');
    return empty($j['success']) && (strpos($m, 'not granted') !== false || strpos($m, 'permission denied') !== false
        || strpos($m, 'not assigned') !== false || strpos($m, 'no grant') !== false
        || strpos($m, 'admin access required') !== false);
}
function passedGate(array $j): bool { return !denied($j) && strpos($j['message'] ?? '', 'NON-JSON') !== 0; }

section('Static + lint');
$apis = ['save_transaction', 'void_transaction', 'save_float_movement', 'get_till_float', 'save_reconciliation',
         'update_reconciliation', 'open_shift', 'close_shift', 'batch_open_shifts', 'batch_close_shifts'];
foreach ($apis as $a) {
    $src = file_get_contents("$root/api/mobile_money/$a.php");
    ok(preg_match('/mmUserCanOnTill|mmUserCanCloseShift|mmRequireTill|mmReconRequireGrant/', $src) === 1, "$a.php enforces the agent grant");
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/api/mobile_money/$a.php") . ' 2>&1', $o, $rc);
    ok($rc === 0, "$a.php lint-clean");
}
$o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/core/mm_float_service.php") . ' 2>&1', $o, $rc);
ok($rc === 0, 'core/mm_float_service.php lint-clean');

$jeBefore = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$fx = mmFixtureCreate($pdo); $U = $fx['users']; $T = $fx['tills']; $A = $fx['agents']; $ADMIN = $fx['admin_id'];
try {
    $act = mmFixtureSeedActivity($pdo, $fx);
    $today = date('Y-m-d'); $yday = date('Y-m-d', strtotime('-1 day'));
    $txn = fn(int $till) => ['till_id' => $till, 'txn_type' => 'cash_in', 'amount' => 1000, 'txn_date' => $today];

    section('save_transaction');
    ok(passedGate($j = api('save_transaction.php', $U['FULL'], $txn($T['A1']))), 'FULL → A1 passes gate (' . ($j['message'] ?? '') . ')');
    ok(denied(api('save_transaction.php', $U['FULL'], $txn($T['C1']))), 'FULL → C1 (not granted) denied');
    ok(denied(api('save_transaction.php', $U['TILL'], $txn($T['A2']))), 'TILL → A2 (other till of same agent) denied');
    ok(denied(api('save_transaction.php', $U['NOREC'], $txn($T['A1']))), 'NOREC → A1 (can_record=0) denied');
    ok(denied(api('save_transaction.php', $U['DEAD'], $txn($T['C1']))), 'DEAD → C1 (suspended agent) denied');
    ok(denied(api('save_transaction.php', $U['NONE'], $txn($T['A1']))), 'NONE → denied');
    ok(passedGate(api('save_transaction.php', $ADMIN, $txn($T['B1']))), 'admin → B1 passes gate');

    section('void_transaction');
    $void = fn(int $id) => ['txn_id' => $id, 'void_reason' => 'scope test'];
    ok(denied(api('void_transaction.php', $U['FULL'], $void($act['txn']['C1']))), 'FULL voids C1 txn → denied');
    ok(denied(api('void_transaction.php', $U['NOREC'], $void($act['txn']['A1']))), 'NOREC voids A1 txn → denied');
    ok(denied(api('void_transaction.php', $U['TILL'], $void($act['txn']['A2']))), 'TILL voids A2 txn → denied');
    ok(passedGate($j = api('void_transaction.php', $U['FULL'], $void($act['txn']['B1']))), 'FULL voids own B1 txn → passes (' . ($j['message'] ?? '') . ')');

    section('save_float_movement');
    $fm = fn(int $till) => ['till_id' => $till, 'movement_type' => 'float_topup', 'amount' => 500, 'movement_date' => $today];
    ok(passedGate($j = api('save_float_movement.php', $U['FULL'], $fm($T['A1']))), 'FULL → A1 top-up passes (' . ($j['message'] ?? '') . ')');
    ok(denied(api('save_float_movement.php', $U['FULL'], $fm($T['C1']))), 'FULL → C1 denied');
    ok(denied(api('save_float_movement.php', $U['NOREC'], $fm($T['A1']))), 'NOREC → A1 denied (D2)');
    ok(denied(api('save_float_movement.php', $U['DEAD'], $fm($T['C1']))), 'DEAD → suspended C1 denied');

    section('get_till_float');
    ok(!empty(api('get_till_float.php', $U['FULL'], [], ['till_id' => $T['A1']])['success']), 'FULL reads A1 float');
    ok(denied(api('get_till_float.php', $U['FULL'], [], ['till_id' => $T['C1']])), 'FULL reads C1 float → denied');
    ok(!empty(api('get_till_float.php', $U['DEAD'], [], ['till_id' => $T['C1']])['success']), 'DEAD reads suspended C1 (view) → allowed');

    section('save_reconciliation (create / EDIT / DELETE) + update_reconciliation');
    ok(passedGate($j = api('save_reconciliation.php', $U['MIXED'], ['till_id' => $T['A2'], 'recon_date' => $yday])), 'MIXED create on A2 passes (' . ($j['message'] ?? '') . ')');
    ok(denied(api('save_reconciliation.php', $U['MIXED'], ['till_id' => $T['A1'], 'recon_date' => $yday])), 'MIXED create on A1 (no reconcile) denied');
    ok(denied(api('save_reconciliation.php', $U['FULL'], ['till_id' => $T['C1'], 'recon_date' => $yday])), 'FULL create on C1 denied');
    ok(denied(api('save_reconciliation.php', $U['MIXED'], ['_method' => 'EDIT', 'recon_id' => $act['recon']['A1'], 'recon_date' => $today])), 'MIXED EDIT A1 recon denied');
    ok(passedGate(api('save_reconciliation.php', $U['MIXED'], ['_method' => 'EDIT', 'recon_id' => $act['recon']['A2'], 'recon_date' => $today])), 'MIXED EDIT A2 recon passes');
    ok(denied(api('save_reconciliation.php', $U['FULL'], ['_method' => 'DELETE', 'recon_id' => $act['recon']['C1']])), 'FULL DELETE C1 recon denied');
    ok(denied(api('update_reconciliation.php', $U['TILL'], ['recon_id' => $act['recon']['A1'], 'action' => 'resolve', 'actual_cash' => 0, 'actual_float' => 0])), 'TILL resolve A1 denied');
    ok(passedGate($j = api('update_reconciliation.php', $U['FULL'], ['recon_id' => $act['recon']['A1'], 'action' => 'resolve', 'actual_cash' => 0, 'actual_float' => 0])), 'FULL resolve A1 passes (' . ($j['message'] ?? '') . ')');

    section('open_shift / batch_open_shifts');
    ok(denied(api('open_shift.php', $U['DEAD'], ['till_id' => $T['C1']])), 'DEAD open on suspended C1 denied');
    ok(denied(api('open_shift.php', $U['FULL'], ['till_id' => $T['C1']])), 'FULL open on C1 denied');
    ok(!empty(($j = api('open_shift.php', $U['FULL'], ['till_id' => $T['A2']]))['success']), 'FULL opens shift on A2 (' . ($j['message'] ?? '') . ')');
    $b = api('batch_open_shifts.php', $U['FULL'], ['tills' => json_encode([['till_id' => $T['C1']], ['till_id' => $T['B1']]])]);
    $byTill = [];
    foreach (($b['results'] ?? []) as $row) $byTill[(int)($row['till_id'] ?? 0)] = $row;
    ok(isset($byTill[$T['C1']]) && empty($byTill[$T['C1']]['success']), 'batch_open: C1 row rejected');
    ok(isset($byTill[$T['B1']]) && !empty($byTill[$T['B1']]['success']), 'batch_open: B1 row opened');

    section('close_shift / batch_close_shifts (D5)');
    $mkShift = function (int $till, int $teller) use ($pdo): int {
        $pdo->prepare("INSERT INTO mm_shifts (shift_code, till_id, teller_user_id, opened_at, status) VALUES (?, ?, ?, NOW(), 'open')")
            ->execute(['ZZSCOPE-OS-' . mt_rand(10000, 99999), $till, $teller]);
        return (int)$pdo->lastInsertId();
    };
    $close = fn(int $id) => ['shift_id' => $id, 'closing_cash' => 0, 'closing_float' => 0];
    $sAdminC1 = $mkShift($T['C1'], $ADMIN);
    ok(denied(api('close_shift.php', $U['FULL'], $close($sAdminC1))), 'FULL closes shift on C1 → denied');
    $sNone = $mkShift($T['B1'], $U['NONE']);        // NONE holds no grant at all (revoked)
    ok(!empty(($j = api('close_shift.php', $U['NONE'], $close($sNone)))['success']), 'revoked teller closes OWN shift (D5) (' . ($j['message'] ?? '') . ')');
    $pdo->prepare("UPDATE mm_user_agent_grants SET can_close_shift=0 WHERE user_id=?")->execute([$U['NOREC']]);
    $sNorec = $mkShift($T['A1'], $U['NOREC']);
    ok(denied(api('close_shift.php', $U['NOREC'], $close($sNorec))), 'explicit can_close_shift=0 still blocks own-shift close');
    $sNone2 = $mkShift($T['A3'], $U['NONE']);
    $b = api('batch_close_shifts.php', $U['NONE'], ['shifts' => json_encode([$close($sNone2), $close($sAdminC1)])]);
    $byShift = [];
    foreach (($b['results'] ?? []) as $row) $byShift[(int)($row['shift_id'] ?? 0)] = $row;
    ok(!empty($byShift[$sNone2]['success']), 'batch_close: revoked teller closes own shift');
    ok(isset($byShift[$sAdminC1]) && empty($byShift[$sAdminC1]['success']), "batch_close: someone else's shift rejected");

    section('Company-level APIs stay admin-only');
    ok(denied(api('save_agent.php', $U['FULL'], ['agent_name' => 'ZZSCOPE hack'])), 'FULL save_agent denied');
    ok(denied(api('save_till.php', $U['FULL'], ['agent_id' => $A['A'], 'network_id' => $fx['network_id'], 'till_number' => 'ZZSCOPE-HACK'])), 'FULL save_till denied');
    ok(denied(api('save_network.php', $U['FULL'], ['network_name' => 'ZZSCOPE net'])), 'FULL save_network denied');
    ok(denied(api('save_commission_rate.php', $U['FULL'], ['network_id' => $fx['network_id'], 'txn_type' => 'cash_in', 'amount_from' => 0, 'rate_value' => 1])),
       'FULL save_commission_rate (valid body) denied');
    ok(denied(api('save_commission_rate.php', $U['FULL'], ['_method' => 'DELETE', 'rate_id' => 999999])), 'FULL delete commission rate denied');
    ok(denied(api('save_commission_received.php', $U['FULL'], ['network_id' => $fx['network_id'], 'amount_received' => 1])), 'FULL save_commission_received denied');
    ok((int)$pdo->query("SELECT COUNT(*) FROM mm_agents WHERE agent_name='ZZSCOPE hack'")->fetchColumn() === 0, 'no agent row written by the forged request');
} finally {
    mmFixtureDestroy($pdo);
    echo "  (runtime fixture + posted ledger entries removed)\n";
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM mm_transactions WHERE till_id NOT IN (SELECT till_id FROM mm_tills)")->fetchColumn()
      + (int)$pdo->query("SELECT COUNT(*) FROM mm_shifts WHERE shift_code LIKE 'ZZSCOPE%' OR shift_code LIKE 'MM-SFT%' AND till_id NOT IN (SELECT till_id FROM mm_tills)")->fetchColumn()
      + (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'zzscope\\_%'")->fetchColumn();
ok($left === 0, 'no fixture rows left behind');
ok((int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeBefore, 'journal_entries count restored (no stray ledger postings)');
