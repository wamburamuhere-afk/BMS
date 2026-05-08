<?php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$payroll_ids = $_POST['payroll_ids'] ?? []; // Expecting an array
$status = $_POST['status'] ?? '';

if (empty($payroll_ids) || !is_array($payroll_ids)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payroll IDs selected']);
    exit();
}

if (empty($status)) {
    echo json_encode(['success' => false, 'message' => 'Status required']);
    exit();
}

try {
    // DB Hardening: Fix ENUMs and add missing columns
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed') DEFAULT 'pending'");
    try { $pdo->exec("ALTER TABLE payroll ADD COLUMN gross_salary DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount"); } catch(Exception $e) {}
} catch (Exception $e) {}

try {
    $placeholders = str_repeat('?,', count($payroll_ids) - 1) . '?';
    
    // Only allow logical transitions
    $where_extra = "";
    if ($status === 'paid') {
        $where_extra = " AND payment_status IN ('approved', 'processing')";
    } elseif ($status === 'approved') {
        $where_extra = " AND payment_status IN ('pending', 'processing')";
    } elseif ($status === 'processing') {
        $where_extra = " AND payment_status IN ('pending', 'rejected')";
    }

    $sql = "UPDATE payroll SET 
                payment_status = ?, 
                updated_at = NOW(),
                payment_date = CASE WHEN ? = 'paid' THEN NOW() ELSE payment_date END
            WHERE payroll_id IN ($placeholders) $where_extra";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$status, $status], $payroll_ids));
    $rowCount = $stmt->rowCount();
    
    // Log bulk update action
    logAudit($pdo, $_SESSION['user_id'], 'bulk_update_payroll_status', [
        'activity_type' => 'update',
        'entity_type' => 'payroll',
        'description' => "Updated status to '$status' for $rowCount payroll records."
    ]);
    
    echo json_encode(['success' => true, 'message' => "Bulk status update completed. $rowCount records updated successfully."]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
