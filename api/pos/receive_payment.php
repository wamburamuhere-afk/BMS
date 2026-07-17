<?php
// scope-audit: skip — acts on one POS sale by primary key under canEdit('pos'); POS project scope deferred (see pos.php)
/**
 * API: Receive a payment against a credit / partially-paid POS sale
 * ----------------------------------------------------------------------------
 * Records a payment in pos_sale_payments, recomputes the sale's payment_status
 * (pending → partial → paid) and, for cash, adds the money to the operator's
 * active shift drawer. Lets a POS sale sold on credit be settled later — fully
 * operational (no GL posting; the system-wide double-entry layer is a separate
 * future project, see double_entry_integration_plan.md).
 *
 * POST (form-encoded): sale_id, amount, payment_method, reference?
 * Permission: canEdit('pos')
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => 'You do not have permission to receive POS payments']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$sale_id = (int)($_POST['sale_id'] ?? 0);
$amount  = round((float)($_POST['amount'] ?? 0), 2);
$method  = $_POST['payment_method'] ?? 'cash';
$reference = trim($_POST['reference'] ?? '');

$allowed = ['cash','card','mobile_money','bank_transfer','voucher','loyalty_points'];
if ($sale_id <= 0)                     { echo json_encode(['success' => false, 'message' => 'Invalid sale.']); exit; }
if ($amount <= 0)                      { echo json_encode(['success' => false, 'message' => 'Enter a payment amount greater than zero.']); exit; }
if (!in_array($method, $allowed, true)){ echo json_encode(['success' => false, 'message' => 'Invalid payment method.']); exit; }

try {
    global $pdo;
    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT * FROM pos_sales WHERE sale_id = ? FOR UPDATE");
    $st->execute([$sale_id]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale)                              { throw new Exception('Sale not found.'); }
    if ((int)$sale['is_return_sale'] === 1)  { throw new Exception('Cannot take a payment on a return.'); }
    if ($sale['sale_status'] === 'voided')   { throw new Exception('This sale is voided.'); }

    $wid = $sale['warehouse_id'] !== null && $sale['warehouse_id'] !== '' ? (int)$sale['warehouse_id'] : null;
    if ($wid !== null && !userCan('warehouse', $wid)) {
        throw new Exception('Access denied: this warehouse is not in your assigned scope.');
    }

    $grand = (float)$sale['grand_total'];
    $paid  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM pos_sale_payments WHERE sale_id = " . (int)$sale_id)->fetchColumn();
    $balance = round($grand - $paid, 2);
    if ($balance <= 0.01) { throw new Exception('This sale is already fully paid.'); }
    if ($amount > $balance + 0.01) { throw new Exception('Payment exceeds the balance due (' . number_format($balance, 2) . ').'); }

    // Record the payment.
    $pdo->prepare("INSERT INTO pos_sale_payments (sale_id, amount, payment_method, reference, notes, received_by, created_at)
                   VALUES (?, ?, ?, ?, 'Payment received', ?, NOW())")
        ->execute([$sale_id, $amount, $method, ($reference !== '' ? $reference : null), $_SESSION['user_id']]);

    // Recompute status.
    $new_paid    = round($paid + $amount, 2);
    $new_balance = round($grand - $new_paid, 2);
    $new_status  = $new_balance <= 0.01 ? 'paid' : 'partial';
    $pdo->prepare("UPDATE pos_sales SET payment_status = ?, updated_at = NOW() WHERE sale_id = ?")
        ->execute([$new_status, $sale_id]);

    // Cash payments land in the operator's active shift drawer.
    if ($method === 'cash') {
        $active = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active' LIMIT 1");
        $active->execute([$_SESSION['user_id']]);
        if ($shift = $active->fetchColumn()) {
            $pdo->prepare("INSERT INTO cash_register_transactions
                              (shift_id, transaction_type, amount, payment_method, reference_number, sale_id, reason, created_by, created_at)
                           VALUES (?, 'cash_in', ?, 'cash', ?, ?, 'POS credit settlement', ?, NOW())")
                ->execute([$shift, $amount, $sale['receipt_number'], $sale_id, $_SESSION['user_id']]);
        }
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Received POS payment {$amount} on Sale #{$sale['receipt_number']} (balance " . number_format($new_balance, 2) . ")");
    logAudit($pdo, $_SESSION['user_id'], 'pos_payment_received', [
        'entity_type' => 'pos_sale', 'entity_id' => $sale_id,
        'new_values' => ['amount' => $amount, 'method' => $method, 'balance_due' => $new_balance, 'payment_status' => $new_status],
    ]);

    echo json_encode([
        'success' => true,
        'message' => $new_balance <= 0.01 ? 'Payment received — sale fully settled.' : ('Payment received. Balance due: ' . number_format($new_balance, 2)),
        'payment_status' => $new_status,
        'amount_paid' => $new_paid,
        'balance_due' => $new_balance,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('receive_payment: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
