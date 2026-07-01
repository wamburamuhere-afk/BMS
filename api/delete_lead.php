<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');

    if (!canDelete('lead_generation')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete leads');
    }

    $id = $_POST['lead_id'] ?? null;
    if (!$id) {
        throw new Exception("Lead ID is required");
    }

    $stmt = $pdo->prepare("DELETE FROM leads WHERE lead_id = ?");
    $stmt->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Delete lead", "deleted lead with id $id");

    echo json_encode(['success' => true, 'message' => "Lead deleted successfully"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
