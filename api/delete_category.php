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

try {
    $category_id = $_POST['category_id'] ?? null;

    if (!$category_id) {
        throw new Exception("Category ID is required");
    }

    $stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
    $stmt->execute([$category_id]);

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
