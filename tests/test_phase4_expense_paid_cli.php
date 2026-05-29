<?php
/**
 * Phase 4.5 — auto-post on expense paid CLI test
 * -----------------------------------------------
 *   php tests/test_phase4_expense_paid_cli.php
 *
 * Verifies:
 *   1. update_expense_status.php lint-clean.
 *   2. Wiring source patterns:
 *      - includes core/auto_post_hook.php
 *      - fetches expense snapshot BEFORE the UPDATE
 *      - adds beginTransaction()/commit() around UPDATE + sig + auto-post
 *      - rollBack() in the catch block
 *      - calls autoPostEvent('expense_paid', 'expense', ...)
 *      - amount = $expense_snap['amount']
 *      - entry_date = $expense_snap['expense_date']
 *      - project_id pass-through from expense
 *      - only posts when $status === 'paid' (reviewed/approved skipped)
 *      - surfaces journal_entry_id / ledger_warning on response
 *      - ordering: snapshot < beginTransaction < UPDATE < autoPostEvent < commit
 *   3. Live-DB end-to-end (BEGIN/ROLLBACK isolation) — 'expense_paid'
 *      event slug round-trips through autoPostEvent → postLedgerEntry,
 *      idempotency holds.
 *   4. Phase 4.3 + 4.4 tests still pass.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/auto_post_hook.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$file = "$root/api/account/update_expense_status.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
file_exists($file) ? pass('update_expense_status.php exists') : fail('missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($file);
$checks = [
    "require_once __DIR__ . '/../../core/auto_post_hook.php'" => 'includes auto_post_hook',
    "autoPostEvent("                                           => 'calls autoPostEvent',
    "'expense_paid'"                                           => 'uses expense_paid event slug',
    "'expense'"                                                => 'uses expense entity_type',
    "SELECT amount, expense_date, project_id"                  => 'fetches expense snapshot BEFORE UPDATE',
    "\$pdo->beginTransaction()"                                 => 'adds beginTransaction (was missing)',
    "\$pdo->commit()"                                           => 'commits after auto-post',
    "if (isset(\$pdo) && \$pdo->inTransaction()) \$pdo->rollBack()" => 'rolls back on exception',
    "if (\$status === 'paid'"                                  => 'only posts on paid transition',
    "'status_not_paid'"                                        => 'non-paid statuses report status_not_paid',
    "\$expense_snap['amount']"                                 => 'amount = expense.amount',
    "\$expense_snap['expense_date']"                           => 'entry_date = expense_date',
    "\$expense_snap['project_id']"                             => 'project_id pass-through',
    'journal_entry_id'                                         => 'surfaces successful entry_id to response',
    'ledger_warning'                                           => 'surfaces mapping_not_configured to response',
    'already_posted'                                           => 'logs already_posted case to audit trail',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// Order check: snapshot fetch < beginTransaction < UPDATE < autoPostEvent < commit
$pos_snap   = strpos($src, "SELECT amount, expense_date, project_id");
$pos_begin  = strpos($src, "\$pdo->beginTransaction()");
$pos_update = strpos($src, "UPDATE expenses SET status");
$pos_post   = strpos($src, "autoPostEvent(");
$pos_commit = strpos($src, "\$pdo->commit()");

($pos_snap !== false && $pos_begin !== false && $pos_update !== false
    && $pos_post !== false && $pos_commit !== false
    && $pos_snap < $pos_begin && $pos_begin < $pos_update
    && $pos_update < $pos_post && $pos_post < $pos_commit)
    ? pass('order: snapshot < beginTransaction < UPDATE < autoPostEvent < commit')
    : fail('ordering broken');

// ─────────────────────────────────────────────────────────────────────────
section('3. Live-DB end-to-end (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$accts = array_map('intval', $pdo
    ->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 2")
    ->fetchAll(PDO::FETCH_COLUMN));
if (count($accts) < 2) { fail('not enough active accounts'); exit(1); }
[$expense_acct, $cash_acct] = $accts;

$mapping_id = (int)$pdo
    ->query("SELECT id FROM journal_mappings WHERE event_type = 'expense_paid' LIMIT 1")
    ->fetchColumn();
$mapping_id > 0 ? pass("found expense_paid mapping (id=$mapping_id)") : fail('expense_paid mapping missing');

$synth_expense_id = 90000201;

$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE journal_mappings SET debit_account_id=$expense_acct, credit_account_id=$cash_acct, is_active=1 WHERE id=$mapping_id");

    $amt = 850.00;
    $r = autoPostEvent($pdo, 'expense_paid', 'expense', $synth_expense_id, $amt, 3, '2026-05-22', 4, 'Test expense paid');
    ($r['posted'] === true && isset($r['entry_id']) && $r['entry_id'] > 0)
        ? pass("post succeeded (entry_id={$r['entry_id']})")
        : fail('post failed: ' . json_encode($r));

    $entry_id = (int)$r['entry_id'];

    // Verify entry tagged correctly
    $je = $pdo->prepare("SELECT entity_type, entity_id, project_id, status, amount, entry_date FROM journal_entries WHERE entry_id = ?");
    $je->execute([$entry_id]);
    $row = $je->fetch(PDO::FETCH_ASSOC);
    ($row && $row['entity_type'] === 'expense' && (int)$row['entity_id'] === $synth_expense_id
        && (int)$row['project_id'] === 3 && $row['status'] === 'posted'
        && abs((float)$row['amount'] - $amt) < 0.01 && $row['entry_date'] === '2026-05-22')
        ? pass('journal_entries row tagged entity_type=expense with all fields correct')
        : fail('journal_entries row wrong: ' . json_encode($row));

    // Verify items: Dr expense / Cr cash
    $items = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ? ORDER BY item_id");
    $items->execute([$entry_id]);
    $lines = $items->fetchAll(PDO::FETCH_ASSOC);
    $has_dr_expense = $has_cr_cash = false;
    $total_dr = $total_cr = 0.0;
    foreach ($lines as $l) {
        if ($l['type'] === 'debit'  && (int)$l['account_id'] === $expense_acct) $has_dr_expense = true;
        if ($l['type'] === 'credit' && (int)$l['account_id'] === $cash_acct)    $has_cr_cash = true;
        if ($l['type'] === 'debit')  $total_dr += (float)$l['amount'];
        if ($l['type'] === 'credit') $total_cr += (float)$l['amount'];
    }
    ($has_dr_expense && $has_cr_cash && abs($total_dr - $total_cr) < 0.01 && abs($total_dr - $amt) < 0.01)
        ? pass("items: Dr expense=$expense_acct, Cr cash=$cash_acct, balanced ($total_dr == $total_cr == $amt)")
        : fail('items wrong: ' . json_encode($lines));

    // Idempotency
    $r2 = autoPostEvent($pdo, 'expense_paid', 'expense', $synth_expense_id, $amt, 3, '2026-05-22', 4, 'Test idemp');
    ($r2['posted'] === false && $r2['reason'] === 'already_posted' && (int)$r2['existing_entry_id'] === $entry_id)
        ? pass("second call → already_posted, existing_entry_id=$entry_id")
        : fail('idempotency broken: ' . json_encode($r2));

    $pdo->rollBack();
    pass('transaction rolled back');

    $cnt_after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id=$synth_expense_id")->fetchColumn();
    $cnt_after === 0 ? pass('no synthetic rows persisted after rollback')
                    : fail("rollback left $cnt_after rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 3 threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Phase 4.3 + 4.4 tests still pass');
// ─────────────────────────────────────────────────────────────────────────
foreach ([
    'tests/test_phase4_auto_post_hook_cli.php',
    'tests/test_phase4_payment_received_cli.php',
] as $rel) {
    $tf = "$root/$rel";
    if (!file_exists($tf)) { pass("$rel not present — skipping"); continue; }
    $rc = 0; $o = [];
    exec("php " . escapeshellarg($tf) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$rel still passes")
              : fail("$rel failed: rc=$rc");
}

exit($failures === 0 ? 0 : 1);
