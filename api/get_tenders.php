<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

global $pdo;

// Pagination & Filtering
$params = [];
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$where = " WHERE 1=1";

if (!empty($_GET['search'])) {
    $where .= " AND (t.tender_no LIKE ? OR t.procuring_entity_name LIKE ? OR c.customer_name LIKE ? OR t.tender_description LIKE ?)";
    $search = "%" . $_GET['search'] . "%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

if (!empty($_GET['status'])) {
    $where .= " AND t.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['category'])) {
    $where .= " AND t.tender_category = ?";
    $params[] = $_GET['category'];
}

if (!empty($_GET['date_from'])) {
    $where .= " AND t.submission_deadline >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where .= " AND t.submission_deadline <= ?";
    $params[] = $_GET['date_to'];
}

// Count total with filters
$count_query = "SELECT COUNT(*) FROM tenders t LEFT JOIN customers c ON t.customer_id = c.customer_id $where";
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();

// Fetch data
$query = "SELECT t.*, c.customer_name as entity_name 
          FROM tenders t 
          LEFT JOIN customers c ON t.customer_id = c.customer_id 
          $where 
          ORDER BY t.created_at DESC 
          LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tenders = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'data' => $tenders,
    'pagination' => [
        'total' => $total_records,
        'limit' => $limit,
        'page' => $page,
        'pages' => ceil($total_records / $limit)
    ]
]);
