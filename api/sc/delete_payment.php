<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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

try {
    $row = $pdo->prepare("SELECT id FROM sc_payments WHERE id = ?");
    $row->execute([$id]);
    if (!$row->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        exit();
    }
    $pdo->prepare("DELETE FROM sc_payments WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'Payment deleted successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
