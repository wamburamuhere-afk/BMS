<?php
// File: api/get_current_stock.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;

if (!$product_id || !$warehouse_id) {
    echo json_encode(['success' => false, 'message' => 'Missing product or warehouse ID']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(stock_quantity, 0) as stock_quantity,
               COALESCE(reserved_quantity, 0) as reserved_quantity
        FROM product_stocks 
        WHERE product_id = ? AND warehouse_id = ?
    ");
    $stmt->execute([$product_id, $warehouse_id]);
    $stock_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $current_stock = $stock_data ? (float)$stock_data['stock_quantity'] : 0;
    
    echo json_encode([
        'success' => true,
        'stock' => $current_stock
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
