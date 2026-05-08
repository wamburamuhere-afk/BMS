<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!hasPermission('delete_purchase_returns')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $returnId = $_POST['return_id'] ?? 0;
    
    if (!$returnId) {
        throw new Exception("Invalid ID");
    }
    
    // Check status before delete
    $stmt = $pdo->prepare("SELECT status FROM purchase_returns WHERE purchase_return_id = ?");
    $stmt->execute([$returnId]);
    $status = $stmt->fetchColumn();

    if (!in_array($status, ['pending', 'rejected', 'cancelled'])) {
        throw new Exception("Cannot delete an approved or completed return");
    }

    $pdo->beginTransaction();

    // Delete items first
    $stmt = $pdo->prepare("DELETE FROM purchase_return_items WHERE purchase_return_id = ?");
    $stmt->execute([$returnId]);

    // Delete header
    $stmt = $pdo->prepare("DELETE FROM purchase_returns WHERE purchase_return_id = ?");
    $stmt->execute([$returnId]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Return deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
