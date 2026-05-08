<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $user_id = $_SESSION['user_id'];
    $starting_cash = floatval($_POST['opening_balance'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Check if user already has an active shift
    $checkStmt = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active'");
    $checkStmt->execute([$user_id]);
    
    if ($checkStmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have an active shift']);
        exit();
    }
    
    // Generate shift code
    $shift_code = 'SH-' . date('Ymd') . '-' . str_pad($user_id, 4, '0', STR_PAD_LEFT) . '-' . time();
    
    // Create new shift
    $stmt = $pdo->prepare("
        INSERT INTO cash_register_shifts (shift_code, user_id, starting_cash, notes, status, start_time)
        VALUES (?, ?, ?, ?, 'active', NOW())
    ");
    
    $stmt->execute([$shift_code, $user_id, $starting_cash, $notes]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Shift opened successfully',
        'shift_id' => $pdo->lastInsertId()
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
