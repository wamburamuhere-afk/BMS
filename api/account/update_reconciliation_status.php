<?php
require_once __DIR__ . '/../../../roots.php';
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
    
    // Update status and timestamp
    $stmt = $pdo->prepare("
        UPDATE bank_reconciliations 
        SET status = ?, updated_at = NOW(), updated_by = ? 
        WHERE reconciliation_id = ?
    ");
    
    $result = $stmt->execute([$status, $_SESSION['user_id'], $reconciliation_id]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }

} catch (Exception $e) {
    error_log("Error updating reconciliation status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
