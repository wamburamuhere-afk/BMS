<?php
/**
 * API: Update GRN Status
 */
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('grn')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change GRN status']);
    exit;
}

try {
    $receipt_id = intval($_POST['receipt_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if ($receipt_id <= 0 || !in_array($status, ['cancelled', 'pending'])) {
        throw new Exception('Invalid parameters');
    }

    // Phase C — block status changes against GRNs on projects not in user scope
    assertScopeForRecord('purchase_receipts', 'receipt_id', $receipt_id);

    // Get GRN info for logging
    $stmt = $pdo->prepare("SELECT receipt_number, status FROM purchase_receipts WHERE receipt_id = ?");
    $stmt->execute([$receipt_id]);
    $grn = $stmt->fetch();

    if (!$grn) {
        throw new Exception('GRN not found');
    }

    $pdo->beginTransaction();

    // Update status
    $stmtUpdate = $pdo->prepare("UPDATE purchase_receipts SET status = ? WHERE receipt_id = ?");
    $stmtUpdate->execute([$status, $receipt_id]);

    // If completed, we should ideally update stock (if it wasn't already updated)
    // However, create_grn.php handles stock if it's created as 'completed'.
    // If it was 'pending' or 'draft' and now 'completed', we need to move stock.
    if ($status === 'completed' && $grn['status'] !== 'completed') {
        // Fetch items to update stock
        $stmtItems = $pdo->prepare("SELECT product_id, quantity_received, unit_price FROM receipt_items WHERE receipt_id = ?");
        $stmtItems->execute([$receipt_id]);
        $items = $stmtItems->fetchAll();

        // Get warehouse
        $stmtWH = $pdo->prepare("SELECT warehouse_id, receipt_date, receipt_number FROM purchase_receipts WHERE receipt_id = ?");
        $stmtWH->execute([$receipt_id]);
        $wh = $stmtWH->fetch();

        foreach ($items as $item) {
            // Update product
            $stmtProd = $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, stock_quantity = stock_quantity + ? WHERE product_id = ?");
            $stmtProd->execute([$item['quantity_received'], $item['quantity_received'], $item['product_id']]);

            // Movement — strict ENUMs (see api/approve_grn.php for the full list).
            // Must use 'purchase_in' + 'purchase_order'; 'in' / 'grn' would be
            // silently truncated by MySQL and roll back the whole transaction.
            $stmtMove = $pdo->prepare("
                INSERT INTO stock_movements (
                    product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, movement_date, created_by, notes
                ) VALUES (?, ?, 'purchase_in', ?, ?, 'purchase_order', ?, ?, ?)
            ");
            $stmtMove->execute([
                $item['product_id'], $wh['warehouse_id'], $item['quantity_received'], $receipt_id, $wh['receipt_date'], $_SESSION['user_id'], "GRN Status Update: " . $wh['receipt_number']
            ]);
        }
    }

    $pdo->commit();

    // Log Audit
    logAudit($pdo, $_SESSION['user_id'], "update_status", [
        'activity_type' => 'update',
        'entity_type' => 'grn',
        'entity_id' => $receipt_id,
        'description' => "Updated GRN #{$grn['receipt_number']} status from {$grn['status']} to $status"
    ]);

    echo json_encode(['success' => true, 'message' => "GRN status updated to $status"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
