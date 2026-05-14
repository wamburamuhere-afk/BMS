<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../helpers.php';
global $pdo;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get parameters from DataTables
$draw = $_GET['draw'] ?? 1;
$start = $_GET['start'] ?? 0;
$length = $_GET['length'] ?? 10;
$searchValue = $_GET['search']['value'] ?? '';

// Custom filters
$expense_account_id = $_GET['expense_account_id'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Get order parameters
$orderColumnIndex = $_GET['order'][0]['column'] ?? 0;
$orderDirection = $_GET['order'][0]['dir'] ?? 'desc';

// Define column mapping
// Define column mapping
$enable_projects = get_setting('enable_projects');
$columns = [
    'e.expense_date',
    'e.description',
    'ea.account_name'
];

if ($enable_projects == '1') {
    $columns[] = 'p.project_name';
}

$columns = array_merge($columns, [
    'e.amount',
    'e.status',
    'u.username',
    ''
]);

// Base query
$selectProjects = ($enable_projects == '1') ? ", p.project_name" : "";
$joinProjects = ($enable_projects == '1') ? "LEFT JOIN projects p ON e.project_id = p.project_id" : "";

$query = "SELECT 
          e.*, 
          ea.account_name as expense_account_name, 
          ba.account_name as bank_account_name,
          u.username as created_by_name,
          et.name as type_name,
          CASE 
            WHEN e.paid_to_type = 'supplier' THEN (SELECT supplier_name FROM suppliers WHERE supplier_id = e.paid_to_id)
            WHEN e.paid_to_type = 'staff' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM employees WHERE employee_id = e.paid_to_id)
            ELSE e.vendor 
          END as paid_to_name
          $selectProjects
          FROM expenses e
          LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id
          LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id
          LEFT JOIN users u ON e.created_by = u.user_id
          LEFT JOIN expense_types et ON e.type_id = et.id
          $joinProjects
          WHERE 1=1";

$countQuery = "SELECT COUNT(*) FROM expenses e 
               LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id
               LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id
               $joinProjects
               WHERE 1=1";

$params = [];

// Apply filters
if (!empty($expense_account_id)) {
    $query .= " AND e.expense_account_id = :expense_account_id";
    $countQuery .= " AND e.expense_account_id = :expense_account_id";
    $params[':expense_account_id'] = $expense_account_id;
}

if (!empty($status)) {
    $query .= " AND e.status = :status";
    $countQuery .= " AND e.status = :status";
    $params[':status'] = $status;
}

if (!empty($date_from)) {
    $query .= " AND e.expense_date >= :date_from";
    $countQuery .= " AND e.expense_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND e.expense_date <= :date_to";
    $countQuery .= " AND e.expense_date <= :date_to";
    $params[':date_to'] = $date_to;
}

// Add search filter if specified
if (!empty($searchValue)) {
    $searchCond = " AND (e.description LIKE :search1 OR 
                    e.reference_number LIKE :search2 OR 
                    ea.account_name LIKE :search3 OR
                    ba.account_name LIKE :search4 OR
                    e.amount LIKE :search5";
    
    if ($enable_projects == '1') {
        $searchCond .= " OR p.project_name LIKE :search6";
    }
    
    // Search in paid_to_name is tricky with subqueries in MySQL for some versions, 
    // but we can search in vendor column which often contains the name too
    $searchCond .= " OR e.vendor LIKE :search7";
    
    $searchCond .= ")";

    $query .= $searchCond;
    $countQuery .= $searchCond; 
    
    $params[':search1'] = "%$searchValue%";
    $params[':search2'] = "%$searchValue%";
    $params[':search3'] = "%$searchValue%";
    $params[':search4'] = "%$searchValue%";
    $params[':search5'] = "%$searchValue%";
    if ($enable_projects == '1') {
        $params[':search6'] = "%$searchValue%";
    }
    $params[':search7'] = "%$searchValue%";
}

// Get total filtered records
$countParams = $params;
$countStmt = $pdo->prepare($countQuery);
foreach ($countParams as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalFiltered = $countStmt->fetchColumn();
$countStmt->closeCursor();

// Add sorting
if (isset($columns[$orderColumnIndex]) && !empty($columns[$orderColumnIndex])) {
    $orderBy = $columns[$orderColumnIndex];
    $query .= " ORDER BY $orderBy $orderDirection";
} else {
    $query .= " ORDER BY e.expense_date DESC, e.created_at DESC";
}

// Add pagination
$query .= " LIMIT :start, :length";
$params[':start'] = (int)$start;
$params[':length'] = (int)$length;

// Prepare and execute main query
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    if ($key === ':start' || $key === ':length') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

// Fetch categories for the current set of expenses (Many-to-Many)
if (!empty($expenses)) {
    $expenseIds = array_column($expenses, 'expense_id');
    $placeholders = implode(',', array_fill(0, count($expenseIds), '?'));
    
    $catStmt = $pdo->prepare("
        SELECT ecm.expense_id, ec.id as category_id, ec.name as category_name 
        FROM expense_category_map ecm
        JOIN expense_categories ec ON ecm.category_id = ec.id
        WHERE ecm.expense_id IN ($placeholders)
    ");
    $catStmt->execute($expenseIds);
    $allCategories = $catStmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
    
    foreach ($expenses as &$exp) {
        $exp['categories'] = $allCategories[$exp['expense_id']] ?? [];
    }
}

// Get total records without filters
$totalRecords = $pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn();

// Get Stats
$statsQuery = "SELECT 
               SUM(amount) as total_expenses,
               SUM(CASE WHEN YEAR(expense_date) = YEAR(CURRENT_DATE) AND MONTH(expense_date) = MONTH(CURRENT_DATE) THEN amount ELSE 0 END) as month_total,
               SUM(CASE WHEN YEAR(expense_date) = YEAR(CURRENT_DATE) THEN amount ELSE 0 END) as year_total
               FROM expenses";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Prepare response
$response = [
    'draw' => (int)$draw,
    'recordsTotal' => (int)$totalRecords,
    'recordsFiltered' => (int)$totalFiltered,
    'data' => $expenses,
    'totalExpenses' => (float)($stats['total_expenses'] ?? 0),
    'monthTotal' => (float)($stats['month_total'] ?? 0),
    'yearTotal' => (float)($stats['year_total'] ?? 0)
];

echo json_encode($response);
?>
