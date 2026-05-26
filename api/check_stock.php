<?php
// File: check_stock.php
// Check product stock availability for POS
// scope-audit: skip — stock check helper for POS; product/stock catalog is global; no project scope needed
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

try {
    // Get product ID
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    
    if (!$product_id) {
        echo json_encode([
            'success' => false,
            'message' => 'Product ID is required'
        ]);
        exit;
    }
    
    // Get product stock information
    $stmt = $pdo->prepare("
        SELECT 
            product_id,
            product_name,
            sku,
            stock_quantity,
            unit,
            selling_price,
            status
        FROM products 
        WHERE product_id = ? AND status = 'active'
    ");
    
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode([
            'success' => false,
            'message' => 'Product not found or inactive'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $product
    ]);
    
} catch (PDOException $e) {
    error_log("Check stock error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}
