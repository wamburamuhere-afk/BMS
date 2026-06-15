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
if (!canView('purchase_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to view purchase orders']);
    exit;
}

try {
    global $pdo;

    // Get filter parameters
    $supplier_id = $_GET['supplier'] ?? '';
    $status = $_GET['status'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';

    // Build query
    $query = "
        SELECT po.*,
               s.supplier_name, s.company_name,
               p.project_name,
               u1.username as created_by_name,
               u2.username as approved_by_name,
               COUNT(poi.item_id) as item_count,
               CASE
                   WHEN (SELECT COUNT(*) FROM deliveries d WHERE d.purchase_order_id = po.purchase_order_id AND d.status != 'cancelled') = 0
                   THEN NULL
                   WHEN (
                       SELECT COUNT(*) FROM purchase_order_items poi2
                       WHERE poi2.purchase_order_id = po.purchase_order_id
                       AND poi2.quantity > COALESCE((
                           SELECT SUM(di.quantity_delivered)
                           FROM delivery_items di
                           JOIN deliveries d ON di.delivery_id = d.delivery_id
                           WHERE d.purchase_order_id = po.purchase_order_id
                           AND d.status != 'cancelled'
                           AND di.product_id = poi2.product_id
                       ), 0)
                   ) > 0 THEN 'partial'
                   ELSE 'complete'
               END as delivery_status
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        LEFT JOIN projects p ON po.project_id = p.project_id
        LEFT JOIN users u1 ON po.created_by = u1.user_id
        LEFT JOIN users u2 ON po.approved_by = u2.user_id
        LEFT JOIN purchase_order_items poi ON po.purchase_order_id = poi.purchase_order_id
        WHERE 1=1
    ";

    $params = [];

    if (!empty($supplier_id)) {
        $query .= " AND po.supplier_id = ?";
        $params[] = $supplier_id;
    }

    if (!empty($status)) {
        $query .= " AND po.status = ?";
        $params[] = $status;
    }

    if (!empty($date_from)) {
        $query .= " AND po.order_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $query .= " AND po.order_date <= ?";
        $params[] = $date_to;
    }

    // Phase C — project-scope filter (non-admin: AND po.project_id IN (...) | admin: '')
    $query .= scopeFilterSql('project', 'po');

    $query .= " GROUP BY po.purchase_order_id ORDER BY po.order_date DESC, po.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    $total_orders = count($orders);
    $total_amount = array_sum(array_column($orders, 'grand_total'));
    
    $pending_orders_list = array_filter($orders, function($order) {
        return in_array($order['status'], ['draft', 'pending', 'reviewed', 'approved', 'ordered', 'partially_received']);
    });
    $pending_count = count($pending_orders_list);
    $pending_amount = array_sum(array_column($pending_orders_list, 'grand_total'));

    // Approved bucket — the summary card reads stats.approved_amount (was never
    // computed here, so the card always showed 0 even with approved POs present).
    $approved_orders_list = array_filter($orders, function($order) {
        return $order['status'] === 'approved';
    });
    $approved_count  = count($approved_orders_list);
    $approved_amount = array_sum(array_column($approved_orders_list, 'grand_total'));

    echo json_encode([
        'success' => true,
        'data' => $orders,
        'stats' => [
            'total_orders'    => $total_orders,
            'total_amount'    => $total_amount,
            'pending_count'   => $pending_count,
            'pending_amount'  => $pending_amount,
            'approved_count'  => $approved_count,
            'approved_amount' => $approved_amount
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching purchase orders: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
