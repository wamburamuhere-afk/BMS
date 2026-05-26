<?php
// scope-audit: skip — write API — change supplier payment status; payment scope deferred to Phase G-2
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

// Workflow review/approve is also enforced per-transition below; this is the
// top-level gate ensuring the caller has at least edit access to payments.
if (!canEdit('supplier_payments')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change supplier payment status']);
    exit;
}

csrf_check();

$id        = intval($_POST['payment_id'] ?? 0);
$newStatus = trim($_POST['new_status']   ?? '');
if (!$id || !$newStatus) { echo json_encode(['success' => false, 'message' => 'payment_id and new_status required']); exit; }

$transitions = [
    'pending'  => ['reviewed'  => 'canReview'],
    'reviewed' => ['approved'  => 'canApprove'],
];

try {
    $cur = $pdo->prepare("SELECT status FROM supplier_payments WHERE payment_id = ?");
    $cur->execute([$id]);
    $current = $cur->fetchColumn();
    if ($current === false) { echo json_encode(['success' => false, 'message' => 'Payment not found']); exit; }

    if (!isset($transitions[$current][$newStatus])) {
        echo json_encode(['success' => false, 'message' => "Cannot change status from '$current' to '$newStatus'"]); exit;
    }

    $permFn = $transitions[$current][$newStatus];
    if (!$permFn('received_invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action']); exit;
    }

    $pdo->prepare("UPDATE supplier_payments SET status = ?, updated_at = NOW() WHERE payment_id = ?")
        ->execute([$newStatus, $id]);

    logActivity($pdo, $_SESSION['user_id'], "Supplier payment #$id status: $current → $newStatus");
    echo json_encode(['success' => true, 'message' => "Payment marked as $newStatus."]);

} catch (PDOException $e) {
    error_log('change_payment_status: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
