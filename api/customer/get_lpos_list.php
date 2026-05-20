<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('customers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$customer_id = intval($_GET['customer_id'] ?? 0);
if (!$customer_id) {
    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT lpo_id, lpo_number, issue_date, expiry_date, amount, currency,
               description, status, document_path, notes, created_at
        FROM customer_lpos
        WHERE customer_id = ? AND status != 'deleted'
        ORDER BY issue_date DESC, lpo_id DESC
    ");
    $stmt->execute([$customer_id]);
    $lpos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $lpos]);
} catch (PDOException $e) {
    error_log("get_lpos_list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
