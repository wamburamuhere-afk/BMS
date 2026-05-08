<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    $bank_account_id = $_GET['bank_account_id'] ?? '';
    
    if (empty($bank_account_id)) {
        throw new Exception('Bank Account ID is required');
    }

    $stmt = $pdo->prepare("SELECT current_balance, account_name, account_code FROM accounts WHERE account_id = ?");
    $stmt->execute([$bank_account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception('Account not found');
    }

    echo json_encode([
        'success' => true,
        'balance' => $account['current_balance'],
        'account_name' => $account['account_name'],
        'account_code' => $account['account_code']
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
