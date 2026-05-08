<?php
// File: api/operations/get_return_grns.php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$warehouse_id = intval($_GET['warehouse_id'] ?? 0);
$supplier_id = intval($_GET['supplier_id'] ?? 0);

if (!$warehouse_id || !$supplier_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    // Select GRNs for this warehouse and supplier
    $stmt = $pdo->prepare("
        SELECT receipt_id, receipt_number, receipt_date
        FROM purchase_receipts
        WHERE warehouse_id = ? AND supplier_id = ? AND status != 'cancelled'
        ORDER BY receipt_date DESC, receipt_id DESC
    ");
    $stmt->execute([$warehouse_id, $supplier_id]);
    $grns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $grns]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
