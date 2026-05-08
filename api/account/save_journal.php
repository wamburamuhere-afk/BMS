<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
global $pdo;

// Ensure authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canCreate('journals')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $entry_date = $_POST['entry_date'];
        $reference_number = $_POST['reference_number'] ?: 'JRNL-' . date('YmdHis');
        $description = $_POST['description'];
        $notes = $_POST['notes'] ?? '';
        $status = $_POST['status'] ?? 'posted';
        
        $debit_accounts = $_POST['debit_accounts'] ?? [];
        $debit_amounts = $_POST['debit_amounts'] ?? [];
        $debit_descriptions = $_POST['debit_descriptions'] ?? [];
        
        $credit_accounts = $_POST['credit_accounts'] ?? [];
        $credit_amounts = $_POST['credit_amounts'] ?? [];
        $credit_descriptions = $_POST['credit_descriptions'] ?? [];
        
        // Validation
        if (empty($debit_accounts) || empty($credit_accounts)) {
            throw new Exception("Please add at least one debit and one credit account.");
        }
        
        $total_debits = 0;
        foreach ($debit_amounts as $amt) $total_debits += (float)$amt;
        
        $total_credits = 0;
        foreach ($credit_amounts as $amt) $total_credits += (float)$amt;
        
        if (abs($total_debits - $total_credits) > 0.01) {
            throw new Exception("Journal entry is not balanced. Difference: " . abs($total_debits - $total_credits));
        }
        
        $pdo->beginTransaction();
        
        // Insert header
        // Note: debit_account_id and credit_account_id are set to 0 for compound entries
        $sql = "INSERT INTO journal_entries (entry_date, reference_number, description, notes, status, created_by, debit_account_id, credit_account_id, amount) VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$entry_date, $reference_number, $description, $notes, $status, $_SESSION['user_id'], $total_debits]);
        $entry_id = $pdo->lastInsertId();
        
        // Insert debits to journal_entry_items
        foreach ($debit_accounts as $i => $account_id) {
            if (empty($account_id) || empty($debit_amounts[$i])) continue;
            $sql = "INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?, ?, 'debit', ?, ?)";
            $pdo->prepare($sql)->execute([$entry_id, $account_id, $debit_amounts[$i], $debit_descriptions[$i] ?? '']);
        }
        
        // Insert credits to journal_entry_items
        foreach ($credit_accounts as $i => $account_id) {
            if (empty($account_id) || empty($credit_amounts[$i])) continue;
            $sql = "INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?, ?, 'credit', ?, ?)";
            $pdo->prepare($sql)->execute([$entry_id, $account_id, $credit_amounts[$i], $credit_descriptions[$i] ?? '']);
        }

        // Prepare for Global Transaction
        $journal_items = [];
        foreach ($debit_accounts as $i => $account_id) {
            if (empty($account_id) || empty($debit_amounts[$i])) continue;
            $journal_items[] = [
                'type' => 'debit',
                'account_id' => $account_id,
                'amount' => $debit_amounts[$i],
                'description' => $debit_descriptions[$i] ?? $description
            ];
        }
        foreach ($credit_accounts as $i => $account_id) {
            if (empty($account_id) || empty($credit_amounts[$i])) continue;
            $journal_items[] = [
                'type' => 'credit',
                'account_id' => $account_id,
                'amount' => $credit_amounts[$i],
                'description' => $credit_descriptions[$i] ?? $description
            ];
        }

        $transactionData = [
            'journal_id' => $entry_id,
            'transaction_date' => $entry_date,
            'amount' => $total_debits,
            'transaction_type' => 'journal',
            'reference_number' => $reference_number,
            'description' => $description,
            'journal_items' => $journal_items
        ];

        // This will insert into both `transactions` and `books_transactions`
        // We use $pdo which is already in a transaction
        $txnResult = recordGlobalTransaction($transactionData, $pdo);
        if (!$txnResult['success']) {
            throw new Exception("Global Transaction Recording Failed: " . $txnResult['error']);
        }

        // Update journal with transaction_id
        $pdo->prepare("UPDATE journal_entries SET transaction_id = ? WHERE entry_id = ?")
            ->execute([$txnResult['transaction_id'], $entry_id]);

        // Log activity
        logActivity($pdo, $_SESSION['user_id'], "Created journal entry: $description ($reference_number)");
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Journal entry created successfully']);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = $e->getMessage();
        if (strpos($message, '1062') !== false && strpos($message, 'reference_number') !== false) {
            $message = "A journal entry with this reference number already exists. Please use a unique reference.";
        }
        echo json_encode(['success' => false, 'message' => $message]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
