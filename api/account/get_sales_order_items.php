<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}
assertScopeForRecord('sales_orders', 'sales_order_id', $order_id);

try {
    global $pdo;
    require_once __DIR__ . '/../../core/sales_invoicing.php';

    // The agreed price, VAT and discount come from the order line itself (not
    // the current catalogue), and only the quantity not yet invoiced is offered.
    echo json_encode(['success' => true, 'data' => soInvoiceableLines($pdo, $order_id)]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
