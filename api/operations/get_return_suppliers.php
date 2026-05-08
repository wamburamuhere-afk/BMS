<?php
// File: api/operations/get_return_suppliers.php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$warehouse_id = intval($_GET['warehouse_id'] ?? 0);
$project_id = intval($_GET['project_id'] ?? 0);

if (!$warehouse_id || !$project_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    // Select suppliers who have GRNs in this warehouse and project
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.supplier_id, s.supplier_name
        FROM suppliers s
        JOIN purchase_receipts pr ON s.supplier_id = pr.supplier_id
        WHERE pr.warehouse_id = ? 
        AND s.project_id = ?
        AND pr.status != 'cancelled'
        ORDER BY s.supplier_name ASC
    ");
    $stmt->execute([$warehouse_id, $project_id]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $suppliers]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
