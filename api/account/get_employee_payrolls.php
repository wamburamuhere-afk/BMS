<?php
// Hardened JSON endpoint: suppress any stray notice/warning HTML so the response
// is ALWAYS valid JSON. A leaked notice here previously left the payroll dropdown
// stuck on "Loading…" (the front-end's $.getJSON could not parse the body).
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('expenses')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$employee_id        = intval($_GET['employee_id'] ?? 0);
$current_payroll_id = intval($_GET['current_payroll_id'] ?? 0);

if (!$employee_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee ID']);
    exit;
}

assertScopeForEmployee($employee_id);

try {
    $stmt = $pdo->prepare("
        SELECT payroll_id, payroll_number, payroll_period, net_salary, payment_status
        FROM payroll
        WHERE employee_id      = ?
          AND status           = 'approved'
          AND (payment_status != 'paid' OR payroll_id = ?)
        ORDER BY payroll_period DESC
    ");
    $stmt->execute([$employee_id, $current_payroll_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(fn($r) => [
        'id'     => $r['payroll_id'],
        'label'  => $r['payroll_number'] . ' — ' . $r['payroll_period'] . ' — TZS ' . number_format((float)$r['net_salary'], 2),
        'amount' => $r['net_salary'],
        'ref'    => $r['payroll_number'],
    ], $rows);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (PDOException $e) {
    error_log('get_employee_payrolls: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
