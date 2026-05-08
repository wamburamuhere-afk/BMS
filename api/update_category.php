<?php
/**
 * API: Update Category
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $category_id = $_POST['category_id'] ?? null;
    $category_name = trim($_POST['category_name'] ?? '');
    $parent_id = intval($_POST['parent_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if (!$category_id || empty($category_name)) {
        throw new Exception("Category ID and name are required");
    }

    $stmt = $pdo->prepare("UPDATE categories SET category_name = ?, parent_id = ?, description = ?, status = ? WHERE category_id = ?");
    $stmt->execute([$category_name, $parent_id, $description, $status, $category_id]);

    echo json_encode([
        'success' => true,
        'message' => "Category updated successfully"
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
