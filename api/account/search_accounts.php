<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

// Enforce authentication
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$searchTerm = isset($_GET['q']) ? trim($_GET['q']) : (isset($_POST['search']) ? trim($_POST['search']) : '');
$type = isset($_GET['type']) ? $_GET['type'] : (isset($_POST['type']) ? $_POST['type'] : '');

if (strlen($searchTerm) < 1) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    // Check if banks table exists
    $checkTableSql = "SHOW TABLES LIKE 'banks'";
    $checkTableStmt = $pdo->query($checkTableSql);
    $banksTableExists = $checkTableStmt->rowCount() > 0;

    $params = [
        ':search' => '%' . $searchTerm . '%',
        ':search2' => '%' . $searchTerm . '%'
    ];

    if ($type === 'bank_disbursement') {
        if ($banksTableExists) {
            $query = "SELECT a.account_id as id, a.account_name as text, a.current_balance as balance, a.account_code as account_number, 
                             COALESCE(b.bank_name, 'Bank') as bank_name
                      FROM accounts a 
                      LEFT JOIN banks b ON a.bank_id = b.bank_id
                      WHERE a.status = 'active' 
                      AND (a.account_name LIKE :search OR a.account_code LIKE :search2)
                      AND a.account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%Asset%' OR type_name LIKE '%Bank%' OR type_name LIKE '%Cash%')";
        } else {
            $query = "SELECT a.account_id as id, a.account_name as text, a.current_balance as balance, a.account_code as account_number, 
                             'Bank' as bank_name
                      FROM accounts a 
                      WHERE a.status = 'active' 
                      AND (a.account_name LIKE :search OR a.account_code LIKE :search2)
                      AND a.account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%Asset%' OR type_name LIKE '%Bank%' OR type_name LIKE '%Cash%')";
        }
    } else {
        $query = "SELECT account_id as id, account_name as text FROM accounts WHERE status = 'active' AND (account_name LIKE :search OR account_code LIKE :search2)";
        
        if ($type === 'expense') {
            $query .= " AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%Expense%')";
        } elseif ($type === 'bank') {
            $query .= " AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%Asset%' OR type_name LIKE '%Bank%' OR type_name LIKE '%Cash%')";
        }
    }
    
    // Always append sorting and limit
    // We sort by 'text' (which is mapped to account_name) to be consistent
    $query .= " ORDER BY text ASC LIMIT 20";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['results' => $results]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
