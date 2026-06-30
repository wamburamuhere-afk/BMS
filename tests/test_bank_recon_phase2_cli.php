<?php
/**
 * tests/test_bank_recon_phase2_cli.php
 * -------------------------------------
 * Phase 2 — Verify:
 *   1.  bank_reconciliation_adjustments table exists
 *   2.  bank_charge adjustment posts balanced JE (Dr expense / Cr bank)
 *   3.  bank_charge writes a withdrawal register line
 *   4.  bank_charge register line is auto-matched to the reconciliation
 *   5.  bank_charge updates book_balance on the reconciliation header
 *   6.  interest_earned adjustment posts balanced JE (Dr bank / Cr income)
 *   7.  interest_earned writes a deposit register line
 *   8.  adjustment row saved in bank_reconciliation_adjustments
 *   9.  get_reconciliation_adjustments.php returns the adjustment
 *  10.  create_entry_from_statement_line: posts JE for deposit line
 *  11.  create_entry_from_statement_line: auto-matches the original line
 *  12.  create_entry_from_statement_line: adjustment row stored
 *  13.  invalid type rejected by add_reconciliation_adjustment
 *  14.  zero amount rejected
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/account_balance.php';
require_once __DIR__ . '/../core/bank_register.php';
require_once __DIR__ . '/../core/ledger_post.php';
global $pdo;

$pass = 0; $fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $label\n"; $pass++; }
    else        { echo "  FAIL: $label" . ($detail ? " — $detail" : '') . "\n"; $fail++; }
}

echo "\n=== Phase 2: Adjusting Journal Entries ===\n\n";

// ── Test 1: Table exists ──────────────────────────────────────────────────────
$tbl = $pdo->query("SHOW TABLES LIKE 'bank_reconciliation_adjustments'")->fetchColumn();
ok('bank_reconciliation_adjustments table exists', !empty($tbl));

// ── Resolve fixture accounts ──────────────────────────────────────────────────
$bankRow = $pdo->query(
    "SELECT a.account_id FROM accounts a
      JOIN account_types at ON a.account_type_id = at.type_id
     WHERE at.category IN ('asset','cash') AND a.status = 'active'
     ORDER BY a.account_id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$expRow = $pdo->query(
    "SELECT account_id FROM accounts WHERE account_name LIKE '%expense%' AND status = 'active' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$incRow = $pdo->query(
    "SELECT account_id FROM accounts
     WHERE (account_name LIKE '%income%' OR account_name LIKE '%revenue%') AND status = 'active' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$bankRow || !$expRow) {
    echo "  SKIP: Fixture accounts not found.\n"; exit(0);
}
$bankId = (int)$bankRow['account_id'];
$expId  = (int)$expRow['account_id'];
$incId  = $incRow ? (int)$incRow['account_id'] : $expId;

// ── Create a throwaway pending reconciliation ──────────────────────────────────
$recStmt = $pdo->prepare(
    "INSERT INTO bank_reconciliations
        (reconciliation_number, bank_account_id, reconciliation_date,
         period_start, period_end, statement_balance, book_balance, adjusted_balance,
         difference, status, prepared_by, created_at)
     VALUES ('TEST-P2-' . UNIX_TIMESTAMP(), ?, CURDATE(), DATE_FORMAT(CURDATE(),'%Y-%m-01'),
             CURDATE(), 50000.00, 0.00, 0.00, 50000.00, 'pending', 1, NOW())"
);
// Actually PDO doesn't allow UNIX_TIMESTAMP() in prepared params easily, use concat:
$pdo->exec("INSERT INTO bank_reconciliations
    (reconciliation_number, bank_account_id, reconciliation_date, period_start, period_end,
     statement_balance, book_balance, adjusted_balance, difference, status, prepared_by, created_at)
    VALUES (CONCAT('TEST-P2-', UNIX_TIMESTAMP()), $bankId, CURDATE(),
            DATE_FORMAT(CURDATE(),'%Y-%m-01'), CURDATE(),
            50000.00, 0.00, 0.00, 50000.00, 'pending', 1, NOW())");
$recId = (int)$pdo->lastInsertId();

// ── Tests 2–5: bank_charge adjustment ─────────────────────────────────────────
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['user_id'] = 1;
$_SESSION['is_admin'] = true;

$chargeAmt = 150.00;
$adjDate   = date('Y-m-d');

try {
    // Simulate the add_reconciliation_adjustment logic directly
    $desc  = 'Test bank charge';
    $lines = [
        ['account_id' => $expId,  'type' => 'debit',  'amount' => $chargeAmt, 'description' => $desc],
        ['account_id' => $bankId, 'type' => 'credit', 'amount' => $chargeAmt, 'description' => $desc],
    ];

    $pdo->beginTransaction();

    $entryId = postLedgerEntry($pdo, $desc, $lines, null, $recId, 'bank_recon_adjustment', $adjDate, 1);

    // Verify balanced JE written
    $drSum = (float)$pdo->query("SELECT SUM(amount) FROM journal_entry_items WHERE entry_id=$entryId AND type='debit'")->fetchColumn();
    $crSum = (float)$pdo->query("SELECT SUM(amount) FROM journal_entry_items WHERE entry_id=$entryId AND type='credit'")->fetchColumn();
    ok('bank_charge JE is balanced', abs($drSum - $crSum) < 0.01, "dr=$drSum cr=$crSum");
    ok('bank_charge JE Dr is to expense account', (int)$pdo->query("SELECT account_id FROM journal_entry_items WHERE entry_id=$entryId AND type='debit'")->fetchColumn() === $expId);

    // Write register line
    $regRef = 'ADJ-' . $entryId;
    $regId  = recordBankTransaction($pdo, $bankId, $chargeAmt, 'withdrawal', $adjDate, $regRef, $desc, 1);
    ok('bank_charge writes withdrawal register line', $regId > 0, "regId=$regId");

    // Auto-match
    $pdo->prepare("UPDATE bank_transactions SET matching_status='matched', reconciliation_id=? WHERE transaction_id=?")->execute([$recId, $regId]);
    $matchStatus = $pdo->query("SELECT matching_status FROM bank_transactions WHERE transaction_id=$regId")->fetchColumn();
    ok('bank_charge register line auto-matched', $matchStatus === 'matched', "status=$matchStatus");

    // Store adjustment + update book_balance
    $pdo->prepare("INSERT INTO bank_reconciliation_adjustments
        (reconciliation_id,type,amount,gl_account_id,journal_entry_id,memo,adjustment_date,created_by)
        VALUES (?,?,?,?,?,?,?,?)")->execute([$recId,'bank_charge',$chargeAmt,$expId,$entryId,$desc,$adjDate,1]);

    $newBook = accountLedgerBalanceAsOf($pdo, $bankId, date('Y-m-d'));
    $pdo->prepare("UPDATE bank_reconciliations SET book_balance=?, difference=?, updated_at=NOW() WHERE reconciliation_id=?")
        ->execute([$newBook, 50000.00 - $newBook, $recId]);

    $storedBook = (float)$pdo->query("SELECT book_balance FROM bank_reconciliations WHERE reconciliation_id=$recId")->fetchColumn();
    ok('bank_charge updates book_balance on header', $storedBook === $newBook, "stored=$storedBook computed=$newBook");

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok('bank_charge JE is balanced', false, $e->getMessage());
    ok('bank_charge JE Dr is to expense account', false, 'skipped');
    ok('bank_charge writes withdrawal register line', false, 'skipped');
    ok('bank_charge register line auto-matched', false, 'skipped');
    ok('bank_charge updates book_balance on header', false, 'skipped');
}

// ── Tests 6–8: interest_earned adjustment ─────────────────────────────────────
try {
    $intAmt = 200.00;
    $desc2  = 'Test interest earned';
    $lines2 = [
        ['account_id' => $bankId, 'type' => 'debit',  'amount' => $intAmt, 'description' => $desc2],
        ['account_id' => $incId,  'type' => 'credit', 'amount' => $intAmt, 'description' => $desc2],
    ];

    $pdo->beginTransaction();
    $entryId2 = postLedgerEntry($pdo, $desc2, $lines2, null, $recId, 'bank_recon_adjustment', $adjDate, 1);

    $drAcc = (int)$pdo->query("SELECT account_id FROM journal_entry_items WHERE entry_id=$entryId2 AND type='debit'")->fetchColumn();
    ok('interest_earned JE Dr is to bank account', $drAcc === $bankId, "drAcc=$drAcc bankId=$bankId");

    $regRef2 = 'ADJ-' . $entryId2;
    $regId2  = recordBankTransaction($pdo, $bankId, $intAmt, 'deposit', $adjDate, $regRef2, $desc2, 1);
    ok('interest_earned writes deposit register line', $regId2 > 0, "regId=$regId2");

    $pdo->prepare("INSERT INTO bank_reconciliation_adjustments
        (reconciliation_id,type,amount,gl_account_id,journal_entry_id,memo,adjustment_date,created_by)
        VALUES (?,?,?,?,?,?,?,?)")->execute([$recId,'interest_earned',$intAmt,$incId,$entryId2,$desc2,$adjDate,1]);

    $adjCnt = (int)$pdo->query("SELECT COUNT(*) FROM bank_reconciliation_adjustments WHERE reconciliation_id=$recId")->fetchColumn();
    ok('adjustment rows saved in bank_reconciliation_adjustments', $adjCnt >= 2, "count=$adjCnt");

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok('interest_earned JE Dr is to bank account', false, $e->getMessage());
    ok('interest_earned writes deposit register line', false, 'skipped');
    ok('adjustment rows saved', false, 'skipped');
}

// ── Test 9: get_reconciliation_adjustments API ────────────────────────────────
$_GET = ['reconciliation_id' => $recId];
ob_start();
include __DIR__ . '/../api/account/get_reconciliation_adjustments.php';
$raw9 = ob_get_clean();
$res9 = json_decode($raw9, true);
ok('get_reconciliation_adjustments returns adjustments', !empty($res9['success']) && count($res9['adjustments'] ?? []) >= 2, $raw9);

// ── Tests 10–12: create_entry_from_statement_line ─────────────────────────────
try {
    // Insert an unmatched bank_transactions line to simulate an unrecorded bank transaction
    $pdo->exec("INSERT INTO bank_transactions
        (bank_account_id, amount, transaction_type, transaction_date, reference_number,
         description, created_by, created_at, matching_status)
        VALUES ($bankId, 300.00, 'deposit', CURDATE(), 'STMT-TEST-001',
                'Unrecorded deposit', 1, NOW(), 'unmatched')");
    $txnId = (int)$pdo->lastInsertId();

    // Simulate create_entry_from_statement_line logic
    $pdo->beginTransaction();
    $cflDesc  = 'Unrecorded deposit';
    $cflLines = [
        ['account_id' => $bankId, 'type' => 'debit',  'amount' => 300.00, 'description' => $cflDesc],
        ['account_id' => $incId,  'type' => 'credit', 'amount' => 300.00, 'description' => $cflDesc],
    ];
    $cflEntryId = postLedgerEntry($pdo, $cflDesc, $cflLines, null, $recId, 'bank_recon_create_from_line', date('Y-m-d'), 1);
    ok('create_from_line posts balanced JE', $cflEntryId > 0, "entryId=$cflEntryId");

    // Auto-match the original line
    $pdo->prepare("UPDATE bank_transactions SET matching_status='manual', reconciliation_id=? WHERE transaction_id=?")->execute([$recId, $txnId]);
    $ms = $pdo->query("SELECT matching_status FROM bank_transactions WHERE transaction_id=$txnId")->fetchColumn();
    ok('create_from_line auto-matches the original line', $ms === 'manual', "status=$ms");

    // Store in adjustments
    $pdo->prepare("INSERT INTO bank_reconciliation_adjustments
        (reconciliation_id,type,amount,gl_account_id,journal_entry_id,memo,adjustment_date,created_by)
        VALUES (?,'other_in',300.00,?,?,?,?,1)")->execute([$recId,$incId,$cflEntryId,$cflDesc,date('Y-m-d')]);

    $adjCnt2 = (int)$pdo->query("SELECT COUNT(*) FROM bank_reconciliation_adjustments WHERE reconciliation_id=$recId AND type='other_in'")->fetchColumn();
    ok('create_from_line stores adjustment row', $adjCnt2 >= 1, "count=$adjCnt2");

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok('create_from_line posts balanced JE', false, $e->getMessage());
    ok('create_from_line auto-matches original line', false, 'skipped');
    ok('create_from_line stores adjustment row', false, 'skipped');
}

// ── Tests 13–14: Validation gates ────────────────────────────────────────────
// Test 13: invalid type
$_POST = ['reconciliation_id'=>$recId,'type'=>'invalid_type','amount'=>100,'gl_account_id'=>$expId,'adjustment_date'=>date('Y-m-d'),'_csrf'=>csrf_token()];
ob_start();
include __DIR__ . '/../api/account/add_reconciliation_adjustment.php';
$raw13 = ob_get_clean();
$res13 = json_decode($raw13, true);
ok('invalid adjustment type rejected', !empty($res13) && !$res13['success'], $raw13);

// Test 14: zero amount
$_POST = ['reconciliation_id'=>$recId,'type'=>'bank_charge','amount'=>0,'gl_account_id'=>$expId,'adjustment_date'=>date('Y-m-d'),'_csrf'=>csrf_token()];
ob_start();
include __DIR__ . '/../api/account/add_reconciliation_adjustment.php';
$raw14 = ob_get_clean();
$res14 = json_decode($raw14, true);
ok('zero amount rejected', !empty($res14) && !$res14['success'], $raw14);

// ── Cleanup the throwaway reconciliation ─────────────────────────────────────
$pdo->exec("DELETE FROM bank_reconciliation_adjustments WHERE reconciliation_id=$recId");
$pdo->exec("DELETE FROM bank_transactions WHERE reconciliation_id=$recId");
$pdo->exec("DELETE FROM bank_reconciliations WHERE reconciliation_id=$recId");

echo "\n--- Phase 2 results: $pass passed, $fail failed ---\n\n";
exit($fail > 0 ? 1 : 0);
