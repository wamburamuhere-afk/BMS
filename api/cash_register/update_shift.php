<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !canEdit('cash_register'))) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Manager access required.']);
    exit();
}

try {
    $shift_id = intval($_POST['shift_id'] ?? 0);
    $starting_cash = floatval($_POST['starting_cash'] ?? 0);
    $ending_cash = ($_POST['ending_cash'] !== '') ? floatval($_POST['ending_cash']) : null;
    $status = trim($_POST['status'] ?? 'active');

    if (!$shift_id) {
        throw new Exception("Shift ID is required.");
    }

    // Check if shift exists
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ?");
    $stmt->execute([$shift_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        throw new Exception("Shift record not found.");
    }

    // Special validation: If changing status to 'active', ensure the user doesn't already have one
    if ($status === 'active' && $shift['status'] !== 'active') {
        $checkStmt = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active'");
        $checkStmt->execute([$shift['user_id']]);
        if ($checkStmt->rowCount() > 0) {
            throw new Exception("Cannot reopen shift. This user already has another active shift.");
        }
    }

    // Update shift
    $updateStmt = $pdo->prepare("
        UPDATE cash_register_shifts 
        SET starting_cash = ?, 
            ending_cash = ?, 
            status = ?,
            end_time = CASE WHEN ? = 'closed' AND end_time IS NULL THEN NOW() ELSE end_time END
        WHERE shift_id = ?
    ");
    
    $updateStmt->execute([$starting_cash, $ending_cash, $status, $status, $shift_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Shift updated successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
