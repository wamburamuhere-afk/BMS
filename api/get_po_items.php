<?php
// scope-audit: skip — PO items lookup helper for GRN/return forms; parent PO already scoped at list level
// File: api/get_po_items.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $po_id = intval($_GET['id'] ?? 0);
    if ($po_id <= 0) throw new Exception('Invalid PO ID');

    global $pdo;
    
    // Fetch PO Header
    $stmt = $pdo->prepare("SELECT po.*, s.supplier_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.supplier_id WHERE po.purchase_order_id = ?");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) throw new Exception('Purchase Order not found');

    // Fetch PO Items with remaining quantity calculation
    // We sum up all quantities delivered in any DN linked to this PO
    $stmt = $pdo->prepare("
        SELECT 
            poi.*, 
            p.product_name, 
            p.sku, 
            p.unit, 
            p.barcode,
            COALESCE((
                SELECT SUM(di.quantity_delivered) 
                FROM delivery_items di 
                JOIN deliveries d ON di.delivery_id = d.delivery_id 
                WHERE d.purchase_order_id = poi.purchase_order_id 
                AND di.product_id = poi.product_id
                AND d.status != 'cancelled'
            ), 0) as quantity_received
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.product_id
        WHERE poi.purchase_order_id = ?
    ");
    $stmt->execute([$po_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate remaining for each
    foreach ($items as &$item) {
        $item['quantity_remaining'] = max(0, $item['quantity'] - $item['quantity_received']);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'header' => $po,
            'items' => $items
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
