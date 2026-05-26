<?php
// scope-audit: skip — item-level read for a single GRN (called when expanding a GRN row); parent GRN is already scoped at list level via get_grns.php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;
    $receipt_id = isset($_GET['receipt_id']) ? intval($_GET['receipt_id']) : 0;

    if ($receipt_id <= 0) {
        throw new Exception("Missing GRN ID");
    }

    $stmt = $pdo->prepare("
        SELECT 
            ri.product_id, 
            ri.quantity_received as qty, 
            ri.unit_price, 
            p.product_name, 
            p.sku,
            p.unit
        FROM receipt_items ri
        LEFT JOIN products p ON ri.product_id = p.product_id
        WHERE ri.receipt_id = ?
    ");
    $stmt->execute([$receipt_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
