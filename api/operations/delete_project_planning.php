<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    $project_id = $_POST['project_id'] ?? null;

    if (!$project_id) throw new Exception('Project ID is required');

    $pdo->beginTransaction();

    // 1. Get the report ID first
    $stmt = $pdo->prepare("SELECT id FROM project_planning_reports WHERE project_id = ?");
    $stmt->execute([$project_id]);
    $report_id = $stmt->fetchColumn();

    if ($report_id) {
        // 2. Delete tasks
        $stmtDeleteTasks = $pdo->prepare("DELETE FROM project_planning_tasks WHERE report_id = ?");
        $stmtDeleteTasks->execute([$report_id]);

        // 3. Delete report
        $stmtDeleteReport = $pdo->prepare("DELETE FROM project_planning_reports WHERE id = ?");
        $stmtDeleteReport->execute([$report_id]);
    }

    $pdo->commit();

    // Phase 3c — project planning is foundational; deletion erases scope baselines.
    logActivity($pdo, $_SESSION['user_id'], "Deleted Project Planning", "Project ID: $project_id");

    echo json_encode(['success' => true, 'message' => 'Project plan deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
