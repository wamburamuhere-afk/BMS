<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/vat.php';
require_once __DIR__ . '/../../core/revenue_posting.php';   // invoiceRevenueReversed()

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// canDelete('invoices') admin-bypasses internally; future roles can be granted via user_roles.php
if (!canDelete('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete invoices']);
    exit;
}

$invoice_id = $_POST['invoice_id'] ?? 0;

if (!$invoice_id) {
    echo json_encode(['success' => false, 'message' => 'Invoice ID required']);
    exit;
}

// Phase C — block deletes against invoices on projects not in user scope
assertScopeForRecord('invoices', 'invoice_id', $invoice_id);

try {
    global $pdo;
    
    // Verify invoice exists and is deletable (e.g. only drafts)
    $stmt = $pdo->prepare("SELECT status FROM invoices WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        throw new Exception("Invoice not found");
    }

    // GUARD — an APPROVED/posted invoice has revenue + COGS in the canonical ledger
    // (Dr AR / Cr Sales Revenue / Cr Output VAT  and  Dr COGS / Cr Inventory), and a
    // PAID one also has a collection entry (Dr Bank / Cr AR). A hard delete here would
    // ORPHAN all of that (revenue, COGS, AR, VAT, bank overstated). So block it and
    // require Cancel first — update_invoice_status.php's 'cancelled' path reverses the
    // GL correctly. Only a draft / never-posted invoice (or one already cancelled and
    // reversed, with no payments) may be hard-deleted.
    $revenuePosted = (int)$pdo->query(
        "SELECT COUNT(*) FROM journal_entries WHERE entity_type='invoice' AND entity_id=" . (int)$invoice_id . " AND status='posted'"
    )->fetchColumn() > 0;
    $needsReversal = $revenuePosted && !invoiceRevenueReversed($pdo, (int)$invoice_id);

    $payChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND status = 'completed'");
    $payChk->execute([(int)$invoice_id]);
    $hasPayments = (int)$payChk->fetchColumn() > 0;

    if ($needsReversal || $hasPayments) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' =>
            'This invoice is approved/posted' . ($hasPayments ? ' and has payments' : '')
            . ' and is locked. Cancel it first (which reverses the ledger entries), then it can be deleted.']);
        exit;
    }

    $pdo->beginTransaction();

    // Un-recognise any output VAT this invoice posted (reverses Output VAT
    // Payable by the exact amount posted; no-op if it was never approved).
    reverseOutputVat($pdo, (int)$invoice_id);

    // Delete items
    $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoice_id]);
    
    // Delete invoice
    $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$invoice_id]);
    
    $pdo->commit();

    // Phase 3a — financial-write audit trail.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Delete invoice", "deleted invoice with id $invoice_id");

    echo json_encode(['success' => true, 'message' => 'Invoice deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error deleting invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
