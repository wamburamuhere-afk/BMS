<?php
// api/account/void_payment.php
//
// account_financial.md flow #12a (FIX) — void a customer receipt and FULLY reverse it.
// There was NO delete/void path for a customer payment, so a mis-keyed receipt could
// not be undone. This posts the contra of the receipt's ledger entry (Dr AR / Cr Bank
// [+ Cr WHT Receivable]), marks the payment 'cancelled', recomputes each affected
// invoice's paid/balance/status, and reverses the bank-register deposit — all in one
// transaction.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';      // assertScopeForRecord
require_once __DIR__ . '/../../core/money_in_posting.php';   // reversePaymentReceived
require_once __DIR__ . '/../../core/bank_register.php';      // reverseBankTransaction

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
// Voiding a receipt is the reverse of recording one — gate with the same canEdit('invoices').
if (!canEdit('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to void payments']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$payment_id = (int)($_POST['payment_id'] ?? 0);
if ($payment_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid payment ID']); exit; }

try {
    global $pdo;

    $p = $pdo->prepare("SELECT payment_id, invoice_id, payment_number, amount, wht_amount,
                               received_into_account_id, status
                          FROM payments WHERE payment_id = ?");
    $p->execute([$payment_id]);
    $pay = $p->fetch(PDO::FETCH_ASSOC);
    if (!$pay) { echo json_encode(['success' => false, 'message' => 'Payment not found']); exit; }
    if ($pay['status'] === 'cancelled') { echo json_encode(['success' => false, 'message' => 'This payment is already voided.']); exit; }
    if ($pay['status'] !== 'completed') { echo json_encode(['success' => false, 'message' => 'Only a completed payment can be voided.']); exit; }

    // Every invoice this payment touched (single link + allocations); confirm project
    // scope on each BEFORE changing anything.
    $invoiceIds = [];
    if (!empty($pay['invoice_id'])) $invoiceIds[(int)$pay['invoice_id']] = true;
    $al = $pdo->prepare("SELECT target_id FROM payment_allocations WHERE payment_id = ? AND target_type = 'invoice'");
    $al->execute([$payment_id]);
    foreach ($al->fetchAll(PDO::FETCH_COLUMN) as $tid) $invoiceIds[(int)$tid] = true;
    foreach (array_keys($invoiceIds) as $iid) {
        assertScopeForRecord('invoices', 'invoice_id', $iid);
    }

    $uid = (int)$_SESSION['user_id'];
    $pdo->beginTransaction();

    // 1. Reverse the receipt's ledger entry (Dr AR / Cr Bank [+ Cr WHT Receivable]).
    $rev = reversePaymentReceived($pdo, $payment_id, $uid);
    if (empty($rev['reversed']) && ($rev['reason'] ?? '') !== 'already_reversed') {
        throw new Exception('Could not reverse the receipt in the ledger (' . ($rev['reason'] ?? 'unknown') . '). Nothing was voided.');
    }

    // 2. Mark the payment cancelled (now excluded from all "completed" sums).
    $pdo->prepare("UPDATE payments SET status='cancelled', updated_at=NOW() WHERE payment_id = ?")->execute([$payment_id]);

    // 3. Recompute each affected invoice from its REMAINING completed payments.
    foreach (array_keys($invoiceIds) as $iid) {
        $grand = (float)$pdo->query("SELECT grand_total FROM invoices WHERE invoice_id = " . (int)$iid)->fetchColumn();
        $alloc = (float)$pdo->query("SELECT COALESCE(SUM(pa.allocated_amount),0)
                                       FROM payment_allocations pa JOIN payments p ON pa.payment_id=p.payment_id
                                      WHERE pa.target_type='invoice' AND pa.target_id=" . (int)$iid . " AND p.status='completed'")->fetchColumn();
        $legacy = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments
                                       WHERE invoice_id=" . (int)$iid . " AND status='completed'
                                         AND payment_id NOT IN (SELECT payment_id FROM payment_allocations)")->fetchColumn();
        $totalPaid = round($alloc + $legacy, 2);
        $newStatus = $totalPaid >= $grand - 0.01 ? 'paid' : ($totalPaid > 0.01 ? 'partial' : 'approved');
        $pdo->prepare("UPDATE invoices SET paid_amount=?, balance_due=GREATEST(grand_total-?,0), status=?, updated_at=NOW() WHERE invoice_id=?")
            ->execute([$totalPaid, $totalPaid, $newStatus, $iid]);
    }

    // 4. Reverse the bank-register deposit (the receipt recorded a net-cash deposit
    //    keyed on the payment_number).
    if (!empty($pay['received_into_account_id']) && !empty($pay['payment_number'])) {
        reverseBankTransaction($pdo, (int)$pay['received_into_account_id'], $pay['payment_number'], 'deposit');
    }

    $pdo->commit();

    logActivity($pdo, $uid, "Voided customer payment {$pay['payment_number']} (TZS " . number_format((float)$pay['amount'], 2) . ")");
    if (function_exists('logAudit')) {
        logAudit($pdo, $uid, 'payment_voided', [
            'entity_type' => 'payment', 'entity_id' => $payment_id,
            'old_values'  => ['status' => 'completed'],
            'new_values'  => ['status' => 'cancelled', 'journal_void_entry' => $rev['entry_id'] ?? null],
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Payment voided — the ledger, invoice balance and bank record have been reversed.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('void_payment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
