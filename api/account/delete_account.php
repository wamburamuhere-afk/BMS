<?php
// ajax/delete_account.php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized access');
    }

    // Deleting accounts is ADMIN-ONLY (regardless of the chart_of_accounts delete permission).
    if (!isAdmin()) {
        throw new Exception('Access Denied: only an administrator can delete accounts');
    }

    $account_id = $_POST['delete_id'] ?? $_POST['account_id'] ?? '';
    
    if (empty($account_id)) {
        throw new Exception('Account ID is required');
    }

    // Fetch account details for logging BEFORE deletion
    $accountStmt = $pdo->prepare("SELECT account_code, account_name, is_system FROM accounts WHERE account_id = ?");
    $accountStmt->execute([$account_id]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception('Account not found');
    }

    $account_display = $account['account_code'] . ' - ' . $account['account_name'];

    // ── SAFEGUARD: never delete an account that is WIRED INTO THE SYSTEM ──────
    // i.e. configured as a default in system_settings (petty cash, AP, WHT, VAT,
    // payroll, SDL, …) or used by auto-posting in journal_mappings. Deleting one
    // silently breaks those features (e.g. "no WHT Receivable account configured").
    // Blocked for everyone, including admins — un-wire it in Settings first.
    $wiredInto = [];
    $ssStmt = $pdo->prepare("SELECT setting_key FROM system_settings WHERE setting_key REGEXP '_account_id$' AND setting_value = ?");
    $ssStmt->execute([(string)$account_id]);
    foreach ($ssStmt->fetchAll(PDO::FETCH_COLUMN) as $k) {
        $wiredInto[] = 'Settings → ' . $k;
    }
    if ($pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch()) {
        $jmStmt = $pdo->prepare("SELECT event_type FROM journal_mappings WHERE debit_account_id = ? OR credit_account_id = ?");
        $jmStmt->execute([$account_id, $account_id]);
        foreach ($jmStmt->fetchAll(PDO::FETCH_COLUMN) as $e) {
            $wiredInto[] = 'Auto-posting → ' . $e;
        }
    }
    if (!empty($wiredInto)) {
        throw new Exception(
            "Cannot delete \"$account_display\" — it is wired into the system ("
            . implode('; ', $wiredInto)
            . "). Re-point or clear those configurations first, then delete it."
        );
    }
    
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
    
    // Delete the account (not wired into the system, no transactions, no
    // sub-accounts — safe to remove).
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
