<?php
// ajax_toggle_warehouse_status.php
require_once __DIR__ . '/roots.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

$warehouse_id = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : 0;
$new_status = $_POST['new_status'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if ($csrf_token !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

if ($warehouse_id <= 0 || empty($new_status)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

try {
    $query = "UPDATE warehouses SET status = ?, updated_by = ?, updated_at = NOW() WHERE warehouse_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$new_status, $_SESSION['user_id'], $warehouse_id]);

    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
