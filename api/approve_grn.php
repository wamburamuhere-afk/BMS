<?php
// File: api/approve_grn.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot
// AND fires the stock-receipt side-effect that the legacy create_grn used to do
// when status was 'completed' (three_approval.md §1 rule 6 compliance).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';
require_once __DIR__ . '/../core/auto_post_hook.php';
require_once __DIR__ . '/../core/stock_ledger.php';

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

    // Phase E — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('purchase_receipts', 'receipt_id', $receipt_id);
    }

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
               ri.batch_number, ri.expiry_date, ri.unit_price, ri.serial_numbers,
               p.is_service, p.track_inventory, p.track_serials
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
    // Phase 17 (pos_upgrade_plan.md §8) — real batch/lot stock ledger, fed
    // right here at the moment stock genuinely enters the warehouse (not at
    // GRN creation, which is still 'pending' and may never be approved).
    // Sparse: a line with neither batch_number nor expiry_date gets no row —
    // that product keeps behaving exactly as it did before this phase.
    $insertBatch  = $pdo->prepare("
        INSERT INTO product_batches (product_id, warehouse_id, batch_number, expiry_date, quantity_received, quantity_remaining, unit_cost, receipt_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    // Phase 26 (pos_upgrade_plan.md §9) — one product_serials row per
    // physical unit, written only for track_serials=1 products, at the
    // moment stock genuinely enters the warehouse (same "stock arrives on
    // approval, not creation" rule Phase 17 established for batches).
    $insertSerial = $pdo->prepare("
        INSERT IGNORE INTO product_serials (product_id, warehouse_id, serial_number, status, receipt_id, created_at)
        VALUES (?, ?, ?, 'in_stock', ?, NOW())
    ");
    // stock_movements has two strict ENUMs:
    //   movement_type  must be one of: purchase_in, sale_out, adjustment_in, adjustment_out, transfer_in, transfer_out, return_in, return_out, production_in, production_out, damaged, expired, found, theft, correction, issue_out
    //   reference_type must be one of: purchase_order, sales_order, pos_sale, invoice, stock_adjustment, stock_transfer, return, production_order, manual
    // GRN approval is "stock arriving from a purchase order", so use:
    //   movement_type='purchase_in', reference_type='purchase_order'.
    // Using literals outside the ENUMs causes MySQL to silently truncate
    // and raise SQLSTATE[01000] 1265, rolling back the whole approve.
    // Movement rows are now written via recordStockMovement() so that value,
    // reference_number and running balance are always populated (core/stock_ledger.php).

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
        recordStockMovement($pdo, [
            'product_id'       => $pid,
            'warehouse_id'     => $warehouse_id,
            'project_id'       => $project_id,
            'movement_type'    => 'purchase_in',
            'quantity'         => $qty,
            'reference_id'     => $receipt_id,
            'reference_type'   => 'purchase_order',
            'reference_number' => $grn['receipt_number'],
            'movement_date'    => $grn['receipt_date'],
            'created_by'       => $_SESSION['user_id'],
            'notes'            => "GRN approved: " . $grn['receipt_number'],
        ]);

        // Phase 17 — only when the line actually specified a batch or expiry.
        $batchNumber = trim((string)($it['batch_number'] ?? ''));
        $expiryDate  = $it['expiry_date'] ?? null;
        if ($batchNumber !== '' || !empty($expiryDate)) {
            $insertBatch->execute([
                $pid, $warehouse_id,
                $batchNumber !== '' ? $batchNumber : null,
                !empty($expiryDate) ? $expiryDate : null,
                $qty, $qty,
                (float)($it['unit_price'] ?? 0),
                $receipt_id,
            ]);
        }

        // Phase 26 — only for a track_serials=1 product with serial numbers
        // actually entered on this line. Lenient by design: whatever count of
        // valid, non-empty, de-duplicated serials was entered is written —
        // this does not block approval on a count mismatch against qty
        // (an operator can still add the rest later via a future GRN or a
        // manual adjustment), consistent with GRN approval never blocking on
        // batch/expiry sparsity either.
        if (!empty($it['track_serials']) && !empty($it['serial_numbers'])) {
            $raw = (string)$it['serial_numbers'];
            $serials = preg_split('/[\r\n,]+/', $raw);
            $serials = array_values(array_unique(array_filter(array_map('trim', $serials), fn($s) => $s !== '')));
            foreach ($serials as $sn) {
                $insertSerial->execute([$pid, $warehouse_id, $sn, $receipt_id]);
            }
        }
    }

    $sigResult = workflowCaptureSignature($pdo, 'grn', $receipt_id, 'approved',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    // ── Update PO and DN status based on quantities received ─────────────────
    $hdrStmt = $pdo->prepare("SELECT purchase_order_id, delivery_id FROM purchase_receipts WHERE receipt_id = ?");
    $hdrStmt->execute([$receipt_id]);
    $hdr = $hdrStmt->fetch(PDO::FETCH_ASSOC);

    // PO: compare total ordered qty vs total received across all approved GRNs
    if (!empty($hdr['purchase_order_id'])) {
        $po_id = (int)$hdr['purchase_order_id'];

        $ordQty = (float)$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$po_id]) ? 0 : 0;
        $stOrd  = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM purchase_order_items WHERE purchase_order_id = ?");
        $stOrd->execute([$po_id]);
        $ordQty = (float)$stOrd->fetchColumn();

        $stRec  = $pdo->prepare("
            SELECT COALESCE(SUM(ri.quantity_received),0)
            FROM receipt_items ri
            JOIN purchase_receipts pr ON ri.receipt_id = pr.receipt_id
            WHERE pr.purchase_order_id = ? AND pr.status = 'approved'
        ");
        $stRec->execute([$po_id]);
        $recQty = (float)$stRec->fetchColumn();

        if ($ordQty > 0) {
            $newPoStatus = ($recQty >= $ordQty) ? 'received' : 'partially_received';
            $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE purchase_order_id = ? AND status NOT IN ('cancelled','rejected')")
                ->execute([$newPoStatus, $po_id]);
        }
    }

    // DN: compare total DN qty vs total received across all approved GRNs for this DN
    if (!empty($hdr['delivery_id'])) {
        $dn_id = (int)$hdr['delivery_id'];

        $stDnQty = $pdo->prepare("SELECT COALESCE(SUM(quantity_delivered),0) FROM delivery_items WHERE delivery_id = ?");
        $stDnQty->execute([$dn_id]);
        $dnQty = (float)$stDnQty->fetchColumn();

        $stDnRec = $pdo->prepare("
            SELECT COALESCE(SUM(ri.quantity_received),0)
            FROM receipt_items ri
            JOIN purchase_receipts pr ON ri.receipt_id = pr.receipt_id
            WHERE pr.delivery_id = ? AND pr.status = 'approved'
        ");
        $stDnRec->execute([$dn_id]);
        $dnRec = (float)$stDnRec->fetchColumn();

        if ($dnQty > 0) {
            $newDnStatus = ($dnRec >= $dnQty) ? 'delivered' : 'partially_delivered';
            $pdo->prepare("UPDATE deliveries SET status = ? WHERE delivery_id = ? AND status NOT IN ('cancelled')")
                ->execute([$newDnStatus, $dn_id]);
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    // money.md OUT-7 policy change — GRN approval no longer posts to the GL.
    // It only ever recognised goods received before the supplier invoice
    // existed; the payable now posts at INVOICE-APPROVAL time instead, via
    // postGoodsInvoiceAccrual() in api/received_invoices.php (with an
    // amount-based guard against any PO whose GRN already posted under the
    // old rule). GRN approval here still only moves physical stock.
    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Approved GRN #" . $grn['receipt_number']);
    }

    $pdo->commit();

    $response = ['success' => true, 'message' => 'GRN approved and stock updated.'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
