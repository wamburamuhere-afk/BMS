<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canDelete('petty_cash')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete petty cash transactions']);
    exit();
}

try {
    $id = $_POST['id'] ?? 0;
    
    if (!$id) {
        throw new Exception("Invalid transaction ID");
    }
    
    // Optional: Only allow deletion of recent transactions or by admin
    // For now, allow logged in user to delete
    
    $stmt = $pdo->prepare("DELETE FROM petty_cash_transactions WHERE id = ?");
    $stmt->execute([$id]);

    // Phase 3b — petty cash deletions are high-sensitivity financial events.
    logActivity($pdo, $_SESSION['user_id'], "Deleted Petty Cash Transaction", "Transaction ID: $id");

    echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
