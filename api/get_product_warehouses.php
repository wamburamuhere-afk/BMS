<?php
// File: api/get_product_warehouses.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/warehouse_scope.php';
global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$product_id = $_GET['product_id'] ?? 0;

try {
    // Fetch warehouses and current stock for the product, scoped to the
    // requesting user's assigned warehouse(s) — Phase 6 (pos_upgrade_plan.md).
    $sql = "
        SELECT
            w.warehouse_id,
            w.warehouse_name,
            COALESCE(ps.stock_quantity, 0) as stock_quantity
        FROM warehouses w
        LEFT JOIN product_stocks ps ON w.warehouse_id = ps.warehouse_id AND ps.product_id = ?
        WHERE w.status = 'active' " . scopeFilterSqlNullable('warehouse', 'w') . "
        ORDER BY w.warehouse_name ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$product_id]);
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $warehouses
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
