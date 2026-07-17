<?php
// scope-audit: skip — adjustment detail read; adjustment scope deferred to Phase G-2
ob_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/warehouse_scope.php';

global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Adjustment ID is required']);
    exit;
}

$adjustment_id = intval($_GET['id']);

try {
    $stmt = $pdo->prepare("
        SELECT 
            sm.*,
            p.product_name,
            p.sku,
            p.barcode,
            u.username as adjusted_by_name,
            w.warehouse_name,
            loc.location_name,
            pr.project_name
        FROM stock_movements sm
        LEFT JOIN products p ON sm.product_id = p.product_id
        LEFT JOIN users u ON sm.created_by = u.user_id
        LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
        LEFT JOIN locations loc ON sm.location_id = loc.location_id
        LEFT JOIN projects pr ON sm.project_id = pr.project_id
        WHERE sm.movement_id = ?
    ");
    $stmt->execute([$adjustment_id]);
    $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);

    ob_clean();
    if ($adjustment) {
        if (!empty($adjustment['project_id']) && !userCan('project', (int)$adjustment['project_id'])) {
            echo json_encode(['success' => false, 'message' => 'Access denied: this record belongs to a project not in your scope.']);
        } elseif (!empty($adjustment['warehouse_id']) && !userCan('warehouse', (int)$adjustment['warehouse_id'])) {
            echo json_encode(['success' => false, 'message' => 'Access denied: this warehouse is not in your assigned scope.']);
        } else {
            echo json_encode(['success' => true, 'data' => $adjustment]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Adjustment not found']);
    }

} catch (PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
