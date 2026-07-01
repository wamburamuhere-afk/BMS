<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
if (!canDelete('projects')) { echo json_encode(['success'=>false,'message'=>'Permission denied']); exit(); }

$id = $_POST['ipc_id'] ?? null;
if (!$id) { echo json_encode(['success'=>false,'message'=>'ID required']); exit(); }

try {
    // Phase E — project-scope gate
    $proj = $pdo->prepare("SELECT project_id FROM interim_payment_certificates WHERE ipc_id = ?");
    $proj->execute([$id]);
    $ipc_project_id = $proj->fetchColumn();
    if ($ipc_project_id && function_exists('userCan') && !userCan('project', (int)$ipc_project_id)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied: project not in your scope.']);
        exit();
    }

    // Only allow delete if not Paid and no linked invoice
    $check = $pdo->prepare("SELECT status, invoice_id FROM interim_payment_certificates WHERE ipc_id=?");
    $check->execute([$id]);
    $ipc = $check->fetch(PDO::FETCH_ASSOC);
    if (!$ipc) { echo json_encode(['success'=>false,'message'=>'IPC not found']); exit(); }
    if ($ipc['status'] === 'Paid') { echo json_encode(['success'=>false,'message'=>'Cannot delete a Paid IPC']); exit(); }
    if ($ipc['invoice_id']) { echo json_encode(['success'=>false,'message'=>'Cannot delete IPC linked to an invoice. Remove the invoice link first.']); exit(); }

    $pdo->prepare("DELETE FROM interim_payment_certificates WHERE ipc_id=?")->execute([$id]);
    logActivity($pdo, $_SESSION['user_id'], "Delete ipc", "deleted IPC with id $id");
    echo json_encode(['success'=>true,'message'=>'IPC deleted successfully']);
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
