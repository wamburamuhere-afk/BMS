<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canEdit('cash_register')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to close cash register shifts']);
    exit();
}

try {
    $user_id = $_SESSION['user_id'];
    $ending_cash = floatval($_POST['closing_balance'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Get user's active shift
    $shiftStmt = $pdo->prepare("SELECT shift_id, starting_cash FROM cash_register_shifts WHERE user_id = ? AND status = 'active'");
    $shiftStmt->execute([$user_id]);
    $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift) {
        echo json_encode(['success' => false, 'message' => 'No active shift found']);
        exit();
    }
    
    // Calculate expected cash and difference
    $transStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_register_transactions WHERE shift_id = ?");
    $transStmt->execute([$shift['shift_id']]);
    $trans_total = $transStmt->fetchColumn();
    
    $expected_cash = $shift['starting_cash'] + $trans_total;
    $cash_difference = $ending_cash - $expected_cash;
    
    // Close shift
    $stmt = $pdo->prepare("
        UPDATE cash_register_shifts 
        SET ending_cash = ?, 
            expected_cash = ?,
            actual_cash = ?,
            cash_difference = ?,
            notes = CONCAT(COALESCE(notes, ''), '\n', ?),
            status = 'closed',
            end_time = NOW(),
            closed_by = ?
        WHERE shift_id = ?
    ");
    
    $stmt->execute([$ending_cash, $expected_cash, $ending_cash, $cash_difference, $notes, $user_id, $shift['shift_id']]);

    // Phase 3b — closing a cash-register shift is a critical financial event.
    logActivity($pdo, $user_id, "Closed Cash Register Shift", "Shift ID: {$shift['shift_id']}, expected: $expected_cash, actual: $ending_cash, diff: $cash_difference");

    echo json_encode([
        'success' => true,
        'message' => 'Shift closed successfully',
        'cash_difference' => $cash_difference
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
