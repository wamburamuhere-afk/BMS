<?php
// api/delete_supplier_category.php — soft-delete an unused supplier category
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canDelete('categories')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$id = (int)($_POST['category_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid category']);
    exit;
}

try {
    $st = $pdo->prepare("SELECT category_name FROM supplier_categories WHERE category_id = ? AND status != 'deleted'");
    $st->execute([$id]);
    $name = $st->fetchColumn();
    if ($name === false) {
        echo json_encode(['success' => false, 'message' => 'Category not found']);
        exit;
    }

    $used = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE category_id = ? AND status != 'deleted'");
    $used->execute([$id]);
    $n = (int)$used->fetchColumn();
    if ($n > 0) {
        echo json_encode(['success' => false, 'message' => "This category is used by $n supplier(s). Deactivate it instead."]);
        exit;
    }

    $pdo->prepare("UPDATE supplier_categories SET status = 'deleted' WHERE category_id = ?")->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'], "Deleted supplier category: $name");

    echo json_encode(['success' => true, 'message' => 'Category deleted.']);

} catch (PDOException $e) {
    error_log("delete_supplier_category: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
