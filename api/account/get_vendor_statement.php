<?php
/**
 * api/account/get_vendor_statement.php
 *
 * Statement of account for ONE supplier/sub-contractor over a date range:
 * opening payable as of date_from, then each bill (charge) and each payment
 * in date order with a running balance, and the closing payable.
 *
 * Payment sources (newest first, then legacy):
 *   1. supplier_invoice_payments — partial-payment instalments (post June 2026)
 *   2. supplier_invoices.payment_date — legacy full-payment stamp on older records
 *
 * Includes credit notes (approved/applied). Project-scoped per security.md §23.
 *
 * Response:
 *   { success, vendor{...}, date_from, date_to, opening_balance,
 *     lines:[{date,type,ref,description,charge,payment,balance}],
 *     totals{charge,payment}, closing_balance }
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

$vendor_id   = (int)($_GET['vendor_id'] ?? 0);
$vendor_type = trim($_GET['vendor_type'] ?? '');   // optional: 'supplier' | 'sub_contractor'
$date_from   = $_GET['date_from'] ?? date('Y-01-01');
$date_to     = $_GET['date_to']   ?? date('Y-m-d');
if (!in_array($vendor_type, ['supplier', 'sub_contractor'], true)) $vendor_type = '';

if ($vendor_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Select a vendor']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) || $date_from > $date_to) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}

try {
    global $pdo;

    // suppliers and sub_contractors are SEPARATE tables that each auto-increment
    // their own supplier_id — so the same numeric id can (and on live data, does)
    // refer to two completely different real-world entities. If the caller tells
    // us which table (vendor_type), look there directly — no ambiguity. If not
    // (legacy callers), fall back to the old either-table guess, but capture WHICH
    // table actually matched so every downstream query can still be filtered by it.
    if ($vendor_type === 'supplier') {
        $v = $pdo->prepare("SELECT supplier_id, supplier_name, email, phone, address FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
        $v->execute([$vendor_id]);
        $vendor = $v->fetch(PDO::FETCH_ASSOC);
    } elseif ($vendor_type === 'sub_contractor') {
        $v = $pdo->prepare("SELECT supplier_id, supplier_name, email, phone, NULL AS address FROM sub_contractors WHERE supplier_id = ? AND status != 'deleted'");
        $v->execute([$vendor_id]);
        $vendor = $v->fetch(PDO::FETCH_ASSOC);
    } else {
        $v = $pdo->prepare("
            SELECT supplier_id, supplier_name, email, phone, address, 'supplier' AS src_type FROM suppliers
            WHERE supplier_id = ? AND status != 'deleted'
            UNION ALL
            SELECT supplier_id, supplier_name, email, phone, NULL AS address, 'sub_contractor' AS src_type FROM sub_contractors
            WHERE supplier_id = ? AND status != 'deleted'
            LIMIT 1
        ");
        $v->execute([$vendor_id, $vendor_id]);
        $vendor = $v->fetch(PDO::FETCH_ASSOC);
        if ($vendor) { $vendor_type = $vendor['src_type']; unset($vendor['src_type']); }
    }
    if (!$vendor) { echo json_encode(['success' => false, 'message' => 'Vendor not found']); exit; }

    // From here on, $vendor_type is always resolved to 'supplier' or 'sub_contractor'
    // (whichever table this id actually came from), and every query below filters
    // supplier_invoices by invoice_type = $vendor_type so a colliding id can never
    // blend two unrelated vendors' transactions into one statement.
    $scope = scopeFilterSqlNullable('project', 'si');

    // ── Opening balance (all qualifying transactions strictly before date_from) ──
    // Charges = approved/partial/paid invoices raised before from
    // Credits  = (a) supplier_invoice_payments before from
    //            (b) legacy payment_date on fully-paid invoices not in sip table
    //            (c) credit notes before from
    // Credit notes only ever apply to genuine suppliers in this schema
    // (supplier_credit_notes has no invoice_type/sub-contractor concept at all),
    // so that leg is zeroed out entirely for a sub-contractor statement.
    $cnLeg = ($vendor_type === 'sub_contractor') ? "(SELECT 0)" : "
          (SELECT COALESCE(SUM(cn.amount), 0)
             FROM supplier_credit_notes cn
            WHERE cn.supplier_id = ? AND cn.status IN ('approved','applied')
              AND cn.credit_date < ?)";

    $ob = $pdo->prepare("
        SELECT
          (SELECT COALESCE(SUM(si.amount), 0)
             FROM supplier_invoices si
            WHERE si.supplier_id = ? AND si.invoice_type = ? AND si.status IN ('approved','partial','paid')
              AND si.date_raised < ? $scope)
          -
          (SELECT COALESCE(SUM(sip.amount), 0)
             FROM supplier_invoice_payments sip
             JOIN supplier_invoices si ON sip.invoice_id = si.id
            WHERE si.supplier_id = ? AND si.invoice_type = ? AND sip.payment_date < ?)
          -
          (SELECT COALESCE(SUM(si.amount), 0)
             FROM supplier_invoices si
            WHERE si.supplier_id = ? AND si.invoice_type = ? AND si.status = 'paid'
              AND si.payment_date IS NOT NULL AND si.payment_date < ?
              AND si.id NOT IN (SELECT DISTINCT invoice_id FROM supplier_invoice_payments)
              $scope)
          -
          $cnLeg
        AS opening
    ");
    $obParams = [$vendor_id, $vendor_type, $date_from,
                 $vendor_id, $vendor_type, $date_from,
                 $vendor_id, $vendor_type, $date_from];
    if ($vendor_type !== 'sub_contractor') { $obParams[] = $vendor_id; $obParams[] = $date_from; }
    $ob->execute($obParams);
    $opening = (float)$ob->fetchColumn();

    $events = [];

    // ── In-range charges: bills raised ────────────────────────────────────
    $billStmt = $pdo->prepare("
        SELECT si.date_raised AS d, si.invoice_ref AS ref, si.invoice_type AS itype,
               si.amount AS amount, si.status
          FROM supplier_invoices si
         WHERE si.supplier_id = ? AND si.invoice_type = ? AND si.status IN ('approved','partial','paid')
           AND si.date_raised BETWEEN ? AND ? $scope
         ORDER BY si.date_raised ASC, si.id ASC
    ");
    $billStmt->execute([$vendor_id, $vendor_type, $date_from, $date_to]);
    foreach ($billStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $typeLabel  = $b['itype'] === 'sub_contractor' ? 'Sub-contractor invoice' : 'Invoice';
        $statusNote = !in_array($b['status'], ['approved','paid'], true) ? " [{$b['status']}]" : '';
        $events[] = ['date' => $b['d'], 'type' => 'bill', 'ref' => $b['ref'],
                     'description' => "{$typeLabel} — {$b['ref']}{$statusNote}",
                     'charge' => (float)$b['amount'], 'payment' => 0.0];
    }

    // ── In-range payments: supplier_invoice_payments (partial instalments) ──
    $sipStmt = $pdo->prepare("
        SELECT sip.payment_date AS d,
               COALESCE(NULLIF(sip.reference,''), si.invoice_ref) AS ref,
               sip.payment_method AS method,
               si.invoice_ref AS inv_ref,
               sip.amount AS amount
          FROM supplier_invoice_payments sip
          JOIN supplier_invoices si ON sip.invoice_id = si.id
         WHERE si.supplier_id = ? AND si.invoice_type = ?
           AND sip.payment_date BETWEEN ? AND ?
         ORDER BY sip.payment_date ASC, sip.id ASC
    ");
    $sipStmt->execute([$vendor_id, $vendor_type, $date_from, $date_to]);
    foreach ($sipStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $method = $s['method'] ? " ({$s['method']})" : '';
        $events[] = ['date' => $s['d'], 'type' => 'payment', 'ref' => $s['ref'],
                     'description' => "Payment{$method} — Invoice {$s['inv_ref']}",
                     'charge' => 0.0, 'payment' => (float)$s['amount']];
    }

    // ── In-range payments: legacy full-payment stamp (pre-partial-payments) ──
    $legStmt = $pdo->prepare("
        SELECT si.payment_date AS d,
               COALESCE(NULLIF(si.payment_ref,''), si.invoice_ref) AS ref,
               si.payment_method AS method,
               si.invoice_ref AS inv_ref,
               si.amount AS amount
          FROM supplier_invoices si
         WHERE si.supplier_id = ? AND si.invoice_type = ? AND si.status = 'paid'
           AND si.payment_date IS NOT NULL
           AND si.payment_date BETWEEN ? AND ?
           AND si.id NOT IN (SELECT DISTINCT invoice_id FROM supplier_invoice_payments)
           $scope
         ORDER BY si.payment_date ASC, si.id ASC
    ");
    $legStmt->execute([$vendor_id, $vendor_type, $date_from, $date_to]);
    foreach ($legStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $method = $s['method'] ? " ({$s['method']})" : '';
        $events[] = ['date' => $s['d'], 'type' => 'payment', 'ref' => $s['ref'],
                     'description' => "Payment{$method} — Invoice {$s['inv_ref']}",
                     'charge' => 0.0, 'payment' => (float)$s['amount']];
    }

    // ── In-range credit notes (suppliers only — see $cnLeg note above) ──────
    if ($vendor_type !== 'sub_contractor') {
        $cnStmt = $pdo->prepare("
            SELECT cn.credit_date AS d, cn.credit_note_number AS ref,
                   cn.reason, cn.amount
              FROM supplier_credit_notes cn
             WHERE cn.supplier_id = ? AND cn.status IN ('approved','applied')
               AND cn.credit_date BETWEEN ? AND ?
             ORDER BY cn.credit_date ASC, cn.credit_note_id ASC
        ");
        $cnStmt->execute([$vendor_id, $date_from, $date_to]);
        foreach ($cnStmt->fetchAll(PDO::FETCH_ASSOC) as $cn) {
            $events[] = ['date' => $cn['d'], 'type' => 'credit_note', 'ref' => $cn['ref'],
                         'description' => 'Credit Note — ' . ($cn['reason'] ?: $cn['ref']),
                         'charge' => 0.0, 'payment' => (float)$cn['amount']];
        }
    }

    // Sort: date ASC, bills before payments on same day
    usort($events, function ($a, $b) {
        if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
        $order = ['bill' => 0, 'credit_note' => 1, 'payment' => 2];
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
        'vendor'          => $vendor,
        'date_from'       => $date_from,
        'date_to'         => $date_to,
        'opening_balance' => round($opening, 2),
        'lines'           => $lines,
        'totals'          => ['charge' => round($totCharge, 2), 'payment' => round($totPayment, 2)],
        'closing_balance' => round($balance, 2),
    ]);

} catch (Throwable $e) {
    error_log('get_vendor_statement error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
