<?php
// File: api/account/approve_invoice.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';
require_once __DIR__ . '/../../core/auto_post_hook.php';

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

    // Phase 4.3 — auto-post to the canonical ledger via journal_mappings.
    // Reads invoice grand_total + invoice_date + project_id so the journal
    // entry matches the document. Quiet no-op while the admin keeps the
    // 'invoice_approved' mapping is_active=0 (default after Phase 4.1).
    $inv = $pdo->prepare("SELECT invoice_number, invoice_date, grand_total, project_id
                            FROM invoices WHERE invoice_id = ?");
    $inv->execute([$invoice_id]);
    $inv_row = $inv->fetch(PDO::FETCH_ASSOC);
    $post_result = ['posted' => false, 'reason' => 'no_amount'];
    if ($inv_row && (float)$inv_row['grand_total'] > 0) {
        $post_result = autoPostEvent(
            $pdo,
            'invoice_approved',
            'invoice',
            $invoice_id,
            (float)$inv_row['grand_total'],
            $inv_row['project_id'] !== null ? (int)$inv_row['project_id'] : null,
            $inv_row['invoice_date'],
            (int)$_SESSION['user_id'],
            "Invoice #{$inv_row['invoice_number']} approved"
        );
    }

    if (function_exists('logActivity')) {
        $note = "Approved Invoice #$invoice_id";
        if (!empty($post_result['posted'])) {
            $note .= " (journal entry #{$post_result['entry_id']})";
        } elseif (($post_result['reason'] ?? '') === 'already_posted') {
            $note .= " (already in ledger as entry #{$post_result['existing_entry_id']})";
        }
        logActivity($pdo, $_SESSION['user_id'], $note);
    }

    $pdo->commit();

    $response = ['success' => true, 'message' => 'Invoice approved.'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    if (!empty($post_result['posted'])) {
        $response['journal_entry_id'] = $post_result['entry_id'];
    } elseif (($post_result['reason'] ?? '') === 'mapping_not_configured') {
        $response['ledger_warning'] = "Invoice approved, but no ledger entry was created — admin has not "
                                    . "set both Dr/Cr accounts for 'invoice_approved' in Journal Mappings.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
