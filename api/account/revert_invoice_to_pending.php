<?php
// api/account/revert_invoice_to_pending.php
//
// Workflow rollback: reviewed|approved → pending.
// For approved invoices this also reverses the GL revenue/COGS/VAT entries
// so the ledger stays balanced. Reviewed stamps and approved stamps are cleared;
// the Created By signature is untouched.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/revenue_posting.php';  // reverseInvoiceRevenue / reverseInvoiceCOGS
require_once __DIR__ . '/../../core/vat.php';              // reverseOutputVat

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to revert invoices']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
if ($invoice_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid invoice ID']);
    exit;
}

try {
    global $pdo;

    assertScopeForRecord('invoices', 'invoice_id', $invoice_id);

    $pdo->beginTransaction();

    $row = $pdo->prepare("SELECT status, invoice_number FROM invoices WHERE invoice_id = ? FOR UPDATE");
    $row->execute([$invoice_id]);
    $inv = $row->fetch(PDO::FETCH_ASSOC);
    if (!$inv) throw new Exception('Invoice not found.');

    $current = $inv['status'];
    if (!in_array($current, ['reviewed', 'approved'], true)) {
        throw new Exception("Only Reviewed or Approved invoices can be sent back to Pending (current status: $current).");
    }

    // Block revert if any completed payment is recorded — a payment proves the
    // customer has already paid; that cash entry can't just be "un-approved".
    $payChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND status = 'completed'");
    $payChk->execute([$invoice_id]);
    if ((int)$payChk->fetchColumn() > 0) {
        throw new Exception('This invoice has completed payment(s) and cannot be sent back to Pending. Void the payment(s) first.');
    }

    $uid = (int)$_SESSION['user_id'];

    // If the invoice was approved its GL entries are live — reverse them first.
    if ($current === 'approved') {
        reverseInvoiceRevenue($pdo, $invoice_id, $uid);
        reverseInvoiceCOGS($pdo, $invoice_id, $uid);
        reverseOutputVat($pdo, $invoice_id);
    }

    // Clear both workflow stamps; Created By columns are never touched.
    $pdo->prepare("
        UPDATE invoices
        SET status            = 'pending',
            reviewed_by       = NULL,
            reviewed_by_name  = NULL,
            reviewed_by_role  = NULL,
            reviewed_at       = NULL,
            approved_by       = NULL,
            approved_by_name  = NULL,
            approved_by_role  = NULL,
            approved_at       = NULL,
            output_vat_posted = NULL,
            updated_by        = ?,
            updated_at        = NOW()
        WHERE invoice_id = ?
    ")->execute([$uid, $invoice_id]);

    // Remove reviewed/approved e-signature rows so the audit panel starts clean.
    $pdo->prepare(
        "DELETE FROM workflow_signatures
         WHERE entity_type = 'invoice' AND entity_id = ? AND action IN ('reviewed','approved')"
    )->execute([$invoice_id]);

    $pdo->commit();

    $num = $inv['invoice_number'];
    logActivity($pdo, $uid, "Reverted invoice $num (ID $invoice_id) from $current back to Pending");
    if (function_exists('logAudit')) {
        logAudit($pdo, $uid, 'invoice_reverted_to_pending', [
            'entity_type' => 'invoice', 'entity_id' => $invoice_id,
            'old_values'  => ['status' => $current],
            'new_values'  => ['status' => 'pending'],
        ]);
    }

    $glNote = ($current === 'approved') ? ' GL entries reversed.' : '';
    echo json_encode(['success' => true, 'message' => "Invoice $num sent back to Pending.$glNote"]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('revert_invoice_to_pending error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
