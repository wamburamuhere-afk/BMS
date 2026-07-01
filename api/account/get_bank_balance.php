<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/account_balance.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $bank_account_id = (int)($_GET['bank_account_id'] ?? 0);
    $as_of           = trim($_GET['as_of'] ?? '');

    if ($bank_account_id <= 0) {
        throw new Exception('Bank Account ID is required');
    }

    $stmt = $pdo->prepare("SELECT account_name, account_code FROM accounts WHERE account_id = ?");
    $stmt->execute([$bank_account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception('Account not found');
    }

    // Return ledger-true balance. When an as_of date is supplied (reconciliation
    // period_end), return the period-bounded figure; otherwise return whole-history.
    $book_balance = ($as_of !== '')
        ? accountLedgerBalanceAsOf($pdo, $bank_account_id, $as_of)
        : accountLedgerBalance($pdo, $bank_account_id);

    echo json_encode([
        'success'      => true,
        'book_balance' => $book_balance,
        'balance'      => $book_balance,   // legacy alias kept for any other callers
        'account_name' => $account['account_name'],
        'account_code' => $account['account_code'],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
