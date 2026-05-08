<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;
    $warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
    $supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;

    if ($warehouse_id <= 0 || $supplier_id <= 0) {
        throw new Exception("Missing parameters");
    }

    $stmt = $pdo->prepare("
        SELECT receipt_id, receipt_number, receipt_date 
        FROM purchase_receipts 
        WHERE warehouse_id = ? AND supplier_id = ? AND status = 'completed'
        ORDER BY receipt_date DESC
    ");
    $stmt->execute([$warehouse_id, $supplier_id]);
    $grns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $grns]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
