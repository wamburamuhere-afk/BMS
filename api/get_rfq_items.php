<?php
// File: api/get_rfq_items.php
require_once __DIR__ . '/../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');

    $rfq_id = intval($_GET['rfq_id'] ?? 0);
    if (!$rfq_id) throw new Exception('RFQ ID is required');

    // Verify RFQ exists and is approved
    $stmt = $pdo->prepare("SELECT rfq_id, rfq_number, status FROM rfq WHERE rfq_id = ?");
    $stmt->execute([$rfq_id]);
    $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rfq) throw new Exception('RFQ not found');

    // Get items with remaining quantity calculation
    $stmt2 = $pdo->prepare("
        SELECT 
            ri.item_id, 
            ri.product_id, 
            ri.description, 
            ri.unit, 
            ri.qty as requested_qty,
            COALESCE((
                SELECT SUM(poi.quantity)
                FROM purchase_order_items poi
                JOIN purchase_orders po ON poi.purchase_order_id = po.purchase_order_id
                WHERE po.rfq_id = ri.rfq_id 
                  AND (
                      (ri.product_id IS NOT NULL AND poi.product_id = ri.product_id)
                      OR (ri.product_id IS NULL AND poi.item_name COLLATE utf8mb4_general_ci = ri.description COLLATE utf8mb4_general_ci)
                  )
                  AND po.status != 'cancelled'
            ), 0) as ordered_qty
        FROM rfq_items ri 
        WHERE ri.rfq_id = ? 
        ORDER BY ri.item_order ASC
    ");
    $stmt2->execute([$rfq_id]);
    $raw_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($raw_items as $item) {
        $remaining = floatval($item['requested_qty']) - floatval($item['ordered_qty']);
        if ($remaining > 0) {
            $item['remaining_qty'] = $remaining;
            $items[] = $item;
        }
    }

    echo json_encode([
        'success' => true,
        'rfq'     => $rfq,
        'items'   => $items
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'items' => []]);
}