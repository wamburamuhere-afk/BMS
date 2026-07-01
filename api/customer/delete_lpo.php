<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('customers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$lpo_id = intval($_POST['lpo_id'] ?? 0);
if (!$lpo_id) {
    echo json_encode(['success' => false, 'message' => 'LPO ID is required']);
    exit;
}

try {
    $check = $pdo->prepare("SELECT lpo_number FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
    $check->execute([$lpo_id]);
    $lpo = $check->fetch(PDO::FETCH_ASSOC);
    if (!$lpo) {
        echo json_encode(['success' => false, 'message' => 'LPO not found']);
        exit;
    }

    $pdo->prepare("UPDATE customer_lpos SET status = 'deleted' WHERE lpo_id = ?")
        ->execute([$lpo_id]);

    logActivity($pdo, $_SESSION['user_id'], "Delete lpo", "deleted LPO #{$lpo['lpo_number']} with id {$lpo_id}");

    echo json_encode(['success' => true, 'message' => 'LPO deleted successfully.']);
} catch (PDOException $e) {
    error_log("delete_lpo error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
