<?php
// API: Get a single employee contract (Tier 2, Phase 2.3) — for view/edit modals.
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('employee_contracts')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$contract_id = intval($_GET['contract_id'] ?? $_GET['id'] ?? 0);
if (!$contract_id) {
    echo json_encode(['success' => false, 'message' => 'Contract ID is required']);
    exit;
}

if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_contracts', 'contract_id', $contract_id);
}

try {
    $stmt = $pdo->prepare("
        SELECT ec.*, e.first_name, e.last_name, e.employee_number,
               DATEDIFF(ec.end_date, CURDATE()) AS days_to_expiry
        FROM employee_contracts ec
        JOIN employees e ON e.employee_id = ec.employee_id
        WHERE ec.contract_id = ? AND ec.status != 'deleted'
    ");
    $stmt->execute([$contract_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { echo json_encode(['success' => false, 'message' => 'Contract not found']); exit; }

    echo json_encode(['success' => true, 'data' => $row]);

} catch (Exception $e) {
    error_log("get_contract error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
