<?php
// ajax/get_accounts.php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;
header('Content-Type: application/json');

try {
    $accountsQuery = "
        SELECT 
            a.account_id,
            a.account_code,
            a.account_name,
            at.type_name as account_type,
            at.display_name as account_type_display,
            a.category_id,
            c.category_name,
            a.description,
            a.opening_balance,
            a.current_balance,
            a.parent_account_id,
            pa.account_name as parent_account_name,
            a.status,
            a.created_at,
            a.updated_at
        FROM accounts a
        LEFT JOIN account_categories c ON a.category_id = c.category_id
        LEFT JOIN accounts pa ON a.parent_account_id = pa.account_id
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        ORDER BY at.type_name, a.account_name
    ";
    
    $stmt = $pdo->query($accountsQuery);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $accounts
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading accounts: ' . $e->getMessage()
    ]);
}
?>
