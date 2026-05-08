<?php
// api/get_chart_of_accounts.php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

// Enable CORS if needed
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

try {
    // Get the request parameters for DataTables
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $searchValue = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
    $orderColumn = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 0;
    $orderDirection = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'ASC';
    $categoryId = isset($_GET['category_id']) ? $_GET['category_id'] : '';
    $accountType = isset($_GET['account_type']) ? $_GET['account_type'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';

    // Column mapping for ordering
    $columns = [
        0 => 'a.account_code',
        1 => 'a.account_name',
        2 => 'at.type_name',
        3 => 'c.category_name',
        4 => 'a.current_balance',
        5 => 'a.status'
    ];

    $orderBy = $columns[$orderColumn] . ' ' . $orderDirection;

    // Build the base query
    $baseQuery = "
        FROM accounts a
        LEFT JOIN account_categories c ON a.category_id = c.category_id
        LEFT JOIN accounts pa ON a.parent_account_id = pa.account_id
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        WHERE 1=1
    ";

    // Apply category filter
    if (!empty($categoryId)) {
        $baseQuery .= " AND a.category_id = :category_id";
    }

    // Apply account type filter
    if (!empty($accountType)) {
        $baseQuery .= " AND at.type_name = :account_type";
    }

    // Apply status filter
    if (!empty($status)) {
        $baseQuery .= " AND a.status = :status";
    }

    // Apply search filter
    if (!empty($searchValue)) {
        $baseQuery .= " AND (
            a.account_code LIKE :search OR
            a.account_name LIKE :search OR
            at.type_name LIKE :search OR
            c.category_name LIKE :search OR
            a.description LIKE :search
        )";
    }

    // Count total records
    $countQuery = "SELECT COUNT(*) as total_count " . $baseQuery;
    $stmt = $pdo->prepare($countQuery);
    
    if (!empty($categoryId)) {
        $stmt->bindValue(':category_id', $categoryId);
    }
    
    if (!empty($accountType)) {
        $stmt->bindValue(':account_type', $accountType);
    }
    
    if (!empty($status)) {
        $stmt->bindValue(':status', $status);
    }
    
    if (!empty($searchValue)) {
        $searchParam = "%$searchValue%";
        $stmt->bindValue(':search', $searchParam);
    }
    
    $stmt->execute();
    $totalRecords = $stmt->fetch(PDO::FETCH_ASSOC)['total_count'];

    // Count filtered records
    $filteredQuery = "SELECT COUNT(*) as filtered_count " . $baseQuery;
    $stmt = $pdo->prepare($filteredQuery);
    
    if (!empty($categoryId)) {
        $stmt->bindValue(':category_id', $categoryId);
    }
    
    if (!empty($accountType)) {
        $stmt->bindValue(':account_type', $accountType);
    }
    
    if (!empty($status)) {
        $stmt->bindValue(':status', $status);
    }
    
    if (!empty($searchValue)) {
        $searchParam = "%$searchValue%";
        $stmt->bindValue(':search', $searchParam);
    }
    
    $stmt->execute();
    $filteredRecords = $stmt->fetch(PDO::FETCH_ASSOC)['filtered_count'];

    // Get the actual data with pagination
    $dataQuery = "
        SELECT 
            a.account_id,
            a.account_code,
            a.account_name,
            at.type_name as account_type,
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
        " . $baseQuery . "
        ORDER BY " . $orderBy . "
        LIMIT :start, :length
    ";

    $stmt = $pdo->prepare($dataQuery);
    
    if (!empty($categoryId)) {
        $stmt->bindValue(':category_id', $categoryId);
    }
    
    if (!empty($accountType)) {
        $stmt->bindValue(':account_type', $accountType);
    }
    
    if (!empty($status)) {
        $stmt->bindValue(':status', $status);
    }
    
    if (!empty($searchValue)) {
        $searchParam = "%$searchValue%";
        $stmt->bindValue(':search', $searchParam);
    }
    
    $stmt->bindValue(':start', $start, PDO::PARAM_INT);
    $stmt->bindValue(':length', $length, PDO::PARAM_INT);
    $stmt->execute();
    
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare the response
    $response = [
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data,
        'success' => true
    ];

    echo json_encode($response);

} catch (Exception $e) {
    // Handle errors
    $response = [
        'draw' => isset($draw) ? $draw : 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'success' => false,
        'message' => 'Error fetching accounts: ' . $e->getMessage()
    ];
    
    echo json_encode($response);
}
?>
