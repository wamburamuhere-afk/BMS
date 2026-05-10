<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$id = $_GET['id'] ?? null;
if (!$id) { echo json_encode(['success'=>false,'message'=>'ID required']); exit(); }

$stmt = $pdo->prepare("
    SELECT i.*, m.description as milestone_description
    FROM project_inspections i
    LEFT JOIN project_milestones m ON i.milestone_id = m.id
    WHERE i.inspection_id = ?
");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) echo json_encode(['success'=>true,'data'=>$row]);
else echo json_encode(['success'=>false,'message'=>'Inspection not found']);
