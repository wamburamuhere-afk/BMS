<?php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to mark payroll as paid']);
    exit();
}

$payroll_id = $_POST['payroll_id'] ?? null;

if (!$payroll_id) {
    echo json_encode(['success' => false, 'message' => 'Payroll ID required']);
    exit();
}

try {
    // DB Hardening
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed') DEFAULT 'pending'");
    try { $pdo->exec("ALTER TABLE payroll ADD COLUMN gross_salary DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount"); } catch(Exception $e) {}
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        UPDATE payroll 
        SET payment_status = 'paid', 
            payment_date = NOW(),
            updated_at = NOW()
        WHERE payroll_id = ? 
        AND payment_status IN ('approved', 'processing')
    ");
    $stmt->execute([$payroll_id]);
    
    if ($stmt->rowCount() > 0) {
        logActivity($pdo, $_SESSION['user_id'], "Marked Payroll Paid", "Payroll ID: $payroll_id");
        echo json_encode(['success' => true, 'message' => 'Payroll marked as paid successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not mark as paid. Ensure the record is Approved or Processing.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
