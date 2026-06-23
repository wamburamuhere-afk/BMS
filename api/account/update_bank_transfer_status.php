<?php
/**
 * api/account/update_bank_transfer_status.php
 *
 * Bank transfers auto-post on creation (add_bank_transfer.php), so the only
 * remaining status action is REVERSE: undo a posted transfer that was made in
 * error. Reversing a posted transfer:
 *     - restores both cash balances,
 *     - removes the canonical journal_entries mirror (so the reports drop it),
 *     - deletes the legacy books_transactions / transactions rows,
 *     - reverses both bank-register rows,
 *     - clears transaction_id and marks the transfer 'reversed'.
 *
 * There is no pending/reviewed/approved/rejected workflow any more.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';           // applyAccountBalanceDelta
require_once __DIR__ . '/../../core/bank_register.php';            // reverseBankTransaction
require_once __DIR__ . '/../../api/helpers/transaction_helper.php'; // unmirrorTransactionFromJournal
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    $id     = (int)($_POST['id'] ?? 0);
    // Accept 'reversed' (preferred). 'rejected' is tolerated as an alias for older UI.
    $status = $_POST['status'] ?? 'reversed';
    if ($id <= 0) throw new Exception('Missing transfer id');
    if (!in_array($status, ['reversed', 'rejected'], true)) {
        throw new Exception('Only reversing a posted transfer is supported.');
    }

    if (!canEdit('bank_transfers')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to reverse a bank transfer']);
        exit;
    }

    $snap = $pdo->prepare("SELECT transfer_number, from_account_id, to_account_id, amount, charges,
                                  status AS old_status, transaction_id
                             FROM bank_transfers WHERE id = ?");
    $snap->execute([$id]);
    $t = $snap->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new Exception('Bank transfer not found');

    if ($t['old_status'] === 'reversed') {
        echo json_encode(['success' => false, 'message' => 'This transfer has already been reversed.']);
        exit;
    }
    if ($t['old_status'] !== 'posted') {
        throw new Exception('Only a posted transfer can be reversed.');
    }

    $amount  = (float)$t['amount'];
    $charges = (float)$t['charges'];
    $total   = round($amount + $charges, 2);
    $from    = (int)$t['from_account_id'];
    $to      = (int)$t['to_account_id'];
    $ref     = $t['transfer_number'];
    $txnId   = (int)$t['transaction_id'];

    $pdo->beginTransaction();

    // Restore both cash balances (mirror of the post: source back up, destination back down).
    applyAccountBalanceDelta($pdo, $from, 'debit',  $total);    // source restored
    applyAccountBalanceDelta($pdo, $to,   'credit', $amount);   // destination restored

    if ($txnId > 0) {
        // Remove the canonical journal mirror FIRST (this is the part the old void
        // path missed — without it a reversed transfer still showed in the reports).
        unmirrorTransactionFromJournal($pdo, $txnId);
        // Then the legacy ledger rows.
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([$txnId]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$txnId]);
    }

    // Reverse both bank-register rows.
    reverseBankTransaction($pdo, $from, $ref, 'withdrawal');
    reverseBankTransaction($pdo, $to,   $ref, 'deposit');

    // Mark reversed + unlink the transaction.
    $pdo->prepare("UPDATE bank_transfers SET status = 'reversed', transaction_id = NULL,
                          updated_by = ?, updated_at = NOW() WHERE id = ?")
        ->execute([(int)$_SESSION['user_id'], $id]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Reversed bank transfer $ref (amount " . number_format($amount, 2) . ")");

    echo json_encode(['success' => true, 'message' => "Transfer $ref reversed — the money has been returned and the entry removed from the reports."]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('update_bank_transfer_status (reverse) error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
