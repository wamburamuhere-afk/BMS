<?php
// api/account/revert_sales_order_to_pending.php
//
// Workflow rollback: reviewed|approved|cancelled → pending.
// No GL to reverse — sales orders do not post to the ledger.
// Reviewed and approved stamps are cleared; the Created By signature stays.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('sales_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to revert sales orders']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$so_id = (int)($_POST['sales_order_id'] ?? 0);
if ($so_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Sales Order ID']);
    exit;
}

try {
    global $pdo;

    assertScopeForRecord('sales_orders', 'sales_order_id', $so_id);

    $pdo->beginTransaction();

    $row = $pdo->prepare("SELECT status, order_number FROM sales_orders WHERE sales_order_id = ? FOR UPDATE");
    $row->execute([$so_id]);
    $so = $row->fetch(PDO::FETCH_ASSOC);
    if (!$so) throw new Exception('Sales Order not found.');

    $current = $so['status'];
    if (!in_array($current, ['reviewed', 'approved', 'cancelled'], true)) {
        throw new Exception("Only Reviewed, Approved or Cancelled orders can be sent back to Pending (current: $current).");
    }

    $uid = (int)$_SESSION['user_id'];

    // Clear both workflow stamps; Created By columns are never touched.
    $pdo->prepare("
        UPDATE sales_orders
        SET status            = 'pending',
            reviewed_by       = NULL,
            reviewed_by_name  = NULL,
            reviewed_by_role  = NULL,
            reviewed_at       = NULL,
            approved_by       = NULL,
            approved_by_name  = NULL,
            approved_by_role  = NULL,
            approved_at       = NULL,
            updated_by        = ?,
            updated_at        = NOW()
        WHERE sales_order_id = ?
    ")->execute([$uid, $so_id]);

    // Remove review/approved e-signature rows so the audit panel starts clean.
    $pdo->prepare(
        "DELETE FROM workflow_signatures
         WHERE entity_type = 'sales_order' AND entity_id = ? AND action IN ('reviewed','approved')"
    )->execute([$so_id]);

    $pdo->commit();

    $num = $so['order_number'];
    logActivity($pdo, $uid, "Reverted Sales Order $num (ID $so_id) from $current back to Pending");
    if (function_exists('logAudit')) {
        logAudit($pdo, $uid, 'sales_order_reverted_to_pending', [
            'entity_type' => 'sales_order', 'entity_id' => $so_id,
            'old_values'  => ['status' => $current],
            'new_values'  => ['status' => 'pending'],
        ]);
    }

    echo json_encode(['success' => true, 'message' => "Sales Order $num sent back to Pending."]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('revert_sales_order_to_pending error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
