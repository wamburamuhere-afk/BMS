<?php
/**
 * Phase 4.4 — auto-post on customer payment received CLI test
 * ------------------------------------------------------------
 *   php tests/test_phase4_payment_received_cli.php
 *
 * Verifies:
 *   1. record_payment.php lint-clean.
 *   2. Wiring source patterns:
 *      - includes core/auto_post_hook.php
 *      - calls autoPostEvent('payment_received', 'payment', ...)
 *      - amount = $amount (the payment amount, not invoice grand_total)
 *      - entry_date = $payment_date
 *      - project_id passed through from $invoice
 *      - only posts when $status === 'completed' (pending payments wait)
 *      - surfaces journal_entry_id / ledger_warning on response
 *      - audit-log enriched with journal entry id
 *      - auto-post BEFORE $pdo->commit() AND AFTER status guard
 *   3. Live-DB end-to-end (BEGIN/ROLLBACK isolation) — confirms the
 *      'payment_received' event slug resolves and round-trips:
 *      a) admin configures the mapping
 *      b) autoPostEvent posts a journal entry tagged entity_type='payment'
 *      c) journal_entry_items balance (Dr cash == Cr receivables)
 *      d) idempotency: second call with same payment_id is a no-op
 *      e) rollback leaves DB untouched
 *   4. Phase 4.3 auto_post_hook test still passes.
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

$file = "$root/api/account/record_payment.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
file_exists($file) ? pass('record_payment.php exists') : fail('missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($file);
$checks = [
    "require_once __DIR__ . '/../../core/auto_post_hook.php'" => 'includes auto_post_hook',
    "autoPostEvent("                                           => 'calls autoPostEvent',
    "'payment_received'"                                       => 'uses payment_received event slug',
    "'payment'"                                                => 'uses payment entity_type',
    "(int)\$payment_id"                                        => 'entity_id = payment_id',
    "(float)\$amount"                                          => 'amount = payment amount (not invoice grand_total)',
    "\$payment_date"                                           => 'entry_date = payment_date',
    "\$invoice['project_id']"                                  => 'project_id pass-through from invoice',
    "if (\$status === 'completed')"                            => 'only posts when status=completed',
    "'status_not_completed'"                                   => 'pending payments report status_not_completed',
    'journal_entry_id'                                         => 'surfaces successful entry_id to response',
    'ledger_warning'                                           => 'surfaces mapping_not_configured to response',
    'already_posted'                                           => 'logs already_posted case to audit trail',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// Order check: autoPostEvent must come BEFORE $pdo->commit()
$pos_post   = strpos($src, 'autoPostEvent(');
$pos_commit = strrpos($src, '$pdo->commit()');
($pos_post !== false && $pos_commit !== false && $pos_post < $pos_commit)
    ? pass('autoPostEvent runs BEFORE $pdo->commit() (same transaction)')
    : fail('auto-post ordering broken — must precede commit');

// Order check: the status guard must come BEFORE the autoPostEvent call
$pos_guard = strpos($src, "if (\$status === 'completed')");
($pos_guard !== false && $pos_post !== false && $pos_guard < $pos_post)
    ? pass('status guard precedes autoPostEvent call')
    : fail('status guard ordering broken');

// ─────────────────────────────────────────────────────────────────────────
section('3. Live-DB end-to-end (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$accts = array_map('intval', $pdo
    ->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 2")
    ->fetchAll(PDO::FETCH_COLUMN));
if (count($accts) < 2) { fail('not enough active accounts'); exit(1); }
[$cash_acct, $ar_acct] = $accts;

$mapping_id = (int)$pdo
    ->query("SELECT id FROM journal_mappings WHERE event_type = 'payment_received' LIMIT 1")
    ->fetchColumn();
$mapping_id > 0 ? pass("found payment_received mapping (id=$mapping_id)") : fail('payment_received mapping missing');

$synth_payment_id = 90000101;

$pdo->beginTransaction();
try {
    // Configure mapping inside the transaction (rolls back at the end)
    $pdo->exec("UPDATE journal_mappings SET debit_account_id=$cash_acct, credit_account_id=$ar_acct, is_active=1 WHERE id=$mapping_id");

    $amt = 567.89;
    $r = autoPostEvent($pdo, 'payment_received', 'payment', $synth_payment_id, $amt, 3, '2026-05-20', 4, 'Test payment received');
    ($r['posted'] === true && isset($r['entry_id']) && $r['entry_id'] > 0)
        ? pass("post succeeded (entry_id={$r['entry_id']})")
        : fail('post failed: ' . json_encode($r));

    $entry_id = (int)$r['entry_id'];

    // Verify journal_entries row
    $je = $pdo->prepare("SELECT entity_type, entity_id, project_id, status, amount, entry_date FROM journal_entries WHERE entry_id = ?");
    $je->execute([$entry_id]);
    $row = $je->fetch(PDO::FETCH_ASSOC);
    ($row && $row['entity_type'] === 'payment' && (int)$row['entity_id'] === $synth_payment_id
        && (int)$row['project_id'] === 3 && $row['status'] === 'posted'
        && abs((float)$row['amount'] - $amt) < 0.01 && $row['entry_date'] === '2026-05-20')
        ? pass('journal_entries row tagged entity_type=payment with all fields correct')
        : fail('journal_entries row wrong: ' . json_encode($row));

    // Verify items balance: Dr cash == Cr receivables
    $items = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ? ORDER BY item_id");
    $items->execute([$entry_id]);
    $lines = $items->fetchAll(PDO::FETCH_ASSOC);
    $has_dr_cash = $has_cr_ar = false;
    $total_dr = $total_cr = 0.0;
    foreach ($lines as $l) {
        if ($l['type'] === 'debit'  && (int)$l['account_id'] === $cash_acct) $has_dr_cash = true;
        if ($l['type'] === 'credit' && (int)$l['account_id'] === $ar_acct)   $has_cr_ar = true;
        if ($l['type'] === 'debit')  $total_dr += (float)$l['amount'];
        if ($l['type'] === 'credit') $total_cr += (float)$l['amount'];
    }
    ($has_dr_cash && $has_cr_ar && abs($total_dr - $total_cr) < 0.01 && abs($total_dr - $amt) < 0.01)
        ? pass("items: Dr cash=$cash_acct, Cr AR=$ar_acct, balanced ($total_dr == $total_cr == $amt)")
        : fail('items wrong: ' . json_encode($lines));

    // Idempotency: second call with same payment_id is a no-op
    $r2 = autoPostEvent($pdo, 'payment_received', 'payment', $synth_payment_id, $amt, 3, '2026-05-20', 4, 'Test idemp');
    ($r2['posted'] === false && $r2['reason'] === 'already_posted' && (int)$r2['existing_entry_id'] === $entry_id)
        ? pass("second call → already_posted, existing_entry_id=$entry_id")
        : fail('idempotency broken: ' . json_encode($r2));

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='payment' AND entity_id=$synth_payment_id")->fetchColumn();
    $cnt === 1 ? pass('exactly 1 row (no double-post)') : fail("expected 1 row, got $cnt");

    $pdo->rollBack();
    pass('transaction rolled back');

    $cnt_after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id=$synth_payment_id")->fetchColumn();
    $cnt_after === 0 ? pass('no synthetic rows persisted after rollback')
                    : fail("rollback left $cnt_after rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 3 threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Phase 4.3 auto_post_hook test still passes');
// ─────────────────────────────────────────────────────────────────────────
$prev = "$root/tests/test_phase4_auto_post_hook_cli.php";
if (file_exists($prev)) {
    $rc = 0; exec("php " . escapeshellarg($prev) . " 2>&1", $o, $rc);
    $rc === 0 ? pass('Phase 4.3 test still passes') : fail('Phase 4.3 test failed: rc=' . $rc);
} else {
    pass('Phase 4.3 test not present — skipping');
}

exit($failures === 0 ? 0 : 1);
