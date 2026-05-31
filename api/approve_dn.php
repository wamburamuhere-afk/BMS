<?php
// File: api/approve_dn.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot
// AND preserves the legacy stock-reservation side-effect for the delivery items.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';
require_once __DIR__ . '/../core/stock_ledger.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canApprove('dn')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to approve delivery notes']);
    exit;
}

try {
    global $pdo;
    $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
    if (!$delivery_id) throw new Exception("Invalid Delivery Note ID");

    // Phase C — block approvals against DNs on projects not in user scope
    assertScopeForRecord('deliveries', 'delivery_id', $delivery_id);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT delivery_number, status, warehouse_id, dn_type FROM deliveries WHERE delivery_id = ? FOR UPDATE");
    $stmt->execute([$delivery_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn) throw new Exception("Delivery Note not found");

    assertApprovable($dn['status']);

    $actor = workflowActorSnapshot();

    // Stamp approval audit
    $upd = $pdo->prepare("
        UPDATE deliveries
        SET status            = 'approved',
            approved_by       = ?,
            approved_by_name  = ?,
            approved_by_role  = ?,
            approved_at       = NOW(),
            updated_by        = ?
        WHERE delivery_id = ?
    ");
    $upd->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $_SESSION['user_id'], $delivery_id]);

    // Preserve legacy automatic side-effects on approval (three_approval.md §1
    // rule 6: "Any automatic side-effect that the document already triggers …
    // continues to run."):
    //
    //   - OUTBOUND DN: reserve stock in the source warehouse for every line.
    //   - INBOUND DN: add stock to the destination warehouse (used to fire from
    //     create_dn.php when status was 'approved'; that path is now disabled,
    //     so the side-effect moves here to the canonical approval transition).
    if (!empty($dn['warehouse_id'])) {
        $items = $pdo->prepare("
            SELECT product_id, quantity_delivered AS quantity
            FROM delivery_items
            WHERE delivery_id = ?
        ");
        $items->execute([$delivery_id]);
        $items = $items->fetchAll(PDO::FETCH_ASSOC);

        $isInbound = (($dn['dn_type'] ?? 'outbound') === 'inbound');

        if ($isInbound) {
            $bumpProduct = $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, stock_quantity = stock_quantity + ? WHERE product_id = ?");
            $checkStock  = $pdo->prepare("SELECT stock_id FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
            $bumpStock   = $pdo->prepare("UPDATE product_stocks SET stock_quantity = stock_quantity + ?, last_updated = NOW() WHERE stock_id = ?");
            $insertStock = $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, last_updated) VALUES (?, ?, ?, NOW())");
            // Both movement_type and reference_type must match their
            // respective stock_movements ENUMs (no plain 'in' / 'dn').
            // 'transfer_in' + 'stock_transfer' = inbound DN moves stock
            // between warehouses. The DN identity is preserved in the
            // notes column and in reference_id; purchases use the GRN
            // path with 'purchase_in' + 'purchase_order' instead.
            foreach ($items as $it) {
                if (empty($it['product_id'])) continue;
                $pid = (int)$it['product_id'];
                $qty = (float)$it['quantity'];
                $bumpProduct->execute([$qty, $qty, $pid]);
                $checkStock->execute([$pid, $dn['warehouse_id']]);
                $stockId = $checkStock->fetchColumn();
                if ($stockId) {
                    $bumpStock->execute([$qty, $stockId]);
                } else {
                    $insertStock->execute([$pid, $dn['warehouse_id'], $qty]);
                }
                recordStockMovement($pdo, [
                    'product_id'       => $pid,
                    'warehouse_id'     => $dn['warehouse_id'],
                    'movement_type'    => 'transfer_in',
                    'quantity'         => $qty,
                    'reference_id'     => $delivery_id,
                    'reference_type'   => 'stock_transfer',
                    'reference_number' => $dn['delivery_number'],
                    'created_by'       => $_SESSION['user_id'],
                    'notes'            => "DN Approved: " . $dn['delivery_number'],
                ]);
            }
        } else {
            // Outbound (Option A): goods physically leave the source warehouse on
            // approval — decrement actual stock (releasing any reservation) and
            // log a sale_out movement so the sale is visible in the ledger.
            $bumpProductOut = $pdo->prepare("UPDATE products SET current_stock = current_stock - ?, stock_quantity = stock_quantity - ? WHERE product_id = ?");
            $bumpStockOut   = $pdo->prepare("UPDATE product_stocks SET stock_quantity = IFNULL(stock_quantity,0) - ?, reserved_quantity = GREATEST(0, IFNULL(reserved_quantity,0) - ?), last_updated = NOW() WHERE product_id = ? AND warehouse_id = ?");
            foreach ($items as $it) {
                if (empty($it['product_id'])) continue;
                $pid = (int)$it['product_id'];
                $qty = (float)$it['quantity'];
                $bumpProductOut->execute([$qty, $qty, $pid]);
                $bumpStockOut->execute([$qty, $qty, $pid, $dn['warehouse_id']]);
                recordStockMovement($pdo, [
                    'product_id'       => $pid,
                    'warehouse_id'     => $dn['warehouse_id'],
                    'movement_type'    => 'sale_out',
                    'quantity'         => $qty,
                    'reference_id'     => $delivery_id,
                    'reference_type'   => 'sales_order',
                    'reference_number' => $dn['delivery_number'],
                    'created_by'       => $_SESSION['user_id'],
                    'notes'            => "DN Approved (outbound): " . $dn['delivery_number'],
                ]);
            }
        }
    }

    workflowCaptureSignature($pdo, 'delivery', $delivery_id, 'approved',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Approved Delivery Note #" . $dn['delivery_number']);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Delivery Note approved.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
