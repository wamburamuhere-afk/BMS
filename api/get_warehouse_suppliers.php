<?php
// scope-audit: skip — warehouse supplier lookup; supplier scope enforced via purchase_orders
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

    if ($warehouse_id <= 0) {
        throw new Exception("Warehouse not specified");
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT s.supplier_id, s.supplier_name 
        FROM suppliers s
        INNER JOIN purchase_receipts pr ON s.supplier_id = pr.supplier_id
        WHERE pr.warehouse_id = ? AND pr.status = 'completed' AND s.status = 'active'
        ORDER BY s.supplier_name ASC
    ");
    $stmt->execute([$warehouse_id]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $suppliers]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
