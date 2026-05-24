<?php
// File: api/approve_grn.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot
// AND fires the stock-receipt side-effect that the legacy create_grn used to do
// when status was 'completed' (three_approval.md §1 rule 6 compliance).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canApprove('grn')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to approve GRNs']);
    exit;
}

try {
    global $pdo;
    $receipt_id = isset($_POST['receipt_id']) ? intval($_POST['receipt_id']) : 0;
    if (!$receipt_id) throw new Exception("Invalid GRN ID");

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT receipt_id, receipt_number, status, warehouse_id, project_id, receipt_date
        FROM purchase_receipts
        WHERE receipt_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$receipt_id]);
    $grn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grn) throw new Exception("GRN not found");

    assertApprovable($grn['status']);

    $actor = workflowActorSnapshot();

    // Stamp approval audit
    $upd = $pdo->prepare("
        UPDATE purchase_receipts
        SET status            = 'approved',
            approved_by       = ?,
            approved_by_name  = ?,
            approved_by_role  = ?,
            approved_at       = NOW()
        WHERE receipt_id = ?
    ");
    $upd->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $receipt_id]);

    // ── Stock-receipt side-effect (preserved from legacy create_grn.php) ──
    // For every receipt line, update the global product stock, the
    // warehouse-specific product_stocks row, and append a stock_movements
    // audit entry. Service products and non-tracked items are skipped.
    $itemsStmt = $pdo->prepare("
        SELECT ri.product_id, ri.quantity_received AS qty,
               p.is_service, p.track_inventory
        FROM receipt_items ri
        LEFT JOIN products p ON ri.product_id = p.product_id
        WHERE ri.receipt_id = ?
    ");
    $itemsStmt->execute([$receipt_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $warehouse_id = (int)$grn['warehouse_id'];
    $project_id   = $grn['project_id'] ? (int)$grn['project_id'] : null;
    $reserve_qty_factor = $project_id ? 1 : 0; // reserve for project-bound GRNs

    $bumpProduct  = $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, stock_quantity = stock_quantity + ? WHERE product_id = ?");
    $checkStock   = $pdo->prepare("SELECT stock_id FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
    $updateStock  = $pdo->prepare("UPDATE product_stocks SET stock_quantity = IFNULL(stock_quantity, 0) + ?, reserved_quantity = IFNULL(reserved_quantity, 0) + ?, last_updated = NOW() WHERE stock_id = ?");
    $insertStock  = $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated) VALUES (?, ?, ?, ?, NOW())");
    $logMovement  = $pdo->prepare("INSERT INTO stock_movements (product_id, warehouse_id, project_id, movement_type, quantity, reference_id, reference_type, movement_date, created_by, notes) VALUES (?, ?, ?, 'in', ?, ?, 'grn', ?, ?, ?)");

    foreach ($items as $it) {
        $pid = (int)$it['product_id'];
        $qty = (float)$it['qty'];
        if ($pid <= 0 || $qty <= 0) continue;
        if (!empty($it['is_service'])) continue;
        $tracked = isset($it['track_inventory']) ? (bool)$it['track_inventory'] : true;
        if (!$tracked) continue;

        $reserve_qty = $reserve_qty_factor * $qty;

        $bumpProduct->execute([$qty, $qty, $pid]);
        $checkStock->execute([$pid, $warehouse_id]);
        $stockId = $checkStock->fetchColumn();
        if ($stockId) {
            $updateStock->execute([$qty, $reserve_qty, $stockId]);
        } else {
            $insertStock->execute([$pid, $warehouse_id, $qty, $reserve_qty]);
        }
        $logMovement->execute([
            $pid, $warehouse_id, $project_id, $qty, $receipt_id,
            $grn['receipt_date'], $_SESSION['user_id'], "GRN approved: " . $grn['receipt_number']
        ]);
    }

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Approved GRN #" . $grn['receipt_number']);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'GRN approved and stock updated.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
