<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $shift_id = $_POST['shift_id'] ?? 0;
    $transaction_type = $_POST['transaction_type'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $reference = trim($_POST['reference'] ?? '');
    
    if (!$shift_id || !$transaction_type || $amount <= 0) {
        throw new Exception("Invalid input data");
    }

    // Verify shift is active
    $shiftStmt = $pdo->prepare("SELECT status FROM cash_register_shifts WHERE shift_id = ?");
    $shiftStmt->execute([$shift_id]);
    $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift || $shift['status'] !== 'active') {
        throw new Exception("Shift is not active");
    }

    // Insert transaction
    $stmt = $pdo->prepare("
        INSERT INTO cash_register_transactions 
        (shift_id, transaction_type, amount, description, reference_number, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $shift_id, 
        $transaction_type, 
        $amount, 
        $description, 
        $reference,
        $_SESSION['user_id']
    ]);

    echo json_encode(['success' => true, 'message' => 'Transaction added successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
