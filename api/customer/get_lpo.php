<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('customers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$lpo_id = intval($_GET['lpo_id'] ?? 0);
if (!$lpo_id) {
    echo json_encode(['success' => false, 'message' => 'LPO ID is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
    $stmt->execute([$lpo_id]);
    $lpo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lpo) {
        echo json_encode(['success' => false, 'message' => 'LPO not found']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $lpo]);
} catch (PDOException $e) {
    error_log("get_lpo error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
