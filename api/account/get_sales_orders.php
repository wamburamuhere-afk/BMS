<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permissions
if (!canView('sales_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

try {
    global $pdo;

    // Get filter parameters
    $status_filter = $_GET['status'] ?? '';
    $customer_filter = intval($_GET['customer'] ?? 0);
    $salesperson_filter = intval($_GET['salesperson'] ?? 0);
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';

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
        if ($status_filter === 'partially_delivered') {
            $where_conditions[] = "so.total_delivered > 0 AND so.total_delivered < so.total_ordered";
        } else {
            $where_conditions[] = "so.status = ?";
            $params[] = $status_filter;
        }
    }

    if ($customer_filter > 0) {
        $where_conditions[] = "so.customer_id = ?";
        $params[] = $customer_filter;
    }

    if ($salesperson_filter > 0) {
        $where_conditions[] = "so.salesperson_id = ?";
        $params[] = $salesperson_filter;
    }

    if (!empty($date_from)) {
        $where_conditions[] = "so.order_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $where_conditions[] = "so.order_date <= ?";
        $params[] = $date_to;
    }

    if (!empty($search_value)) {
        $where_conditions[] = "(so.order_number LIKE ? OR so.reference LIKE ? OR c.customer_name LIKE ?)";
        $search_term = "%$search_value%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    $where_sql = implode(" AND ", $where_conditions);

    // 1. Get Total Count (without filters)
    $stmt = $pdo->query("SELECT COUNT(*) FROM sales_orders");
    $recordsTotal = $stmt->fetchColumn();

    // 2. Get Filtered Count and Stats (with filters)
    // We do one query to get stats sum and count
    $stats_query = "
        SELECT 
            COUNT(*) as count,
            COALESCE(SUM(so.grand_total), 0) as total_value,
            SUM(CASE WHEN so.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN so.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN so.status = 'processing' THEN 1 ELSE 0 END) as processing_count,
            SUM(CASE WHEN so.status = 'draft' THEN 1 ELSE 0 END) as draft_count,
            SUM(CASE WHEN so.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
            SUM(CASE WHEN so.total_delivered > 0 AND so.total_delivered < so.total_ordered THEN 1 ELSE 0 END) as partially_delivered_count
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        WHERE $where_sql
    ";
    
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute($params);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $recordsFiltered = $stats_result['count'];

    // 3. Get Data (with Limit/Offset)
    $query = "
        SELECT 
            so.sales_order_id, so.order_number, so.customer_id, so.order_date, so.delivery_date, 
            so.status, so.grand_total, so.tax_amount, so.discount_amount, so.shipping_cost,
            so.currency, so.payment_terms, so.reference, so.total_ordered, so.notes,
            so.total_delivered, so.total_invoiced,
            proj.project_name,
            c.customer_name,
            c.company_name,
            c.phone as customer_phone,
            c.email as customer_email,
            u1.username as created_by_name,
            u2.username as salesperson_name,
            u3.username as updated_by_name,
            (SELECT COUNT(*) FROM sales_order_items WHERE order_id = so.sales_order_id) as total_items,
            COUNT(DISTINCT i.invoice_id) as invoice_count,
            COALESCE(SUM(p.amount), 0) as total_paid,
            CASE
                WHEN so.status = 'cancelled' THEN 'cancelled'
                WHEN so.status = 'completed' THEN 'completed'
                WHEN so.status = 'delivered' THEN 'delivered'
                WHEN so.total_delivered > 0 AND so.total_delivered < so.total_ordered THEN 'partially_delivered'
                WHEN so.status = 'approved' THEN 'approved'
                WHEN so.status = 'pending' THEN 'pending'
                ELSE 'draft'
            END as display_status,
            CASE
                WHEN so.warehouse_id IS NOT NULL THEN 'Inventory'
                ELSE 'Non-Inventory'
            END as order_type
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        LEFT JOIN projects proj ON so.project_id = proj.project_id
        LEFT JOIN users u1 ON so.created_by = u1.user_id
        LEFT JOIN users u2 ON so.salesperson_id = u2.user_id
        LEFT JOIN users u3 ON so.updated_by = u3.user_id
        LEFT JOIN invoices i ON so.sales_order_id  = i.order_id AND i.status != 'cancelled'
        LEFT JOIN payments p ON i.invoice_id = p.invoice_id AND p.status = 'completed'
        WHERE $where_sql
        GROUP BY so.sales_order_id 
        ORDER BY so.order_date DESC, so.created_at DESC
        LIMIT ? OFFSET ?
    ";

    // Add limit/offset to params
    // Note: PDO params must be passed by reference or value properly if executed directly, 
    // but mixing named (:limit) and positional (?) parameters is tricky.
    // It's safer to use all positional or all named. 
    // Let's stick to standard prepare/execute with the existing params array, then bind limit/offset manually.
    
    $stmt = $pdo->prepare($query);
    
    // Bind positionals 1-based
    // Bind positionals 1-based
    $param_index = 1;
    foreach ($params as $v) {
        $stmt->bindValue($param_index++, $v);
    }
    
    // Bind limit/offset
    $stmt->bindValue($param_index++, (int)$length, PDO::PARAM_INT);
    $stmt->bindValue($param_index++, (int)$start, PDO::PARAM_INT);
    
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'draw' => $draw,
        'recordsTotal' => intval($recordsTotal),
        'recordsFiltered' => intval($recordsFiltered),
        'data' => $orders,
        'stats' => [
            'total_orders' => intval($stats_result['count']),
            'total_value' => floatval($stats_result['total_value']),
            'pending_count' => intval($stats_result['pending_count']),
            'approved_count' => intval($stats_result['approved_count']),
            'completed_count' => intval($stats_result['completed_count'] ?? 0), // completed usually part of delivered workflow or separate
            'processing_count' => intval($stats_result['processing_count']),
            'draft_count' => intval($stats_result['draft_count']),
            'delivered_count' => intval($stats_result['delivered_count']),
            'partially_delivered_count' => intval($stats_result['partially_delivered_count'])
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching sales orders: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
