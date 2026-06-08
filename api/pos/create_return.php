<?php
// scope-audit: skip — acts on one POS sale by primary key under canCreate('pos'); POS project scope deferred (see pos.php)
/**
 * API: Create a POS Sales Return / Refund
 * ----------------------------------------------------------------------------
 * Returns selected items (partial or full) from a completed POS sale. Creates a
 * dedicated RETURN row in pos_sales (is_return_sale = 1, original_sale_id set,
 * positive amounts), restocks the returned goods, refunds the cash drawer, and
 * marks the original sale `partially_refunded` or `refunded`.
 *
 * The return row is contra-revenue: the Income Statement keeps the original
 * sale's gross revenue/COGS and subtracts the return (net) + restocked cost — so
 * there is no double-count. Voiding an entire mistaken sale is a different action
 * (api/pos/void_sale.php); this one is a goods return.
 *
 * POST (form-encoded):
 *   original_sale_id, reason, refund_method (cash|card|mobile_money|bank_transfer),
 *   items = JSON array of { sale_item_id, return_qty }
 * Permission: canCreate('pos')
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/stock_ledger.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('pos'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => 'You do not have permission to process POS returns']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$original_sale_id = (int)($_POST['original_sale_id'] ?? 0);
$reason           = trim($_POST['reason'] ?? '');
$refund_method    = $_POST['refund_method'] ?? 'cash';
$itemsRaw         = $_POST['items'] ?? '';

$allowed_methods = ['cash', 'card', 'mobile_money', 'bank_transfer'];
if (!in_array($refund_method, $allowed_methods, true)) { echo json_encode(['success' => false, 'message' => 'Invalid refund method.']); exit; }
if ($original_sale_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid original sale.']); exit; }
if ($reason === '')         { echo json_encode(['success' => false, 'message' => 'A return reason is required.']); exit; }

$requested = json_decode($itemsRaw, true);
if (!is_array($requested) || count($requested) === 0) { echo json_encode(['success' => false, 'message' => 'Select at least one item to return.']); exit; }
// Normalise: sale_item_id => return_qty (>0)
$want = [];
foreach ($requested as $r) {
    $iid = (int)($r['sale_item_id'] ?? 0);
    $rq  = (float)($r['return_qty'] ?? 0);
    if ($iid > 0 && $rq > 0) $want[$iid] = ($want[$iid] ?? 0) + $rq;
}
if (!$want) { echo json_encode(['success' => false, 'message' => 'Nothing to return.']); exit; }

try {
    global $pdo;
    $pdo->beginTransaction();

    // Lock + validate the original.
    $st = $pdo->prepare("SELECT * FROM pos_sales WHERE sale_id = ? FOR UPDATE");
    $st->execute([$original_sale_id]);
    $orig = $st->fetch(PDO::FETCH_ASSOC);
    if (!$orig)                               { throw new Exception('Original sale not found.'); }
    if ((int)$orig['is_return_sale'] === 1)   { throw new Exception('Cannot return a return transaction.'); }
    if (!in_array($orig['sale_status'], ['completed', 'partially_refunded'], true)) {
        throw new Exception('Only completed sales can be returned (status: ' . $orig['sale_status'] . ').');
    }

    $warehouse_id = $orig['warehouse_id'] !== null && $orig['warehouse_id'] !== '' ? (int)$orig['warehouse_id'] : null;
    $project_id   = $orig['project_id']   !== null && $orig['project_id']   !== '' ? (int)$orig['project_id']   : null;

    // Load the original lines being returned, with returnable balance + product flags.
    $lineStmt = $pdo->prepare("SELECT psi.*, p.is_service
                                 FROM pos_sale_items psi
                            LEFT JOIN products p ON p.product_id = psi.product_id
                                WHERE psi.sale_id = ?");
    $lineStmt->execute([$original_sale_id]);
    $origLines = [];
    foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $l) { $origLines[(int)$l['sale_item_id']] = $l; }

    // Validate each requested return against the returnable balance.
    $toReturn = [];
    foreach ($want as $iid => $rq) {
        if (!isset($origLines[$iid])) { throw new Exception('Line not part of this sale.'); }
        $l = $origLines[$iid];
        $already    = (float)$l['returned_quantity'];
        $returnable = (float)$l['quantity'] - $already;
        if ($rq > $returnable + 0.0001) {
            throw new Exception("Cannot return {$rq} of '{$l['product_name']}' — only {$returnable} remaining.");
        }
        $toReturn[$iid] = $rq;
    }

    // Money for the return row (positive amounts; proportional to the original line).
    $r_subtotal = 0.0; $r_discount = 0.0; $r_tax = 0.0; $r_grand = 0.0;
    foreach ($toReturn as $iid => $rq) {
        $l   = $origLines[$iid];
        $q   = max((float)$l['quantity'], 1e-9);
        $r_subtotal += (float)$l['unit_price']       * $rq;          // gross (pre-discount)
        $r_discount += ((float)$l['discount_amount'] / $q) * $rq;
        $r_tax      += ((float)$l['tax_amount']      / $q) * $rq;
        $r_grand    += ((float)$l['line_total']      / $q) * $rq + ((float)$l['tax_amount'] / $q) * $rq;
    }
    $r_subtotal = round($r_subtotal, 2); $r_discount = round($r_discount, 2);
    $r_tax = round($r_tax, 2);           $r_grand = round($r_grand, 2);

    // Shift: operator's active shift, else fall back to the original sale's shift (NOT NULL column).
    $shiftStmt = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active' LIMIT 1");
    $shiftStmt->execute([$_SESSION['user_id']]);
    $shift_id = $shiftStmt->fetchColumn() ?: (int)$orig['shift_id'];

    $return_receipt = 'RET-' . date('Ymd') . '-' . mt_rand(1000, 9999);

    // Create the return header.
    $pdo->prepare("INSERT INTO pos_sales
                      (receipt_number, shift_id, user_id, customer_id, customer_name, warehouse_id, project_id,
                       subtotal, discount_amount, tax_amount, grand_total,
                       payment_method, payment_status, sale_status,
                       is_return_sale, original_sale_id, return_reason, sale_date, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', 'refunded', 1, ?, ?, NOW(), NOW())")
        ->execute([
            $return_receipt, $shift_id, $_SESSION['user_id'], $orig['customer_id'], $orig['customer_name'],
            $warehouse_id, $project_id, $r_subtotal, $r_discount, $r_tax, $r_grand,
            $refund_method, $original_sale_id, $reason,
        ]);
    $return_id = (int)$pdo->lastInsertId();

    // Return lines + restock + flag the originals.
    $insLine = $pdo->prepare("INSERT INTO pos_sale_items
                                 (sale_id, product_id, product_name, quantity, unit_price, tax_rate, tax_amount, discount_rate, discount_amount, line_total)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $bumpOrig = $pdo->prepare("UPDATE pos_sale_items
                                  SET returned_quantity = returned_quantity + ?, is_returned = IF(returned_quantity + ? >= quantity, 1, 0)
                                WHERE sale_item_id = ?");
    $restoreGlobal = $pdo->prepare("UPDATE products
                                       SET stock_quantity = stock_quantity + ?, current_stock = current_stock + ?
                                     WHERE product_id = ?");
    $restoreWh = $pdo->prepare("UPDATE product_stocks
                                   SET stock_quantity = IFNULL(stock_quantity,0) + ?
                                 WHERE product_id = ? AND warehouse_id = ?");

    foreach ($toReturn as $iid => $rq) {
        $l   = $origLines[$iid];
        $q   = max((float)$l['quantity'], 1e-9);
        $pid = (int)$l['product_id'];
        $ln_tax   = round(((float)$l['tax_amount']      / $q) * $rq, 2);
        $ln_disc  = round(((float)$l['discount_amount'] / $q) * $rq, 2);
        $ln_total = round(((float)$l['line_total']      / $q) * $rq, 2);

        $insLine->execute([
            $return_id, $pid, $l['product_name'], $rq, (float)$l['unit_price'],
            (float)$l['tax_rate'], $ln_tax, (float)$l['discount_rate'], $ln_disc, $ln_total,
        ]);
        $bumpOrig->execute([$rq, $rq, $iid]);

        if ((int)($l['is_service'] ?? 0) !== 1 && $pid > 0) {
            $restoreGlobal->execute([$rq, $rq, $pid]);
            if ($warehouse_id !== null) $restoreWh->execute([$rq, $pid, $warehouse_id]);
            recordStockMovement($pdo, [
                'product_id'       => $pid,
                'warehouse_id'     => $warehouse_id,
                'project_id'       => $project_id,
                'movement_type'    => 'return_in',
                'quantity'         => $rq,
                'reference_id'     => $return_id,
                'reference_type'   => 'pos_return',
                'reference_number' => $return_receipt,
                'created_by'       => $_SESSION['user_id'],
                'notes'            => 'Return against POS Sale #' . $orig['receipt_number'] . ' — ' . $reason,
            ]);
        }
    }

    // Refund cash drawer (cash refunds only, against the active shift).
    if ($refund_method === 'cash' && $shift_id) {
        $pdo->prepare("INSERT INTO cash_register_transactions
                          (shift_id, transaction_type, amount, payment_method, reference_number, sale_id, reason, created_by, created_at)
                       VALUES (?, 'refund', ?, 'cash', ?, ?, ?, ?, NOW())")
            ->execute([$shift_id, $r_grand, $return_receipt, $return_id, 'Return: ' . $reason, $_SESSION['user_id']]);
    }

    // Flip the original: fully returned across all lines → 'refunded', else 'partially_refunded'.
    $remaining = (float)$pdo->query("SELECT COALESCE(SUM(quantity - returned_quantity),0) FROM pos_sale_items WHERE sale_id = " . (int)$original_sale_id)->fetchColumn();
    $new_status = $remaining <= 0.0001 ? 'refunded' : 'partially_refunded';
    $pdo->prepare("UPDATE pos_sales SET sale_status = ?, updated_at = NOW() WHERE sale_id = ?")
        ->execute([$new_status, $original_sale_id]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "POS Return #{$return_receipt} against Sale #{$orig['receipt_number']} (" . number_format($r_grand, 2) . ")");
    logAudit($pdo, $_SESSION['user_id'], 'pos_return', [
        'entity_type' => 'pos_sale',
        'entity_id'   => $original_sale_id,
        'old_values'  => ['sale_status' => $orig['sale_status']],
        'new_values'  => ['sale_status' => $new_status, 'return_id' => $return_id, 'refund' => $r_grand],
    ]);

    echo json_encode([
        'success'        => true,
        'message'        => 'Return processed. Refund: ' . number_format($r_grand, 2),
        'return_id'      => $return_id,
        'receipt_number' => $return_receipt,
        'refund_total'   => $r_grand,
        'original_status'=> $new_status,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('create_return: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
