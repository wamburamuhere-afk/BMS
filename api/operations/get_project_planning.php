<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');

    $project_id = $_GET['project_id'] ?? null;
    $report_id = $_GET['report_id'] ?? null;

    if (!$project_id) throw new Exception('Project ID is required');

    if (!$report_id) {
        // Automatically fetch the existing report for this project
        $stmtReport = $pdo->prepare("SELECT * FROM project_planning_reports WHERE project_id = ? LIMIT 1");
        $stmtReport->execute([$project_id]);
        $report = $stmtReport->fetch(PDO::FETCH_ASSOC);
        
        if ($report) {
            $report_id = $report['id'];
        }
    } else {
        $stmtReport = $pdo->prepare("SELECT * FROM project_planning_reports WHERE id = ?");
        $stmtReport->execute([$report_id]);
        $report = $stmtReport->fetch(PDO::FETCH_ASSOC);
    }

    if ($report) {
        $stmtTasks = $pdo->prepare("SELECT * FROM project_planning_tasks WHERE report_id = ? ORDER BY id ASC");
        $stmtTasks->execute([$report_id]);
        $tasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'report' => $report, 'tasks' => $tasks]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No planning found for this project', 'no_plan' => true]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
