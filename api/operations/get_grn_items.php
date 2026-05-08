<?php
// File: api/operations/get_grn_items.php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$receipt_id = intval($_GET['receipt_id'] ?? 0);
$warehouse_id = intval($_GET['warehouse_id'] ?? 0);

if (!$receipt_id || !$warehouse_id) {
    echo json_encode(['success' => false, 'message' => 'Missing receipt_id or warehouse_id']);
    exit();
}

try {
    // Select items from receipt_items joined with products and current stock
    $stmt = $pdo->prepare("
        SELECT 
            ri.receipt_item_id,
            ri.product_id,
            p.product_name,
            p.sku,
            p.barcode,
            ri.quantity_received AS qty,
            ri.unit_price,
            (ri.quantity_received * ri.unit_price) AS total,
            ri.unit,
            COALESCE(ps.stock_quantity, 0) AS current_stock
        FROM receipt_items ri
        JOIN products p ON ri.product_id = p.product_id
        LEFT JOIN product_stocks ps ON p.product_id = ps.product_id AND ps.warehouse_id = ?
        WHERE ri.receipt_id = ?
        ORDER BY ri.receipt_item_id ASC
    ");
    $stmt->execute([$warehouse_id, $receipt_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $items]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
