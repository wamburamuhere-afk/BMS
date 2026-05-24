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

    if ($status === 'reviewed') {
        $stmt = $pdo->prepare("UPDATE invoices SET status = ?, reviewed_by = ?, updated_by = ?, updated_at = NOW() WHERE invoice_id = ?");
        $result = $stmt->execute([$status, $userId, $userId, $invoice_id]);
    } elseif ($status === 'approved') {
        $stmt = $pdo->prepare("UPDATE invoices SET status = ?, approved_by = ?, updated_by = ?, updated_at = NOW() WHERE invoice_id = ?");
        $result = $stmt->execute([$status, $userId, $userId, $invoice_id]);
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
    }
    
    if ($result) {
        // Phase 3a — financial-write audit trail.
        logActivity($pdo, $userId, "Updated Invoice Status", "Invoice ID: $invoice_id, new status: $status");
        echo json_encode(['success' => true, 'message' => 'Invoice status updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }

} catch (Exception $e) {
    error_log("Error updating invoice status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
