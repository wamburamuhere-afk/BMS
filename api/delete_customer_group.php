<?php
// api/delete_customer_group.php — soft-delete a customer group
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/customer_groups.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canDelete('customer_groups')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$id = (int)($_POST['group_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid group']);
    exit;
}

try {
    $g = customerGroupFind($pdo, $id);
    if (!$g) {
        echo json_encode(['success' => false, 'message' => 'Group not found']);
        exit;
    }
    // Soft delete (security.md §12): the row and its member list are kept.
    $pdo->prepare("UPDATE customer_groups SET status = 'deleted', updated_by = ? WHERE group_id = ?")
        ->execute([(int)$_SESSION['user_id'], $id]);

    logActivity($pdo, $_SESSION['user_id'], "Deleted customer group #$id: {$g['group_name']}");
    echo json_encode(['success' => true, 'message' => 'Customer group deleted.']);
} catch (PDOException $e) {
    error_log('delete_customer_group: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
