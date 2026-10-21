<?php
// api/account/revert_quotation_to_pending.php
//
// Workflow rollback: reviewed|approved|cancelled → pending for a Quotation.
// Clears review/approval stamps and removes the workflow_signatures rows
// (entity_type = 'quotation') so the audit panel starts clean.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: only Admins can revert quotations']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$qid = (int)($_POST['quotation_id'] ?? 0);
if ($qid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Quotation ID']);
    exit;
}

try {
    global $pdo;

    assertScopeForRecord('quotations', 'sales_order_id', $qid);

    $pdo->beginTransaction();

    $row = $pdo->prepare("SELECT status, order_number FROM quotations WHERE sales_order_id = ? FOR UPDATE");
    $row->execute([$qid]);
    $q = $row->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quotation not found.');

    $current = $q['status'];
    if (!in_array($current, ['reviewed', 'approved', 'cancelled'], true)) {
        throw new Exception("Only Reviewed, Approved or Cancelled quotations can be sent back to Pending (current: $current).");
    }

    $uid = (int)$_SESSION['user_id'];

    // quotations table has reviewed_by/reviewed_at/approved_by/approved_at
    // but NOT the _name/_role columns (those live on sales_orders only).
    $pdo->prepare("
        UPDATE quotations
        SET status       = 'pending',
            reviewed_by  = NULL,
            reviewed_at  = NULL,
            approved_by  = NULL,
            approved_at  = NULL,
            updated_by   = ?,
            updated_at   = NOW()
        WHERE sales_order_id = ?
    ")->execute([$uid, $qid]);

    $pdo->prepare(
        "DELETE FROM workflow_signatures
         WHERE entity_type = 'quotation' AND entity_id = ? AND action IN ('reviewed','approved')"
    )->execute([$qid]);

    $pdo->commit();

    $num = $q['order_number'];
    logActivity($pdo, $uid, "Reverted Quotation $num (ID $qid) from $current back to Pending");
    if (function_exists('logAudit')) {
        logAudit($pdo, $uid, 'quotation_reverted_to_pending', [
            'entity_type' => 'quotation', 'entity_id' => $qid,
            'old_values'  => ['status' => $current],
            'new_values'  => ['status' => 'pending'],
        ]);
    }

    echo json_encode(['success' => true, 'message' => "Quotation $num sent back to Pending."]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('revert_quotation_to_pending error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
