<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    // 1. Fetch all active types
    $typesStmt = $pdo->prepare("SELECT id, name FROM expense_types WHERE status = 'active' ORDER BY name ASC");
    $typesStmt->execute();
    $types = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch all active categories
    $catsStmt = $pdo->prepare("SELECT id, type_id, name FROM expense_categories WHERE status = 'active' ORDER BY name ASC");
    $catsStmt->execute();
    $categories = $catsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Map categories to types
    $schema = [];
    foreach ($types as $type) {
        $typeId = $type['id'];
        $type['categories'] = array_values(array_filter($categories, function($cat) use ($typeId) {
            return $cat['type_id'] == $typeId;
        }));
        $schema[] = $type;
    }

    echo json_encode(['success' => true, 'data' => $schema]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
