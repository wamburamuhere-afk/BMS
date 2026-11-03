<?php
// api/add_supplier_category.php — create a supplier category (app/bms/Suppliers/supplier_categories.php)
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canCreate('categories')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$name        = trim($_POST['category_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Category name is required']);
    exit;
}
if (mb_strlen($name) > 255) {
    echo json_encode(['success' => false, 'message' => 'Category name is too long']);
    exit;
}

try {
    // category_name is UNIQUE across every row, deleted ones included.
    $st = $pdo->prepare("SELECT category_id, status FROM supplier_categories WHERE LOWER(category_name) = LOWER(?)");
    $st->execute([$name]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);

    if ($existing && $existing['status'] !== 'deleted') {
        echo json_encode(['success' => false, 'message' => 'A category with this name already exists']);
        exit;
    }

    if ($existing) {
        // Re-adding a deleted name brings that row back rather than failing on the unique key.
        $pdo->prepare("UPDATE supplier_categories SET category_name = ?, description = ?, status = ?, created_by = ?, created_at = NOW()
                       WHERE category_id = ?")
            ->execute([$name, $description !== '' ? $description : null, $status, $_SESSION['user_id'], $existing['category_id']]);
        $id = (int)$existing['category_id'];
    } else {
        $pdo->prepare("INSERT INTO supplier_categories (category_name, description, status, created_by, created_at) VALUES (?, ?, ?, ?, NOW())")
            ->execute([$name, $description !== '' ? $description : null, $status, $_SESSION['user_id']]);
        $id = (int)$pdo->lastInsertId();
    }

    logActivity($pdo, $_SESSION['user_id'], "Created supplier category: $name");

    echo json_encode(['success' => true, 'message' => 'Category created successfully.', 'id' => $id]);

} catch (PDOException $e) {
    error_log("add_supplier_category: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
