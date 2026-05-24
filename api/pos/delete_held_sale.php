<?php
/**
 * API: Delete Held Sale
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canDelete('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete held POS sales']);
    exit();
}

try {
    global $pdo;

    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true);
    $hold_id = isset($data['hold_id']) ? intval($data['hold_id']) : 0;
    $user_id = $_SESSION['user_id'];
    
    if ($hold_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid hold ID']);
        exit();
    }
    
    // Verify ownership and status
    $stmt = $pdo->prepare("SELECT hold_id FROM pos_held_sales WHERE hold_id = ? AND user_id = ? AND status = 'held'");
    $stmt->execute([$hold_id, $user_id]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Held sale not found or access denied']);
        exit();
    }
    
    // Delete (or soft delete)
    // For now we'll update status to 'cancelled' so we keep history, or delete physically.
    // Let's delete strictly to keep it clean as requested.
    $stmt = $pdo->prepare("DELETE FROM pos_held_sales WHERE hold_id = ?");
    $stmt->execute([$hold_id]);

    logActivity($pdo, $user_id, "Deleted Held POS Sale", "Hold ID: $hold_id");

    echo json_encode([
        'success' => true,
        'message' => 'Held sale deleted successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
