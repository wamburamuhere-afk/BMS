<?php
/**
 * api/account/get_vendor_statement.php
 *
 * Statement of account for ONE supplier/sub-contractor over a date range:
 * opening payable as of date_from, then each bill (charge) and its settlement in
 * date order with a running balance, and the closing payable.
 *
 * BMS note: sourced from supplier_invoices (the single, self-consistent source —
 * a bill is a charge on date_raised and, once status='paid', a settlement on
 * payment_date). Amounts are net of WHT withheld (the supplier-facing payable).
 * Project-scoped per security.md §23.
 *
 * Response:
 *   { success, vendor{id,name,...}, date_from, date_to, opening_balance,
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

$vendor_id = (int)($_GET['vendor_id'] ?? 0);
$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

if ($vendor_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Select a vendor']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}

try {
    global $pdo;

    $v = $pdo->prepare("SELECT supplier_id, supplier_name, email, phone, address FROM suppliers WHERE supplier_id = ?");
    $v->execute([$vendor_id]);
    $vendor = $v->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) { echo json_encode(['success' => false, 'message' => 'Vendor not found']); exit; }

    $scope = scopeFilterSqlNullable('project', 'si');

    // Net-of-WHT payable expression reused across queries.
    $net = "(si.amount - COALESCE(si.wht_amount,0))";

    // ── Opening payable (before date_from) = bills raised − bills paid ────
    $ob = $pdo->prepare("
        SELECT
          (SELECT COALESCE(SUM($net),0) FROM supplier_invoices si
             WHERE si.supplier_id = ? AND si.status IN ('approved','paid')
               AND si.date_raised < ? $scope)
          -
          (SELECT COALESCE(SUM($net),0) FROM supplier_invoices si
             WHERE si.supplier_id = ? AND si.status = 'paid'
               AND si.payment_date IS NOT NULL AND si.payment_date < ? $scope) AS opening
    ");
    $ob->execute([$vendor_id, $date_from, $vendor_id, $date_from]);
    $opening = (float)$ob->fetchColumn();

    // ── In-range charges (bills raised) ───────────────────────────────────
    $billStmt = $pdo->prepare("
        SELECT si.date_raised AS d, si.invoice_ref AS ref, si.invoice_type AS itype, $net AS amount
          FROM supplier_invoices si
         WHERE si.supplier_id = ? AND si.status IN ('approved','paid')
           AND si.date_raised BETWEEN ? AND ? $scope
    ");
    $billStmt->execute([$vendor_id, $date_from, $date_to]);
    $bills = $billStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── In-range settlements (bills paid) ─────────────────────────────────
    $payStmt = $pdo->prepare("
        SELECT si.payment_date AS d, si.invoice_ref AS ref, si.payment_method AS method, $net AS amount
          FROM supplier_invoices si
         WHERE si.supplier_id = ? AND si.status = 'paid'
           AND si.payment_date IS NOT NULL AND si.payment_date BETWEEN ? AND ? $scope
    ");
    $payStmt->execute([$vendor_id, $date_from, $date_to]);
    $settlements = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    $events = [];
    foreach ($bills as $b) {
        $label = ($b['itype'] === 'sub_contractor' ? 'Sub-contractor bill ' : 'Bill ') . $b['ref'];
        $events[] = ['date' => $b['d'], 'type' => 'bill', 'ref' => $b['ref'],
                     'description' => $label, 'charge' => (float)$b['amount'], 'payment' => 0.0];
    }
    foreach ($settlements as $s) {
        $events[] = ['date' => $s['d'], 'type' => 'payment', 'ref' => $s['ref'],
                     'description' => 'Payment made' . ($s['method'] ? ' (' . $s['method'] . ')' : ''),
                     'charge' => 0.0, 'payment' => (float)$s['amount']];
    }
    usort($events, function ($a, $b) {
        if ($a['date'] === $b['date']) return ($a['type'] === 'bill' ? 0 : 1) <=> ($b['type'] === 'bill' ? 0 : 1);
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
        'vendor'          => $vendor,
        'date_from'       => $date_from,
        'date_to'         => $date_to,
        'opening_balance' => $opening,
        'lines'           => $lines,
        'totals'          => ['charge' => $totCharge, 'payment' => $totPayment],
        'closing_balance' => $balance,
    ]);

} catch (Throwable $e) {
    error_log('get_vendor_statement error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
