<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/account_balance.php';

header('Content-Type: application/json');

// Check authentication
if (!isAuthenticated()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
try {
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $params = [];
    $banksTableExists = false;

    // Bank/cash accounts = the single "bank nature" marker (asset + cash_flow='cash'),
    // the SAME test the payment "Paid From" list uses. (The previous query joined a
    // non-existent accounts.bank_id column and matched all current assets — fixed.)
    $sql = "SELECT
                a.account_id,
                a.account_code,
                a.account_name,
                a.account_code as account_number,
                a.account_name as bank_name,
                a.current_balance as balance,
                COALESCE(a.currency, 'TZS') as currency,
                a.status
            FROM accounts a
            WHERE a.status = 'active'
              AND a.account_type = 'asset'
              AND a.cash_flow_category = 'cash'";

    if (!empty($search)) {
        $sql .= " AND (a.account_name LIKE ? OR a.account_code LIKE ?)";
        $params = ["%$search%", "%$search%"];
    }

    $sql .= " ORDER BY a.account_code, a.account_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Drift-proof: show the ledger-true balance (opening + posted movements),
    // not the cached current_balance — so it always reflects every transaction.
    $ledger = ledgerBalanceMap($pdo);
    foreach ($accounts as &$acct) {
        $acct['balance'] = $ledger[(int)$acct['account_id']] ?? $acct['balance'];
    }
    unset($acct);

    // Get distinct banks for filter
    $banks = [];
    foreach ($accounts as $account) {
        if (!in_array($account['bank_name'], $banks)) {
            $banks[] = $account['bank_name'];
        }
    }

    // Return JSON response
    echo json_encode([
        'success' => true,
        'accounts' => $accounts,
        'banks' => $banks,
        'count' => count($accounts),
        'banks_table_exists' => $banksTableExists
    ]);

} catch (PDOException $e) {
    // Log the error
    error_log("Database error in get_bank_accounts.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error in get_bank_accounts.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}
?>
