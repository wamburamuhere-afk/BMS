<?php
// ajax/delete_account.php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    $account_id = $_POST['delete_id'] ?? $_POST['account_id'] ?? '';
    
    if (empty($account_id)) {
        throw new Exception('Account ID is required');
    }

    // Fetch account details for logging BEFORE deletion
    $accountStmt = $pdo->prepare("SELECT account_code, account_name FROM accounts WHERE account_id = ?");
    $accountStmt->execute([$account_id]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception('Account not found');
    }

    $account_display = $account['account_code'] . ' - ' . $account['account_name'];
    
    // Check if account has transactions
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM journal_entry_items WHERE account_id = ?
    ");
    $checkStmt->execute([$account_id]);
    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        throw new Exception('Cannot delete account with existing transactions. Please set it to inactive instead.');
    }
    
    // Check if account has sub-accounts
    $subAccountStmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM accounts WHERE parent_account_id = ?
    ");
    $subAccountStmt->execute([$account_id]);
    $subResult = $subAccountStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($subResult['count'] > 0) {
        throw new Exception('Cannot delete account with sub-accounts. Please delete or reassign sub-accounts first.');
    }
    
    // Delete the account
    $stmt = $pdo->prepare("DELETE FROM accounts WHERE account_id = ?");
    $stmt->execute([$account_id]);
    
    logActivity($pdo, $_SESSION['user_id'], "Deleted account: $account_display");
    
    echo json_encode([
        'success' => true,
        'message' => 'Account deleted successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
