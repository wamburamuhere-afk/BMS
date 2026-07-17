<?php
ob_start();
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/warehouse_scope.php';

// Ensure no output before JSON
if (ob_get_length()) ob_clean(); 
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); 
ini_set('log_errors', 1);

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permissions (View GRN)
if (!canView('grn')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to view GRN data.']);
    exit;
}

try {
    global $pdo;

    // Get filter parameters
    $status_filter = $_GET['status'] ?? '';
    $supplier_filter = intval($_GET['supplier'] ?? 0);
    $warehouse_filter = intval($_GET['warehouse'] ?? 0);
    $po_filter = intval($_GET['po'] ?? 0);
    $project_filter = intval($_GET['project'] ?? 0);
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';

    if ($warehouse_filter > 0 && !userCan('warehouse', $warehouse_filter)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this warehouse is not in your assigned scope.']);
        exit;
    }

    // Get DataTables parameters
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search_value = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';

    // Base query conditions
    $where_conditions = ["1=1"];
    $params = [];

    // Apply filters
    if (!empty($status_filter)) {
        $where_conditions[] = "pr.status = ?";
        $params[] = $status_filter;
    }

    if ($supplier_filter > 0) {
        $where_conditions[] = "pr.supplier_id = ?";
        $params[] = $supplier_filter;
    }

    if ($warehouse_filter > 0) {
        $where_conditions[] = "pr.warehouse_id = ?";
        $params[] = $warehouse_filter;
    }

    if ($po_filter > 0) {
        $where_conditions[] = "pr.purchase_order_id = ?";
        $params[] = $po_filter;
    }

    if ($project_filter > 0) {
        $where_conditions[] = "po.project_id = ?";
        $params[] = $project_filter;
    }

    if (!empty($date_from)) {
        $where_conditions[] = "pr.receipt_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $where_conditions[] = "pr.receipt_date <= ?";
        $params[] = $date_to;
    }

    if (!empty($search_value)) {
        $where_conditions[] = "(pr.receipt_number LIKE ? OR s.supplier_name LIKE ? OR po.order_number LIKE ? OR pr.delivery_note LIKE ?)";
        $search_term = "%$search_value%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    $where_sql = implode(" AND ", $where_conditions) . scopeFilterSqlNullable('project', 'po') . scopeFilterSqlNullable('warehouse', 'pr');

    // 1. Get Total Count (without filters)
    $stmt = $pdo->query("SELECT COUNT(*) FROM purchase_receipts");
    $recordsTotal = $stmt->fetchColumn();

    // 2. Get Filtered Count and Stats (with filters)
    // We do one query to get stats sum and count
    $stats_query = "
        SELECT 
            COUNT(*) as count,
            COALESCE(SUM(total_stats.grn_value), 0) as total_value,
            SUM(CASE WHEN pr.status = 'draft' THEN 1 ELSE 0 END) as draft_count,
            SUM(CASE WHEN pr.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN pr.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN pr.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
        FROM purchase_receipts pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        -- Calculate total value subquery to avoid massive joins for stats if possible, 
        -- but here for simplicity we might need to join receipt_items or use a subquery for value
        LEFT JOIN (
            SELECT receipt_id, SUM(quantity_received * unit_price) as grn_value
            FROM receipt_items
            GROUP BY receipt_id
        ) total_stats ON pr.receipt_id = total_stats.receipt_id
        WHERE $where_sql
    ";
    
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute($params);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $recordsFiltered = $stats_result['count'];

    // 3. Get Data (with Limit/Offset)
    $query = "
        SELECT 
            pr.*,
            s.supplier_name,
            s.company_name,
            w.warehouse_name,
            po.order_number,
            proj.project_name,
            u1.username as received_by_name,
            u2.username as created_by_name,
            COUNT(ri.receipt_item_id) as total_items,
            COALESCE(SUM(ri.quantity_received * ri.unit_price), 0) as total_value,
            GROUP_CONCAT(DISTINCT p.product_name SEPARATOR ', ') as product_names
        FROM purchase_receipts pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        LEFT JOIN projects proj ON po.project_id = proj.project_id
        LEFT JOIN warehouses w ON pr.warehouse_id = w.warehouse_id
        LEFT JOIN receipt_items ri ON pr.receipt_id = ri.receipt_id
        LEFT JOIN products p ON ri.product_id = p.product_id
        LEFT JOIN users u1 ON pr.received_by = u1.user_id
        LEFT JOIN users u2 ON pr.created_by = u2.user_id
        WHERE $where_sql
        GROUP BY pr.receipt_id 
        ORDER BY pr.receipt_date DESC, pr.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($query);
    
    // Bind positionals 1-based
    $param_index = 1;
    foreach ($params as $v) {
        $stmt->bindValue($param_index++, $v);
    }
    

    // Bind limit/offset
    $stmt->bindValue($param_index++, (int)$length, PDO::PARAM_INT);
    $stmt->bindValue($param_index++, (int)$start, PDO::PARAM_INT);
    
    // Debug SQL and Params
    file_put_contents('debug_grn_log.txt', date('Y-m-d H:i:s') . " - SQL: " . $query . "\nParams: " . json_encode($params) . "\n", FILE_APPEND);

    $stmt->execute();
    $grns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug Results
    file_put_contents('debug_grn_log.txt', date('Y-m-d H:i:s') . " - Count: " . count($grns) . "\n", FILE_APPEND);
    if (count($grns) > 0) {
        file_put_contents('debug_grn_log.txt', "First Row: " . json_encode($grns[0]) . "\n", FILE_APPEND);
    }

    echo json_encode([
        'success' => true,
        'draw' => $draw,
        'recordsTotal' => intval($recordsTotal),
        'recordsFiltered' => intval($recordsFiltered),
        'data' => $grns,
        'stats' => [
            'total_grns' => intval($stats_result['count']),
            'total_value' => floatval($stats_result['total_value']),
            'draft_count' => intval($stats_result['draft_count']),
            'pending_count' => intval($stats_result['pending_count']),
            'completed_count' => intval($stats_result['completed_count']),
            'cancelled_count' => intval($stats_result['cancelled_count'])
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching GRNs: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
