<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canDelete('supplier_payments')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete sub-contractor payments']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$id = intval($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Payment ID is required']);
    exit();
}

// Phase C — block deletes against sc_payments on projects not in user scope
assertScopeForRecord('sc_payments', 'id', $id);

try {
    $row = $pdo->prepare("SELECT transaction_id FROM sc_payments WHERE id = ?");
    $row->execute([$id]);
    $rec = $row->fetch(PDO::FETCH_ASSOC);
    if (!$rec) {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        exit();
    }
    if (!empty($rec['transaction_id'])) {
        reverseOutflow($pdo, (int)$rec['transaction_id']);
    }
    $pdo->prepare("DELETE FROM sc_payments WHERE id = ?")->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'], "Delete sub-contractor payment", "deleted sub-contractor payment with id $id");

    echo json_encode(['success' => true, 'message' => 'Payment deleted successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
