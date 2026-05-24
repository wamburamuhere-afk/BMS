<?php
/**
 * API: Open/Start Shift
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canCreate('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to open POS shifts']);
    exit();
}

try {
    global $pdo;

    $user_id = $_SESSION['user_id'];
    $opening_cash = isset($_POST['opening_cash']) ? floatval($_POST['opening_cash']) : 0;
    
    // Check if user already has an active shift
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $existing_shift = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_shift) {
        echo json_encode([
            'success' => false,
            'message' => 'You already have an active shift. Please close it first.'
        ]);
        exit();
    }
    
    // Generate shift code
    $shift_code = 'SHIFT-' . date('Ymd-His') . '-' . $user_id;
    
    // Create new shift
    $stmt = $pdo->prepare("
        INSERT INTO cash_register_shifts 
        (shift_code, user_id, start_time, starting_cash, status, created_at) 
        VALUES (?, ?, NOW(), ?, 'active', NOW())
    ");
    
    $stmt->execute([$shift_code, $user_id, $opening_cash]);
    $shift_id = $pdo->lastInsertId();
    
    // Store shift ID in session
    $_SESSION['shift_id'] = $shift_id;
    
    // Note: We DO NOT record an 'Opening cash' transaction in the transactions table
    // because the pos.php calculation already adds shift_active['starting_cash'].
    // Recording it as a 'cash_in' transaction causes the balance to double.
    
    require_once __DIR__ . '/../../helpers.php';
    $username = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $user_id, 'Open POS Shift', "$username opened a new POS shift (Starting Cash: " . number_format($opening_cash, 2) . ")");

    echo json_encode([
        'success' => true,
        'message' => 'Shift started successfully',
        'shift_id' => $shift_id,
        'shift_code' => $shift_code,
        'starting_cash' => $opening_cash
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
