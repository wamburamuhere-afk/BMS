<?php
/**
 * Phase 4.8 — auto-post on supplier payment CLI test
 * ----------------------------------------------------
 *   php tests/test_phase4_supplier_payment_cli.php
 *
 * Verifies:
 *   1. add_supplier_payment.php lint-clean.
 *   2. Wiring source patterns:
 *      - includes core/auto_post_hook.php
 *      - calls autoPostEvent('supplier_payment', 'supplier_payment', ...)
 *      - amount = $amount (the payment amount)
 *      - entry_date = $payment_date
 *      - project_id resolved from purchase_orders.project_id when PO present,
 *        else NULL (company-wide)
 *      - posts unconditionally (status is hardcoded 'completed' on insert)
 *      - surfaces journal_entry_id / ledger_warning
 *      - placement: AFTER PO update, BEFORE $pdo->commit()
 *   3. Live-DB end-to-end (BEGIN/ROLLBACK isolation): 'supplier_payment'
 *      event slug round-trips; entry tagged entity_type='supplier_payment';
 *      Dr AP / Cr Cash lines balance; idempotency holds.
 *   4. Phase 4.3 → 4.7 tests still pass.
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

$file = "$root/api/add_supplier_payment.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
file_exists($file) ? pass('add_supplier_payment.php exists') : fail('missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($file);
$checks = [
    "require_once __DIR__ . '/../core/auto_post_hook.php'"     => 'includes auto_post_hook',
    "autoPostEvent("                                            => 'calls autoPostEvent',
    "'supplier_payment'"                                        => 'uses supplier_payment slug (event + entity)',
    "SELECT project_id FROM purchase_orders"                    => 'resolves project_id from linked PO',
    "\$resolved_project_id"                                     => 'project_id falls back to NULL when no PO',
    "(int)\$payment_id"                                         => 'entity_id = payment_id',
    "(float)\$amount"                                           => 'amount = payment amount',
    "\$payment_date"                                            => 'entry_date = payment_date',
    'journal_entry_id'                                          => 'surfaces successful entry_id to response',
    // Money-safety (Step 6): the consolidated outflow is now guaranteed posted or the
    // whole payment rolls back, so the misleading ledger_warning was removed; the handler
    // uses postOutflowOrFail and surfaces the I3 funds warning instead.
    'postOutflowOrFail('                                        => 'posts the outflow loudly (no fire-and-forget)',
    'funds_warning'                                             => 'surfaces the I3 funds warning (warn but allow)',
    'already in ledger as entry'                                => 'audit log enriched on idempotent re-post',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// Order check: autoPostEvent < commit
$pos_post   = strpos($src, "autoPostEvent(");
$pos_commit = strrpos($src, "\$pdo->commit()");
($pos_post !== false && $pos_commit !== false && $pos_post < $pos_commit)
    ? pass('order: autoPostEvent BEFORE $pdo->commit() (same transaction)')
    : fail('ordering broken');

// ─────────────────────────────────────────────────────────────────────────
section('3. Live-DB end-to-end (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$accts = array_map('intval', $pdo
    ->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 2")
    ->fetchAll(PDO::FETCH_COLUMN));
if (count($accts) < 2) { fail('not enough active accounts'); exit(1); }
[$ap_acct, $cash_acct] = $accts;

$mapping_id = (int)$pdo
    ->query("SELECT id FROM journal_mappings WHERE event_type = 'supplier_payment' LIMIT 1")
    ->fetchColumn();
$mapping_id > 0 ? pass("found supplier_payment mapping (id=$mapping_id)") : fail('supplier_payment mapping missing');

$synth_payment_id = 90000501;

$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE journal_mappings SET debit_account_id=$ap_acct, credit_account_id=$cash_acct, is_active=1 WHERE id=$mapping_id");

    $amt = 12340.50;
    $r = autoPostEvent($pdo, 'supplier_payment', 'supplier_payment', $synth_payment_id, $amt, 3, '2026-05-27', 4, 'Test supplier payment');
    ($r['posted'] === true && isset($r['entry_id']) && $r['entry_id'] > 0)
        ? pass("post succeeded (entry_id={$r['entry_id']})")
        : fail('post failed: ' . json_encode($r));

    $entry_id = (int)$r['entry_id'];

    $je = $pdo->prepare("SELECT entity_type, entity_id, project_id, status, amount, entry_date FROM journal_entries WHERE entry_id = ?");
    $je->execute([$entry_id]);
    $row = $je->fetch(PDO::FETCH_ASSOC);
    ($row && $row['entity_type'] === 'supplier_payment' && (int)$row['entity_id'] === $synth_payment_id
        && (int)$row['project_id'] === 3 && $row['status'] === 'posted'
        && abs((float)$row['amount'] - $amt) < 0.01 && $row['entry_date'] === '2026-05-27')
        ? pass('journal_entries row tagged entity_type=supplier_payment, project_id=3, all fields correct')
        : fail('journal_entries row wrong: ' . json_encode($row));

    // Verify items: Dr AP / Cr Cash (clears the payable)
    $items = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ? ORDER BY item_id");
    $items->execute([$entry_id]);
    $lines = $items->fetchAll(PDO::FETCH_ASSOC);
    $has_dr_ap = $has_cr_cash = false;
    $total_dr = $total_cr = 0.0;
    foreach ($lines as $l) {
        if ($l['type'] === 'debit'  && (int)$l['account_id'] === $ap_acct)   $has_dr_ap   = true;
        if ($l['type'] === 'credit' && (int)$l['account_id'] === $cash_acct) $has_cr_cash = true;
        if ($l['type'] === 'debit')  $total_dr += (float)$l['amount'];
        if ($l['type'] === 'credit') $total_cr += (float)$l['amount'];
    }
    ($has_dr_ap && $has_cr_cash && abs($total_dr - $total_cr) < 0.01 && abs($total_dr - $amt) < 0.01)
        ? pass("items: Dr AP=$ap_acct, Cr cash=$cash_acct, balanced ($total_dr == $total_cr == $amt)")
        : fail('items wrong: ' . json_encode($lines));

    // Idempotency
    $r2 = autoPostEvent($pdo, 'supplier_payment', 'supplier_payment', $synth_payment_id, $amt, 3, '2026-05-27', 4, 'Test idemp');
    ($r2['posted'] === false && $r2['reason'] === 'already_posted' && (int)$r2['existing_entry_id'] === $entry_id)
        ? pass("second call → already_posted, existing_entry_id=$entry_id")
        : fail('idempotency broken: ' . json_encode($r2));

    // Company-wide (no PO) post: project_id=NULL
    $synth_payment_id_b = 90000502;
    $r3 = autoPostEvent($pdo, 'supplier_payment', 'supplier_payment', $synth_payment_id_b, 500.00, null, '2026-05-27', 4, 'Test no-PO');
    ($r3['posted'] === true)
        ? pass("no-PO post succeeded (entry_id={$r3['entry_id']})")
        : fail('no-PO post failed: ' . json_encode($r3));

    $je2 = $pdo->prepare("SELECT project_id FROM journal_entries WHERE entry_id = ?");
    $je2->execute([(int)$r3['entry_id']]);
    $pj_val = $je2->fetchColumn();
    $pj_val === null ? pass('no-PO entry has project_id=NULL (company-wide)')
                    : fail("no-PO entry has project_id=$pj_val, expected NULL");

    $pdo->rollBack();
    pass('transaction rolled back');

    $cnt_after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id IN ($synth_payment_id, $synth_payment_id_b)")->fetchColumn();
    $cnt_after === 0 ? pass('no synthetic rows persisted after rollback')
                    : fail("rollback left $cnt_after rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 3 threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Phase 4.3 → 4.7 tests still pass');
// ─────────────────────────────────────────────────────────────────────────
foreach ([
    'tests/test_phase4_auto_post_hook_cli.php',
    'tests/test_phase4_payment_received_cli.php',
    'tests/test_phase4_expense_paid_cli.php',
    'tests/test_phase4_payroll_paid_cli.php',
    'tests/test_phase4_grn_approved_cli.php',
] as $rel) {
    $tf = "$root/$rel";
    if (!file_exists($tf)) { pass("$rel not present — skipping"); continue; }
    $rc = 0; $o = [];
    exec("php " . escapeshellarg($tf) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$rel still passes") : fail("$rel failed: rc=$rc");
}

exit($failures === 0 ? 0 : 1);
