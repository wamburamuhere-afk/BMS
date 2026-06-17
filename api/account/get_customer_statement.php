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

    $cnScope = scopeFilterSqlNullable('project', 'cn');

    // ── Opening balance (everything strictly before date_from) ────────────
    // All invoices regardless of payment status (user wants full picture).
    $ob = $pdo->prepare("
        SELECT
          (SELECT COALESCE(SUM(i.grand_total),0) FROM invoices i
             WHERE i.customer_id = ? AND i.status NOT IN ('draft','cancelled','void','deleted')
               AND i.invoice_date < ? $invScope)
          -
          (SELECT COALESCE(SUM(p.amount),0) FROM payments p
             WHERE p.customer_id = ? AND p.status = 'completed'
               AND p.payment_date < ? $payScope)
          -
          (SELECT COALESCE(SUM(cn.grand_total),0) FROM credit_notes cn
             WHERE cn.customer_id = ? AND cn.status IN ('approved','applied')
               AND cn.credit_date < ?) AS opening
    ");
    $ob->execute([$customer_id, $date_from, $customer_id, $date_from, $customer_id, $date_from]);
    $opening = (float)$ob->fetchColumn();

    // ── In-range invoices (Dr — all statuses except draft/cancelled/void) ──
    $invStmt = $pdo->prepare("
        SELECT i.invoice_date AS d, i.invoice_number AS ref,
               i.grand_total AS amount, i.status
          FROM invoices i
         WHERE i.customer_id = ? AND i.status NOT IN ('draft','cancelled','void','deleted')
           AND i.invoice_date BETWEEN ? AND ? $invScope
         ORDER BY i.invoice_date ASC, i.invoice_id ASC
    ");
    $invStmt->execute([$customer_id, $date_from, $date_to]);

    // ── In-range payments (Cr) ────────────────────────────────────────────
    $payStmt = $pdo->prepare("
        SELECT p.payment_date AS d, p.payment_number AS ref,
               p.amount AS amount, p.payment_method AS method,
               i.invoice_number AS inv_ref
          FROM payments p
          LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
         WHERE p.customer_id = ? AND p.status = 'completed'
           AND p.payment_date BETWEEN ? AND ? $payScope
         ORDER BY p.payment_date ASC, p.payment_id ASC
    ");
    $payStmt->execute([$customer_id, $date_from, $date_to]);

    // ── In-range credit notes (Cr) ────────────────────────────────────────
    $cnStmt = $pdo->prepare("
        SELECT cn.credit_date AS d, cn.credit_note_number AS ref,
               cn.grand_total AS amount, cn.reason
          FROM credit_notes cn
         WHERE cn.customer_id = ? AND cn.status IN ('approved','applied')
           AND cn.credit_date BETWEEN ? AND ?
         ORDER BY cn.credit_date ASC, cn.credit_note_id ASC
    ");
    $cnStmt->execute([$customer_id, $date_from, $date_to]);

    // ── Merge + sort chronologically, compute running balance ─────────────
    $events = [];
    foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $statusNote = in_array($r['status'], ['approved','paid'], true) ? '' : " [{$r['status']}]";
        $events[] = ['date' => $r['d'], 'type' => 'invoice', 'ref' => $r['ref'],
                     'description' => "Invoice — {$r['ref']}{$statusNote}",
                     'charge' => (float)$r['amount'], 'payment' => 0.0];
    }
    foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $method  = $s['method'] ? " ({$s['method']})" : '';
        $invNote = $s['inv_ref'] ? " — Invoice {$s['inv_ref']}" : '';
        $events[] = ['date' => $s['d'], 'type' => 'payment', 'ref' => $s['ref'],
                     'description' => "Payment{$method}{$invNote}",
                     'charge' => 0.0, 'payment' => (float)$s['amount']];
    }
    foreach ($cnStmt->fetchAll(PDO::FETCH_ASSOC) as $cn) {
        $events[] = ['date' => $cn['d'], 'type' => 'credit_note', 'ref' => $cn['ref'],
                     'description' => 'Credit Note — ' . ($cn['reason'] ?: $cn['ref']),
                     'charge' => 0.0, 'payment' => (float)$cn['amount']];
    }
    usort($events, function ($a, $b) {
        if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
        $order = ['invoice' => 0, 'credit_note' => 1, 'payment' => 2];
        return ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9);
    });

    $balance = $opening; $totCharge = 0.0; $totPayment = 0.0; $lines = [];
    foreach ($events as $e) {
        $balance    += $e['charge'] - $e['payment'];
        $totCharge  += $e['charge'];
        $totPayment += $e['payment'];
        $e['balance'] = round($balance, 2);
        $lines[] = $e;
    }

    echo json_encode([
        'success'         => true,
        'customer'        => $customer,
        'date_from'       => $date_from,
        'date_to'         => $date_to,
        'opening_balance' => round($opening, 2),
        'lines'           => $lines,
        'totals'          => ['charge' => round($totCharge, 2), 'payment' => round($totPayment, 2)],
        'closing_balance' => round($balance, 2),
    ]);

} catch (Throwable $e) {
    error_log('get_customer_statement error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
