<?php
/**
 * api/account/apply_customer_advance.php  (money.md IN-7)
 *
 * Apply a customer's available ADVANCE / DEPOSIT to an outstanding invoice. Draws
 * the requested amount FIFO across the customer's advance payments, settling the
 * invoice from the deposit liability.
 *
 *   Dr Client Deposits (2-1600)  /  Cr Accounts Receivable (1-1200)
 *
 * Writes one 'invoice' payment_allocations row per advance payment consumed (each
 * with its own balanced GL entry, idempotent on the allocation id), and reduces the
 * invoice's balance like a normal receipt. No cash moves (no Bank Statement line) —
 * the cash was banked when the advance was received.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/customer_advance.php';   // advance helpers
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot apply receipts']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $invoice_id  = (int)($_POST['invoice_id'] ?? 0);
    $amount      = round((float)($_POST['amount'] ?? 0), 2);
    $apply_date  = $_POST['apply_date'] ?? date('Y-m-d');

    if ($customer_id <= 0) throw new Exception('Select a customer.');
    if ($invoice_id <= 0) throw new Exception('Select an invoice to apply the advance to.');
    if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $apply_date)) throw new Exception('A valid date is required.');

    $pdo->beginTransaction();

    // Lock + verify the invoice.
    assertScopeForRecord('invoices', 'invoice_id', $invoice_id);
    $inv = $pdo->prepare("SELECT invoice_id, customer_id, grand_total, paid_amount, balance_due, status, project_id
                            FROM invoices WHERE invoice_id = ? FOR UPDATE");
    $inv->execute([$invoice_id]);
    $invoice = $inv->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) throw new Exception("Invoice #$invoice_id not found.");
    if ((int)$invoice['customer_id'] !== $customer_id) throw new Exception('That invoice does not belong to this customer.');
    $balance_due = (float)$invoice['balance_due'];
    if ($amount > $balance_due + 0.01) {
        throw new Exception('Apply amount (' . number_format($amount, 2) . ') exceeds the invoice balance (' . number_format($balance_due, 2) . ').');
    }

    // Available advance for this customer.
    $available = customerAdvanceAvailable($pdo, $customer_id);
    if ($amount > $available + 0.01) {
        throw new Exception('Apply amount (' . number_format($amount, 2) . ') exceeds the available advance (' . number_format($available, 2) . ').');
    }

    $project_id = $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null;

    // Draw FIFO across the customer's advance payments that still have a balance.
    $advPayments = $pdo->query("
        SELECT DISTINCT pa.payment_id, p.payment_number
          FROM payment_allocations pa
          JOIN payments p ON p.payment_id = pa.payment_id
         WHERE pa.target_type = 'advance' AND pa.target_id = $customer_id AND p.status = 'completed'
      ORDER BY pa.payment_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $amount;
    $applied_total = 0.0;
    $allocIns = $pdo->prepare("INSERT INTO payment_allocations (payment_id, payment_kind, target_type, target_id, allocated_amount)
                               VALUES (?, 'customer', 'invoice', ?, ?)");
    foreach ($advPayments as $ap) {
        if ($remaining <= 0.005) break;
        $pid_adv = (int)$ap['payment_id'];
        $avail_p = advancePaymentAvailable($pdo, $pid_adv);
        if ($avail_p <= 0.005) continue;
        $take = round(min($remaining, $avail_p), 2);
        if ($take <= 0) continue;

        $allocIns->execute([$pid_adv, $invoice_id, $take]);
        $allocId = (int)$pdo->lastInsertId();

        // GL: Dr Client Deposits / Cr AR for this draw.
        $post = postAdvanceApplication($pdo, $allocId, $take, $apply_date, $project_id,
            (int)$_SESSION['user_id'], "{$ap['payment_number']} → invoice #$invoice_id");
        if (empty($post['posted'])) {
            throw new Exception('Could not post the advance application to the ledger (' . ($post['reason'] ?? 'unknown') . ').');
        }
        $remaining = round($remaining - $take, 2);
        $applied_total = round($applied_total + $take, 2);
    }

    if ($applied_total + 0.01 < $amount) {
        throw new Exception('Only ' . number_format($applied_total, 2) . ' of the advance could be applied — insufficient available balance.');
    }

    // Recompute the invoice's paid total (all 'invoice' allocations + legacy single payments).
    $newPaid = round((float)$pdo->query("SELECT COALESCE(SUM(allocated_amount),0)
                                           FROM payment_allocations pa
                                           JOIN payments p ON pa.payment_id = p.payment_id
                                          WHERE pa.target_type='invoice' AND pa.target_id=$invoice_id AND p.status='completed'")->fetchColumn(), 2);
    $legacy = round((float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments
                                         WHERE invoice_id=$invoice_id AND status='completed'
                                           AND payment_id NOT IN (SELECT payment_id FROM payment_allocations)")->fetchColumn(), 2);
    $totalPaid = round($newPaid + $legacy, 2);
    $grand = (float)$pdo->query("SELECT grand_total FROM invoices WHERE invoice_id=$invoice_id")->fetchColumn();
    $newStatus = ($totalPaid >= $grand - 0.01) ? 'paid' : 'partial';
    $pdo->prepare("UPDATE invoices SET paid_amount=?, balance_due=GREATEST(grand_total-?,0), status=?, payment_date=?, updated_at=NOW() WHERE invoice_id=?")
        ->execute([$totalPaid, $totalPaid, $newStatus, $apply_date, $invoice_id]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Applied advance " . number_format($applied_total, 2) . " to invoice #$invoice_id (customer #$customer_id)");

    echo json_encode([
        'success' => true,
        'message' => 'Advance applied: ' . number_format($applied_total, 2) . ' to invoice #' . $invoice_id . '.',
        'applied' => $applied_total,
        'invoice_status' => $newStatus,
        'available_balance' => customerAdvanceAvailable($pdo, $customer_id),
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('apply_customer_advance error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
