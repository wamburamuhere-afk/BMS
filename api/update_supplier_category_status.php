<?php
// api/update_supplier_category_status.php — activate / deactivate a supplier category
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('categories')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$id     = (int)($_POST['category_id'] ?? 0);
$status = $_POST['status'] ?? '';
if ($id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid category or status']);
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

    $pdo->prepare("UPDATE supplier_categories SET status = ? WHERE category_id = ?")->execute([$status, $id]);

    logActivity($pdo, $_SESSION['user_id'], ($status === 'active' ? 'Activated' : 'Deactivated') . " supplier category: $name");

    echo json_encode(['success' => true, 'message' => 'Category ' . ($status === 'active' ? 'activated' : 'deactivated') . '.']);

} catch (PDOException $e) {
    error_log("update_supplier_category_status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
