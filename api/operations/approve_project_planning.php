<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    if (!canApprove('projects') && !canEdit('projects')) {
        throw new Exception('Access Denied: you do not have permission to approve project planning');
    }

    $project_id = $_POST['project_id'] ?? null;

    if (!$project_id) throw new Exception('Project ID is required');

    // Phase B (scope) — block approvals against projects not in user scope
    if (!userCan('project', (int)$project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this project is not in your scope.');
    }

    $stmtApprove = $pdo->prepare("UPDATE project_planning_reports SET status = 'approved' WHERE project_id = ?");
    $stmtApprove->execute([$project_id]);

    // Phase 3c — project planning approvals are high-sensitivity operational events.
    logActivity($pdo, $_SESSION['user_id'], "Approved Project Planning", "Project ID: $project_id");

    echo json_encode(['success' => true, 'message' => 'Plan approved successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
