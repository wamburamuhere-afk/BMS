<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) { echo json_encode(['success'=>false,'message'=>'Project ID required']); exit(); }
assertScopeForRecord('projects', 'project_id', intval($project_id));

$stmt = $pdo->prepare("
    SELECT i.*, m.description as milestone_description
    FROM project_inspections i
    LEFT JOIN project_milestones m ON i.milestone_id = m.id
    WHERE i.project_id = ?
    ORDER BY i.inspection_date DESC, i.created_at DESC
");
$stmt->execute([$project_id]);
echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
