<?php
// File: api/account/review_invoice.php
// Workflow transition: pending → reviewed. Stamps reviewed_by + audit snapshot.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canReview('invoices')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to review invoices']);
    exit;
}

try {
    global $pdo;
    $invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
    if (!$invoice_id) throw new Exception("Invalid Invoice ID");

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT status FROM invoices WHERE invoice_id = ? FOR UPDATE");
    $stmt->execute([$invoice_id]);
    $current_status = $stmt->fetchColumn();
    if ($current_status === false) throw new Exception("Invoice not found");

    assertReviewable($current_status);

    $actor = workflowActorSnapshot();

    $stmt = $pdo->prepare("
        UPDATE invoices
        SET status            = 'reviewed',
            reviewed_by       = ?,
            reviewed_by_name  = ?,
            reviewed_by_role  = ?,
            reviewed_at       = NOW(),
            updated_by        = ?,
            updated_at        = NOW()
        WHERE invoice_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $_SESSION['user_id'], $invoice_id]);

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Reviewed Invoice #$invoice_id");
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Invoice marked as reviewed.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
