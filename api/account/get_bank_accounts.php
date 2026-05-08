<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

// Check authentication
if (!isAuthenticated()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
try {
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $params = [];

    // Check if banks table exists
    $checkTableSql = "SHOW TABLES LIKE 'banks'";
    $checkTableStmt = $pdo->query($checkTableSql);
    $banksTableExists = $checkTableStmt->rowCount() > 0;

    if ($banksTableExists) {
        // Query with banks table join
        $sql = "SELECT 
                    a.account_id,
                    a.account_code,
                    a.account_name,
                    a.account_code as account_number,
                    b.bank_name,
                    b.bank_code,
                    a.current_balance as balance,
                    COALESCE(a.currency, 'TZS') as currency,
                    a.status
                FROM accounts a
                LEFT JOIN banks b ON a.bank_id = b.bank_id
                WHERE a.account_type_id IN (
                    SELECT type_id FROM account_types WHERE type_name IN ('bank', 'current_assets')
                )
                AND a.status = 'active'";
        
        if (!empty($search)) {
            $sql .= " AND (a.account_name LIKE ? OR a.account_code LIKE ? OR b.bank_name LIKE ?)";
            $params = ["%$search%", "%$search%", "%$search%"];
        }
        
        $sql .= " ORDER BY b.bank_name, a.account_name ASC";
    } else {
        // Fallback to basic query without banks table
        $sql = "SELECT 
                    a.account_id,
                    a.account_code,
                    a.account_name,
                    a.account_code as account_number,
                    CASE 
                        WHEN a.account_name LIKE '%CRDB%' THEN 'CRDB Bank'
                        WHEN a.account_name LIKE '%NMB%' THEN 'NMB Bank'
                        WHEN a.account_name LIKE '%NBC%' THEN 'NBC Bank'
                        WHEN a.account_name LIKE '%TIB%' THEN 'TIB Bank'
                        ELSE 'Bank Account'
                    END as bank_name,
                    a.current_balance as balance,
                    'TZS' as currency,
                    a.status
                FROM accounts a
                WHERE a.account_type_id IN (
                    SELECT type_id FROM account_types WHERE type_name IN ('bank', 'current_assets')
                )
                AND a.status = 'active'";
        
        if (!empty($search)) {
            $sql .= " AND (a.account_name LIKE ? OR a.account_code LIKE ?)";
            $params = ["%$search%", "%$search%"];
        }
        
        $sql .= " ORDER BY a.account_name ASC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
