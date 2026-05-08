<?php
// File: api/get_project_warehouse_stock.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
try {
    $warehouse_id = intval($_GET['warehouse_id'] ?? 0);
    $project_id   = intval($_GET['project_id']   ?? 0);
    if ($warehouse_id <= 0) throw new Exception('Warehouse ID required.');

    // Validate warehouse belongs to project
    if ($project_id > 0) {
        $wh = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ? AND project_id = ?");
        $wh->execute([$warehouse_id, $project_id]);
        if (!$wh->fetch()) throw new Exception('Warehouse does not belong to this project.');
    }

    $stmt = $pdo->prepare("
        SELECT
            ps.product_id, p.product_name, p.sku, p.unit,
            ps.stock_quantity, ps.reserved_quantity, ps.available_quantity
        FROM product_stocks ps
        JOIN products p ON ps.product_id = p.product_id
        WHERE ps.warehouse_id = ? AND ps.available_quantity > 0
        ORDER BY p.product_name ASC
    ");
    $stmt->execute([$warehouse_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true, 'data'=>$data]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
