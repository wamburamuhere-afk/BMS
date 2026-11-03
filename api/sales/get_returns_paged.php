<?php
// scope-audit: skip — filters via scopeFilterSqlNullable('warehouse', 'w') below (see $scope_join/$scope_filter)
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

// DataTables server-side mode (app/bms/sales/sales_returns/sales_returns.php):
// sent `draw`, answers {draw, recordsTotal, recordsFiltered, data}. Sorting is a
// whitelist by column index; column 0 (S/No) is the default newest-first order.
$dt_mode = isset($_GET['draw']);
$dt_search = '';
$order_sql = "sr.return_date DESC, sr.created_at DESC";
$sn_desc = false;
if ($dt_mode) {
    $limit  = max(1, min(500, (int)($_GET['length'] ?? 25)));
    $offset = max(0, (int)($_GET['start'] ?? 0));
    $dt_search = trim((string)($_GET['search']['value'] ?? ''));
    $col = (int)($_GET['order'][0]['column'] ?? 0);
    $dir = strtolower((string)($_GET['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $sortable = [1 => 'sr.return_number', 2 => 'sr.return_date', 3 => 'so.order_number', 4 => 'c.customer_name',
                 5 => 'total_items', 6 => 'sr.total_amount', 7 => 'sr.status'];
    if (isset($_GET['order'][0]['column']) && isset($sortable[$col])) {
        $order_sql = $sortable[$col] . " $dir, sr.sales_return_id $dir";
    } elseif ($col === 0 && $dir === 'DESC') {
        $order_sql = "sr.return_date ASC, sr.created_at ASC";   // S/No descending = oldest first
        $sn_desc = true;
    }
}

// Phase 6 (pos_upgrade_plan.md) parity with sales_return_view.php: a sales
// return carries no project/warehouse_id of its own, so its warehouse is
// resolved through the source invoice (or, if not yet invoiced, the sales
// order) — the same COALESCE used in sales_return_view.php's single-record
// gate. Joined here so the list, stats and count all agree with what a
// non-admin is actually allowed to open.
$scope_join = "
    LEFT JOIN sales_orders so_scope ON sr.sales_order_id = so_scope.sales_order_id
    LEFT JOIN invoices inv_scope ON sr.invoice_id = inv_scope.invoice_id
    LEFT JOIN warehouses w_scope ON w_scope.warehouse_id = COALESCE(inv_scope.warehouse_id, so_scope.warehouse_id)
";
$scope_filter = scopeFilterSqlNullable('warehouse', 'w_scope');

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

$where_sql = implode(' AND ', $where_clauses) . $scope_filter;
$total_where_sql = $where_sql;   // filters only — DataTables' recordsTotal
$total_params = $params;

if ($dt_search !== '') {
    $where_sql .= " AND (sr.return_number LIKE ? OR c.customer_name LIKE ? OR c.company_name LIKE ? OR so.order_number LIKE ?)";
    $like = '%' . $dt_search . '%';
    array_push($params, $like, $like, $like, $like);
}

// Stats — same filters minus the status filter so cards always show the full breakdown
$stats_where  = ["1=1"];
$stats_params = [];
if ($customer_id > 0)   { $stats_where[] = "sr.customer_id = ?";  $stats_params[] = $customer_id; }
if (!empty($date_from)) { $stats_where[] = "sr.return_date >= ?"; $stats_params[] = $date_from; }
if (!empty($date_to))   { $stats_where[] = "sr.return_date <= ?"; $stats_params[] = $date_to; }
$stats_where_sql = implode(' AND ', $stats_where) . $scope_filter;
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
        $scope_join
        WHERE $stats_where_sql
    ");
    $statsStmt->execute($stats_params);
    $stats_data = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: $stats_data;
} catch (Exception $e) { /* non-fatal */ }

// Count Query (customers / sales_orders joined so the search columns resolve)
$count_join = "LEFT JOIN customers c ON sr.customer_id = c.customer_id
    LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id";
$count_query = "SELECT COUNT(*) FROM sales_returns sr $count_join $scope_join WHERE $where_sql";
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
    $scope_join
    WHERE $where_sql
    ORDER BY $order_sql
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
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
    unset($row);

} catch (Exception $e) {
    $data = [];
}

if ($dt_mode) {
    $totStmt = $pdo->prepare("SELECT COUNT(*) FROM sales_returns sr $scope_join WHERE $total_where_sql");
    $totStmt->execute($total_params);
    foreach ($data as $i => &$row) {
        $row['sn'] = $sn_desc ? ((int)$total_records - $offset - $i) : ($offset + $i + 1);
    }
    unset($row);
    echo json_encode([
        'draw'            => (int)$_GET['draw'],
        'recordsTotal'    => (int)$totStmt->fetchColumn(),
        'recordsFiltered' => (int)$total_records,
        'data'            => $data,
        'stats'           => $stats_data,
    ]);
    exit;
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
