<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';

// Ensure no output before JSON
ob_clean(); 
header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;

    // Get filter parameters
    $status_filter = $_GET['status'] ?? '';
    $category_filter = intval($_GET['category'] ?? 0);
    $country_filter = $_GET['country'] ?? '';
    $city_filter = $_GET['city'] ?? '';

    // Get DataTables parameters
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search_value = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';

    // Base query conditions
    $where_conditions = ["c.status != 'deleted'"];
    $params = [];

    // Apply filters
    if (!empty($status_filter)) {
        $where_conditions[] = "c.status = ?";
        $params[] = $status_filter;
    }

    if ($category_filter > 0) {
        $where_conditions[] = "c.category_id = ?";
        $params[] = $category_filter;
    }

    if (!empty($country_filter)) {
        $where_conditions[] = "c.country LIKE ?";
        $params[] = "%$country_filter%";
    }

    if (!empty($city_filter)) {
        $where_conditions[] = "c.city LIKE ?";
        $params[] = "%$city_filter%";
    }

    if (!empty($search_value)) {
        $where_conditions[] = "(c.customer_name LIKE ? OR c.company_name LIKE ? OR c.customer_code LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.contact_person LIKE ?)";
        $search_term = "%$search_value%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    $scope_sql = scopeFilterSqlNullable('project', 'c');
    $where_sql = implode(" AND ", $where_conditions) . $scope_sql;

    // 1. Get Total Count (total records in table context)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE c.status != 'deleted'" . $scope_sql);
    $stmt->execute();
    $recordsTotal = $stmt->fetchColumn();

    // 2. Get Filtered Count and Stats
    $stats_query = "
        SELECT 
            COUNT(*) as count,
            SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN c.status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
            SUM(CASE WHEN c.status = 'suspended' THEN 1 ELSE 0 END) as suspended_count,
            SUM(CASE WHEN c.status = 'blacklisted' THEN 1 ELSE 0 END) as blacklisted_count
        FROM customers c
        WHERE $where_sql
    ";
    
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute($params);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $recordsFiltered = $stats_result['count'] ?? 0;

    // 3. Get Data (with Limit/Offset)
    $query = "
        SELECT 
            c.*,
            cc.category_name,
            COALESCE(ord_stats.total_orders, 0) as total_orders,
            COALESCE(ord_stats.pending_orders, 0) as pending_orders,
            COALESCE(ord_stats.completed_orders, 0) as completed_orders,
            COALESCE(inv_stats.total_invoices, 0) as total_invoices,
            COALESCE(inv_stats.total_paid, 0) as total_paid,
            COALESCE(inv_stats.total_unpaid, 0) as total_unpaid
        FROM customers c
        LEFT JOIN customer_categories cc ON c.category_id = cc.category_id
        LEFT JOIN (
            SELECT 
                customer_id, 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders
            FROM sales_orders
            GROUP BY customer_id
        ) ord_stats ON c.customer_id = ord_stats.customer_id
        LEFT JOIN (
            SELECT 
                customer_id, 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = 'paid' THEN grand_total ELSE 0 END) as total_paid,
                SUM(CASE WHEN status != 'paid' AND status != 'cancelled' THEN grand_total ELSE 0 END) as total_unpaid
            FROM invoices
            GROUP BY customer_id
        ) inv_stats ON c.customer_id = inv_stats.customer_id
        WHERE $where_sql
        ORDER BY c.customer_name ASC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($query);
    
    $param_index = 1;
    foreach ($params as $v) {
        $stmt->bindValue($param_index++, $v);
    }
    
    $stmt->bindValue($param_index++, (int)$length, PDO::PARAM_INT);
    $stmt->bindValue($param_index++, (int)$start, PDO::PARAM_INT);
    
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'draw' => $draw,
        'recordsTotal' => intval($recordsTotal),
        'recordsFiltered' => intval($recordsFiltered),
        'data' => $customers,
        'stats' => [
            'total' => intval($recordsTotal),
            'filtered' => intval($recordsFiltered),
            'active' => intval($stats_result['active_count'] ?? 0),
            'inactive' => intval($stats_result['inactive_count'] ?? 0),
            'suspended' => intval($stats_result['suspended_count'] ?? 0),
            'blacklisted' => intval($stats_result['blacklisted_count'] ?? 0)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
