<?php
// File: api/approve_dn.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot
// AND preserves the legacy stock-reservation side-effect for the delivery items.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';

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
            // movement_type must match the stock_movements ENUM
            // (no plain 'in' — use 'transfer_in' for an inbound DN which is
            // a warehouse-to-warehouse stock movement; purchases use the
            // GRN path with 'purchase_in' instead).
            $logMove     = $pdo->prepare("INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, movement_date, created_by, notes) VALUES (?, ?, 'transfer_in', ?, ?, 'dn', NOW(), ?, ?)");

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
                $logMove->execute([$pid, $dn['warehouse_id'], $qty, $delivery_id, $_SESSION['user_id'], "DN Approved: " . $dn['delivery_number']]);
            }
        } else {
            // Outbound: reserve stock
            $reserve = $pdo->prepare("UPDATE product_stocks SET reserved_quantity = reserved_quantity + ? WHERE product_id = ? AND warehouse_id = ?");
            foreach ($items as $it) {
                if (empty($it['product_id'])) continue;
                $reserve->execute([$it['quantity'], $it['product_id'], $dn['warehouse_id']]);
            }
        }
    }

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Approved Delivery Note #" . $dn['delivery_number']);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Delivery Note approved.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
