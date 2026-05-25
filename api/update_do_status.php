<?php
// File: api/update_do_status.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

if (!canEdit('do')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to change DO status']);
    exit;
}

try {
    $do_id   = intval($_POST['do_id']  ?? 0);
    $status  = trim($_POST['status']   ?? '');
    $user_id = $_SESSION['user_id'];
    $allowed = ['in_transit','delivered','cancelled'];

    if ($do_id <= 0)               throw new Exception('Invalid DO ID.');
    if (!in_array($status,$allowed)) throw new Exception('Invalid status.');

    // Phase C — block status changes against DOs on projects not in user scope
    assertScopeForRecord('delivery_orders', 'do_id', $do_id);

    // Load DO
    $stmt = $pdo->prepare("SELECT do.*, dn.delivery_number as dn_number FROM delivery_orders do LEFT JOIN deliveries dn ON do.dn_id = dn.delivery_id WHERE do.do_id = ?");
    $stmt->execute([$do_id]);
    $do = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$do) throw new Exception('Delivery Order not found.');
    if ($do['status'] === 'delivered')  throw new Exception('DO is already delivered.');
    if ($do['status'] === 'cancelled')  throw new Exception('DO is already cancelled.');

    // Load DN items for stock update
    $items = $pdo->prepare("SELECT product_id, quantity_delivered as quantity FROM delivery_items WHERE delivery_id = ?");
    $items->execute([$do['dn_id']]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    if ($status === 'delivered') {
        // Update DO status + timestamp
        $pdo->prepare("UPDATE delivery_orders SET status='delivered', delivered_at=NOW(), updated_by=? WHERE do_id=?")
            ->execute([$user_id, $do_id]);

        // Update DN status to delivered
        $pdo->prepare("UPDATE deliveries SET status='delivered' WHERE delivery_id=?")
            ->execute([$do['dn_id']]);

        // Deduct stock from product_stocks and release reservation
        $deduct = $pdo->prepare("
            UPDATE product_stocks
            SET stock_quantity    = stock_quantity - ?,
                reserved_quantity = GREATEST(0, reserved_quantity - ?)
            WHERE product_id = ? AND warehouse_id = ?
        ");

        // Record stock_movements (issue_out)
        $movement = $pdo->prepare("
            INSERT INTO stock_movements
                (product_id, movement_type, quantity, unit, unit_cost, total_cost,
                 reference_number, warehouse_id, project_id,
                 stock_before, stock_after, notes, created_by)
            SELECT
                ps.product_id, 'issue_out', ?, p.unit, p.cost_price, (? * p.cost_price),
                ?, ?, ?,
                ps.stock_quantity, (ps.stock_quantity - ?),
                CONCAT('DO #', ?, ' | DN #', ?),
                ?
            FROM product_stocks ps
            JOIN products p ON ps.product_id = p.product_id
            WHERE ps.product_id = ? AND ps.warehouse_id = ?
        ");

        foreach ($items as $item) {
            // Deduct
            $deduct->execute([$item['quantity'], $item['quantity'], $item['product_id'], $do['warehouse_id']]);
            // Record movement
            $movement->execute([
                $item['quantity'],                   // quantity
                $item['quantity'],                   // for total_cost
                $do['do_number'],                    // reference_number
                $do['warehouse_id'],                 // warehouse_id
                $do['project_id'],                   // project_id
                $item['quantity'],                   // stock_after calc
                $do['do_number'],                    // DO number in notes
                $do['dn_number'],                    // DN number in notes
                $user_id,                            // created_by
                $item['product_id'],                 // WHERE product_id
                $do['warehouse_id']                  // WHERE warehouse_id
            ]);
        }

        logActivity($pdo, $user_id, "Marked DO #{$do['do_number']} as delivered — stock deducted.");

    } elseif ($status === 'in_transit') {
        $pdo->prepare("UPDATE delivery_orders SET status='in_transit', updated_by=? WHERE do_id=?")
            ->execute([$user_id, $do_id]);
        logActivity($pdo, $user_id, "Marked DO #{$do['do_number']} as in transit.");

    } elseif ($status === 'cancelled') {
        $pdo->prepare("UPDATE delivery_orders SET status='cancelled', updated_by=? WHERE do_id=?")
            ->execute([$user_id, $do_id]);

        // Release reservation and revert DN to approved
        $release = $pdo->prepare("
            UPDATE product_stocks
            SET reserved_quantity = GREATEST(0, reserved_quantity - ?)
            WHERE product_id = ? AND warehouse_id = ?
        ");
        foreach ($items as $item) {
            $release->execute([$item['quantity'], $item['product_id'], $do['warehouse_id']]);
        }
        $pdo->prepare("UPDATE deliveries SET status='approved' WHERE delivery_id=?")->execute([$do['dn_id']]);
        logActivity($pdo, $user_id, "Cancelled DO #{$do['do_number']} — reservation released.");
    }

    $pdo->commit();
    echo json_encode(['success'=>true, 'message'=>"Delivery Order status updated to " . strtoupper(str_replace('_',' ',$status)) . "."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
