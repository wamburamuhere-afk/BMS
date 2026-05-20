<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('customers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$lpo_id    = intval($_POST['lpo_id'] ?? 0);
$new_status = trim($_POST['new_status'] ?? '');

if (!$lpo_id) {
    echo json_encode(['success' => false, 'message' => 'LPO ID is required']);
    exit;
}

// Allowed workflow transitions
$transitions = [
    'pending'  => 'reviewed',
    'reviewed' => 'approved',
];

try {
    $stmt = $pdo->prepare("SELECT lpo_number, status FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
    $stmt->execute([$lpo_id]);
    $lpo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lpo) {
        echo json_encode(['success' => false, 'message' => 'LPO not found']);
        exit;
    }

    if (!isset($transitions[$lpo['status']]) || $transitions[$lpo['status']] !== $new_status) {
        echo json_encode(['success' => false, 'message' => "Cannot change status from '{$lpo['status']}' to '{$new_status}'"]);
        exit;
    }

    $pdo->prepare("UPDATE customer_lpos SET status = ? WHERE lpo_id = ?")
        ->execute([$new_status, $lpo_id]);

    logActivity($pdo, $_SESSION['user_id'], "LPO #{$lpo['lpo_number']} (ID:{$lpo_id}): {$lpo['status']} → {$new_status}");

    echo json_encode(['success' => true, 'message' => 'LPO status updated to ' . ucfirst($new_status) . '.']);
} catch (PDOException $e) {
    error_log("change_lpo_status error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
