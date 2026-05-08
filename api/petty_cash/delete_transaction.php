<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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
    
    echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
