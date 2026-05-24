<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $userId = $_SESSION['user_id'] ?? 0;
    
    $id = $_POST['campaign_id'] ?? null;
    if (!$id) {
        throw new Exception("Campaign ID is required");
    }

    $stmt = $pdo->prepare("DELETE FROM marketing_campaigns WHERE campaign_id = ?");
    $stmt->execute([$id]);

    logActivity($pdo, $userId, "Deleted Marketing Campaign", "Campaign ID: $id");

    echo json_encode(['success' => true, 'message' => "Campaign deleted successfully"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
