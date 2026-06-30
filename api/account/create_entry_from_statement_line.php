<?php
/**
 * api/account/create_entry_from_statement_line.php
 * --------------------------------------------------
 * Creates a GL journal entry for an unmatched bank-register line (e.g., a
 * direct debit or unrecorded fee that exists in bank_transactions but has no
 * corresponding source document). After posting, the original line is
 * auto-matched to the reconciliation so the difference narrows.
 *
 * Flow:
 *   1. Load the unmatched bank_transactions line.
 *   2. Build Dr/Cr lines — bank leg + user-supplied contra account.
 *   3. Post via postLedgerEntry().
 *   4. Auto-match the original bank_transactions row.
 *   5. Store in bank_reconciliation_adjustments (type='other_out'/'other_in').
 *   6. Refresh book_balance on the reconciliation header.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/ledger_post.php';
require_once __DIR__ . '/../../core/account_balance.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}
csrf_check();
if (!canEdit('bank_reconciliation')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
}

try {
    $recId       = (int)($_POST['reconciliation_id'] ?? 0);
    $txnId       = (int)($_POST['transaction_id']    ?? 0);
    $glAccountId = (int)($_POST['gl_account_id']     ?? 0);
    $memo        = trim($_POST['memo'] ?? '');

    if ($recId  <= 0) throw new Exception('Invalid reconciliation');
    if ($txnId  <= 0) throw new Exception('Invalid transaction line');
    if ($glAccountId <= 0) throw new Exception('GL contra account is required');

    // Load the reconciliation
    $rec = $pdo->prepare(
        "SELECT * FROM bank_reconciliations WHERE reconciliation_id = ? AND status = 'pending'"
    );
    $rec->execute([$recId]);
    $rec = $rec->fetch(PDO::FETCH_ASSOC);
    if (!$rec) throw new Exception('Reconciliation not found or not pending');

    $bankId = (int)$rec['bank_account_id'];

    // Load the bank_transactions line — must belong to this bank account
    $line = $pdo->prepare(
        "SELECT * FROM bank_transactions
          WHERE transaction_id = ? AND bank_account_id = ?
            AND COALESCE(matching_status,'unmatched') NOT IN ('matched','manual','reconciled')"
    );
    $line->execute([$txnId, $bankId]);
    $line = $line->fetch(PDO::FETCH_ASSOC);
    if (!$line) throw new Exception('Line not found, does not belong to this account, or already matched');

    $amount  = (float)$line['amount'];
    $date    = $line['transaction_date'];
    $desc    = $memo ?: ($line['description'] ?: 'Unrecorded bank transaction');
    $userId  = (int)$_SESSION['user_id'];

    // Deposit = money IN to bank → Dr Bank / Cr contra
    // Withdrawal = money OUT of bank → Dr contra / Cr Bank
    $isDeposit = ($line['transaction_type'] === 'deposit');
    if ($isDeposit) {
        $adjType = 'other_in';
        $lines   = [
            ['account_id' => $bankId,      'type' => 'debit',  'amount' => $amount, 'description' => $desc],
            ['account_id' => $glAccountId, 'type' => 'credit', 'amount' => $amount, 'description' => $desc],
        ];
    } else {
        $adjType = 'other_out';
        $lines   = [
            ['account_id' => $glAccountId, 'type' => 'debit',  'amount' => $amount, 'description' => $desc],
            ['account_id' => $bankId,      'type' => 'credit', 'amount' => $amount, 'description' => $desc],
        ];
    }

    $pdo->beginTransaction();

    $entryId = postLedgerEntry($pdo, $desc, $lines, null, $recId, 'bank_recon_create_from_line', $date, $userId);

    // Auto-match the original statement line
    $pdo->prepare(
        "UPDATE bank_transactions
            SET matching_status = 'manual', reconciliation_id = ?, status = 'cleared', updated_at = NOW()
          WHERE transaction_id = ?"
    )->execute([$recId, $txnId]);

    // Store in adjustments table
    $pdo->prepare(
        "INSERT INTO bank_reconciliation_adjustments
            (reconciliation_id, type, amount, gl_account_id, journal_entry_id, memo, adjustment_date, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$recId, $adjType, $amount, $glAccountId, $entryId, $memo ?: null, $date, $userId]);

    // Refresh book_balance on the reconciliation header
    $newBook = accountLedgerBalanceAsOf($pdo, $bankId, $rec['period_end']);
    $newDiff = round((float)$rec['statement_balance'] - $newBook, 2);
    $pdo->prepare(
        "UPDATE bank_reconciliations SET book_balance = ?, difference = ?, updated_at = NOW()
          WHERE reconciliation_id = ?"
    )->execute([$newBook, $newDiff, $recId]);

    $pdo->commit();

    logActivity($pdo, $userId, 'Bank recon: entry created from statement line',
        "Rec #$recId — TXN#$txnId — JE#$entryId — $desc");

    echo json_encode([
        'success'  => true,
        'message'  => 'Journal entry posted and line matched.',
        'entry_id' => $entryId,
        'new_book' => $newBook,
        'new_diff' => $newDiff,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('create_entry_from_statement_line error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
