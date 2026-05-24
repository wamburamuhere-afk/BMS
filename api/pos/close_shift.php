<?php
/**
 * API: Close/End Shift
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canEdit('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to close POS shifts']);
    exit();
}

try {
    global $pdo;

    $user_id = $_SESSION['user_id'];
    $ending_cash = isset($_POST['ending_cash']) ? floatval($_POST['ending_cash']) : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    // Get active shift
    $shift_id = isset($_SESSION['shift_id']) ? $_SESSION['shift_id'] : null;
    
    if (!$shift_id) {
        echo json_encode([
            'success' => false,
            'message' => 'No active shift found'
        ]);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$shift_id, $user_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift) {
        echo json_encode([
            'success' => false,
            'message' => 'Active shift not found'
        ]);
        exit();
    }
    
    // Calculate expected cash
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'cash_in' THEN amount ELSE 0 END), 0) as cash_in,
            COALESCE(SUM(CASE WHEN transaction_type = 'cash_out' THEN amount ELSE 0 END), 0) as cash_out,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'sale' THEN amount ELSE 0 END), 0) as cash_sales,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'refund' THEN amount ELSE 0 END), 0) as cash_refunds
        FROM cash_register_transactions 
        WHERE shift_id = ?
    ");
    $stmt->execute([$shift_id]);
    $cash_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $expected_cash = $shift['starting_cash'] + 
                    $cash_data['cash_in'] - 
                    $cash_data['cash_out'] + 
                    $cash_data['cash_sales'] - 
                    $cash_data['cash_refunds'];
    
    $cash_difference = $ending_cash - $expected_cash;
    
    // Close shift
    $stmt = $pdo->prepare("
        UPDATE cash_register_shifts 
        SET end_time = NOW(), 
            ending_cash = ?, 
            expected_cash = ?,
            cash_difference = ?,
            notes = ?,
            status = 'closed',
            updated_at = NOW()
        WHERE shift_id = ?
    ");
    
    $stmt->execute([$ending_cash, $expected_cash, $cash_difference, $notes, $shift_id]);
    
    // Clear session shift
    unset($_SESSION['shift_id']);
    
    require_once __DIR__ . '/../../helpers.php';
    $username = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $user_id, 'Close POS Shift', "$username closed POS shift #{$shift['shift_code']} (Ending Cash: " . number_format($ending_cash, 2) . ")");

    echo json_encode([
        'success' => true,
        'message' => 'Shift closed successfully',
        'shift_id' => $shift_id,
        'starting_cash' => $shift['starting_cash'],
        'ending_cash' => $ending_cash,
        'expected_cash' => $expected_cash,
        'cash_difference' => $cash_difference
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
