<?php
/**
 * Simple test endpoint - no authentication
 */
// scope-audit: skip — developer test script; not a runtime data endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}


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
        'message' => t('Products loaded successfully')
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
