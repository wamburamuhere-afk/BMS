<?php
// api/update_supplier_category.php — edit a supplier category (app/bms/Suppliers/supplier_categories.php)
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

$id          = (int)($_POST['category_id'] ?? 0);
$name        = trim($_POST['category_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
if ($id <= 0 || $name === '') {
    echo json_encode(['success' => false, 'message' => 'Category and name are required']);
    exit;
}
if (mb_strlen($name) > 255) {
    echo json_encode(['success' => false, 'message' => 'Category name is too long']);
    exit;
}

try {
    $st = $pdo->prepare("SELECT category_name FROM supplier_categories WHERE category_id = ? AND status != 'deleted'");
    $st->execute([$id]);
    $old = $st->fetchColumn();
    if ($old === false) {
        echo json_encode(['success' => false, 'message' => 'Category not found']);
        exit;
    }

    $dup = $pdo->prepare("SELECT COUNT(*) FROM supplier_categories WHERE LOWER(category_name) = LOWER(?) AND category_id != ?");
    $dup->execute([$name, $id]);
    if ((int)$dup->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'A category with this name already exists']);
        exit;
    }

    $pdo->prepare("UPDATE supplier_categories SET category_name = ?, description = ?, status = ? WHERE category_id = ?")
        ->execute([$name, $description !== '' ? $description : null, $status, $id]);

    logActivity($pdo, $_SESSION['user_id'], "Updated supplier category #$id: $old" . ($old !== $name ? " → $name" : ''));

    echo json_encode(['success' => true, 'message' => 'Category updated successfully.']);

} catch (PDOException $e) {
    error_log("update_supplier_category: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
