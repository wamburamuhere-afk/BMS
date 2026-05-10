<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) { echo json_encode(['success'=>false,'message'=>'Project ID required']); exit(); }

$stmt = $pdo->prepare("
    SELECT ipc.*, m.description as milestone_description, i.invoice_number
    FROM interim_payment_certificates ipc
    LEFT JOIN project_milestones m ON ipc.milestone_id = m.id
    LEFT JOIN invoices i ON ipc.invoice_id = i.invoice_id
    WHERE ipc.project_id = ?
    ORDER BY ipc.created_at DESC
");
$stmt->execute([$project_id]);
echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
