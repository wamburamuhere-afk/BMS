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

try {
    $receipt_id = intval($_POST['receipt_id'] ?? 0);

    if ($receipt_id <= 0) {
        throw new Exception('Invalid GRN ID');
    }

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

    // Log Audit
    logAudit($pdo, $_SESSION['user_id'], "delete", [
        'activity_type' => 'delete',
        'entity_type' => 'grn',
        'entity_id' => $receipt_id,
        'description' => "Deleted Goods Received Note #{$grn['receipt_number']} (Previous status: {$grn['status']})"
    ]);

    echo json_encode(['success' => true, 'message' => "GRN #{$grn['receipt_number']} deleted successfully"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
