<?php
// File: api/delete_product.php
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

if (!isAdmin() && !canDelete('products')) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to delete products']);
    exit();
}

$product_id = intval($_POST['product_id'] ?? 0);

if (!$product_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Get product name before deleting
    $stmt = $pdo->prepare("SELECT product_name, sku FROM products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }

    // Check if product has any sales orders referencing it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_order_items WHERE product_id = ?");
    $stmt->execute([$product_id]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode([
            'success'  => false,
            'message'  => 'Cannot delete: this product has existing sales orders. Consider deactivating it instead.'
        ]);
        exit();
    }

    // Check purchase orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE product_id = ?");
    $stmt->execute([$product_id]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode([
            'success'  => false,
            'message'  => 'Cannot delete: this product has existing purchase orders. Consider deactivating it instead.'
        ]);
        exit();
    }

    // Delete related stock movements
    $pdo->prepare("DELETE FROM stock_movements WHERE product_id = ?")->execute([$product_id]);

    // Delete related product stocks
    $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$product_id]);

    // Delete the product
    $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$product_id]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], 'Deleted Product', "Deleted product: {$product['product_name']} (SKU: {$product['sku']})");

    echo json_encode(['success' => true, 'message' => "Product '{$product['product_name']}' deleted successfully"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
