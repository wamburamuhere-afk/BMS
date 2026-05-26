<?php
// File: api/account/approve_invoice.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canApprove('invoices')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to approve invoices']);
    exit;
}

try {
    global $pdo;
    $invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
    if (!$invoice_id) throw new Exception("Invalid Invoice ID");

    // Phase C — block approvals against invoices on projects not in user scope
    assertScopeForRecord('invoices', 'invoice_id', $invoice_id);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT status FROM invoices WHERE invoice_id = ? FOR UPDATE");
    $stmt->execute([$invoice_id]);
    $current_status = $stmt->fetchColumn();
    if ($current_status === false) throw new Exception("Invoice not found");

    assertApprovable($current_status);

    $actor = workflowActorSnapshot();

    $stmt = $pdo->prepare("
        UPDATE invoices
        SET status            = 'approved',
            approved_by       = ?,
            approved_by_name  = ?,
            approved_by_role  = ?,
            approved_at       = NOW(),
            updated_by        = ?,
            updated_at        = NOW()
        WHERE invoice_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $_SESSION['user_id'], $invoice_id]);

    $sigResult = workflowCaptureSignature($pdo, 'invoice', $invoice_id, 'approved',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Approved Invoice #$invoice_id");
    }

    $pdo->commit();

    $response = ['success' => true, 'message' => 'Invoice approved.'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
