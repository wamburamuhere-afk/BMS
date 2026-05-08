<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $entry_id = $_POST['entry_id'] ?? 0;
    $entry_date = $_POST['entry_date'] ?? '';
    $amount = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $debit_account_id = $_POST['debit_account_id'] ?? '';
    $credit_account_id = $_POST['credit_account_id'] ?? '';
    $description = $_POST['description'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'draft';

    if ($entry_id <= 0 || empty($entry_date) || $amount <= 0 || empty($debit_account_id) || empty($credit_account_id) || empty($description)) {
        throw new Exception('Invalid input data');
    }

    $pdo->beginTransaction();

    // Update header
    $sql = "UPDATE journal_entries SET 
            entry_date = ?, 
            amount = ?, 
            debit_account_id = ?, 
            credit_account_id = ?, 
            description = ?, 
            notes = ?, 
            status = ?, 
            updated_at = NOW(), 
            updated_by = ? 
            WHERE entry_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$entry_date, $amount, $debit_account_id, $credit_account_id, $description, $notes, $status, $_SESSION['user_id'], $entry_id]);

    // Update items (simpler to delete and re-insert for 2-item transactions)
    $stmt = $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?");
    $stmt->execute([$entry_id]);

    $sql_item = "INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?, ?, ?, ?, ?)";
    $stmt_item = $pdo->prepare($sql_item);
    
    $stmt_item->execute([$entry_id, $debit_account_id, 'debit', $amount, $description]);
    $stmt_item->execute([$entry_id, $credit_account_id, 'credit', $amount, $description]);

    logActivity($pdo, $_SESSION['user_id'], "Updated transaction ID: $entry_id - $description (Amount: " . number_format($amount, 2) . ")");

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Transaction updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error in update_transaction.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
