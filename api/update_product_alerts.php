<?php
// File: api/update_product_alerts.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

error_reporting(0);
ini_set('display_errors', 0);

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!isAdmin() && !canEdit('products')) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions to update alert settings']);
    exit();
}

$product_id     = intval($_POST['product_id'] ?? 0);
$reorder_level  = floatval($_POST['reorder_level'] ?? 0);
$min_stock_level= floatval($_POST['min_stock_level'] ?? 0);
$max_stock_level= floatval($_POST['max_stock_level'] ?? 0);
$email_alerts   = isset($_POST['email_alerts']) ? 1 : 0;

if (!$product_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit();
}

// Phase E — project-scope gate
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('products', 'product_id', $product_id);
}

try {
    // Check product exists
    $stmt = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }

    // Update stock alert thresholds
    $stmt = $pdo->prepare("
        UPDATE products SET
            reorder_level    = ?,
            min_stock_level  = ?,
            max_stock_level  = ?,
            email_alerts     = ?,
            updated_at       = NOW(),
            updated_by       = ?
        WHERE product_id = ?
    ");
    $stmt->execute([
        $reorder_level,
        $min_stock_level,
        $max_stock_level,
        $email_alerts,
        $_SESSION['user_id'],
        $product_id
    ]);

    logActivity($pdo, $_SESSION['user_id'], 'Updated Stock Alerts', 
        "Updated alert settings for product: {$product['product_name']} (Reorder: $reorder_level, Min: $min_stock_level, Max: $max_stock_level)");

    echo json_encode([
        'success' => true,
        'message' => 'Alert settings updated successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
