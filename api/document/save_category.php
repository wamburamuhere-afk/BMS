<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $category_name = $_POST['category_name'] ?? '';
    $description = $_POST['description'] ?? '';
    $color = $_POST['color'] ?? '#6c757d';

    if (empty($category_name)) {
        throw new Exception('Category name is required');
    }

    $stmt = $pdo->prepare("INSERT INTO document_categories (category_name, description, color) VALUES (?, ?, ?)");
    $stmt->execute([$category_name, $description, $color]);

    echo json_encode([
        'success' => true,
        'message' => 'Category added successfully',
        'id' => $pdo->lastInsertId()
    ]);

    // Log the action
    logAudit($pdo, $_SESSION['user_id'], 'create_document_category', [
        'activity_type' => 'create',
        'description' => "Created document category: $category_name",
        'entity_type' => 'document_category',
        'entity_id' => $pdo->lastInsertId()
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
