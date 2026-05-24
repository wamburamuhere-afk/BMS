<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    if (!canCreate('projects') && !canEdit('projects')) {
        throw new Exception('Access Denied: you do not have permission to save project planning');
    }

    $project_id = $_POST['project_id'] ?? null;
    $title = $_POST['title'] ?? 'Project Plan';
    $tasks = json_decode($_POST['tasks'] ?? '[]', true);

    if (!$project_id) throw new Exception('Project ID is required');

    $pdo->beginTransaction();

    // Enforce single report per project
    $stmtCheck = $pdo->prepare("SELECT id FROM project_planning_reports WHERE project_id = ? LIMIT 1");
    $stmtCheck->execute([$project_id]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        $report_id = $existing['id'];
        $stmtUpdate = $pdo->prepare("UPDATE project_planning_reports SET title = ? WHERE id = ?");
        $stmtUpdate->execute([$title, $report_id]);
        
        $stmtDelete = $pdo->prepare("DELETE FROM project_planning_tasks WHERE report_id = ?");
        $stmtDelete->execute([$report_id]);
    } else {
        $stmtInsertReport = $pdo->prepare("INSERT INTO project_planning_reports (project_id, title, created_by) VALUES (?, ?, ?)");
        $stmtInsertReport->execute([$project_id, $title, $_SESSION['user_id']]);
        $report_id = $pdo->lastInsertId();
    }

    $stmtInsertTask = $pdo->prepare("INSERT INTO project_planning_tasks (report_id, task_name, duration_days, start_date, finish_date, is_phase, level, temp_id_mapped) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    $tempIdToRealId = [];
    foreach ($tasks as $t) {
        $stmtInsertTask->execute([
            $report_id,
            $t['task_name'],
            $t['duration_days'],
            $t['start_date'],
            $t['finish_date'],
            $t['is_phase'] ?? 0,
            $t['level'] ?? 0,
            $t['temp_id'] ?? null
        ]);
        $tempIdToRealId[$t['temp_id']] = $pdo->lastInsertId();
    }

    // Pass 2: Update parent_id using the map
    $stmtUpdateParent = $pdo->prepare("UPDATE project_planning_tasks SET parent_id = ? WHERE id = ?");
    foreach ($tasks as $t) {
        if (!empty($t['parent_temp_id'])) {
            $realParentId = $tempIdToRealId[$t['parent_temp_id']] ?? null;
            if ($realParentId) {
                $stmtUpdateParent->execute([$realParentId, $tempIdToRealId[$t['temp_id']]]);
            }
        }
    }

    $pdo->commit();

    // Phase 3c — project planning sets the baseline schedule.
    logActivity($pdo, $_SESSION['user_id'], "Saved Project Planning", "Report ID: $report_id");

    echo json_encode(['success' => true, 'message' => 'Planning saved successfully', 'report_id' => $report_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
