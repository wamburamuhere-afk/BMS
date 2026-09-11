<?php
// scope-audit: skip — acts on one POS sale by primary key under canDelete('pos'); POS project scope deferred (see pos.php)
/**
 * API: Void a POS sale
 * ----------------------------------------------------------------------------
 * Reverses a completed POS sale in full: restores stock, refunds the cash drawer
 * (for cash sales, against the operator's active shift), and marks the sale
 * `voided`. A voided sale is treated as if it never happened — it is excluded
 * from the Income Statement automatically (the P&L only recognises non-voided
 * sales). Use this to reverse a mistaken or test sale.
 *
 * Not a return/refund-of-goods workflow — that is api/pos/create_return.php.
 *
 * POST (form-encoded): sale_id, reason
 * Permission: canDelete('pos')
 */
require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/stock_ledger.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_batch_consumption.php';
require_once __DIR__ . '/../../core/pos_serial_tracking.php';
require_once __DIR__ . '/../../core/pos_combo_products.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canDelete('pos'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => t('You do not have permission to void POS sales')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

$sale_id = (int)($_POST['sale_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');
if ($sale_id <= 0)   { echo json_encode(['success' => false, 'message' => t('Invalid sale.')]); exit; }
if ($reason === '')  { echo json_encode(['success' => false, 'message' => t('A void reason is required.')]); exit; }

try {
    global $pdo;
    $pdo->beginTransaction();

    // Lock + validate the sale.
    $st = $pdo->prepare("SELECT * FROM pos_sales WHERE sale_id = ? FOR UPDATE");
    $st->execute([$sale_id]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale)                              { throw new Exception('Sale not found.'); }
    if ((int)$sale['is_return_sale'] === 1)  { throw new Exception('A return transaction cannot be voided.'); }
    if ($sale['sale_status'] === 'voided')   { throw new Exception('This sale is already voided.'); }
    if ($sale['sale_status'] !== 'completed'){ throw new Exception('Only completed sales can be voided (status: ' . $sale['sale_status'] . ').'); }

    $warehouse_id = $sale['warehouse_id'] !== null && $sale['warehouse_id'] !== '' ? (int)$sale['warehouse_id'] : null;
    $project_id   = $sale['project_id']   !== null && $sale['project_id']   !== '' ? (int)$sale['project_id']   : null;

    if ($warehouse_id !== null && !userCan('warehouse', $warehouse_id)) {
        throw new Exception('Access denied: this warehouse is not in your assigned scope.');
    }

    // Reverse every line back into stock (mirrors process_sale.php in reverse).
    $items = $pdo->prepare("SELECT psi.*, p.is_service, p.is_combo
                              FROM pos_sale_items psi
                         LEFT JOIN products p ON p.product_id = psi.product_id
                             WHERE psi.sale_id = ?");
    $items->execute([$sale_id]);
    $lines = $items->fetchAll(PDO::FETCH_ASSOC);

    $restoreGlobal = $pdo->prepare("UPDATE products
                                       SET stock_quantity = stock_quantity + ?, current_stock = current_stock + ?
                                     WHERE product_id = ?");
    $restoreWh = $pdo->prepare("UPDATE product_stocks
                                   SET stock_quantity = IFNULL(stock_quantity,0) + ?
                                 WHERE product_id = ? AND warehouse_id = ?");

    foreach ($lines as $ln) {
        if ((int)($ln['is_service'] ?? 0) === 1) continue;   // services hold no stock
        $pid = (int)$ln['product_id'];
        $qty = (float)$ln['quantity'];
        if ($pid <= 0 || $qty <= 0) continue;

        // Phase 23 (pos_upgrade_plan.md §8) — a combo line restores its
        // COMPONENTS, not itself (it never had its own stock decremented).
        if (!empty($ln['is_combo'])) {
            if ($warehouse_id !== null) {
                reverseComboComponents($pdo, $pid, $qty, $warehouse_id, $project_id, $sale_id, $sale['receipt_number'], $_SESSION['user_id']);
            }
            continue;
        }

        $restoreGlobal->execute([$qty, $qty, $pid]);
        if ($warehouse_id !== null) $restoreWh->execute([$qty, $pid, $warehouse_id]);

        recordStockMovement($pdo, [
            'product_id'       => $pid,
            'warehouse_id'     => $warehouse_id,
            'project_id'       => $project_id,
            'movement_type'    => 'return_in',
            'quantity'         => $qty,
            'reference_id'     => $sale_id,
            'reference_type'   => 'pos_void',
            'reference_number' => $sale['receipt_number'],
            'created_by'       => $_SESSION['user_id'],
            'notes'            => 'Void of POS Sale #' . $sale['receipt_number'] . ' — ' . $reason,
        ]);

        // Phase 17b (pos_upgrade_plan.md §8) — restore into the EXACT batch(es)
        // this line originally drew from (FEFO), not just a generic quantity
        // bump. No-op for a non-batch-tracked line (no pos_sale_item_batches rows).
        reverseFefoBatchConsumption($pdo, (int)$ln['sale_item_id']);

        // Phase 26 (pos_upgrade_plan.md §9) — restore every serial this line
        // sold back to in_stock. No-op for a non-serial-tracked line (no
        // pos_sale_item_serials rows).
        reverseSerialsForSaleItem($pdo, (int)$ln['sale_item_id']);
    }

    // Refund the cash drawer for cash sales, against the operator's active shift.
    if ($sale['payment_method'] === 'cash') {
        $shiftStmt = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active' LIMIT 1");
        $shiftStmt->execute([$_SESSION['user_id']]);
        $active_shift = $shiftStmt->fetchColumn();
        if ($active_shift) {
            $pdo->prepare("INSERT INTO cash_register_transactions
                              (shift_id, transaction_type, amount, payment_method, reference_number, sale_id, reason, created_by, created_at)
                           VALUES (?, 'refund', ?, 'cash', ?, ?, ?, ?, NOW())")
                ->execute([$active_shift, (float)$sale['grand_total'], $sale['receipt_number'], $sale_id, 'Void: ' . $reason, $_SESSION['user_id']]);
        }
    }

    // Mark voided.
    $pdo->prepare("UPDATE pos_sales
                      SET sale_status = 'voided', voided_at = NOW(), voided_by = ?, void_reason = ?, updated_at = NOW()
                    WHERE sale_id = ?")
        ->execute([$_SESSION['user_id'], $reason, $sale_id]);

    // Reverse the sale's ledger postings (revenue + COGS), if any were posted by
    // postPosSale() at sale time. Mirrors create_return.php's use of the same
    // generic reverser; keyed on '<entity>_void' so it can never collide with a
    // genuine return (postPosReturn uses 'pos_return'/'pos_return_cogs').
    // Best-effort: never blocks the void — a voided sale must always void even if
    // accounting can't post, exactly like the original sale could always complete.
    require_once __DIR__ . '/../../core/expense_posting.php';
    $glRevenue = reverseAccrualEntry($pdo, 'pos_sale', $sale_id, (int)$_SESSION['user_id']);
    $glCogs    = reverseAccrualEntry($pdo, 'pos_cogs',  $sale_id, (int)$_SESSION['user_id']);
    if (!empty($glRevenue['reason']) && !in_array($glRevenue['reason'], ['reversed', 'already_reversed', 'no_accrual'], true)) {
        logActivity($pdo, $_SESSION['user_id'], 'POS Void GL warning',
            "Void of Sale #{$sale['receipt_number']} (id $sale_id) did NOT reverse the ledger: " . $glRevenue['reason']);
    }

    // Phase 11 (pos_upgrade_plan.md §7) — reverse any loyalty points this sale
    // earned or redeemed. A void is "as if the sale never happened", exactly
    // like the stock/cash/GL reversal above — a voided sale must not leave a
    // customer either richer or poorer in points. Idempotent.
    require_once __DIR__ . '/../../core/pos_loyalty.php';
    reverseLoyaltyForSale($pdo, $sale_id, (int)$_SESSION['user_id']);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Voided POS Sale #{$sale['receipt_number']} (" . number_format((float)$sale['grand_total'], 2) . ")");
    logAudit($pdo, $_SESSION['user_id'], 'pos_void', [
        'entity_type' => 'pos_sale',
        'entity_id'   => $sale_id,
        'old_values'  => ['sale_status' => 'completed'],
        'new_values'  => ['sale_status' => 'voided', 'void_reason' => $reason],
    ]);

    echo json_encode(['success' => true, 'message' => t('Sale voided. Stock and cash have been reversed.')]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('void_sale: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
