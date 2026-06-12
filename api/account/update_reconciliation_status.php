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

// Check permission (adjust 'bank_reconciliation' if needed to a generic perm or specific one)
// if (!canEdit('bank_reconciliation')) { ... }

$reconciliation_id = $_POST['reconciliation_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$reconciliation_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$valid_statuses = ['pending', 'reconciled', 'disputed', 'cancelled'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

try {
    global $pdo;
    
    // Update status + capture who changed it and when.
    // NOTE: bank_reconciliations has no updated_by column — the actor/timestamp of a
    // status change is recorded via reviewed_by / reviewed_date (its review fields).
    $stmt = $pdo->prepare("
        UPDATE bank_reconciliations
        SET status = ?, updated_at = NOW(), reviewed_by = ?, reviewed_date = NOW()
        WHERE reconciliation_id = ?
    ");

    $result = $stmt->execute([$status, $_SESSION['user_id'], $reconciliation_id]);

    if ($result) {
        // Phase 3a — financial-write audit trail.
        logActivity($pdo, $_SESSION['user_id'], "Updated Bank Reconciliation Status", "Reconciliation ID: $reconciliation_id, new status: $status");
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }

} catch (Exception $e) {
    error_log("Error updating reconciliation status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
