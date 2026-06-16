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
require_once __DIR__ . '/../core/purchase_posting.php';   // postGrnReceipt (OUT-7)

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

    // money.md OUT-7 — post the inventory receipt to the canonical ledger.
    // GRN approval = goods received from supplier on credit (no cash moves until
    // the supplier invoice is paid): Dr Inventory / Cr Accounts Payable. The
    // supplier payment (OUT-3) later clears the AP. Posted directly via
    // postGrnReceipt() (B-series pattern) instead of the disabled
    // journal_mappings gate, so Inventory actually reaches the GL. Best-effort:
    // a missed post never blocks the (physical) goods receipt — it surfaces as a
    // ledger warning. Value comes fresh from receipt_items (quantity_received ×
    // unit_price); the denormalised purchase_receipts.total_received is not trusted.
    $totStmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_received * unit_price), 0) AS grn_total
          FROM receipt_items
         WHERE receipt_id = ?
    ");
    $totStmt->execute([$receipt_id]);
    $grn_total = (float)$totStmt->fetchColumn();

    $post_result = postGrnReceipt(
        $pdo,
        (int)$receipt_id,
        $grn_total,
        $grn['receipt_date'],
        $project_id,
        (int)$_SESSION['user_id'],
        $grn['receipt_number']
    );

    if (function_exists('logActivity')) {
        $log_note = "Approved GRN #" . $grn['receipt_number'];
        if (!empty($post_result['posted']) && !empty($post_result['entry_id'])) {
            $verb = ($post_result['reason'] ?? '') === 'already_posted' ? 'already in ledger as entry' : 'journal entry';
            $log_note .= " ($verb #{$post_result['entry_id']})";
        }
        logActivity($pdo, $_SESSION['user_id'], $log_note);
    }

    $pdo->commit();

    $response = ['success' => true, 'message' => 'GRN approved and stock updated.'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    if (!empty($post_result['posted']) && !empty($post_result['entry_id'])) {
        $response['journal_entry_id'] = $post_result['entry_id'];
    } elseif (($post_result['reason'] ?? '') === 'accounts_not_configured') {
        $response['ledger_warning'] = "GRN approved, but no ledger entry was created — the Inventory or "
                                    . "Accounts Payable control account could not be resolved. Set them in Settings.";
    } elseif (($post_result['reason'] ?? '') === 'post_error') {
        $response['ledger_warning'] = "GRN approved, but the inventory ledger entry failed to post — see the server log.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
