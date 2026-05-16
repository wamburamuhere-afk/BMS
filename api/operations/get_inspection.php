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
if (!$row) { echo json_encode(['success'=>false,'message'=>'Inspection not found']); exit(); }

// Load inspectors from inspection_inspectors table
$ins_stmt = $pdo->prepare("SELECT inspector_name, inspector_org FROM inspection_inspectors WHERE inspection_id = ? ORDER BY sort_order ASC");
$ins_stmt->execute([$id]);
$inspectors = $ins_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load attachments
$att_stmt = $pdo->prepare("SELECT id, original_name, display_name, file_name, file_type, file_size FROM inspection_attachments WHERE inspection_id = ? ORDER BY uploaded_at ASC");
$att_stmt->execute([$id]);
$attachments = $att_stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success'=>true, 'data'=>$row, 'inspectors'=>$inspectors, 'attachments'=>$attachments]);
