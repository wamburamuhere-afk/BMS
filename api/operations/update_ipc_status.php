<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/workflow.php';
require_once __DIR__ . '/../../core/ipc_posting.php';   // postIpcRevenue (OUT-15)
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

    // Status flip + workflow signature commit together — an IPC can't reach a
    // new status with no record of who moved it there.
    $pdo->beginTransaction();
    try {
        if ($newStatus === 'Viewed') {
            $upd = $pdo->prepare("UPDATE interim_payment_certificates SET status=?, reviewed_by=?, updated_at=NOW() WHERE ipc_id=?");
            $upd->execute([$newStatus, $userId, $ipc_id]);
            workflowCaptureSignature($pdo, 'ipc', $ipc_id, 'reviewed', $userId, $actor['name'], $actor['role']);
        } else {
            $upd = $pdo->prepare("UPDATE interim_payment_certificates SET status=?, approved_by=?, updated_at=NOW() WHERE ipc_id=?");
            $upd->execute([$newStatus, $userId, $ipc_id]);
            workflowCaptureSignature($pdo, 'ipc', $ipc_id, 'approved', $userId, $actor['name'], $actor['role']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    logActivity($pdo, $_SESSION['user_id'], "Updated IPC {$ipc_id} status to {$newStatus}");

    // money.md OUT-15 — recognise contract revenue when the IPC is certified
    // (Approved): Dr Accounts Receivable / Cr Contract Revenue (net_payable).
    // Best-effort: a missing account never blocks certification — it surfaces as
    // a ledger warning. Idempotent on (entity_type='ipc', ipc_id), so a warned
    // approval is healed by re-approving flows or the posting backfill.
    // (postIpcRevenue posts via ledger_post.php, which is internally atomic.)
    $resp = ['success' => true, 'message' => 'Status updated to ' . $newStatus];
    if ($newStatus === 'Approved') {
        $ipc_post = postIpcRevenue($pdo, (int)$ipc_id, (int)$userId);
        if (!empty($ipc_post['posted']) && !empty($ipc_post['entry_id'])) {
            $resp['journal_entry_id'] = $ipc_post['entry_id'];
        } elseif (($ipc_post['reason'] ?? '') === 'accounts_not_configured') {
            $resp['ledger_warning'] = 'IPC approved, but no ledger entry was created — the Accounts '
                                    . 'Receivable / Contract Revenue account could not be resolved.';
        } elseif (($ipc_post['reason'] ?? '') === 'post_error') {
            $resp['ledger_warning'] = 'IPC approved, but the contract-revenue entry failed to post — see the server log.';
        }
    }
    echo json_encode($resp);
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
