<?php
/**
 * API: Delete Category
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canDelete('categories')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete product categories']);
    exit();
}

try {
    $category_id = $_POST['category_id'] ?? null;

    if (!$category_id) {
        throw new Exception("Category ID is required");
    }

    // No FK links products/sub-categories to categories, so a plain DELETE would leave
    // them pointing at a category that no longer exists. Refuse instead, with a clear reason.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
    $stmt->execute([$category_id]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new Exception("This category is used by products. Move those products to another category first (or make the category Inactive instead).");
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = ?");
    $stmt->execute([$category_id]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new Exception("This category has sub-categories. Delete or move them first.");
    }

    $stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
    $stmt->execute([$category_id]);

    logActivity($pdo, $_SESSION['user_id'], "Delete product category", "deleted product category with id $category_id");

    echo json_encode([
        'success' => true,
        'message' => "Category deleted successfully"
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
