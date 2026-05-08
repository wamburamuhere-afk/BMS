<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

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

// Check delete permission
if (!canDelete('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to delete invoices']);
    exit;
}

$invoice_id = $_POST['invoice_id'] ?? 0;

if (!$invoice_id) {
    echo json_encode(['success' => false, 'message' => 'Invoice ID required']);
    exit;
}

try {
    global $pdo;
    
    // Verify invoice exists and is deletable (e.g. only drafts)
    $stmt = $pdo->prepare("SELECT status FROM invoices WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) {
        throw new Exception("Invoice not found");
    }
    
    if ($invoice['status'] !== 'draft') {
        throw new Exception("Only draft invoices can be deleted");
    }

    $pdo->beginTransaction();
    
    // Delete items
    $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoice_id]);
    
    // Delete invoice
    $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$invoice_id]);
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Invoice deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error deleting invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
