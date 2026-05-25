<?php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change payroll status']);
    exit();
}

$payroll_id = $_POST['payroll_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$payroll_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Payroll ID and status required']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('payroll', 'payroll_id', $payroll_id);
}

try {
    // DB Hardening
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed') DEFAULT 'pending'");
    try { $pdo->exec("ALTER TABLE payroll ADD COLUMN gross_salary DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount"); } catch(Exception $e) {}
} catch (Exception $e) {}

try {
    // Additional fields based on status
    $sql = "UPDATE payroll SET payment_status = ?, updated_by = ?, updated_at = NOW()";
    
    if ($status === 'approved') {
        $sql .= ", approved_by = " . $_SESSION['user_id'] . ", date_approved = NOW()";
    } elseif ($status === 'paid') {
         $sql .= ", payment_date = NOW()";
    }
    
    $sql .= " WHERE payroll_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status, $_SESSION['user_id'], $payroll_id]);
    
    // Log status update action
    logAudit($pdo, $_SESSION['user_id'], 'update_payroll_status', [
        'activity_type' => 'update',
        'entity_type' => 'payroll',
        'entity_id' => $payroll_id,
        'description' => "Updated payroll status to '$status' for record ID: $payroll_id"
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Status updated successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
