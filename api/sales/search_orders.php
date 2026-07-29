<?php
// File: api/sales/search_orders.php
// This endpoint used to claim "SO scope enforced at sales_orders list level"
// via a scope-audit skip marker, but it is its own independent query — the
// list page's scope filter never applied here. A non-admin searching this
// picker (used when creating a sales return) could find and select ANY
// sales order in the system, not just ones tied to their assigned
// projects/warehouses. Now uses the same scope helpers as sales_orders.php.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['results' => []]);
    exit;
}

global $pdo;
$term = isset($_GET['q']) ? $_GET['q'] : '';

try {
    $query = "
        SELECT so.sales_order_id, so.order_number, c.customer_name
        FROM sales_orders so
        JOIN customers c ON so.customer_id = c.customer_id
        WHERE (so.order_number LIKE ? OR c.customer_name LIKE ?)
        AND so.status IN ('approved', 'completed')
    ";
    $query .= scopeFilterSqlNullable('project', 'so');
    $query .= scopeFilterSqlNullable('warehouse', 'so');
    $query .= " LIMIT 20";

    $stmt = $pdo->prepare($query);

    $searchTerm = "%$term%";
    $stmt->execute([$searchTerm, $searchTerm]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = array_map(function($order) {
        return [
            'id' => $order['sales_order_id'],
            'text' => '#' . $order['order_number'] . ' - ' . $order['customer_name']
        ];
    }, $orders);

    echo json_encode($results);

} catch (Exception $e) {
    echo json_encode(['results' => []]);
}
?>
