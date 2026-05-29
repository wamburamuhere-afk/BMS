<?php
/**
 * Phase 4.7 — auto-post on GRN approval CLI test
 * -----------------------------------------------
 *   php tests/test_phase4_grn_approved_cli.php
 *
 * Verifies:
 *   1. approve_grn.php lint-clean.
 *   2. Wiring source patterns:
 *      - includes core/auto_post_hook.php
 *      - calls autoPostEvent('grn_approved', 'grn', ...)
 *      - amount computed via SUM(quantity_received * unit_price) from
 *        receipt_items (NOT purchase_receipts.total_received)
 *      - entry_date = $grn['receipt_date']
 *      - project_id pass-through from $grn
 *      - surfaces journal_entry_id / ledger_warning on response
 *      - placement: AFTER workflowCaptureSignature, BEFORE $pdo->commit()
 *   3. Live-DB end-to-end (BEGIN/ROLLBACK isolation): 'grn_approved' event
 *      slug round-trips through autoPostEvent → postLedgerEntry; entry
 *      tagged entity_type='grn'; Dr Inventory / Cr AP lines balance;
 *      idempotency holds.
 *   4. Phase 4.3 → 4.6 tests still pass.
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

$file = "$root/api/approve_grn.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
file_exists($file) ? pass('approve_grn.php exists') : fail('missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($file);
$checks = [
    "require_once __DIR__ . '/../core/auto_post_hook.php'"     => 'includes auto_post_hook',
    "autoPostEvent("                                            => 'calls autoPostEvent',
    "'grn_approved'"                                            => 'uses grn_approved event slug',
    "'grn'"                                                     => 'uses grn entity_type',
    "SUM(quantity_received * unit_price)"                       => 'total computed from receipt_items (not denormalised total_received)',
    "FROM receipt_items"                                        => 'queries receipt_items',
    "\$grn['receipt_date']"                                     => 'entry_date = receipt_date',
    "\$project_id"                                              => 'project_id pass-through',
    "(int)\$receipt_id"                                         => 'entity_id = receipt_id',
    'journal_entry_id'                                          => 'surfaces successful entry_id to response',
    'ledger_warning'                                            => 'surfaces mapping_not_configured to response',
    'already_posted'                                            => 'logs already_posted case to audit trail',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// Order check: sig < autoPostEvent < commit (within same transaction)
$pos_sig    = strpos($src, "workflowCaptureSignature");
$pos_post   = strpos($src, "autoPostEvent(");
$pos_commit = strpos($src, "\$pdo->commit()");

($pos_sig !== false && $pos_post !== false && $pos_commit !== false
    && $pos_sig < $pos_post && $pos_post < $pos_commit)
    ? pass('order: workflowCaptureSignature < autoPostEvent < commit')
    : fail('ordering broken');

// ─────────────────────────────────────────────────────────────────────────
section('3. Live-DB end-to-end (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$accts = array_map('intval', $pdo
    ->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 2")
    ->fetchAll(PDO::FETCH_COLUMN));
if (count($accts) < 2) { fail('not enough active accounts'); exit(1); }
[$inventory_acct, $ap_acct] = $accts;

$mapping_id = (int)$pdo
    ->query("SELECT id FROM journal_mappings WHERE event_type = 'grn_approved' LIMIT 1")
    ->fetchColumn();
$mapping_id > 0 ? pass("found grn_approved mapping (id=$mapping_id)") : fail('grn_approved mapping missing');

$synth_grn_id = 90000401;

$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE journal_mappings SET debit_account_id=$inventory_acct, credit_account_id=$ap_acct, is_active=1 WHERE id=$mapping_id");

    $amt = 4321.78;
    $r = autoPostEvent($pdo, 'grn_approved', 'grn', $synth_grn_id, $amt, 3, '2026-05-26', 4, 'Test GRN approved');
    ($r['posted'] === true && isset($r['entry_id']) && $r['entry_id'] > 0)
        ? pass("post succeeded (entry_id={$r['entry_id']})")
        : fail('post failed: ' . json_encode($r));

    $entry_id = (int)$r['entry_id'];

    // Verify entry tagged correctly
    $je = $pdo->prepare("SELECT entity_type, entity_id, project_id, status, amount, entry_date FROM journal_entries WHERE entry_id = ?");
    $je->execute([$entry_id]);
    $row = $je->fetch(PDO::FETCH_ASSOC);
    ($row && $row['entity_type'] === 'grn' && (int)$row['entity_id'] === $synth_grn_id
        && (int)$row['project_id'] === 3 && $row['status'] === 'posted'
        && abs((float)$row['amount'] - $amt) < 0.01 && $row['entry_date'] === '2026-05-26')
        ? pass('journal_entries row tagged entity_type=grn, project_id=3, all fields correct')
        : fail('journal_entries row wrong: ' . json_encode($row));

    // Verify items: Dr inventory / Cr AP
    $items = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ? ORDER BY item_id");
    $items->execute([$entry_id]);
    $lines = $items->fetchAll(PDO::FETCH_ASSOC);
    $has_dr_inv = $has_cr_ap = false;
    $total_dr = $total_cr = 0.0;
    foreach ($lines as $l) {
        if ($l['type'] === 'debit'  && (int)$l['account_id'] === $inventory_acct) $has_dr_inv = true;
        if ($l['type'] === 'credit' && (int)$l['account_id'] === $ap_acct)        $has_cr_ap  = true;
        if ($l['type'] === 'debit')  $total_dr += (float)$l['amount'];
        if ($l['type'] === 'credit') $total_cr += (float)$l['amount'];
    }
    ($has_dr_inv && $has_cr_ap && abs($total_dr - $total_cr) < 0.01 && abs($total_dr - $amt) < 0.01)
        ? pass("items: Dr inventory=$inventory_acct, Cr AP=$ap_acct, balanced ($total_dr == $total_cr == $amt)")
        : fail('items wrong: ' . json_encode($lines));

    // Idempotency
    $r2 = autoPostEvent($pdo, 'grn_approved', 'grn', $synth_grn_id, $amt, 3, '2026-05-26', 4, 'Test idemp');
    ($r2['posted'] === false && $r2['reason'] === 'already_posted' && (int)$r2['existing_entry_id'] === $entry_id)
        ? pass("second call → already_posted, existing_entry_id=$entry_id")
        : fail('idempotency broken: ' . json_encode($r2));

    $pdo->rollBack();
    pass('transaction rolled back');

    $cnt_after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id=$synth_grn_id")->fetchColumn();
    $cnt_after === 0 ? pass('no synthetic rows persisted after rollback')
                    : fail("rollback left $cnt_after rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 3 threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Phase 4.3 → 4.6 tests still pass');
// ─────────────────────────────────────────────────────────────────────────
foreach ([
    'tests/test_phase4_auto_post_hook_cli.php',
    'tests/test_phase4_payment_received_cli.php',
    'tests/test_phase4_expense_paid_cli.php',
    'tests/test_phase4_payroll_paid_cli.php',
] as $rel) {
    $tf = "$root/$rel";
    if (!file_exists($tf)) { pass("$rel not present — skipping"); continue; }
    $rc = 0; $o = [];
    exec("php " . escapeshellarg($tf) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$rel still passes") : fail("$rel failed: rc=$rc");
}

exit($failures === 0 ? 0 : 1);
