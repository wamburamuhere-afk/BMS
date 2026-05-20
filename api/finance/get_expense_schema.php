<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

function buildCategoryTree($allCats, $typeId, $parentId = null) {
    $result = [];
    foreach ($allCats as $cat) {
        $catParent = ($cat['parent_id'] === null || $cat['parent_id'] === '') ? null : (int)$cat['parent_id'];
        if ($cat['type_id'] == $typeId && $catParent === $parentId) {
            $cat['children'] = buildCategoryTree($allCats, $typeId, (int)$cat['id']);
            $result[] = $cat;
        }
    }
    return $result;
}

try {
    $typesStmt = $pdo->prepare("SELECT id, name, show_project FROM expense_types WHERE status = 'active' ORDER BY name ASC");
    $typesStmt->execute();
    $types = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

    $hasParentId = $pdo->query("SHOW COLUMNS FROM expense_categories LIKE 'parent_id'")->rowCount() > 0;

    if ($hasParentId) {
        $catsStmt = $pdo->prepare("SELECT id, type_id, parent_id, name FROM expense_categories WHERE status = 'active' ORDER BY name ASC");
    } else {
        $catsStmt = $pdo->prepare("SELECT id, type_id, NULL as parent_id, name FROM expense_categories WHERE status = 'active' ORDER BY name ASC");
    }
    $catsStmt->execute();
    $allCats = $catsStmt->fetchAll(PDO::FETCH_ASSOC);

    $schema = [];
    foreach ($types as $type) {
        $type['categories'] = buildCategoryTree($allCats, $type['id'], null);
        $schema[] = $type;
    }

    echo json_encode(['success' => true, 'data' => $schema]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
