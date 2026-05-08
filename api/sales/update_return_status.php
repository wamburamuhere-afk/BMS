<?php
// File: api/sales/update_return_status.php
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
$status = $_POST['status'] ?? '';

if ($return_id <= 0 || empty($status)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE sales_returns SET status = ? WHERE sales_return_id = ?");
    $stmt->execute([$status, $return_id]);

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    
    $stmt_num = $pdo->prepare("SELECT return_number FROM sales_returns WHERE sales_return_id = ?");
    $stmt_num->execute([$return_id]);
    $return_num = $stmt_num->fetchColumn();
    
    logActivity($pdo, $_SESSION['user_id'], 'Update Sales Return Status', "$user_name updated Sales Return #$return_num status to " . ucfirst($status));

    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
