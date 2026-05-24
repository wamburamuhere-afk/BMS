<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $id = $_POST['id'] ?? null;
    if (!$id) throw new Exception("ID required");

    $stmt = $pdo->prepare("DELETE FROM compliance_records WHERE id = ?");
    $stmt->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Compliance Record", "Record ID: $id");

    echo json_encode(['success' => true, 'message' => "Record deleted"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
