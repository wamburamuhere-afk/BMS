<?php
// File: api/create_category.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    $category_name = trim($_POST['category_name'] ?? '');
    $parent_id = intval($_POST['parent_id'] ?? 0);
    $type = $_POST['type'] ?? 'product';
    
    if (empty($category_name)) {
        throw new Exception('Category name is required');
    }
    
    // Check for duplicate category
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE category_name = ? AND type = ?");
    $stmt->execute([$category_name, $type]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('Category already exists');
    }
    
    // Generate category code
    $category_code = strtolower(str_replace(' ', '-', $category_name));
    $description = trim($_POST['description'] ?? '');
    
    $stmt = $pdo->prepare("
        INSERT INTO categories (category_name, category_code, parent_id, description, type, status) 
        VALUES (?, ?, ?, ?, ?, 'active')
    ");
    $stmt->execute([$category_name, $category_code, $parent_id, $description, $type]);
    
    $category_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Category created successfully',
        'category_id' => $category_id,
        'category_name' => $category_name
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}