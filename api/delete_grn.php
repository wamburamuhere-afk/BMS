<?php
/**
 * API: Delete GRN
 */
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('grn')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete GRNs']);
    exit;
}

try {
    $receipt_id = intval($_POST['receipt_id'] ?? 0);

    if ($receipt_id <= 0) {
        throw new Exception('Invalid GRN ID');
    }

    // Phase C — block deletes against GRNs on projects not in user scope.
    // GRN inherits its project_id from purchase_receipts.project_id directly.
    assertScopeForRecord('purchase_receipts', 'receipt_id', $receipt_id);

    // Get GRN info
    $stmt = $pdo->prepare("SELECT receipt_number, status FROM purchase_receipts WHERE receipt_id = ?");
    $stmt->execute([$receipt_id]);
    $grn = $stmt->fetch();

    if (!$grn) {
        throw new Exception('GRN not found');
    }

    // If completed, we should ideally reverse stock before deleting, 
    // but usually deleting a completed GRN is restricted or should reverse stock.
    // For now, let's allow it but log it clearly.
    
    $pdo->beginTransaction();

    // Delete items first
    $stmtItems = $pdo->prepare("DELETE FROM receipt_items WHERE receipt_id = ?");
    $stmtItems->execute([$receipt_id]);

    // Delete GRN
    $stmtDel = $pdo->prepare("DELETE FROM purchase_receipts WHERE receipt_id = ?");
    $stmtDel->execute([$receipt_id]);

    $pdo->commit();

    // Audit trail (rich) + Activity Log feed (visible on activity_log.php).
    logAudit($pdo, $_SESSION['user_id'], "delete", [
        'activity_type' => 'delete',
        'entity_type' => 'grn',
        'entity_id' => $receipt_id,
        'description' => "Deleted Goods Received Note #{$grn['receipt_number']} (Previous status: {$grn['status']})"
    ]);
    logActivity($pdo, $_SESSION['user_id'], 'Delete grn',
        "deleted GRN #{$grn['receipt_number']} with id {$receipt_id} (was {$grn['status']})");

    echo json_encode(['success' => true, 'message' => "GRN #{$grn['receipt_number']} deleted successfully"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
