<?php
/**
 * Phase 4.3 — auto_post_hook + invoice-approved wiring CLI test
 * --------------------------------------------------------------
 *   php tests/test_phase4_auto_post_hook_cli.php
 *
 * Verifies:
 *   1. Files lint-clean (core/auto_post_hook.php + approve_invoice.php).
 *   2. autoPostEvent() source patterns.
 *   3. approve_invoice.php wiring patterns.
 *   4. autoPostEvent() end-to-end via live DB inside one big BEGIN/ROLLBACK:
 *      a) is_active=0 → returns posted=false, reason=mapping_inactive
 *      b) is_active=1 but accounts NULL → posted=false, mapping_not_configured
 *      c) fully configured → posted=true, entry_id set, row in journal_entries
 *         + Dr/Cr lines in journal_entry_items, debits == credits
 *      d) idempotency: second call with same entity_id returns
 *         posted=false, already_posted, existing_entry_id matches first
 *      e) amount<=0 throws LedgerException
 *      f) unknown event_type throws LedgerException
 *      g) ROLLBACK leaves live DB untouched
 *   5. Phase 0.3 ledger_post test still passes.
 *   6. Phase 4.1 + 4.2 journal_mappings tests still pass.
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

$hook_file    = "$root/core/auto_post_hook.php";
$approve_file = "$root/api/account/approve_invoice.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ([$hook_file, $approve_file] as $f) {
    file_exists($f) ? pass(basename($f) . ' exists') : fail(basename($f) . ' missing');
    $rc = 0; exec("php -l " . escapeshellarg($f) . " 2>&1", $o, $rc);
    $rc === 0 ? pass(basename($f) . ' lint-clean') : fail(basename($f) . ' lint failed');
    $o = [];
}

// ─────────────────────────────────────────────────────────────────────────
section('2. core/auto_post_hook.php source patterns');
// ─────────────────────────────────────────────────────────────────────────
$hook_src = file_get_contents($hook_file);
$hook_checks = [
    "function autoPostEvent"                                    => 'autoPostEvent function defined',
    "require_once __DIR__ . '/ledger_post.php'"                  => 'pulls in postLedgerEntry helper',
    "FROM journal_mappings"                                      => 'reads journal_mappings',
    "WHERE event_type = ?"                                       => 'lookup by event_type slug',
    "'mapping_inactive'"                                         => 'kill-switch path returns mapping_inactive',
    "'mapping_not_configured'"                                   => 'NULL FK path returns mapping_not_configured',
    "'already_posted'"                                           => 'idempotency path returns already_posted',
    "FROM journal_entries"                                       => 'idempotency check queries journal_entries',
    "WHERE entity_type = ?"                                      => 'idempotency uses entity_type+entity_id',
    "AND status      = 'posted'"                                 => 'idempotency restricts to posted entries',
    "postLedgerEntry("                                           => 'delegates posting to ledger_post helper',
    "'debit'"                                                    => 'maps debit_account_id → debit line',
    "'credit'"                                                   => 'maps credit_account_id → credit line',
    "throw new LedgerException"                                  => 'throws LedgerException on bad caller input',
    'amount must be > 0'                                         => 'rejects amount <= 0',
    'unknown event_type'                                         => 'rejects unknown event_type',
];
foreach ($hook_checks as $needle => $label) {
    strpos($hook_src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. approve_invoice.php wiring patterns (IN-3: revenue recognition)');
// ─────────────────────────────────────────────────────────────────────────
// NOTE: as of the money.md IN-3 fix, approve_invoice.php no longer routes revenue
// through the gated autoPostEvent('invoice_approved') path. It now posts a single
// balanced double-entry (Dr AR / Cr Sales Revenue / Cr Output VAT) via
// core/revenue_posting.php::postInvoiceRevenue(). autoPostEvent() itself is still
// validated generically in sections 2 + 4 (other events may use it).
$approve_src = file_get_contents($approve_file);
$approve_checks = [
    "require_once __DIR__ . '/../../core/revenue_posting.php'"    => 'includes revenue_posting (IN-3)',
    "postInvoiceRevenue("                                         => 'calls postInvoiceRevenue (Dr AR / Cr Revenue / Cr VAT)',
    'journal_entry_id'                                            => 'surfaces successful entry_id to response',
    "accounts_not_configured"                                     => 'surfaces a clear "accounts not configured" warning',
    "\$pdo->commit()"                                             => 'revenue posting runs inside the same transaction',
];
foreach ($approve_checks as $needle => $label) {
    strpos($approve_src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// Order check: revenue posting must come BEFORE $pdo->commit() but AFTER workflowCaptureSignature
$pos_sig    = strpos($approve_src, 'workflowCaptureSignature');
$pos_post   = strpos($approve_src, 'postInvoiceRevenue(');
$pos_commit = strpos($approve_src, '$pdo->commit()');
($pos_sig !== false && $pos_post !== false && $pos_commit !== false
    && $pos_sig < $pos_post && $pos_post < $pos_commit)
    ? pass('revenue posting runs AFTER workflowCaptureSignature and BEFORE commit (in same transaction)')
    : fail('revenue posting ordering broken — must be sig < post < commit');

// ─────────────────────────────────────────────────────────────────────────
section('4. End-to-end against live DB (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

// Pick two distinct active accounts for the test mapping
$accts = array_map('intval', $pdo
    ->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 2")
    ->fetchAll(PDO::FETCH_COLUMN));
if (count($accts) < 2) { fail('not enough active accounts'); exit(1); }
[$ar_acct, $rev_acct] = $accts;

// Get the existing 'invoice_approved' mapping id
$mapping_id = (int)$pdo
    ->query("SELECT id FROM journal_mappings WHERE event_type = 'invoice_approved' LIMIT 1")
    ->fetchColumn();
$mapping_id > 0 ? pass("found invoice_approved mapping (id=$mapping_id)") : fail('invoice_approved mapping missing');

// Use a synthetic entity_id far above the real invoice range to avoid collisions
$synth_entity_id_a = 90000001;
$synth_entity_id_b = 90000002;
$synth_entity_id_c = 90000003;

$pdo->beginTransaction();
try {
    // ── 4a. is_active = 0 → kill-switch no-op ────────────────────────────
    $pdo->exec("UPDATE journal_mappings SET debit_account_id=NULL, credit_account_id=NULL, is_active=0 WHERE id=$mapping_id");
    $r = autoPostEvent($pdo, 'invoice_approved', 'invoice', $synth_entity_id_a, 100.00, null, '2026-05-01', 4, 'Test inactive');
    ($r['posted'] === false && $r['reason'] === 'mapping_inactive')
        ? pass('4a. is_active=0 → posted=false, reason=mapping_inactive')
        : fail('4a. unexpected: ' . json_encode($r));

    // No journal entry should exist
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='invoice' AND entity_id=$synth_entity_id_a")->fetchColumn();
    $cnt === 0 ? pass('4a. no journal_entries row written')
              : fail("4a. unexpected journal_entries row ($cnt)");

    // ── 4b. is_active=1 but accounts NULL → mapping_not_configured ───────
    $pdo->exec("UPDATE journal_mappings SET debit_account_id=NULL, credit_account_id=NULL, is_active=1 WHERE id=$mapping_id");
    $r = autoPostEvent($pdo, 'invoice_approved', 'invoice', $synth_entity_id_a, 100.00, null, '2026-05-01', 4, 'Test no-cfg');
    ($r['posted'] === false && $r['reason'] === 'mapping_not_configured')
        ? pass('4b. is_active=1 + NULL FKs → posted=false, reason=mapping_not_configured')
        : fail('4b. unexpected: ' . json_encode($r));

    // ── 4c. Fully configured → posted=true ───────────────────────────────
    $pdo->exec("UPDATE journal_mappings SET debit_account_id=$ar_acct, credit_account_id=$rev_acct, is_active=1 WHERE id=$mapping_id");
    $amt = 1234.56;
    $r = autoPostEvent($pdo, 'invoice_approved', 'invoice', $synth_entity_id_b, $amt, 3, '2026-05-15', 4, 'Test posted');
    ($r['posted'] === true && isset($r['entry_id']) && $r['entry_id'] > 0)
        ? pass("4c. configured → posted=true, entry_id={$r['entry_id']}")
        : fail('4c. unexpected: ' . json_encode($r));

    $entry_id_b = (int)$r['entry_id'];

    // Verify the row
    $row = $pdo->prepare("SELECT entity_type, entity_id, project_id, status, amount, entry_date FROM journal_entries WHERE entry_id = ?");
    $row->execute([$entry_id_b]);
    $je = $row->fetch(PDO::FETCH_ASSOC);
    ($je && $je['entity_type'] === 'invoice' && (int)$je['entity_id'] === $synth_entity_id_b
        && (int)$je['project_id'] === 3 && $je['status'] === 'posted'
        && abs((float)$je['amount'] - $amt) < 0.01 && $je['entry_date'] === '2026-05-15')
        ? pass('4c. journal_entries row carries entity link, project, status=posted, amount, date')
        : fail('4c. journal_entries row wrong: ' . json_encode($je));

    // Verify items
    $items = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ? ORDER BY item_id");
    $items->execute([$entry_id_b]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);
    $total_dr = 0.0; $total_cr = 0.0; $has_dr = false; $has_cr = false;
    foreach ($rows as $li) {
        if ($li['type'] === 'debit')  { $total_dr += (float)$li['amount']; $has_dr = ((int)$li['account_id'] === $ar_acct); }
        if ($li['type'] === 'credit') { $total_cr += (float)$li['amount']; $has_cr = ((int)$li['account_id'] === $rev_acct); }
    }
    (count($rows) === 2 && $has_dr && $has_cr && abs($total_dr - $total_cr) < 0.01 && abs($total_dr - $amt) < 0.01)
        ? pass("4c. 2 items: Dr account=$ar_acct, Cr account=$rev_acct, balanced ($total_dr == $total_cr)")
        : fail('4c. items wrong: ' . json_encode($rows));

    // ── 4d. Idempotency — second call with same entity_id ───────────────
    $r2 = autoPostEvent($pdo, 'invoice_approved', 'invoice', $synth_entity_id_b, $amt, 3, '2026-05-15', 4, 'Test idemp');
    ($r2['posted'] === false && $r2['reason'] === 'already_posted' && (int)$r2['existing_entry_id'] === $entry_id_b)
        ? pass("4d. second call → posted=false, already_posted, existing_entry_id=$entry_id_b")
        : fail('4d. unexpected: ' . json_encode($r2));

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='invoice' AND entity_id=$synth_entity_id_b")->fetchColumn();
    $cnt === 1 ? pass('4d. still exactly 1 journal_entries row (no double-post)')
              : fail("4d. expected 1 row, got $cnt");

    // ── 4e. amount <= 0 throws ──────────────────────────────────────────
    try {
        autoPostEvent($pdo, 'invoice_approved', 'invoice', $synth_entity_id_c, 0, null, '2026-05-15', 4, 'Test bad amt');
        fail('4e. amount=0 did NOT throw');
    } catch (LedgerException $e) {
        strpos($e->getMessage(), 'amount must be > 0') !== false
            ? pass('4e. amount=0 throws LedgerException (correct message)')
            : fail('4e. wrong message: ' . $e->getMessage());
    }

    try {
        autoPostEvent($pdo, 'invoice_approved', 'invoice', $synth_entity_id_c, -5.5, null, '2026-05-15', 4, 'Test neg amt');
        fail('4e. amount=-5.5 did NOT throw');
    } catch (LedgerException $e) {
        pass('4e. amount<0 also throws LedgerException');
    }

    // ── 4f. Unknown event_type throws ───────────────────────────────────
    try {
        autoPostEvent($pdo, 'does_not_exist_event', 'invoice', $synth_entity_id_c, 100, null, '2026-05-15', 4, 'Test bad slug');
        fail('4f. unknown event_type did NOT throw');
    } catch (LedgerException $e) {
        strpos($e->getMessage(), 'unknown event_type') !== false
            ? pass('4f. unknown event_type throws LedgerException (correct message)')
            : fail('4f. wrong message: ' . $e->getMessage());
    }

    // ── 4g. Rollback for clean exit ─────────────────────────────────────
    $pdo->rollBack();
    pass('4g. transaction rolled back');

    // Confirm no persistent state
    $cnt_after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id IN ($synth_entity_id_a, $synth_entity_id_b, $synth_entity_id_c)")->fetchColumn();
    $cnt_after === 0 ? pass('4g. no synthetic journal_entries rows persisted after rollback')
                    : fail("4g. ROLLBACK left $cnt_after rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 4 threw unexpectedly: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Phase 0.3 ledger_post test still passes');
// ─────────────────────────────────────────────────────────────────────────
$prev_test = "$root/tests/test_phase0_ledger_post_cli.php";
if (file_exists($prev_test)) {
    $rc = 0; exec("php " . escapeshellarg($prev_test) . " 2>&1", $o, $rc);
    $rc === 0 ? pass('Phase 0.3 ledger_post test still passes')
              : fail('Phase 0.3 test failed: rc=' . $rc);
} else {
    pass('Phase 0.3 test not present — skipping');
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Phase 4.1 + 4.2 journal_mappings tests still pass');
// ─────────────────────────────────────────────────────────────────────────
foreach ([
    'tests/test_phase4_journal_mappings_schema_cli.php',
    'tests/test_phase4_journal_mappings_admin_cli.php',
] as $rel) {
    $tf = "$root/$rel";
    if (!file_exists($tf)) { pass("$rel not present — skipping"); continue; }
    $rc2 = 0; $o2 = [];
    exec("php " . escapeshellarg($tf) . " 2>&1", $o2, $rc2);
    $rc2 === 0 ? pass("$rel still passes")
              : fail("$rel failed: rc=$rc2");
}

exit($failures === 0 ? 0 : 1);
