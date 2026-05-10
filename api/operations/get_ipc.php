<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$id = $_GET['id'] ?? null;
if (!$id) { echo json_encode(['success'=>false,'message'=>'ID required']); exit(); }

$stmt = $pdo->prepare("
    SELECT ipc.*, m.description as milestone_description, i.invoice_number
    FROM interim_payment_certificates ipc
    LEFT JOIN project_milestones m ON ipc.milestone_id = m.id
    LEFT JOIN invoices i ON ipc.invoice_id = i.invoice_id
    WHERE ipc.ipc_id = ?
");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) echo json_encode(['success'=>true,'data'=>$row]);
else echo json_encode(['success'=>false,'message'=>'IPC not found']);
