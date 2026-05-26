<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/workflow.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

if (!canEdit('projects')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to change IPC status']);
    exit();
}

$ipc_id    = intval($_POST['ipc_id'] ?? 0);
$newStatus = trim($_POST['status'] ?? '');

if (!$ipc_id || !in_array($newStatus, ['Viewed', 'Approved'])) {
    echo json_encode(['success'=>false,'message'=>'Invalid request']); exit();
}

try {
    // Phase E — project-scope gate
    $proj = $pdo->prepare("SELECT project_id FROM interim_payment_certificates WHERE ipc_id = ?");
    $proj->execute([$ipc_id]);
    $ipc_project_id = $proj->fetchColumn();
    if ($ipc_project_id && function_exists('userCan') && !userCan('project', (int)$ipc_project_id)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied: project not in your scope.']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT status FROM interim_payment_certificates WHERE ipc_id = ?");
    $stmt->execute([$ipc_id]);
    $current = $stmt->fetchColumn();

    if ($newStatus === 'Viewed' && $current !== 'Draft') {
        echo json_encode(['success'=>false,'message'=>'Only Draft IPCs can be marked as Reviewed']); exit();
    }
    if ($newStatus === 'Approved' && $current !== 'Viewed') {
        echo json_encode(['success'=>false,'message'=>'Only Viewed IPCs can be Approved']); exit();
    }

    $userId = $_SESSION['user_id'];
    $actor  = workflowActorSnapshot();
    if ($newStatus === 'Viewed') {
        $upd = $pdo->prepare("UPDATE interim_payment_certificates SET status=?, reviewed_by=?, updated_at=NOW() WHERE ipc_id=?");
        $upd->execute([$newStatus, $userId, $ipc_id]);
        workflowCaptureSignature($pdo, 'ipc', $ipc_id, 'reviewed', $userId, $actor['name'], $actor['role']);
    } else {
        $upd = $pdo->prepare("UPDATE interim_payment_certificates SET status=?, approved_by=?, updated_at=NOW() WHERE ipc_id=?");
        $upd->execute([$newStatus, $userId, $ipc_id]);
        workflowCaptureSignature($pdo, 'ipc', $ipc_id, 'approved', $userId, $actor['name'], $actor['role']);
    }
    logActivity($pdo, $_SESSION['user_id'], "Updated IPC {$ipc_id} status to {$newStatus}");
    echo json_encode(['success'=>true,'message'=>'Status updated to ' . $newStatus]);
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
