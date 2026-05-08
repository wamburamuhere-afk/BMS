<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $id = $_POST['lead_id'] ?? null;
    if (!$id) {
        throw new Exception("Lead ID is required");
    }

    $stmt = $pdo->prepare("DELETE FROM leads WHERE lead_id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => "Lead deleted successfully"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
