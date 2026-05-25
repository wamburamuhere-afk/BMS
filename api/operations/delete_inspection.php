<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
if (!canDelete('projects')) { echo json_encode(['success'=>false,'message'=>'Permission denied']); exit(); }

$id = $_POST['inspection_id'] ?? null;
if (!$id) { echo json_encode(['success'=>false,'message'=>'ID required']); exit(); }

try {
    // Phase E — project-scope gate via inspection's project_id
    $proj = $pdo->prepare("SELECT project_id FROM project_inspections WHERE inspection_id = ?");
    $proj->execute([$id]);
    $insp_project_id = $proj->fetchColumn();
    if ($insp_project_id && function_exists('userCan') && !userCan('project', (int)$insp_project_id)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied: project not in your scope.']);
        exit();
    }

    $pdo->prepare("DELETE FROM project_inspections WHERE inspection_id=?")->execute([$id]);
    logActivity($pdo, $_SESSION['user_id'], "Deleted inspection ID: {$id}");
    echo json_encode(['success'=>true,'message'=>'Inspection deleted successfully']);
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
