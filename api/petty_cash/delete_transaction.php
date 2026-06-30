<?php
// api/petty_cash/delete_transaction.php
//
// account_financial.md flow #11 (FIX) — deleting a petty-cash transaction now
// REVERSES its ledger posting. This was a bare `DELETE FROM petty_cash_transactions`
// that left the journal_entries mirror, the legacy transactions/books_transactions
// rows AND the accounts.current_balance deltas behind — so after the source was gone
// the expense stayed in the P&L and Petty Cash stayed reduced (the reports never
// reacted to the delete).
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';   // reversePettyCashLedger
require_once __DIR__ . '/../../core/bank_register.php';    // reverseBankTransaction

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('petty_cash')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete petty cash transactions']);
    exit;
}

try {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        throw new Exception("Invalid transaction ID");
    }

    // Snapshot the posting (type + ledger txn) + receipt BEFORE deleting, so the
    // matching journal can be reversed and the file cleaned up.
    $row = $pdo->prepare("SELECT type, transaction_id, receipt_file FROM petty_cash_transactions WHERE id = ?");
    $row->execute([$id]);
    $tx = $row->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        throw new Exception("Transaction not found.");
    }

    // Reverse the ledger + delete the record in ONE transaction, so a failure leaves
    // NOTHING half-done.
    $pdo->beginTransaction();

    // expense → reverseOutflow (undo the source leg); deposit/top-up →
    // reverseJournalBalances (undo BOTH legs). Each restores account balances, removes
    // the journal_entries mirror and the legacy transactions/books_transactions rows.
    // No-op if the entry was never posted (transaction_id null/0).
    reversePettyCashLedger($pdo, (string)($tx['type'] ?? ''), (int)($tx['transaction_id'] ?? 0));

    $pdo->prepare("DELETE FROM petty_cash_transactions WHERE id = ?")->execute([$id]);

    $pdo->commit();

    // Remove the bank register lines that were written on save.
    $regRef = 'PC-' . $id;
    $txType = (string)($tx['type'] ?? '');
    if ($txType === 'expense') {
        // We need the fund account to delete the right register row.
        // The fund_account_id was on the deleted row; fetch it from pct before delete already
        // happened above, so we need a snapshot. We stored it in $tx — re-query won't work
        // (deleted). Instead: reverseBankTransaction will delete by reference+type regardless of account,
        // so we pass account_id=0 sentinel and match by reference only via direct SQL.
        $pdo->prepare("DELETE FROM bank_transactions WHERE reference_number = ? AND transaction_type = 'withdrawal'")
            ->execute([$regRef]);
    } elseif ($txType === 'deposit') {
        $pdo->prepare("DELETE FROM bank_transactions WHERE reference_number IN (?, ?)")
            ->execute([$regRef . '-out', $regRef . '-in']);
    }

    // Remove the receipt file once the row is gone.
    if (!empty($tx['receipt_file'])) {
        $path = __DIR__ . '/../../uploads/finance/petty_cash/' . $tx['receipt_file'];
        if (is_file($path)) @unlink($path);
    }

    // Phase 3b — petty cash deletions are high-sensitivity financial events.
    logActivity($pdo, $_SESSION['user_id'], "Deleted Petty Cash Transaction", "Transaction ID: $id (ledger reversed)");

    echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
