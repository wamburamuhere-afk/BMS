<?php
// File: api/sales/delete_return.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

global $pdo;
$return_id = intval($_POST['return_id'] ?? 0);

if ($return_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    
    $stmt_num = $pdo->prepare("SELECT return_number FROM sales_returns WHERE sales_return_id = ?");
    $stmt_num->execute([$return_id]);
    $return_num = $stmt_num->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM sales_returns WHERE sales_return_id = ?");
    $stmt->execute([$return_id]);

    logActivity($pdo, $_SESSION['user_id'], 'Delete Sales Return', "$user_name deleted Sales Return #$return_num");

    echo json_encode(['success' => true, 'message' => 'Return deleted successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
