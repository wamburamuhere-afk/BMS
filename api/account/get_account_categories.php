<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;
header('Content-Type: application/json');

function buildCategoryTree($categories, $parentId = null) {
    $branch = [];
    
    foreach ($categories as $category) {
        if ($category['parent_category_id'] == $parentId) {
            $children = buildCategoryTree($categories, $category['category_id']);
            if ($children) {
                $category['children'] = $children;
                $category['has_children'] = true;
            } else {
                $category['has_children'] = false;
            }
            
            // Get account count for this category
            $stmt = $GLOBALS['pdo']->prepare("
                SELECT COUNT(*) as account_count 
                FROM accounts 
                WHERE category_id = ?
            ");
            $stmt->execute([$category['category_id']]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            $category['account_count'] = $count['account_count'];
            
            $branch[] = $category;
        }
    }
    
    return $branch;
}

try {
    $query = "
        SELECT 
            c.category_id,
            c.category_name,
            at.type_name as category_type,
            c.description,
            c.parent_category_id,
            c.created_at
        FROM account_categories c
        LEFT JOIN account_types at ON c.account_type_id = at.type_id
        ORDER BY c.category_name
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build hierarchical tree
    $categoryTree = buildCategoryTree($categories);
    
    echo json_encode([
        'success' => true,
        'categories' => $categoryTree
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
