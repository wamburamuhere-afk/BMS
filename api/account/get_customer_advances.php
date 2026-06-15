<?php
/**
 * api/account/get_customer_advances.php  (money.md IN-7)
 *
 * List a customer's advance receipts with their available (unapplied) balances, plus
 * the customer's total gross / applied / available deposit. Feeds the advance-apply
 * UI and the customer statement / AR aging deposit memos.
 *
 *   { success, customer_id, totals:{gross,applied,available},
 *     advances:[{payment_id,payment_number,payment_date,amount,applied,available,reference}] }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/customer_advance.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('invoices')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$customer_id = (int)($_GET['customer_id'] ?? 0);
if ($customer_id <= 0) { echo json_encode(['success' => false, 'message' => 'customer_id is required']); exit; }

try {
    $rows = $pdo->prepare("
        SELECT p.payment_id, p.payment_number, p.payment_date, p.reference_number,
               pa.allocated_amount AS amount
          FROM payment_allocations pa
          JOIN payments p ON p.payment_id = pa.payment_id
         WHERE pa.target_type = 'advance' AND pa.target_id = ? AND p.status = 'completed'
      ORDER BY p.payment_id ASC
    ");
    $rows->execute([$customer_id]);

    $advances = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['payment_id'];
        $gross = round((float)$r['amount'], 2);
        $avail = advancePaymentAvailable($pdo, $pid);
        $advances[] = [
            'payment_id'     => $pid,
            'payment_number' => $r['payment_number'],
            'payment_date'   => $r['payment_date'],
            'reference'      => $r['reference_number'],
            'amount'         => $gross,
            'applied'        => round($gross - $avail, 2),
            'available'      => $avail,
        ];
    }

    $gross     = customerAdvanceGross($pdo, $customer_id);
    $applied   = customerAdvanceApplied($pdo, $customer_id);
    $available = customerAdvanceAvailable($pdo, $customer_id);

    echo json_encode([
        'success'     => true,
        'customer_id' => $customer_id,
        'totals'      => ['gross' => $gross, 'applied' => $applied, 'available' => $available],
        'advances'    => $advances,
    ]);

} catch (Throwable $e) {
    error_log('get_customer_advances error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
