<?php
/**
 * Tender participation fee -> real GL payment — CLI test
 * --------------------------------------------------------
 *   php tests/test_tender_participation_fee_cli.php
 *
 * post_principle.md verification for the tender participation fee: exercises
 * the REAL payTenderParticipationFee()/reverseTenderParticipationFee()
 * (core/tender_fee.php) end to end, not a re-derivation of their logic.
 *
 *   q1/q5 - the paid fee actually lands in journal_entries/journal_entry_items
 *           (accrual leg + the postOutflow-mirrored settlement leg), balanced
 *   q2    - correct Dr/Cr: Dr Expense (via Accrued Expenses) / Cr the chosen
 *           Paid-From bank account; the bank account's stored balance actually
 *           moves
 *   q3    - a fee of 0 posts NOTHING (no cash event) but the tender still
 *           advances to INVITATION; a fee > 0 REQUIRES a Paid-From account
 *   q4    - idempotent: a tender already marked paid refuses a second RECORD_FEE
 *   q6    - reverseTenderParticipationFee() unwinds the accrual, the outflow,
 *           and the bank register row, restoring the bank balance exactly
 *
 * payTenderParticipationFee() follows the same $ownTxn convention as
 * core/tender_award.php::awardTenderToProject() — detects it's already inside
 * this test's transaction and does not commit/rollback its own, so the whole
 * test rolls back cleanly. Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/tender_fee.php";
require_once "$root/core/expense_posting.php"; // accrualEntryId/accrualVoided for assertions
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a, $b){ return abs((float)$a - (float)$b) < 0.01; }
function bal(PDO $pdo, int $id){ $s=$pdo->prepare("SELECT current_balance FROM accounts WHERE account_id=?"); $s->execute([$id]); return (float)$s->fetchColumn(); }
function legsBalancedJei(PDO $pdo, int $entryId){
    $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE 0 END),0) d, COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) c FROM journal_entry_items WHERE entry_id=?");
    $s->execute([$entryId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return approx($r['d'], $r['c']) && $r['d'] > 0;
}
function jeiDebit(PDO $pdo, int $entryId, int $accountId): float {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=? AND account_id=? AND type='debit'");
    $s->execute([$entryId, $accountId]);
    return (float)$s->fetchColumn();
}
function jeiCredit(PDO $pdo, int $entryId, int $accountId): float {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=? AND account_id=? AND type='credit'");
    $s->execute([$entryId, $accountId]);
    return (float)$s->fetchColumn();
}

register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. New/changed files are lint-clean');
    // ─────────────────────────────────────────────────────────────────────
    foreach ([
        'core/tender_fee.php',
        'api/tender_workflow.php',
        'app/bms/tenders/tenders.php',
        'migrations/2026_09_06_tender_participation_fee_payment.php',
    ] as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }
    ok(function_exists('payTenderParticipationFee'), 'payTenderParticipationFee() is defined');
    ok(function_exists('reverseTenderParticipationFee'), 'reverseTenderParticipationFee() is defined');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Schema — new tenders columns exist');
    // ─────────────────────────────────────────────────────────────────────
    foreach ([
        'participation_fee_paid', 'participation_fee_paid_date',
        'participation_fee_bank_account_id', 'participation_fee_expense_account_id',
        'participation_fee_transaction_id',
    ] as $col) {
        ok((bool)$pdo->query("SHOW COLUMNS FROM tenders LIKE '$col'")->fetch(), "tenders.$col exists");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. Build test fixtures — bank account, expense account, tender');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $pdo->exec("
        INSERT INTO accounts (account_code, account_name, account_type, cash_flow_category, status, opening_balance, current_balance)
        VALUES ('TEST-BANK-FEE', 'CLI Test Bank (Fee)', 'asset', 'cash', 'active', 100000, 100000)
    ");
    $bankAccountId = (int)$pdo->lastInsertId();

    $pdo->exec("
        INSERT INTO accounts (account_code, account_name, account_type, status, opening_balance, current_balance)
        VALUES ('TEST-EXP-FEE', 'CLI Test Tender Fee Expense', 'expense', 'active', 0, 0)
    ");
    $expenseAccountId = (int)$pdo->lastInsertId();

    $accruedAcc = accruedExpensesAccountId($pdo);
    ok($accruedAcc !== null, 'an Accrued Expenses account resolves (setting or code 2-1500) — required for the settle leg');

    $bankBefore = bal($pdo, $bankAccountId);

    $pdo->exec("
        INSERT INTO tenders (tender_no, tender_description, procuring_entity_name, status, currency, tender_sum)
        VALUES ('TEST-FEE-001', 'CLI Fee Test Tender', 'Test Procuring Entity', 'APPROVED', 'TShs', 0)
    ");
    $tenderId = (int)$pdo->lastInsertId();
    $testUserId = 1;

    // ─────────────────────────────────────────────────────────────────────
    section('4. Validation — a fee > 0 without a Paid-From account is refused');
    // ─────────────────────────────────────────────────────────────────────
    $noBank = payTenderParticipationFee($pdo, $tenderId, $testUserId, ['fee_amount' => 25000]);
    ok($noBank['success'] === false, 'refused: no bank_account_id supplied for a non-zero fee');
    ok(stripos($noBank['message'], 'Paid From') !== false, "refusal message names the missing 'Paid From' account");

    // ─────────────────────────────────────────────────────────────────────
    section('5. Pay the fee for real — Dr Expense (via Accrued) / Cr Bank');
    // ─────────────────────────────────────────────────────────────────────
    $result = payTenderParticipationFee($pdo, $tenderId, $testUserId, [
        'fee_amount'         => 25000,
        'bank_account_id'    => $bankAccountId,
        'expense_account_id' => $expenseAccountId,
    ]);
    ok($result['success'] === true, 'payTenderParticipationFee() reports success: ' . ($result['message'] ?? ''));
    $txnId = $result['transaction_id'] ?? null;
    ok(!empty($txnId), 'a transaction_id was returned');

    $t = $pdo->prepare("SELECT * FROM tenders WHERE tender_id = ?");
    $t->execute([$tenderId]);
    $tender = $t->fetch(PDO::FETCH_ASSOC);
    ok($tender['status'] === 'INVITATION', 'tender moved to INVITATION status');
    ok((int)$tender['participation_fee_paid'] === 1, 'tenders.participation_fee_paid = 1');
    ok($tender['participation_fee_paid_date'] !== null, 'tenders.participation_fee_paid_date is set');
    ok((int)$tender['participation_fee_bank_account_id'] === $bankAccountId, 'the chosen bank account was stored');
    ok((int)$tender['participation_fee_expense_account_id'] === $expenseAccountId, 'the chosen expense account was stored');
    ok((int)$tender['participation_fee_transaction_id'] === (int)$txnId, 'the outflow transaction_id was stored for later reversal');
    ok(approx((float)$tender['participation_fee_amount'], 25000), 'tenders.participation_fee_amount = 25,000');

    ok(approx(bal($pdo, $bankAccountId), $bankBefore - 25000), 'the bank account balance actually decreased by 25,000');

    $accrualEntryId = accrualEntryId($pdo, 'tender_participation_fee', $tenderId);
    ok($accrualEntryId !== null, 'an accrual entry was posted under entity_type=tender_participation_fee');
    if ($accrualEntryId) {
        ok(approx(jeiDebit($pdo, $accrualEntryId, $expenseAccountId), 25000), 'accrual entry: Expense account DEBITED 25,000');
        ok(approx(jeiCredit($pdo, $accrualEntryId, (int)$accruedAcc), 25000), 'accrual entry: Accrued Expenses CREDITED 25,000');
        ok(legsBalancedJei($pdo, $accrualEntryId), 'accrual entry is balanced (Dr = Cr)');
    }

    $mirrorEntry = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ? AND status = 'posted' LIMIT 1");
    $mirrorEntry->execute([$txnId]);
    $mirrorEntryId = $mirrorEntry->fetchColumn();
    ok($mirrorEntryId !== false, 'the outflow was mirrored into the canonical journal_entries ledger');
    if ($mirrorEntryId) {
        ok(approx(jeiDebit($pdo, (int)$mirrorEntryId, (int)$accruedAcc), 25000), 'settlement entry: Accrued Expenses DEBITED 25,000 (clearing the accrual)');
        ok(approx(jeiCredit($pdo, (int)$mirrorEntryId, $bankAccountId), 25000), 'settlement entry: Bank account CREDITED 25,000');
        ok(legsBalancedJei($pdo, (int)$mirrorEntryId), 'settlement entry is balanced (Dr = Cr)');
    }

    $reg = $pdo->prepare("SELECT * FROM bank_transactions WHERE bank_account_id = ? AND reference_number = ?");
    $reg->execute([$bankAccountId, 'TENDER-FEE-' . $tenderId]);
    $regRow = $reg->fetch(PDO::FETCH_ASSOC);
    ok($regRow !== false, 'a bank register row was written for the payment');
    if ($regRow) {
        ok($regRow['transaction_type'] === 'withdrawal', 'bank register row is a withdrawal');
        ok(approx((float)$regRow['amount'], 25000), 'bank register row amount = 25,000');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('6. Idempotency — a second RECORD_FEE on the same tender is refused');
    // ─────────────────────────────────────────────────────────────────────
    $second = payTenderParticipationFee($pdo, $tenderId, $testUserId, [
        'fee_amount' => 99999, 'bank_account_id' => $bankAccountId, 'expense_account_id' => $expenseAccountId,
    ]);
    ok($second['success'] === false, 'a second fee-payment attempt on the same tender is refused');
    ok(stripos($second['message'], 'already') !== false, 'the refusal message says it was already paid');

    $countAfter = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE entity_type = 'tender_participation_fee' AND entity_id = ? AND status = 'posted'");
    $countAfter->execute([$tenderId]);
    ok((int)$countAfter->fetchColumn() === 1, 'still exactly one posted accrual entry — no duplicate was created');

    // ─────────────────────────────────────────────────────────────────────
    section('7. Reversal (tender delete path) — accrual + outflow + register all unwind');
    // ─────────────────────────────────────────────────────────────────────
    reverseTenderParticipationFee($pdo, $tenderId, $testUserId);

    ok(accrualVoided($pdo, 'tender_participation_fee', $tenderId), 'the accrual was reversed (void entry posted)');
    ok(approx(bal($pdo, $bankAccountId), $bankBefore), 'the bank account balance was restored to its original value');

    $regAfter = $pdo->prepare("SELECT * FROM bank_transactions WHERE bank_account_id = ? AND reference_number = ?");
    $regAfter->execute([$bankAccountId, 'TENDER-FEE-' . $tenderId]);
    ok($regAfter->fetch() === false, 'the bank register row was removed');

    $mirrorGone = $pdo->prepare("SELECT 1 FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ?");
    $mirrorGone->execute([$txnId]);
    ok($mirrorGone->fetch() === false, 'the mirrored settlement journal entry was removed');

    // ─────────────────────────────────────────────────────────────────────
    section('8. Zero fee — nothing payable posts nothing, but the tender still advances');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec("
        INSERT INTO tenders (tender_no, tender_description, procuring_entity_name, status, currency, tender_sum)
        VALUES ('TEST-FEE-002', 'CLI Fee Test Tender (Zero)', 'Test Procuring Entity', 'APPROVED', 'TShs', 0)
    ");
    $tenderId2 = (int)$pdo->lastInsertId();

    $zero = payTenderParticipationFee($pdo, $tenderId2, $testUserId, ['fee_amount' => 0]);
    ok($zero['success'] === true, 'a zero fee is accepted with no accounts named: ' . ($zero['message'] ?? ''));
    ok(empty($zero['transaction_id']), 'no outflow transaction was posted for a zero fee');

    $t2 = $pdo->prepare("SELECT status, participation_fee_paid FROM tenders WHERE tender_id = ?");
    $t2->execute([$tenderId2]);
    $tender2 = $t2->fetch(PDO::FETCH_ASSOC);
    ok($tender2['status'] === 'INVITATION', 'the zero-fee tender still advanced to INVITATION');
    ok((int)$tender2['participation_fee_paid'] === 0, 'participation_fee_paid stayed 0 — nothing was actually paid');

    $noAccrual = accrualEntryId($pdo, 'tender_participation_fee', $tenderId2);
    ok($noAccrual === null, 'no accrual entry exists for the zero-fee tender');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — no test data left behind');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
