<?php
// api/operations/delete_maintenance_log.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isAuthenticated()) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

if (!canDelete('maintenance')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access Denied: you do not have permission to delete maintenance logs"]);
    exit;
}

$id = $_POST['log_id'] ?? null;

try {
    $stmt = $pdo->prepare("DELETE FROM maintenance_logs WHERE log_id = ?");
    $stmt->execute([$id]);

    // Phase 3c — maintenance log deletes destroy audit history.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Maintenance Log", "Log ID: $id");

    echo json_encode(["success" => true, "message" => "Log deleted"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
