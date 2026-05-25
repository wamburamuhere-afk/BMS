<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$supplier_id = intval($_GET['supplier_id'] ?? 0);
$project_id  = intval($_GET['project_id'] ?? 0);

if (!$supplier_id || !$project_id) {
    echo json_encode(['success' => false, 'message' => 'supplier_id and project_id are required']);
    exit();
}

// Phase C — block reads against projects not in user scope
if (!userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT id, payment_date, amount, currency, payment_method,
               reference_number, receipt_number, notes, status, created_at
        FROM sc_payments
        WHERE supplier_id = ? AND project_id = ?
        ORDER BY payment_date DESC, created_at DESC
    ");
    $stmt->execute([$supplier_id, $project_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = array_sum(array_column($payments, 'amount'));

    echo json_encode(['success' => true, 'payments' => $payments, 'total' => $total]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
