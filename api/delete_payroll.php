<?php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canDelete('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete payroll records']);
    exit();
}

$payroll_id = $_POST['payroll_id'] ?? null;

if (!$payroll_id) {
    echo json_encode(['success' => false, 'message' => 'Payroll ID required']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('payroll', 'payroll_id', $payroll_id);
}

try {
    // Check if record exists
    $stmt = $pdo->prepare("SELECT payroll_number FROM payroll WHERE payroll_id = ?");
    $stmt->execute([$payroll_id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payroll) {
        throw new Exception('Payroll record not found');
    }

    $stmt = $pdo->prepare("DELETE FROM payroll WHERE payroll_id = ?");
    $stmt->execute([$payroll_id]);

    // Log delete action
    logAudit($pdo, $_SESSION['user_id'], 'delete_payroll', [
        'activity_type' => 'delete',
        'entity_type' => 'payroll',
        'entity_id' => $payroll_id,
        'description' => "Deleted payroll record #" . $payroll['payroll_number'] . " (ID: $payroll_id)"
    ]);

    echo json_encode(['success' => true, 'message' => 'Payroll record deleted successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
