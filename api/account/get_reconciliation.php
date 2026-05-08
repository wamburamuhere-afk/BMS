<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    $id = $_GET['id'] ?? 0;
    if (!$id) {
        throw new Exception('Invalid Reconciliation ID');
    }

    $stmt = $pdo->prepare("SELECT * FROM bank_reconciliations WHERE reconciliation_id = ?");
    $stmt->execute([$id]);
    $reconciliation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reconciliation) {
        throw new Exception('Reconciliation not found');
    }

    echo json_encode(['success' => true, 'data' => $reconciliation]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
