<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $category_id = $_POST['category_id'] ?? '';
    $category_name = $_POST['category_name'] ?? '';
    $category_type = $_POST['category_type'] ?? '';
    $description = $_POST['category_description'] ?? '';
    $parent_category_id = !empty($_POST['parent_category_id']) ? $_POST['parent_category_id'] : null;

    if (empty($category_name) || empty($category_type)) {
        throw new Exception('Category Name and Type are required');
    }

    if (!empty($category_id)) {
        // Update
        $stmt = $pdo->prepare("
            UPDATE account_categories SET 
                category_name = ?, 
                category_type = ?, 
                description = ?, 
                parent_category_id = ?,
                updated_at = NOW()
            WHERE category_id = ?
        ");
        $stmt->execute([$category_name, $category_type, $description, $parent_category_id, $category_id]);
        $message = 'Category updated successfully';
    } else {
        // Insert
        $stmt = $pdo->prepare("
            INSERT INTO account_categories (
                category_name, 
                category_type, 
                description, 
                parent_category_id, 
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$category_name, $category_type, $description, $parent_category_id]);
        $message = 'Category created successfully';
    }

    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
