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

    $stmt = $pdo->prepare("SELECT delivery_number, status, warehouse_id, dn_type, supplier_id, subcontractor_id, party_type, purchase_order_id, project_id FROM deliveries WHERE delivery_id = ? FOR UPDATE");
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

    // Auto-create GRN (pending) for inbound DNs so the receipt is pre-built for the accountant
    $auto_grn_ref = null;
    $auto_grn_id  = null;
    $dn_is_inbound = (($dn['dn_type'] ?? 'outbound') === 'inbound');
    if ($dn_is_inbound) {
        try {
            // Generate GRN receipt_number (GRN-YYYY-NNNN)
            $grn_year = date('Y');
            $maxGrn   = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(receipt_number,'-',-1) AS UNSIGNED)) FROM purchase_receipts WHERE receipt_number LIKE ?");
            $maxGrn->execute(["GRN-{$grn_year}-%"]);
            $maxNum     = (int)$maxGrn->fetchColumn();
            $grn_number = 'GRN-' . $grn_year . '-' . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);

            $grn_supplier_id = !empty($dn['supplier_id']) ? (int)$dn['supplier_id']
                             : (!empty($dn['subcontractor_id']) ? (int)$dn['subcontractor_id'] : null);

            $pdo->beginTransaction();

            $insGrn = $pdo->prepare("
                INSERT INTO purchase_receipts
                    (receipt_number, purchase_order_id, project_id, supplier_id, warehouse_id,
                     receipt_date, delivery_note, delivery_id, status, notes, received_by, created_by)
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, 'pending', ?, ?, ?)
            ");
            $insGrn->execute([
                $grn_number,
                $dn['purchase_order_id'] ?: null,
                $dn['project_id']        ?: null,
                $grn_supplier_id,
                (int)$dn['warehouse_id'],
                $dn['delivery_number'],
                $delivery_id,
                'Auto-created from DN approval: ' . $dn['delivery_number'],
                $_SESSION['user_id'],
                $_SESSION['user_id'],
            ]);
            $receipt_id = (int)$pdo->lastInsertId();

            // Copy items from delivery_items; pull unit_price from PO if linked
            $dnItems = $pdo->prepare("
                SELECT di.product_id, di.quantity_delivered AS qty, di.unit,
                       COALESCE(poi.unit_price, 0) AS unit_price,
                       COALESCE(poi.tax_rate, 0)   AS tax_rate
                FROM delivery_items di
                LEFT JOIN purchase_order_items poi
                    ON poi.purchase_order_id = ? AND poi.product_id = di.product_id
                WHERE di.delivery_id = ?
            ");
            $dnItems->execute([$dn['purchase_order_id'] ?: 0, $delivery_id]);

            $itemIns = $pdo->prepare("
                INSERT INTO receipt_items
                    (receipt_id, product_id, quantity_received, unit_price, tax_rate, tax_amount, unit)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($dnItems->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tax_amount = (float)$r['qty'] * (float)$r['unit_price'] * ((float)$r['tax_rate'] / 100);
                $itemIns->execute([
                    $receipt_id, (int)$r['product_id'], (float)$r['qty'],
                    (float)$r['unit_price'], (float)$r['tax_rate'], round($tax_amount, 2),
                    $r['unit'] ?: 'pcs',
                ]);
            }

            workflowCaptureSignature($pdo, 'grn', $receipt_id, 'created',
                (int)$_SESSION['user_id'], $actor['name'], $actor['role']);

            $pdo->commit();

            $auto_grn_ref = $grn_number;
            $auto_grn_id  = $receipt_id;

            if (function_exists('logActivity')) {
                logActivity($pdo, $_SESSION['user_id'],
                    "Auto-created GRN #{$grn_number} from DN approval (DN #{$dn['delivery_number']})");
            }
        } catch (Exception $eGrn) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Auto-GRN creation failed for DN #{$delivery_id}: " . $eGrn->getMessage());
        }
    }

    $response = ['success' => true, 'message' => 'Delivery Note approved.'];
    if ($auto_grn_ref) {
        $response['message']      = "Delivery Note approved. GRN #{$auto_grn_ref} created automatically (pending approval).";
        $response['auto_grn_ref'] = $auto_grn_ref;
        $response['auto_grn_id']  = $auto_grn_id;
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
