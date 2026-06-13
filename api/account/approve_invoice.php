<?php
// File: api/account/approve_invoice.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';
require_once __DIR__ . '/../../core/auto_post_hook.php';
require_once __DIR__ . '/../../core/vat.php';
require_once __DIR__ . '/../../core/revenue_posting.php';   // IN-3: postInvoiceRevenue

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

    // IN-3 (money.md): recognise revenue with ONE balanced double-entry into the
    // canonical ledger — Dr Accounts Receivable / Cr Sales Revenue / Cr Output VAT.
    // This supersedes the old single-sided postOutputVat() nudge and the gated
    // autoPostEvent() no-op. Idempotent (won't double-post on re-approval).
    $post_result = postInvoiceRevenue($pdo, $invoice_id, (int)$_SESSION['user_id']);

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
    } elseif (($post_result['reason'] ?? '') === 'accounts_not_configured') {
        $response['ledger_warning'] = "Invoice approved, but no ledger entry was created — the Accounts "
                                    . "Receivable and/or Sales Revenue account could not be resolved. "
                                    . "Configure them in settings (or ensure 1-1200 / 4-1000 exist).";
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
