<?php
/**
 * api/account/get_outstanding.php
 *
 * Lists a customer's OPEN invoices (still carrying a balance) for the Receive
 * Payment screen, so one receipt can be ticked across several of them. Read-only.
 * Project-scoped per security.md §23.
 *
 *   { success, customer_id, invoices:[{invoice_id,invoice_number,invoice_date,
 *     due_date,grand_total,paid_amount,balance}], total_outstanding }
 */
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('invoices')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$customer_id = (int)($_GET['customer_id'] ?? 0);
if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Select a customer']);
    exit;
}

try {
    $scope = scopeFilterSqlNullable('project', 'i');
    $stmt = $pdo->prepare("
        SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.due_date,
               i.grand_total, i.paid_amount, i.balance_due AS balance, i.currency
          FROM invoices i
         WHERE i.customer_id = ?
           AND i.balance_due > 0
           AND i.status IN ('approved','partial','paid')
           $scope
         ORDER BY i.invoice_date ASC, i.invoice_id ASC
    ");
    $stmt->execute([$customer_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0.0;
    $invoices = [];
    foreach ($rows as $r) {
        $bal = (float)$r['balance'];
        $total += $bal;
        $invoices[] = [
            'invoice_id'     => (int)$r['invoice_id'],
            'invoice_number' => $r['invoice_number'],
            'invoice_date'   => $r['invoice_date'],
            'due_date'       => $r['due_date'],
            'grand_total'    => (float)$r['grand_total'],
            'paid_amount'    => (float)$r['paid_amount'],
            'balance'        => $bal,
        ];
    }

    echo json_encode([
        'success'           => true,
        'customer_id'       => $customer_id,
        'invoices'          => $invoices,
        'total_outstanding' => round($total, 2),
    ]);

} catch (Throwable $e) {
    error_log('get_outstanding error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
