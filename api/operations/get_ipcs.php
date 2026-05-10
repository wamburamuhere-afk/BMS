<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) { echo json_encode(['success'=>false,'message'=>'Project ID required']); exit(); }

$stmt = $pdo->prepare("
    SELECT ipc.*, m.description as milestone_description, i.invoice_number,
        so.order_number,
        COALESCE(so_c.customer_name, proj_c.customer_name) AS customer_name
    FROM interim_payment_certificates ipc
    LEFT JOIN project_milestones m ON ipc.milestone_id = m.id
    LEFT JOIN invoices i ON ipc.invoice_id = i.invoice_id
    LEFT JOIN sales_orders so ON ipc.sales_order_id = so.sales_order_id
    LEFT JOIN customers so_c ON so.customer_id = so_c.customer_id
    LEFT JOIN projects p ON ipc.project_id = p.project_id
    LEFT JOIN customers proj_c ON p.customer_id = proj_c.customer_id
    WHERE ipc.project_id = ?
    ORDER BY ipc.created_at DESC
");
$stmt->execute([$project_id]);
echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
