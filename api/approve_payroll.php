<?php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canApprove('payroll') && !canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to approve payroll']);
    exit();
}

$payroll_id = $_POST['payroll_id'] ?? null;

if (!$payroll_id) {
    echo json_encode(['success' => false, 'message' => 'Payroll ID required']);
    exit();
}

try {
    $stmt = $pdo->prepare("UPDATE payroll SET payment_status = 'approved' WHERE payroll_id = ?");
    $stmt->execute([$payroll_id]);
    
    // Log approval action
    logAudit($pdo, $_SESSION['user_id'], 'approve_payroll', [
        'activity_type' => 'update',
        'entity_type' => 'payroll',
        'entity_id' => $payroll_id,
        'description' => "Approved payroll record ID: $payroll_id"
    ]);

    echo json_encode(['success' => true, 'message' => 'Payroll approved successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
