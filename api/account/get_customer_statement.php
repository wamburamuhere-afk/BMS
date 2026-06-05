<?php
/**
 * api/account/get_customer_statement.php
 *
 * Statement of account for ONE customer over a date range: opening balance as of
 * date_from, then each invoice (charge) and payment (settlement) in date order
 * with a running balance, and the closing balance.
 *
 * Charges come from invoices (issued = approved/partial/paid); settlements from
 * the payments table (completed). Project-scoped per security.md §23.
 *
 * Response:
 *   { success, customer{id,name,...}, date_from, date_to, opening_balance,
 *     lines:[{date,type,ref,description,charge,payment,balance}], totals{charge,payment},
 *     closing_balance }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('financial_reports')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$customer_id = (int)($_GET['customer_id'] ?? 0);
$date_from   = $_GET['date_from'] ?? date('Y-01-01');
$date_to     = $_GET['date_to']   ?? date('Y-m-d');

if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Select a customer']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}

try {
    global $pdo;

    $cust = $pdo->prepare("SELECT customer_id, customer_name, email, phone, address FROM customers WHERE customer_id = ?");
    $cust->execute([$customer_id]);
    $customer = $cust->fetch(PDO::FETCH_ASSOC);
    if (!$customer) { echo json_encode(['success' => false, 'message' => 'Customer not found']); exit; }

    // Project-scope clauses (non-admins only see their projects' rows + untagged).
    $invScope = scopeFilterSqlNullable('project', 'i');
    $payScope = scopeFilterSqlNullable('project', 'p');

    // ── Opening balance (everything strictly before date_from) ────────────
    $ob = $pdo->prepare("
        SELECT
          (SELECT COALESCE(SUM(i.grand_total),0) FROM invoices i
             WHERE i.customer_id = ? AND i.status IN ('approved','partial','paid')
               AND i.invoice_date < ? $invScope)
          -
          (SELECT COALESCE(SUM(p.amount),0) FROM payments p
             WHERE p.customer_id = ? AND p.status = 'completed'
               AND p.payment_date < ? $payScope) AS opening
    ");
    $ob->execute([$customer_id, $date_from, $customer_id, $date_from]);
    $opening = (float)$ob->fetchColumn();

    // ── In-range charges (invoices) ───────────────────────────────────────
    $invStmt = $pdo->prepare("
        SELECT i.invoice_date AS d, i.invoice_number AS ref, i.grand_total AS amount
          FROM invoices i
         WHERE i.customer_id = ? AND i.status IN ('approved','partial','paid')
           AND i.invoice_date BETWEEN ? AND ? $invScope
    ");
    $invStmt->execute([$customer_id, $date_from, $date_to]);
    $charges = $invStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── In-range settlements (payments) ───────────────────────────────────
    $payStmt = $pdo->prepare("
        SELECT p.payment_date AS d, p.payment_number AS ref, p.amount AS amount, p.payment_method AS method
          FROM payments p
         WHERE p.customer_id = ? AND p.status = 'completed'
           AND p.payment_date BETWEEN ? AND ? $payScope
    ");
    $payStmt->execute([$customer_id, $date_from, $date_to]);
    $settlements = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Merge + sort chronologically, compute running balance ─────────────
    $events = [];
    foreach ($charges as $c) {
        $events[] = ['date' => $c['d'], 'type' => 'invoice', 'ref' => $c['ref'],
                     'description' => 'Invoice ' . $c['ref'], 'charge' => (float)$c['amount'], 'payment' => 0.0];
    }
    foreach ($settlements as $s) {
        $events[] = ['date' => $s['d'], 'type' => 'payment', 'ref' => $s['ref'],
                     'description' => 'Payment received' . ($s['method'] ? ' (' . $s['method'] . ')' : ''),
                     'charge' => 0.0, 'payment' => (float)$s['amount']];
    }
    usort($events, function ($a, $b) {
        if ($a['date'] === $b['date']) return ($a['type'] === 'invoice' ? 0 : 1) <=> ($b['type'] === 'invoice' ? 0 : 1);
        return strcmp($a['date'], $b['date']);
    });

    $balance = $opening; $totCharge = 0.0; $totPayment = 0.0; $lines = [];
    foreach ($events as $e) {
        $balance += $e['charge'] - $e['payment'];
        $totCharge += $e['charge']; $totPayment += $e['payment'];
        $e['balance'] = $balance;
        $lines[] = $e;
    }

    echo json_encode([
        'success'         => true,
        'customer'        => $customer,
        'date_from'       => $date_from,
        'date_to'         => $date_to,
        'opening_balance' => $opening,
        'lines'           => $lines,
        'totals'          => ['charge' => $totCharge, 'payment' => $totPayment],
        'closing_balance' => $balance,
    ]);

} catch (Throwable $e) {
    error_log('get_customer_statement error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
