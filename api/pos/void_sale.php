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
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/stock_ledger.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canDelete('pos'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => 'You do not have permission to void POS sales']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$sale_id = (int)($_POST['sale_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');
if ($sale_id <= 0)   { echo json_encode(['success' => false, 'message' => 'Invalid sale.']); exit; }
if ($reason === '')  { echo json_encode(['success' => false, 'message' => 'A void reason is required.']); exit; }

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

    // Reverse every line back into stock (mirrors process_sale.php in reverse).
    $items = $pdo->prepare("SELECT psi.*, p.is_service
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

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Voided POS Sale #{$sale['receipt_number']} (" . number_format((float)$sale['grand_total'], 2) . ")");
    logAudit($pdo, $_SESSION['user_id'], 'pos_void', [
        'entity_type' => 'pos_sale',
        'entity_id'   => $sale_id,
        'old_values'  => ['sale_status' => 'completed'],
        'new_values'  => ['sale_status' => 'voided', 'void_reason' => $reason],
    ]);

    echo json_encode(['success' => true, 'message' => 'Sale voided. Stock and cash have been reversed.']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('void_sale: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
