<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    $project_id = $_POST['project_id'] ?? null;

    if (!$project_id) throw new Exception('Project ID is required');

    $stmtApprove = $pdo->prepare("UPDATE project_planning_reports SET status = 'approved' WHERE project_id = ?");
    $stmtApprove->execute([$project_id]);

    echo json_encode(['success' => true, 'message' => 'Plan approved successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
