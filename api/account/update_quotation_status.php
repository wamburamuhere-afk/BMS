<?php
// File: api/account/update_quotation_status.php
// Updates the status of a quotation in the dedicated `quotations` table.
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

if (!canEdit('sales_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to update quotation status']);
    exit;
}

try {
    global $pdo;

    $quotation_id = intval($_POST['quotation_id'] ?? $_POST['order_id'] ?? 0);
    $status       = $_POST['status'] ?? '';

    if (!$quotation_id || !$status) {
        throw new Exception("Missing quotation ID or status");
    }

    $valid_statuses = ['draft', 'pending', 'approved', 'cancelled'];
    if (!in_array($status, $valid_statuses, true)) {
        throw new Exception("Invalid status");
    }

    $info = $pdo->prepare("SELECT order_number FROM quotations WHERE sales_order_id = ?");
    $info->execute([$quotation_id]);
    $quotation = $info->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        throw new Exception("Quotation not found");
    }

    $pdo->prepare("UPDATE quotations SET status = ?, updated_at = NOW(), updated_by = ? WHERE sales_order_id = ?")
        ->execute([$status, $_SESSION['user_id'], $quotation_id]);

    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], "Update Quotation Status",
        "$user_name updated Quotation #{$quotation['order_number']} status to " . ucfirst($status));

    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
