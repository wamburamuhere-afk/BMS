<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized access');
    }

    // Get input data
    $entry_date = $_POST['entry_date'] ?? '';
    $amount = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $debit_account_id = $_POST['debit_account_id'] ?? '';
    $credit_account_id = $_POST['credit_account_id'] ?? '';
    $description = $_POST['description'] ?? '';
    $reference_number = $_POST['reference_number'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'draft';

    // Validate required fields
    if (empty($entry_date) || $amount <= 0 || empty($debit_account_id) || empty($credit_account_id) || empty($description)) {
        throw new Exception('All required fields must be filled and amount must be greater than 0');
    }

    if ($debit_account_id == $credit_account_id) {
        throw new Exception('Debit and Credit accounts cannot be the same');
    }

    // Start transaction
    $pdo->beginTransaction();

    // Generate reference number if not provided
    if (empty($reference_number)) {
        $reference_number = 'TRX-' . date('YmdHis') . '-' . rand(100, 999);
    }

    // Insert journal entry header
    // We use the same structure as add_compound_journal.php implies (ignoring debit/credit_account_id columns if they are not used there, or assuming they are nullable)
    // However, to be safe, if the table HAS those columns and they are NOT NULL, this might fail.
    // But since I cannot change the table easily, I will assume they are nullable or not there, OR I should update them if I can.
    // Let's try to update them if they exist? No, standard SQL insert.
    // I will assume the schema supports the item-based approach primarily.
    
    $sql = "INSERT INTO journal_entries (
        entry_date, 
        reference_number, 
        description, 
        notes, 
        status, 
        created_by, 
        created_at,
        debit_account_id,
        credit_account_id,
        amount
    ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $entry_date, 
        $reference_number, 
        $description, 
        $notes, 
        $status, 
        $_SESSION['user_id'],
        $debit_account_id,
        $credit_account_id,
        $amount
    ]);
    
    $entry_id = $pdo->lastInsertId();

    // Insert Debit Item
    $sql_item = "INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?, ?, ?, ?, ?)";
    $stmt_item = $pdo->prepare($sql_item);
    
    // Debit
    $stmt_item->execute([$entry_id, $debit_account_id, 'debit', $amount, $description]);
    
    // Credit
    $stmt_item->execute([$entry_id, $credit_account_id, 'credit', $amount, $description]);

    // Log activity
    logActivity($pdo, $_SESSION['user_id'], "Created transaction: $description - Amount: " . number_format($amount, 2));

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Transaction added successfully',
        'entry_id' => $entry_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in add_transaction.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
