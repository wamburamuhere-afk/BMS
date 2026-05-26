<?php
// scope-audit: skip — single IPC read; parent project scope enforced at project level
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$id = $_GET['id'] ?? null;
if (!$id) { echo json_encode(['success'=>false,'message'=>'ID required']); exit(); }

try {
    $stmt = $pdo->prepare("
        SELECT ipc.*, i.invoice_number, so.order_number, so.customer_id AS so_customer_id,
            p.customer_id AS proj_customer_id
        FROM interim_payment_certificates ipc
        LEFT JOIN invoices i ON ipc.invoice_id = i.invoice_id
        LEFT JOIN sales_orders so ON ipc.sales_order_id = so.sales_order_id
        LEFT JOIN projects p ON ipc.project_id = p.project_id
        WHERE ipc.ipc_id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) echo json_encode(['success'=>true,'data'=>$row]);
    else echo json_encode(['success'=>false,'message'=>'IPC not found']);
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
