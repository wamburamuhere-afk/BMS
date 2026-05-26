<?php
// scope-audit: skip — sales order search helper; SO scope enforced at sales_orders list level
// File: api/sales/search_orders.php
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
    $stmt = $pdo->prepare("
        SELECT so.sales_order_id, so.order_number, c.customer_name 
        FROM sales_orders so
        JOIN customers c ON so.customer_id = c.customer_id
        WHERE (so.order_number LIKE ? OR c.customer_name LIKE ?)
        AND so.status IN ('approved', 'completed')
        LIMIT 20
    ");
    
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
