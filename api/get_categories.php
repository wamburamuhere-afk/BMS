<?php
// File: api/get_categories.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;
    
    $type = $_GET['type'] ?? 'product';
    
    $stmt = $pdo->prepare("SELECT category_id, category_name, parent_id FROM categories WHERE status = 'active' AND type = ? ORDER BY category_name");
    $stmt->execute([$type]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $categories
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
