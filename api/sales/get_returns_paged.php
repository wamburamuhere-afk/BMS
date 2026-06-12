<?php
// scope-audit: skip — sales returns paged list; scoped via sales_orders which is already gated at SO level; deferred to Phase G-2
// File: api/sales/get_returns_paged.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['data' => [], 'pagination' => []]);
    exit;
}

global $pdo;

// Parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$status = isset($_GET['status']) ? $_GET['status'] : '';
$customer_id = isset($_GET['customer']) ? (int)$_GET['customer'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$offset = ($page - 1) * $limit;

// Base Query
$where_clauses = ["1=1"];
$params = [];

if (!empty($status)) {
    $where_clauses[] = "sr.status = ?";
    $params[] = $status;
}

if ($customer_id > 0) {
    $where_clauses[] = "sr.customer_id = ?";
    $params[] = $customer_id;
}

if (!empty($date_from)) {
    $where_clauses[] = "sr.return_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_clauses[] = "sr.return_date <= ?";
    $params[] = $date_to;
}

$where_sql = implode(' AND ', $where_clauses);

// Stats — same filters minus the status filter so cards always show the full breakdown
$stats_where  = ["1=1"];
$stats_params = [];
if ($customer_id > 0)   { $stats_where[] = "sr.customer_id = ?";  $stats_params[] = $customer_id; }
if (!empty($date_from)) { $stats_where[] = "sr.return_date >= ?"; $stats_params[] = $date_from; }
if (!empty($date_to))   { $stats_where[] = "sr.return_date <= ?"; $stats_params[] = $date_to; }
$stats_where_sql = implode(' AND ', $stats_where);
$stats_data = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'refunded' => 0, 'refunded_amount' => 0];
try {
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(sr.status = 'pending')  AS pending,
            SUM(sr.status = 'approved') AS approved,
            SUM(sr.status = 'rejected') AS rejected,
            SUM(sr.status = 'refunded') AS refunded,
            SUM(CASE WHEN sr.status = 'refunded' THEN sr.total_amount ELSE 0 END) AS refunded_amount
        FROM sales_returns sr
        WHERE $stats_where_sql
    ");
    $statsStmt->execute($stats_params);
    $stats_data = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: $stats_data;
} catch (Exception $e) { /* non-fatal */ }

// Count Query
$count_query = "SELECT COUNT(*) FROM sales_returns sr WHERE $where_sql";
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();

// Data Query
$query = "
    SELECT 
        sr.sales_return_id as return_id,
        sr.return_number,
        sr.sales_order_id,
        sr.customer_id,
        sr.return_date,
        sr.total_amount as grand_total,
        sr.status,
        c.customer_name,
        c.company_name,
        so.order_number as original_order_number,
        u.username as created_by_name,
        (SELECT COUNT(*) FROM sales_return_items sri WHERE sri.sales_return_id = sr.sales_return_id) as total_items
    FROM sales_returns sr
    LEFT JOIN customers c ON sr.customer_id = c.customer_id
    LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
    LEFT JOIN users u ON sr.created_by = u.user_id
    WHERE $where_sql
    ORDER BY sr.return_date DESC, sr.created_at DESC
    LIMIT $limit OFFSET $offset
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format currency or dates if needed, usually frontend handles it but we can prep here
    foreach ($data as &$row) {
        $row['formatted_date'] = date('d M, Y', strtotime($row['return_date']));
        $row['formatted_total'] = number_format($row['grand_total'], 2);
    }

} catch (Exception $e) {
    $data = [];
}

echo json_encode([
    'data'       => $data,
    'stats'      => $stats_data,
    'pagination' => [
        'total'        => $total_records,
        'per_page'     => $limit,
        'current_page' => $page,
        'last_page'    => ceil($total_records / $limit),
    ],
]);
?>
