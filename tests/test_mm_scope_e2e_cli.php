<?php
/**
 * MM agent-scope — Phase 7: end-to-end teller day through the real APIs
 *   php tests/test_mm_scope_e2e_cli.php
 *
 * Granted teller (TILL, till-only grant on A1, plus can_reconcile turned on for this run):
 *   open shift → cash-in → float top-up → close shift → start reconciliation → resolve.
 * Every step repeated on a foreign till (B1) is refused. Each successful step is checked
 * in the DB; ledger stays balanced; all rows + ledger entries removed in finally.
 * Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
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
function call(string $file, int $uid, array $post): array
{
    $j = json_decode(trim(mmFixtureRun("api/mobile_money/$file", $uid, true, [], $post)['out']), true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'NON-JSON'];
}

$jeBefore  = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$balBefore = assertLedgerBalanced($pdo);
$fx = mmFixtureCreate($pdo); $U = $fx['users']; $T = $fx['tills'];
$me = $U['TILL']; $mine = $T['A1']; $foreign = $T['B1'];
$today = date('Y-m-d');
try {
    $pdo->prepare("UPDATE mm_user_agent_grants SET can_reconcile=1 WHERE user_id=?")->execute([$me]);

    section('1. Open shift');
    ok(empty(call('open_shift.php', $me, ['till_id' => $foreign, 'opening_cash' => 100000, 'opening_float' => 500000])['success']), 'foreign till B1 refused');
    $j = call('open_shift.php', $me, ['till_id' => $mine, 'opening_cash' => 100000, 'opening_float' => 500000]);
    ok(!empty($j['success']), 'own till A1 opened (' . ($j['message'] ?? '') . ')');
    $shift = $pdo->prepare("SELECT * FROM mm_shifts WHERE till_id=? AND teller_user_id=? AND status='open'");
    $shift->execute([$mine, $me]);
    $shift = $shift->fetch(PDO::FETCH_ASSOC);
    ok($shift && (float)$shift['opening_cash'] === 100000.0, 'DB: open shift row with opening cash 100,000');

    section('2. Record cash-in');
    $txn = ['txn_type' => 'cash_in', 'amount' => 25000, 'txn_date' => $today, 'customer_phone' => '255711000000'];
    ok(empty(call('save_transaction.php', $me, $txn + ['till_id' => $foreign])['success']), 'foreign till B1 refused');
    $j = call('save_transaction.php', $me, $txn + ['till_id' => $mine]);
    ok(!empty($j['success']), 'cash-in on A1 posted (' . ($j['message'] ?? '') . ')');
    $row = $pdo->prepare("SELECT status, journal_entry_id, teller_user_id, agent_id, shift_id FROM mm_transactions WHERE mm_txn_id=?");
    $row->execute([(int)($j['txn_id'] ?? 0)]);
    $row = $row->fetch(PDO::FETCH_ASSOC);
    ok($row && $row['status'] === 'posted' && (int)$row['journal_entry_id'] > 0, 'DB: transaction posted with a journal entry');
    ok($row && (int)$row['shift_id'] === (int)$shift['shift_id'], "DB: transaction linked to the teller's open shift");
    ok($row && (int)$row['teller_user_id'] === $me && (int)$row['agent_id'] === $fx['agents']['A'], 'DB: teller + agent recorded correctly');

    section('3. Float top-up');
    $fm = ['movement_type' => 'float_topup', 'amount' => 50000, 'movement_date' => $today];
    ok(empty(call('save_float_movement.php', $me, $fm + ['till_id' => $foreign])['success']), 'foreign till B1 refused');
    $j = call('save_float_movement.php', $me, $fm + ['till_id' => $mine]);
    ok(!empty($j['success']), 'top-up on A1 posted (' . ($j['message'] ?? '') . ')');
    ok((int)$pdo->query("SELECT COUNT(*) FROM mm_float_movements WHERE till_id=$mine AND amount=50000")->fetchColumn() === 1, 'DB: float movement row');

    section('4. Close shift');
    $foreignShift = (int)$pdo->query("SELECT 0")->fetchColumn();
    $pdo->prepare("INSERT INTO mm_shifts (shift_code, till_id, teller_user_id, opened_at, status) VALUES ('ZZSCOPE-E2E-B1', ?, ?, NOW(), 'open')")
        ->execute([$foreign, $fx['admin_id']]);
    $foreignShift = (int)$pdo->lastInsertId();
    ok(empty(call('close_shift.php', $me, ['shift_id' => $foreignShift, 'closing_cash' => 0, 'closing_float' => 0])['success']), "someone else's B1 shift refused");
    $j = call('close_shift.php', $me, ['shift_id' => (int)$shift['shift_id'], 'closing_cash' => 125000, 'closing_float' => 475000]);
    ok(!empty($j['success']), 'own shift closed (' . ($j['message'] ?? '') . ')');
    $closed = $pdo->query("SELECT status, expected_cash, expected_float FROM mm_shifts WHERE shift_id=" . (int)$shift['shift_id'])->fetch(PDO::FETCH_ASSOC);
    ok($closed['status'] === 'closed' && (float)$closed['expected_cash'] === 125000.0 && (float)$closed['expected_float'] === 475000.0,
       'DB: shift closed, expected cash 125,000 / float 475,000 (opening ± cash-in)');
    $j = call('save_transaction.php', $me, $txn + ['till_id' => $mine]);
    ok(empty($j['success']) && stripos($j['message'] ?? '', 'open a shift') !== false, 'after close: recording refused until a new shift is opened');

    section('5. Reconcile');
    ok(empty(call('save_reconciliation.php', $me, ['till_id' => $foreign, 'recon_date' => $today])['success']), 'foreign till B1 refused');
    $j = call('save_reconciliation.php', $me, ['till_id' => $mine, 'recon_date' => $today]);
    ok(!empty($j['success']), 'reconciliation started on A1 (' . ($j['message'] ?? '') . ')');
    $rid = (int)$pdo->query("SELECT recon_id FROM mm_reconciliations WHERE till_id=$mine AND recon_date=" . $pdo->quote($today))->fetchColumn();
    ok($rid > 0, 'DB: reconciliation row');
    $j = call('update_reconciliation.php', $me, ['recon_id' => $rid, 'action' => 'resolve', 'actual_cash' => 125000, 'actual_float' => 475000]);
    ok(!empty($j['success']), 'reconciliation resolved (' . ($j['message'] ?? '') . ')');
    ok($pdo->query("SELECT status FROM mm_reconciliations WHERE recon_id=$rid")->fetchColumn() === 'resolved', 'DB: status resolved');

    section('6. What the teller sees afterwards');
    $r = mmFixtureRun('app/bms/mobile_money/mm_dashboard.php', $me, true);
    ok(preg_match('#<i class="bi bi-cash-stack"></i>\s*25,000 TZS#', $r['out']) === 1, "dashboard: today's volume = own 25,000");
    $r = mmFixtureRun('app/bms/mobile_money/mm_reports.php', $me, true, ['report' => 'shift_summary']);
    ok(strpos($r['out'], (string)$shift['shift_code']) !== false && strpos($r['out'], 'ZZSCOPE-E2E-B1') === false, 'shift report: own shift only');

    section('Ledger');
    $bal = assertLedgerBalanced($pdo);
    ok(abs((float)$bal['dr_cr_difference'] - (float)$balBefore['dr_cr_difference']) < 0.01,
       'the day\'s postings are balanced: Dr−Cr difference unchanged (' . $balBefore['dr_cr_difference'] . ' → ' . $bal['dr_cr_difference'] . ')');
    ok((float)$bal['sum_debit'] > (float)$balBefore['sum_debit'], 'ledger actually received the day\'s postings');
} finally {
    mmFixtureDestroy($pdo);
    echo "  (fixture + posted ledger entries removed)\n";
}
ok((int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeBefore, 'journal_entries count restored');
