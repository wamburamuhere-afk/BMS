<?php
/**
 * api/account/get_employee_statement.php
 *
 * Statement of account for ONE employee over a date range:
 * opening payable (accrued but unpaid salary) as of date_from, then each
 * payroll run (charge) and each salary payment in date order with a running
 * balance, and the closing payable.
 *
 * Charge  = payroll run (status IN approved/paid) — gross_salary accrued to
 *           the company's obligation; net_salary is what the employee takes home
 *           but the full gross is what we owe before deductions are remitted.
 *           We use net_salary here because that is the actual cash obligation
 *           to the employee (PAYE/NSSF are separate payable accounts).
 * Payment = payroll run where payment_status = 'paid' AND payment_date is set.
 *
 * Response:
 *   { success, employee{...}, date_from, date_to, opening_balance,
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

$employee_id = (int)($_GET['employee_id'] ?? 0);
$date_from   = $_GET['date_from'] ?? date('Y-01-01');
$date_to     = $_GET['date_to']   ?? date('Y-m-d');

if ($employee_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Select an employee']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)   ||
    $date_from > $date_to) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}

try {
    global $pdo;

    // Employee lookup — scope-guarded so a non-admin can only see their own project's staff.
    // assertScopeForEmployee() returns false (with 403) for out-of-scope ids; for admins it
    // returns true unconditionally. We also include the nullable scope clause in the SELECT so
    // a hand-crafted request can't bypass the guard via a direct id.
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }
    $scopeE  = scopeFilterSqlNullable('project', 'e');
    $empStmt = $pdo->prepare("
        SELECT e.employee_id,
               CONCAT(e.first_name, ' ', e.last_name) AS full_name,
               e.employee_number, e.email, e.phone, e.department,
               e.designation_id, e.basic_salary
          FROM employees e
         WHERE e.employee_id = ? AND e.status != 'terminated' $scopeE
    ");
    $empStmt->execute([$employee_id]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }

    // ── Opening balance (net salary accrued before date_from, minus payments made before date_from) ──
    $ob = $pdo->prepare("
        SELECT
          (SELECT COALESCE(SUM(net_salary), 0)
             FROM payroll
            WHERE employee_id = ? AND status IN ('approved','paid')
              AND payroll_date < ?)
          -
          (SELECT COALESCE(SUM(net_salary), 0)
             FROM payroll
            WHERE employee_id = ? AND payment_status = 'paid'
              AND payment_date IS NOT NULL AND payment_date < ?)
        AS opening
    ");
    $ob->execute([$employee_id, $date_from, $employee_id, $date_from]);
    $opening = (float)$ob->fetchColumn();

    $events = [];

    // ── In-range charges: approved/paid payroll runs ──
    $chargeStmt = $pdo->prepare("
        SELECT payroll_date AS d, payroll_number AS ref,
               net_salary AS amount, status, payment_status,
               CONCAT(YEAR(payroll_date), '-', LPAD(MONTH(payroll_date), 2, '0')) AS period_label
          FROM payroll
         WHERE employee_id = ? AND status IN ('approved','paid')
           AND payroll_date BETWEEN ? AND ?
         ORDER BY payroll_date ASC, payroll_id ASC
    ");
    $chargeStmt->execute([$employee_id, $date_from, $date_to]);
    foreach ($chargeStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $events[] = [
            'date'        => $r['d'],
            'type'        => 'charge',
            'ref'         => $r['ref'],
            'description' => 'Payroll — ' . $r['period_label'],
            'charge'      => (float)$r['amount'],
            'payment'     => 0.0,
        ];
    }

    // ── In-range payments: runs where salary was actually paid ──
    $payStmt = $pdo->prepare("
        SELECT payment_date AS d, payroll_number AS ref,
               net_salary AS amount, payment_method,
               CONCAT(YEAR(payroll_date), '-', LPAD(MONTH(payroll_date), 2, '0')) AS period_label
          FROM payroll
         WHERE employee_id = ? AND payment_status = 'paid'
           AND payment_date IS NOT NULL
           AND payment_date BETWEEN ? AND ?
         ORDER BY payment_date ASC, payroll_id ASC
    ");
    $payStmt->execute([$employee_id, $date_from, $date_to]);
    foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $method = $r['payment_method'] ? " ({$r['payment_method']})" : '';
        $events[] = [
            'date'        => $r['d'],
            'type'        => 'payment',
            'ref'         => $r['ref'],
            'description' => "Salary Payment{$method} — {$r['period_label']}",
            'charge'      => 0.0,
            'payment'     => (float)$r['amount'],
        ];
    }

    // Sort: date ASC, charges before payments on same day
    usort($events, function ($a, $b) {
        if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
        $order = ['charge' => 0, 'payment' => 1];
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
        'employee'        => $employee,
        'date_from'       => $date_from,
        'date_to'         => $date_to,
        'opening_balance' => round($opening, 2),
        'lines'           => $lines,
        'totals'          => ['charge' => round($totCharge, 2), 'payment' => round($totPayment, 2)],
        'closing_balance' => round($balance, 2),
    ]);

} catch (Throwable $e) {
    error_log('get_employee_statement error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
