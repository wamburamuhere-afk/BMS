<?php
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

    // Fetch PO Items
    $stmt = $pdo->prepare("
        SELECT poi.*, p.product_name, p.sku, p.unit, p.barcode
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.product_id
        WHERE poi.purchase_order_id = ?
    ");
    $stmt->execute([$po_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
