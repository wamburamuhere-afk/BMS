<?php
// File: api/account/review_quotation.php
// Workflow transition: pending -> reviewed. Stamps reviewed_by / reviewed_at.
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

if (!canReview('sales_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to review quotations']);
    exit;
}

try {
    global $pdo;

    $id = intval($_POST['quotation_id'] ?? $_POST['id'] ?? 0);
    if (!$id) {
        throw new Exception("Missing quotation ID");
    }

    $stmt = $pdo->prepare("SELECT order_number, status FROM quotations WHERE sales_order_id = ?");
    $stmt->execute([$id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) {
        throw new Exception("Quotation not found");
    }
    if ($quote['status'] !== 'pending') {
        throw new Exception("Only a pending quotation can be reviewed (current status: " . ucfirst($quote['status']) . ").");
    }

    $pdo->prepare("
        UPDATE quotations
        SET status = 'reviewed', reviewed_by = ?, reviewed_at = NOW(), updated_by = ?, updated_at = NOW()
        WHERE sales_order_id = ?
    ")->execute([$_SESSION['user_id'], $_SESSION['user_id'], $id]);

    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Review Quotation',
        "$user_name marked Quotation #{$quote['order_number']} as reviewed");

    echo json_encode(['success' => true, 'message' => 'Quotation marked as reviewed.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
