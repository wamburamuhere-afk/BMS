<?php
// File: api/review_dn.php
// Workflow transition: pending → reviewed. Stamps reviewed_by + audit snapshot.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canReview('dn')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to review delivery notes']);
    exit;
}

try {
    global $pdo;
    $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
    if (!$delivery_id) throw new Exception("Invalid Delivery Note ID");

    // Phase E — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('deliveries', 'delivery_id', $delivery_id);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT delivery_number, status FROM deliveries WHERE delivery_id = ? FOR UPDATE");
    $stmt->execute([$delivery_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn) throw new Exception("Delivery Note not found");

    assertReviewable($dn['status']);

    $actor = workflowActorSnapshot();

    $stmt = $pdo->prepare("
        UPDATE deliveries
        SET status            = 'reviewed',
            reviewed_by       = ?,
            reviewed_by_name  = ?,
            reviewed_by_role  = ?,
            reviewed_at       = NOW(),
            updated_by        = ?
        WHERE delivery_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $_SESSION['user_id'], $delivery_id]);

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Reviewed Delivery Note #" . $dn['delivery_number']);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Delivery Note marked as reviewed.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
