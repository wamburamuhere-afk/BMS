<?php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// canEdit('payroll') admin-bypasses internally; replaces legacy hard-coded
// role-string check so non-admin roles (e.g., Accountant) can be delegated
// via user_roles.php instead of code.
if (!canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to process payroll']);
    exit();
}

try {
    // Get form data
    $action = $_POST['action'] ?? '';
    $payroll_ids_json = $_POST['payroll_ids'] ?? '[]';
    $payroll_ids = json_decode($payroll_ids_json, true);
    
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    if (empty($payroll_ids) || !is_array($payroll_ids)) {
        throw new Exception('No payroll records selected');
    }
    
    $pdo->beginTransaction();
    
    $updated_count = 0;
    
    switch ($action) {
        case 'update_status':
            $status = $_POST['status'] ?? '';
            if (empty($status)) {
                throw new Exception('Status is required');
            }
            
            $stmt = $pdo->prepare("UPDATE payroll SET payment_status = ? WHERE payroll_id = ?");
            foreach ($payroll_ids as $id) {
                $stmt->execute([$status, $id]);
                $updated_count++;
            }
            break;
            
        case 'update_payment_method':
            $method = $_POST['payment_method'] ?? '';
            if (empty($method)) {
                throw new Exception('Payment method is required');
            }
            
            $stmt = $pdo->prepare("UPDATE payroll SET payment_method = ? WHERE payroll_id = ?");
            foreach ($payroll_ids as $id) {
                $stmt->execute([$method, $id]);
                $updated_count++;
            }
            break;
            
        case 'add_allowance':
            $amount = floatval($_POST['amount'] ?? 0);
            $description = $_POST['description'] ?? 'Bulk Allowance';
            
            if ($amount <= 0) {
                throw new Exception('Valid amount is required');
            }
            
            // Get current allowances and update
            $get_stmt = $pdo->prepare("SELECT allowances, gross_salary, net_salary, deductions, tax_amount FROM payroll WHERE payroll_id = ?");
            $update_stmt = $pdo->prepare("UPDATE payroll SET allowances = ?, gross_salary = ?, net_salary = ? WHERE payroll_id = ?");
            
            foreach ($payroll_ids as $id) {
                $get_stmt->execute([$id]);
                $record = $get_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($record) {
                    $new_allowances = $record['allowances'] + $amount;
                    $new_gross = $record['gross_salary'] + $amount; // Assuming basic stays same
                    // Recalculate net (gross - deductions - tax)
                    // Note: This doesn't auto-recalculate tax. Ideally it should.
                    $new_net = $new_gross - $record['deductions'] - $record['tax_amount'];
                    
                    $update_stmt->execute([$new_allowances, $new_gross, $new_net, $id]);
                    $updated_count++;
                }
            }
            break;
            
        case 'add_deduction':
            $amount = floatval($_POST['amount'] ?? 0);
            $description = $_POST['description'] ?? 'Bulk Deduction';
            
            if ($amount <= 0) {
                throw new Exception('Valid amount is required');
            }
            
            // Get current deductions and update
            $get_stmt = $pdo->prepare("SELECT deductions, gross_salary, net_salary, tax_amount FROM payroll WHERE payroll_id = ?");
            $update_stmt = $pdo->prepare("UPDATE payroll SET deductions = ?, net_salary = ? WHERE payroll_id = ?");
            
            foreach ($payroll_ids as $id) {
                $get_stmt->execute([$id]);
                $record = $get_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($record) {
                    $new_deductions = $record['deductions'] + $amount;
                    $new_net = $record['gross_salary'] - $new_deductions - $record['tax_amount'];
                    
                    $update_stmt->execute([$new_deductions, $new_net, $id]);
                    $updated_count++;
                }
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    $pdo->commit();
    
    // Log bulk update action
    logAudit($pdo, $_SESSION['user_id'], 'bulk_payroll_action', [
        'activity_type' => 'process',
        'entity_type' => 'payroll',
        'description' => "Bulk payroll action '$action' applied to $updated_count records."
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Successfully updated $updated_count records"
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
