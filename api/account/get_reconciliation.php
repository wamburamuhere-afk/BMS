<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    $id = $_GET['id'] ?? 0;
    if (!$id) {
        throw new Exception('Invalid Reconciliation ID');
    }

    // Include the account code/name so the edit modal can show the bank account
    // label in its AJAX Select2 (which otherwise only knows the id).
    $stmt = $pdo->prepare("
        SELECT br.*, a.account_code, a.account_name
          FROM bank_reconciliations br
          LEFT JOIN accounts a ON br.bank_account_id = a.account_id
         WHERE br.reconciliation_id = ?
    ");
    $stmt->execute([$id]);
    $reconciliation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reconciliation) {
        throw new Exception('Reconciliation not found');
    }

    echo json_encode(['success' => true, 'data' => $reconciliation]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
