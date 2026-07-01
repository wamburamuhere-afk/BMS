<?php
// File: api/customer/approve_lpo.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canApprove('lpo')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to approve LPOs']);
    exit;
}

try {
    global $pdo;
    $lpo_id = isset($_POST['lpo_id']) ? intval($_POST['lpo_id']) : 0;
    if (!$lpo_id) throw new Exception('Invalid LPO ID');

    assertScopeForRecord('customer_lpos', 'lpo_id', $lpo_id);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT status, lpo_number FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted' FOR UPDATE");
    $stmt->execute([$lpo_id]);
    $lpo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lpo) throw new Exception('LPO not found');

    assertApprovable($lpo['status']);

    $actor = workflowActorSnapshot();

    $stmt = $pdo->prepare("
        UPDATE customer_lpos
        SET status = 'approved', approved_by = ?, approved_by_name = ?, approved_by_role = ?, approved_at = NOW()
        WHERE lpo_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $lpo_id]);

    $sigResult = workflowCaptureSignature($pdo, 'customer_lpo', $lpo_id, 'approved', $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity($pdo, $_SESSION['user_id'], "Approved LPO #{$lpo['lpo_number']} (ID $lpo_id)");

    $pdo->commit();

    $response = ['success' => true, 'message' => 'LPO approved.'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
