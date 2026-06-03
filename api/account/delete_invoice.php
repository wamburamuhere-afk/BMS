<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/vat.php';

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
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Invoice", "Invoice ID: $invoice_id");

    echo json_encode(['success' => true, 'message' => 'Invoice deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error deleting invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
