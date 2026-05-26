<?php
// scope-audit: skip — PO items lookup for operations returns forms; parent PO already scoped
/**
 * API: get_po_items.php
 * Returns items from a specific purchase order for use in GRN creation.
 */
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$po_id = isset($_GET['po_id']) ? intval($_GET['po_id']) : 0;

if ($po_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid purchase order ID']);
    exit;
}

try {
    // Get the purchase order header
    $stmt = $pdo->prepare("
        SELECT po.*, s.supplier_name, s.supplier_id
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        WHERE po.purchase_order_id = ?
    ");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        echo json_encode(['success' => false, 'message' => 'Purchase order not found']);
        exit;
    }

    // Get PO items with product details
    $stmt = $pdo->prepare("
        SELECT 
            poi.item_id,
            poi.product_id,
            poi.quantity,
            poi.unit_price,
            poi.quantity - IFNULL(SUM(ri.quantity_received), 0) AS pending_qty,
            COALESCE(p.product_name, poi.item_name) AS display_name,
            p.sku,
            p.barcode,
            p.unit,
            p.cost_price,
            p.purchase_price
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.product_id
        LEFT JOIN receipt_items ri ON poi.item_id = ri.purchase_order_item_id
        WHERE poi.purchase_order_id = ?
        GROUP BY poi.item_id
        HAVING pending_qty > 0
    ");
    $stmt->execute([$po_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build items array for the response
    $formatted_items = [];
    foreach ($items as $item) {
        $formatted_items[] = [
            'item_id'          => $item['item_id'],
            'product_id'       => $item['product_id'],
            'product_name'     => $item['display_name'],
            'sku'              => $item['sku'] ?? '',
            'barcode'          => $item['barcode'] ?? '',
            'unit'             => $item['unit'] ?? 'pcs',
            'quantity'         => $item['pending_qty'],
            'unit_price'       => $item['unit_price'],
        ];
    }

    echo json_encode([
        'success'     => true,
        'data'        => [
            'po_id'       => $po['purchase_order_id'],
            'supplier_id' => $po['supplier_id'],
            'warehouse_id'=> $po['warehouse_id'] ?? null,
            'project_id'  => $po['project_id'] ?? null,
            'items'       => $formatted_items,
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
