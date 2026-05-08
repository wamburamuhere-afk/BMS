<?php
// ajax_delete_warehouse.php
require_once __DIR__ . '/roots.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// User role check (simplified - adjusting based on warehouses.php logic)
$user_role = $_SESSION['user_role'] ?? '';
if ($user_role !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

$warehouse_id = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : 0;
$csrf_token = $_POST['csrf_token'] ?? '';

if ($csrf_token !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

if ($warehouse_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Warehouse ID']);
    exit();
}

try {
    // Check if warehouse has stock
    $query = "SELECT SUM(stock_quantity) as total_stock FROM product_stocks WHERE warehouse_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$warehouse_id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stock && $stock['total_stock'] > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete warehouse with existing stock. Transfer stock first.']);
        exit();
    }

    // Check if warehouse has locations
    $query = "SELECT COUNT(*) as location_count FROM locations WHERE warehouse_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$warehouse_id]);
    $locations = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($locations && $locations['location_count'] > 1) { // 1 because we often create a default location
        echo json_encode(['success' => false, 'message' => 'Cannot delete warehouse with locations. Delete locations first.']);
        exit();
    }

    // Soft delete
    $query = "UPDATE warehouses SET status = 'deleted', updated_by = ?, updated_at = NOW() WHERE warehouse_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$_SESSION['user_id'], $warehouse_id]);

    echo json_encode(['success' => true, 'message' => 'Warehouse deleted successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
