<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized access');
    $user_id    = intval($_SESSION['user_id']);

    if (!canDelete('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete NIP products');
    }

    $product_id = intval($_POST['product_id'] ?? 0);
    if (!$product_id) throw new Exception('Product ID is required.');

    // Phase D — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('products', 'product_id', $product_id);
    }

    $stmt = $pdo->prepare("SELECT product_id, product_name FROM products WHERE product_id = ? AND is_service = 1");
    $stmt->execute([$product_id]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prod) throw new Exception('NIP product not found.');

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM product_assembly_components WHERE parent_product_id = ?")->execute([$product_id]);
    $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$product_id]);
    $pdo->prepare("DELETE FROM stock_movements WHERE product_id = ?")->execute([$product_id]);
    $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$product_id]);
    $pdo->commit();

    logActivity($pdo, $user_id, "Deleted NIP product \"{$prod['product_name']}\" (ID {$product_id}) and all its materials");

    echo json_encode(['success' => true, 'message' => "\"{$prod['product_name']}\" and all related materials have been deleted."]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
