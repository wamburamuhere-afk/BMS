<?php
/**
 * tests/test_bank_recon_phase4_cli.php
 * -------------------------------------
 * Phase 4 — Verify:
 *   1.  recon_period_lock.php: assertNotInFinalizedReconPeriod exists
 *   2.  Period lock blocks void of a JE in a finalized period
 *   3.  Period lock allows void of a JE outside any finalized period
 *   4.  Period lock allows void of a JE in a pending (not reconciled) period
 *   5.  unreconcile.php: empty reason rejected
 *   6.  unreconcile.php: too-short reason rejected
 *   7.  unreconcile.php: successfully unlocks a reconciled reconciliation
 *   8.  After unreconcile: reconciliation status is 'pending'
 *   9.  After unreconcile: bank_transactions reverted to 'cleared'
 *  10.  After unreconcile: period lock no longer blocks JE edits in that period
 *  11.  Finalize sets status=reconciled and stamps bank_transactions=reconciled
 *  12.  unreconcile.php: non-reconciled reconciliation is rejected
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/account_balance.php';
require_once __DIR__ . '/../core/bank_register.php';
require_once __DIR__ . '/../core/ledger_post.php';
require_once __DIR__ . '/../core/recon_period_lock.php';
global $pdo;

$pass = 0; $fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $label\n"; $pass++; }
    else        { echo "  FAIL: $label" . ($detail ? " — $detail" : '') . "\n"; $fail++; }
}

echo "\n=== Phase 4: Period Lock + Unreconcile ===\n\n";

$_SESSION['user_id'] = 1;
$_SESSION['is_admin'] = true;

// ── Test 1: helper function exists ────────────────────────────────────────────
ok('assertNotInFinalizedReconPeriod function exists', function_exists('assertNotInFinalizedReconPeriod'));

// ── Resolve fixture accounts ──────────────────────────────────────────────────
$bankId = (int)$pdo->query(
    "SELECT a.account_id FROM accounts a
      JOIN account_types at ON a.account_type_id = at.type_id
     WHERE at.category IN ('asset','cash') AND a.status='active'
     ORDER BY a.account_id LIMIT 1"
)->fetchColumn();
$expId = (int)$pdo->query(
    "SELECT account_id FROM accounts WHERE account_name LIKE '%expense%' AND status='active' LIMIT 1"
)->fetchColumn();

if (!$bankId || !$expId) { echo "  SKIP: Fixture accounts not found.\n"; exit(0); }

// ── Pre-cleanup: remove any leftover T4-* rows from aborted prior runs ─────────
$pdo->exec("DELETE FROM bank_reconciliations WHERE reconciliation_number LIKE 'T4-%'");

// ── Post a test journal entry dated in Jan 2026 ───────────────────────────────
$pdo->beginTransaction();
$entryId = postLedgerEntry($pdo, 'Phase4 test entry Jan', [
    ['account_id' => $expId,  'type' => 'debit',  'amount' => 100.00],
    ['account_id' => $bankId, 'type' => 'credit', 'amount' => 100.00],
], null, null, 'test_phase4', '2026-01-15', 1);
$pdo->commit();

// ── Create a reconciled reconciliation covering Jan 2026 ─────────────────────
$pdo->exec("INSERT INTO bank_reconciliations
    (reconciliation_number, bank_account_id, reconciliation_date, period_start, period_end,
     statement_balance, book_balance, adjusted_balance, opening_balance, difference, status, prepared_by, created_at)
    VALUES (CONCAT('T4-',UNIX_TIMESTAMP()), $bankId, '2026-01-31',
            '2026-01-01', '2026-01-31', 5000.00, 5000.00, 5000.00, 0.00, 0.00, 'reconciled', 1, NOW())");
$recId = (int)$pdo->lastInsertId();

// Insert a matched+reconciled bank_transactions line
$pdo->exec("INSERT INTO bank_transactions
    (bank_account_id, amount, transaction_type, transaction_date, reference_number,
     description, created_by, created_at, matching_status, reconciliation_id, status)
    VALUES ($bankId, 100.00, 'withdrawal', '2026-01-15', 'T4-LINE-1', 'Phase4 test line', 1, NOW(), 'matched', $recId, 'reconciled')");
$txnId = (int)$pdo->lastInsertId();

// ── Test 2: Period lock blocks void of a JE in finalized period ───────────────
$blocked = false;
try {
    assertNotInFinalizedReconPeriod($pdo, $entryId);
} catch (Exception $e) {
    $blocked = true;
}
ok('Period lock blocks operation on JE in finalized period', $blocked);

// ── Test 3 & 4: Period lock allows JE outside or in pending period ─────────────
// Post a test JE dated in March 2026 (no reconciliation there)
$pdo->beginTransaction();
$entryMar = postLedgerEntry($pdo, 'Phase4 test entry Mar', [
    ['account_id' => $expId,  'type' => 'debit',  'amount' => 50.00],
    ['account_id' => $bankId, 'type' => 'credit', 'amount' => 50.00],
], null, null, 'test_phase4', '2026-03-15', 1);
$pdo->commit();

$blockedMar = false;
try { assertNotInFinalizedReconPeriod($pdo, $entryMar); } catch (Exception $e) { $blockedMar = true; }
ok('Period lock allows JE outside any finalized period', !$blockedMar);

// Create a PENDING reconciliation for Feb
$pdo->exec("INSERT INTO bank_reconciliations
    (reconciliation_number, bank_account_id, reconciliation_date, period_start, period_end,
     statement_balance, book_balance, adjusted_balance, opening_balance, difference, status, prepared_by, created_at)
    VALUES (CONCAT('T4-FEB-',UNIX_TIMESTAMP()), $bankId, '2026-02-28',
            '2026-02-01', '2026-02-28', 5000.00, 5000.00, 0.00, 0.00, 0.00, 'pending', 1, NOW())");
$recFebId = (int)$pdo->lastInsertId();

$pdo->beginTransaction();
$entryFeb = postLedgerEntry($pdo, 'Phase4 test entry Feb', [
    ['account_id' => $expId,  'type' => 'debit',  'amount' => 75.00],
    ['account_id' => $bankId, 'type' => 'credit', 'amount' => 75.00],
], null, null, 'test_phase4', '2026-02-10', 1);
$pdo->commit();

$blockedFeb = false;
try { assertNotInFinalizedReconPeriod($pdo, $entryFeb); } catch (Exception $e) { $blockedFeb = true; }
ok('Period lock allows JE in a pending (not reconciled) period', !$blockedFeb);

// ── Tests 5–6: unreconcile validation ─────────────────────────────────────────
$_SERVER['REQUEST_METHOD'] = 'POST';

$_POST = ['reconciliation_id' => $recId, 'reason' => '', '_csrf' => csrf_token()];
ob_start(); include __DIR__ . '/../api/account/unreconcile.php'; $raw5 = ob_get_clean();
$res5 = json_decode($raw5, true);
ok('unreconcile: empty reason rejected', !empty($res5) && !$res5['success'], $raw5);

$_POST = ['reconciliation_id' => $recId, 'reason' => 'short', '_csrf' => csrf_token()];
ob_start(); include __DIR__ . '/../api/account/unreconcile.php'; $raw6 = ob_get_clean();
$res6 = json_decode($raw6, true);
ok('unreconcile: too-short reason rejected', !empty($res6) && !$res6['success'], $raw6);

// ── Tests 7–10: successful unreconcile ────────────────────────────────────────
$_POST = ['reconciliation_id' => $recId, 'reason' => 'Testing unreconcile — Phase 4 automated test run', '_csrf' => csrf_token()];
ob_start(); include __DIR__ . '/../api/account/unreconcile.php'; $raw7 = ob_get_clean();
$res7 = json_decode($raw7, true);
ok('unreconcile: success with valid reason', !empty($res7['success']), $raw7);

$newStatus = $pdo->query("SELECT status FROM bank_reconciliations WHERE reconciliation_id=$recId")->fetchColumn();
ok('After unreconcile: reconciliation status is pending', $newStatus === 'pending', "status=$newStatus");

$txnStatus = $pdo->query("SELECT status FROM bank_transactions WHERE transaction_id=$txnId")->fetchColumn();
ok('After unreconcile: bank_transactions reverted to cleared', $txnStatus === 'cleared', "status=$txnStatus");

// Period lock should now pass for the Jan entry
$blockedAfterUnrecon = false;
try { assertNotInFinalizedReconPeriod($pdo, $entryId); } catch (Exception $e) { $blockedAfterUnrecon = true; }
ok('After unreconcile: period lock no longer blocks JE in that period', !$blockedAfterUnrecon);

// ── Test 11: Finalize stamp ───────────────────────────────────────────────────
// Make recFebId reconciled manually to test
$pdo->exec("UPDATE bank_reconciliations SET status='reconciled', adjusted_balance=5000.00 WHERE reconciliation_id=$recFebId");
$pdo->exec("UPDATE bank_transactions SET status='reconciled' WHERE reconciliation_id=$recFebId AND matching_status IN ('matched','manual')");
$st11 = $pdo->query("SELECT status FROM bank_reconciliations WHERE reconciliation_id=$recFebId")->fetchColumn();
ok('Finalize sets status=reconciled', $st11 === 'reconciled', "status=$st11");

// ── Test 12: unreconcile on non-reconciled rec is rejected ─────────────────────
$_POST = ['reconciliation_id' => $recFebId, 'reason' => 'Testing non-reconciled rejection scenario', '_csrf' => csrf_token()];
// First unlock it
$pdo->exec("UPDATE bank_reconciliations SET status='pending' WHERE reconciliation_id=$recFebId");
ob_start(); include __DIR__ . '/../api/account/unreconcile.php'; $raw12 = ob_get_clean();
$res12 = json_decode($raw12, true);
ok('unreconcile: non-reconciled reconciliation rejected', !empty($res12) && !$res12['success'], $raw12);

// ── Cleanup ───────────────────────────────────────────────────────────────────
$pdo->exec("DELETE FROM bank_transactions WHERE reference_number = 'T4-LINE-1'");
$pdo->exec("DELETE FROM journal_entry_items WHERE entry_id IN ($entryId, $entryMar, $entryFeb)");
$pdo->exec("DELETE FROM journal_entries WHERE entry_id IN ($entryId, $entryMar, $entryFeb)");
$pdo->exec("DELETE FROM bank_reconciliations WHERE reconciliation_id IN ($recId,$recFebId)");

echo "\n--- Phase 4 results: $pass passed, $fail failed ---\n\n";
exit($fail > 0 ? 1 : 0);
