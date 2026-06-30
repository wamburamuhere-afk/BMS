<?php
/**
 * api/account/add_reconciliation_adjustment.php
 * -----------------------------------------------
 * Posts an adjusting journal entry for a bank reconciliation and writes a
 * matching bank-register line so the item appears in the worksheet and can
 * drive the difference to zero.
 *
 * Supported adjustment types and their Dr/Cr:
 *   bank_charge     Dr gl_account_id (expense)      Cr bank
 *   interest_earned Dr bank                         Cr gl_account_id (income)
 *   nsf             Dr gl_account_id (AR / expense) Cr bank
 *   standing_order  Dr gl_account_id (expense)      Cr bank
 *   other_out       Dr gl_account_id                Cr bank   (user-defined withdrawal)
 *   other_in        Dr bank                         Cr gl_account_id (user-defined deposit)
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/ledger_post.php';
require_once __DIR__ . '/../../core/bank_register.php';
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

$allowedTypes = ['bank_charge', 'interest_earned', 'nsf', 'standing_order', 'other_out', 'other_in'];

try {
    $recId        = (int)($_POST['reconciliation_id'] ?? 0);
    $type         = trim($_POST['type'] ?? '');
    $amount       = (float)($_POST['amount'] ?? 0);
    $glAccountId  = (int)($_POST['gl_account_id'] ?? 0);
    $memo         = trim($_POST['memo'] ?? '');
    $adjDate      = trim($_POST['adjustment_date'] ?? date('Y-m-d'));

    if ($recId <= 0)                              throw new Exception('Invalid reconciliation');
    if (!in_array($type, $allowedTypes, true))    throw new Exception('Invalid adjustment type');
    if ($amount <= 0)                             throw new Exception('Amount must be greater than zero');
    if ($glAccountId <= 0)                        throw new Exception('GL account is required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $adjDate)) throw new Exception('Invalid date format');

    // Load the reconciliation header
    $rec = $pdo->prepare(
        "SELECT r.*, a.account_name AS bank_name
           FROM bank_reconciliations r
           JOIN accounts a ON a.account_id = r.bank_account_id
          WHERE r.reconciliation_id = ? AND r.status = 'pending'"
    );
    $rec->execute([$recId]);
    $rec = $rec->fetch(PDO::FETCH_ASSOC);
    if (!$rec) throw new Exception('Reconciliation not found or not in pending status');

    $bankId  = (int)$rec['bank_account_id'];
    $userId  = (int)$_SESSION['user_id'];
    $desc    = $memo ?: ucwords(str_replace('_', ' ', $type));
    $projId  = null;  // reconciliation adjustments are company-wide

    // Build Dr/Cr based on type
    $isDeposit = in_array($type, ['interest_earned', 'other_in'], true);
    if ($isDeposit) {
        // Bank receives money: Dr Bank / Cr gl_account_id
        $lines = [
            ['account_id' => $bankId,      'type' => 'debit',  'amount' => $amount, 'description' => $desc],
            ['account_id' => $glAccountId, 'type' => 'credit', 'amount' => $amount, 'description' => $desc],
        ];
        $regType = 'deposit';
    } else {
        // Bank pays out: Dr gl_account_id / Cr Bank
        $lines = [
            ['account_id' => $glAccountId, 'type' => 'debit',  'amount' => $amount, 'description' => $desc],
            ['account_id' => $bankId,      'type' => 'credit', 'amount' => $amount, 'description' => $desc],
        ];
        $regType = 'withdrawal';
    }

    $pdo->beginTransaction();

    // Post the balanced journal entry
    $entryId = postLedgerEntry($pdo, $desc, $lines, $projId, $recId, 'bank_recon_adjustment', $adjDate, $userId);

    // Write the bank register line (auto-reference = ADJ-{entryId})
    $regRef = 'ADJ-' . $entryId;
    $regId  = recordBankTransaction($pdo, $bankId, $amount, $regType, $adjDate, $regRef, $desc, $userId);

    // Auto-match the new register line to this reconciliation
    if ($regId) {
        $pdo->prepare(
            "UPDATE bank_transactions
                SET matching_status = 'matched', reconciliation_id = ?, status = 'cleared', updated_at = NOW()
              WHERE transaction_id = ?"
        )->execute([$recId, $regId]);
    }

    // Store the adjustment record
    $pdo->prepare(
        "INSERT INTO bank_reconciliation_adjustments
            (reconciliation_id, type, amount, gl_account_id, journal_entry_id, memo, adjustment_date, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$recId, $type, $amount, $glAccountId, $entryId, $memo ?: null, $adjDate, $userId]);

    // Update the reconciliation's book_balance to reflect the adjustment
    // (re-derive from ledger as-of period_end so it stays accurate)
    require_once __DIR__ . '/../../core/account_balance.php';
    $newBook = accountLedgerBalanceAsOf($pdo, $bankId, $rec['period_end']);
    $newDiff = round((float)$rec['statement_balance'] - $newBook, 2);
    $pdo->prepare(
        "UPDATE bank_reconciliations SET book_balance = ?, difference = ?, updated_at = NOW()
          WHERE reconciliation_id = ?"
    )->execute([$newBook, $newDiff, $recId]);

    $pdo->commit();

    logActivity($pdo, $userId, 'Bank reconciliation adjustment',
        "Rec #$recId — $type — " . number_format($amount, 2) . " — JE#$entryId");

    echo json_encode([
        'success'   => true,
        'message'   => 'Adjustment posted and matched.',
        'entry_id'  => $entryId,
        'new_book'  => $newBook,
        'new_diff'  => $newDiff,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('add_reconciliation_adjustment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
