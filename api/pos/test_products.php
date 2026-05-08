<?php
/**
 * Simple test endpoint - no authentication
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../roots.php';

try {
    global $pdo;
    
    // Simple query
    $stmt = $pdo->query("SELECT 
        product_id,
        product_name,
        sku,
        selling_price,
        stock_quantity,
        is_service
    FROM products 
    WHERE status = 'active' 
    ORDER BY product_name 
    LIMIT 20");
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $products,
        'count' => count($products),
        'message' => 'Products loaded successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>
