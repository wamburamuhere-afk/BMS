<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    
    $project_id = $_GET['project_id'] ?? null;
    if (!$project_id) throw new Exception('Project ID is required');
    assertScopeForRecord('projects', 'project_id', intval($project_id));

    $stmt = $pdo->prepare("SELECT *, (SELECT id FROM project_milestones pm2 WHERE pm2.parent_id = pm.id LIMIT 1) as has_children FROM project_milestones pm WHERE project_id = ? AND scope_type = 'milestone' ORDER BY id ASC");
    $stmt->execute([$project_id]);
    $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $milestones]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
