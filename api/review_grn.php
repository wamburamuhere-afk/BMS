<?php
// File: api/review_grn.php
// Workflow transition: pending → reviewed. Stamps reviewed_by + audit snapshot.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canReview('grn')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to review GRNs']);
    exit;
}

try {
    global $pdo;
    $receipt_id = isset($_POST['receipt_id']) ? intval($_POST['receipt_id']) : 0;
    if (!$receipt_id) throw new Exception("Invalid GRN ID");

    // Phase E — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('purchase_receipts', 'receipt_id', $receipt_id);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT receipt_number, status FROM purchase_receipts WHERE receipt_id = ? FOR UPDATE");
    $stmt->execute([$receipt_id]);
    $grn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grn) throw new Exception("GRN not found");

    assertReviewable($grn['status']);

    $actor = workflowActorSnapshot();

    $stmt = $pdo->prepare("
        UPDATE purchase_receipts
        SET status            = 'reviewed',
            reviewed_by       = ?,
            reviewed_by_name  = ?,
            reviewed_by_role  = ?,
            reviewed_at       = NOW()
        WHERE receipt_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $receipt_id]);

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Reviewed GRN #" . $grn['receipt_number']);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'GRN marked as reviewed.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
