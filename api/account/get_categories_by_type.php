<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;
header('Content-Type: application/json');

try {
    $categoryType = $_GET['type'] ?? '';
    
    $query = "
        SELECT 
            category_id,
            category_name,
            category_type,
            parent_category_id
        FROM account_categories
        WHERE category_type = ?
        ORDER BY category_name
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$categoryType]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
