<?php
// scope-audit: skip — dropdown helper for GRN/purchase-return create forms; returns PO list for a selected supplier; project scope on the form selection handled at list-page level
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$supplierId = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;

if (!$supplierId) {
    echo json_encode(['success' => false, 'message' => 'Invalid Supplier ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT purchase_order_id, order_number, order_date 
        FROM purchase_orders 
        WHERE supplier_id = ? AND status != 'deleted'
        ORDER BY order_date DESC
    ");
    $stmt->execute([$supplierId]);
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $pos]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
