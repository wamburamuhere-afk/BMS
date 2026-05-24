<?php
// api/operations/delete_project.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;
$id = $_POST['project_id'] ?? null;

try {
    $stmt = $pdo->prepare("DELETE FROM projects WHERE project_id = ?");
    $stmt->execute([$id]);

    // Phase 3c — project deletes have wide downstream impact (POs, GRNs, payroll).
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Project", "Project ID: $id");

    echo json_encode(["success" => true, "message" => "Project deleted successfully"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
