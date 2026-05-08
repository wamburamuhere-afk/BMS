<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }

    // Get input data
    $entry_date = $_POST['entry_date'] ?? '';
    $reference_number = $_POST['reference_number'] ?? '';
    $description = $_POST['description'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'draft';
    $debit_items = json_decode($_POST['debit_items'] ?? '[]', true);
    $credit_items = json_decode($_POST['credit_items'] ?? '[]', true);

    // Validate required fields
    if (empty($entry_date) || empty($description)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Entry date and description are required']);
        exit;
    }

    // Validate items
    if (empty($debit_items) || empty($credit_items)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Both debit and credit items are required']);
        exit;
    }

    // Calculate totals
    $total_debits = array_sum(array_column($debit_items, 'amount'));
    $total_credits = array_sum(array_column($credit_items, 'amount'));

    // Validate balance
    if (abs($total_debits - $total_credits) > 0.01) { // Allow small floating point differences
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Debits and credits must be equal']);
        exit;
    }

    // Generate reference number if not provided
    if (empty($reference_number)) {
        $reference_number = 'JRNL-' . date('YmdHis');
    }

    // Start transaction
    $pdo->beginTransaction();

    try {
        // Insert journal entry
        $sql = "INSERT INTO journal_entries (
            entry_date, reference_number, description, notes, status, created_by, created_at,
            debit_account_id, credit_account_id, amount
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$entry_date, $reference_number, $description, $notes, $status, $_SESSION['user_id'], $total_debits]);
        $entry_id = $pdo->lastInsertId();

        // Insert debit items
        foreach ($debit_items as $item) {
            $sql = "INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?, ?, 'debit', ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$entry_id, $item['account_id'], $item['amount'], $item['description'] ?? '']);
        }

        // Insert credit items
        foreach ($credit_items as $item) {
            $sql = "INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?, ?, 'credit', ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$entry_id, $item['account_id'], $item['amount'], $item['description'] ?? '']);
        }

        // Prepare for Global Transaction
        $journal_items_all = [];
        foreach ($debit_items as $item) {
            $journal_items_all[] = [
                'type' => 'debit',
                'account_id' => $item['account_id'],
                'amount' => $item['amount'],
                'description' => $item['description'] ?? $description
            ];
        }
        foreach ($credit_items as $item) {
            $journal_items_all[] = [
                'type' => 'credit',
                'account_id' => $item['account_id'],
                'amount' => $item['amount'],
                'description' => $item['description'] ?? $description
            ];
        }

        $transactionData = [
            'journal_id' => $entry_id,
            'transaction_date' => $entry_date,
            'amount' => $total_debits,
            'transaction_type' => 'journal',
            'reference_number' => $reference_number,
            'description' => $description,
            'journal_items' => $journal_items_all
        ];

        $txnResult = recordGlobalTransaction($transactionData, $pdo);
        if (!$txnResult['success']) {
            throw new Exception("Global Transaction Recording Failed: " . $txnResult['error']);
        }

        // Update journal with transaction_id
        $pdo->prepare("UPDATE journal_entries SET transaction_id = ? WHERE entry_id = ?")
            ->execute([$txnResult['transaction_id'], $entry_id]);

        // Log activity
        logActivity($pdo, $_SESSION['user_id'], "Created compound journal entry: $description - Total: " . number_format($total_debits, 2));

        $pdo->commit();

        http_response_code(201);
        echo json_encode([
            'success' => true, 
            'message' => 'Compound journal entry created successfully',
            'entry_id' => $entry_id,
            'reference_number' => $reference_number
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log("Database error in add_compound_journal.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in add_compound_journal.php: " . $e->getMessage());
    $message = $e->getMessage();
    if (strpos($message, '1062') !== false && strpos($message, 'reference_number') !== false) {
        $message = "A journal entry with this reference number already exists. Please use a unique reference.";
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $message]);
}
?>
