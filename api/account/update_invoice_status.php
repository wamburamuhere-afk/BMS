<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/vat.php';
require_once __DIR__ . '/../../core/revenue_posting.php';

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

// Check permissions
if (!canEdit('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to update invoice status']);
    exit;
}

$invoice_id = $_POST['invoice_id'] ?? 0;
$status = $_POST['status'] ?? '';

if (!$invoice_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Invoice ID and status required']);
    exit;
}

// Phase C — block status changes against invoices on projects not in user scope
assertScopeForRecord('invoices', 'invoice_id', $invoice_id);

$valid_statuses = ['draft', 'pending', 'reviewed', 'approved', 'sent', 'paid', 'partial', 'cancelled', 'overdue'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

try {
    global $pdo;

    // Enforce workflow for reviewed/approved
    if (in_array($status, ['reviewed', 'approved'])) {
        $cur = $pdo->prepare("SELECT status FROM invoices WHERE invoice_id = ?");
        $cur->execute([$invoice_id]);
        $current = $cur->fetchColumn();
        if ($status === 'reviewed' && $current !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'Only Pending invoices can be marked as Reviewed']); exit;
        }
        if ($status === 'approved' && $current !== 'reviewed') {
            echo json_encode(['success' => false, 'message' => 'Only Reviewed invoices can be Approved']); exit;
        }
    }

    $userId = $_SESSION['user_id'];

    // Guard: an invoice that already has payment(s) recorded must not be cancelled.
    // Cancelling reverses revenue/COGS/output VAT, but the payment's own entry
    // (Dr Bank / Cr AR) would remain untouched — leaving AR wrong and the bank
    // overstated against revenue that no longer exists. Mirrors the equivalent
    // guard already enforced on Bills (supplierInvoiceHasPayments() in
    // received_invoices.php) — remove/void the payment(s) first, then cancel.
    if ($status === 'cancelled') {
        $payChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND status = 'completed'");
        $payChk->execute([$invoice_id]);
        if ((int)$payChk->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'This invoice has payment(s) recorded and cannot be cancelled. Remove or void the payment(s) first.']);
            exit;
        }
    }

    $pdo->beginTransaction();

    if ($status === 'reviewed') {
        $stmt = $pdo->prepare("UPDATE invoices SET status = ?, reviewed_by = ?, updated_by = ?, updated_at = NOW() WHERE invoice_id = ?");
        $result = $stmt->execute([$status, $userId, $userId, $invoice_id]);
    } elseif ($status === 'approved') {
        $stmt = $pdo->prepare("UPDATE invoices SET status = ?, approved_by = ?, updated_by = ?, updated_at = NOW() WHERE invoice_id = ?");
        $result = $stmt->execute([$status, $userId, $userId, $invoice_id]);
        // IN-3 (money.md): recognise revenue with ONE balanced entry into the canonical
        // ledger (Dr AR / Cr Sales Revenue / Cr Output VAT). Supersedes the single-sided
        // postOutputVat() nudge. Idempotent — safe alongside approve_invoice.php.
        require_once __DIR__ . '/../../core/revenue_posting.php';
        postInvoiceRevenue($pdo, (int)$invoice_id, (int)$userId);
        // IS Phase 2 — match COGS to the revenue: Dr Cost of Goods Sold / Cr Inventory.
        postInvoiceCOGS($pdo, (int)$invoice_id, (int)$userId);
    } elseif ($status === 'paid') {
        $stmt = $pdo->prepare("
            UPDATE invoices
            SET status = ?, paid_amount = grand_total, balance_due = 0,
                payment_date = COALESCE(payment_date, CURDATE()),
                updated_by = ?, updated_at = NOW()
            WHERE invoice_id = ?
        ");
        $result = $stmt->execute([$status, $userId, $invoice_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE invoices SET status = ?, updated_by = ?, updated_at = NOW() WHERE invoice_id = ?");
        $result = $stmt->execute([$status, $userId, $invoice_id]);
        if ($status === 'cancelled') {
            // Reverse revenue GL entry (Dr AR / Cr Revenue) — idempotent.
            reverseInvoiceRevenue($pdo, (int)$invoice_id, (int)$userId);
            // Reverse COGS GL entry (Dr COGS / Cr Inventory) — idempotent.
            reverseInvoiceCOGS($pdo, (int)$invoice_id, (int)$userId);
            // Un-recognise output VAT stamp — idempotent.
            reverseOutputVat($pdo, (int)$invoice_id);
        }
    }

    if ($result && $pdo->inTransaction()) $pdo->commit();

    if ($result) {
        // Phase 3a — financial-write audit trail.
        logActivity($pdo, $userId, "Updated Invoice Status", "Invoice ID: $invoice_id, new status: $status");
        echo json_encode(['success' => true, 'message' => 'Invoice status updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Error updating invoice status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
