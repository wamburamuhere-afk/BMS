<?php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$delivery_id = intval($_GET['delivery_id'] ?? 0);

if (!$delivery_id) {
    echo json_encode(['success' => false, 'message' => 'delivery_id required']);
    exit;
}

try {
    // Fetch DN header for supplier/warehouse/project/PO context
    $hdr = $pdo->prepare("
        SELECT d.supplier_id, d.warehouse_id, d.project_id, d.purchase_order_id,
               po.order_number
        FROM deliveries d
        LEFT JOIN purchase_orders po ON d.purchase_order_id = po.purchase_order_id
        WHERE d.delivery_id = ? AND d.dn_type = 'inbound'
    ");
    $hdr->execute([$delivery_id]);
    $header = $hdr->fetch(PDO::FETCH_ASSOC);

    if (!$header) {
        echo json_encode(['success' => false, 'message' => 'Delivery Note not found']);
        exit;
    }

    // Fetch items — map quantity_delivered → pending_qty so addItemRow() works unchanged
    $items = $pdo->prepare("
        SELECT di.product_id,
               di.product_name,
               di.sku,
               di.unit,
               di.quantity_delivered  AS pending_qty,
               COALESCE(p.cost_price, 0) AS unit_price
        FROM delivery_items di
        LEFT JOIN products p ON di.product_id = p.product_id
        WHERE di.delivery_id = ?
    ");
    $items->execute([$delivery_id]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => [
            'supplier_id'       => $header['supplier_id'],
            'warehouse_id'      => $header['warehouse_id'],
            'project_id'        => $header['project_id'],
            'purchase_order_id' => $header['purchase_order_id'],
            'order_number'      => $header['order_number'],
            'items'             => $rows,
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
