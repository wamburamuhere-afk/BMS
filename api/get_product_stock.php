<?php
// scope-audit: skip — product stock lookup helper for forms; product catalog is global
// File: api/get_product_stock.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    $product_id = $_GET['product_id'] ?? 0;
    $warehouse_id = $_GET['warehouse_id'] ?? 0;
    
    if (!$product_id) {
        throw new Exception('Product ID is required');
    }
    
    // Get product details
    if ($warehouse_id) {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   COALESCE(ps.stock_quantity, 0) as total_stock,
                   COALESCE(ps.reserved_quantity, 0) as reserved_quantity,
                   COALESCE(ps.stock_quantity - ps.reserved_quantity, 0) as available_stock
            FROM products p
            LEFT JOIN product_stocks ps ON p.product_id = ps.product_id AND ps.warehouse_id = ?
            WHERE p.product_id = ?
        ");
        $stmt->execute([$warehouse_id, $product_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   COALESCE(p.current_stock, 0) as total_stock,
                   0 as reserved_quantity,
                   COALESCE(p.current_stock, 0) as available_stock
            FROM products p
            WHERE p.product_id = ?
        ");
        $stmt->execute([$product_id]);
    }
    
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        throw new Exception('Product not found');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $product
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>