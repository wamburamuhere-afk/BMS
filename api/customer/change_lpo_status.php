<?php
// File: api/customer/change_lpo_status.php
// Manual cancellation ONLY. The pending -> reviewed -> approved chain now runs
// exclusively through review_lpo.php / approve_lpo.php (three-approval, with
// canReview/canApprove gating + signature capture). partially_fulfilled /
// fulfilled are derived automatically from linked outbound DN quantities
// (see api/approve_dn.php) and are never set here.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('lpo')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$lpo_id     = intval($_POST['lpo_id'] ?? 0);
$new_status = trim($_POST['new_status'] ?? '');

if (!$lpo_id) {
    echo json_encode(['success' => false, 'message' => 'LPO ID is required']);
    exit;
}

if ($new_status !== 'cancelled') {
    echo json_encode(['success' => false, 'message' => 'This endpoint only supports cancelling an LPO. Use the Review/Approve actions for workflow transitions.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT lpo_number, status FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
    $stmt->execute([$lpo_id]);
    $lpo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lpo) {
        echo json_encode(['success' => false, 'message' => 'LPO not found']);
        exit;
    }

    if ($lpo['status'] === 'fulfilled' || $lpo['status'] === 'cancelled') {
        echo json_encode(['success' => false, 'message' => "Cannot cancel an LPO that is already '{$lpo['status']}'"]);
        exit;
    }

    $pdo->prepare("UPDATE customer_lpos SET status = 'cancelled' WHERE lpo_id = ?")
        ->execute([$lpo_id]);

    logActivity($pdo, $_SESSION['user_id'], "LPO #{$lpo['lpo_number']} (ID:{$lpo_id}): {$lpo['status']} → cancelled");

    echo json_encode(['success' => true, 'message' => 'LPO cancelled.']);
} catch (PDOException $e) {
    error_log("change_lpo_status error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
