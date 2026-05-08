<?php
/**
 * Transaction Helper
 * Handles global transaction recording across modules.
 */

/**
 * Records a transaction in both the central transactions table and books_transactions table.
 * 
 * @param array $data Transaction data
 * @param PDO $pdo Database connection
 * @return array Result with success status and transaction_id
 */
function recordGlobalTransaction($data, $pdo) {
    try {
        // 1. Prepare transaction header data
        $transaction_date = $data['transaction_date'] ?? date('Y-m-d');
        $amount = $data['amount'] ?? 0;
        $transaction_type = $data['transaction_type'] ?? 'general';
        $reference_number = $data['reference_number'] ?? '';
        $description = $data['description'] ?? '';

        // 2. Insert into transactions table
        $sql = "INSERT INTO transactions (transaction_date, amount, transaction_type, reference_number, description) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$transaction_date, $amount, $transaction_type, $reference_number, $description]);
        $transaction_id = $pdo->lastInsertId();

        // 3. Handle line items
        if (isset($data['journal_items']) && is_array($data['journal_items'])) {
            // Complex transaction with multiple items (e.g., compound journal)
            foreach ($data['journal_items'] as $item) {
                $sql_item = "INSERT INTO books_transactions (transaction_id, account_id, type, amount, description) 
                             VALUES (?, ?, ?, ?, ?)";
                $stmt_item = $pdo->prepare($sql_item);
                $stmt_item->execute([
                    $transaction_id, 
                    $item['account_id'], 
                    $item['type'], 
                    $item['amount'], 
                    $item['description'] ?? $description
                ]);
            }
        } elseif (isset($data['account_id']) && isset($data['contra_account_id'])) {
            // Simple transaction with two sides (e.g., expense)
            // Side 1: The primary account (e.g., Expense account gets a debit usually?)
            // Expenses: Debit Expense Account, Credit Bank Account.
            
            $side1_type = ($transaction_type === 'expense') ? 'debit' : 'debit';
            $side2_type = ($side1_type === 'debit') ? 'credit' : 'debit';

            // Insert Side 1
            $sql_s1 = "INSERT INTO books_transactions (transaction_id, account_id, type, amount, description) 
                       VALUES (?, ?, ?, ?, ?)";
            $pdo->prepare($sql_s1)->execute([
                $transaction_id, 
                $data['account_id'], 
                $side1_type, 
                $amount, 
                $description
            ]);

            // Insert Side 2
            $sql_s2 = "INSERT INTO books_transactions (transaction_id, account_id, type, amount, description) 
                       VALUES (?, ?, ?, ?, ?)";
            $pdo->prepare($sql_s2)->execute([
                $transaction_id, 
                $data['contra_account_id'], 
                $side2_type, 
                $amount, 
                $description
            ]);
        }

        // 4. Update account balances if necessary (Optional depending on business logic)
        // In some systems, current_balance is updated here.
        
        return [
            'success' => true, 
            'transaction_id' => $transaction_id
        ];

    } catch (Exception $e) {
        error_log("Error in recordGlobalTransaction: " . $e->getMessage());
        return [
            'success' => false, 
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Updates an existing transaction.
 */
function updateGlobalTransaction($transaction_id, $data, $pdo) {
    try {
        $transaction_date = $data['transaction_date'] ?? date('Y-m-d');
        $amount = $data['amount'] ?? 0;
        $transaction_type = $data['transaction_type'] ?? 'general';
        $reference_number = $data['reference_number'] ?? '';
        $description = $data['description'] ?? '';

        // 1. Update main transaction header
        $sql = "UPDATE transactions SET 
                transaction_date = ?, amount = ?, transaction_type = ?, 
                reference_number = ?, description = ? 
                WHERE transaction_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$transaction_date, $amount, $transaction_type, $reference_number, $description, $transaction_id]);

        // 2. Clear old books_transactions
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([$transaction_id]);

        // 3. Re-insert line items (using same logic as record)
        if (isset($data['journal_items']) && is_array($data['journal_items'])) {
            foreach ($data['journal_items'] as $item) {
                $sql_item = "INSERT INTO books_transactions (transaction_id, account_id, type, amount, description) 
                             VALUES (?, ?, ?, ?, ?)";
                $pdo->prepare($sql_item)->execute([
                    $transaction_id, $item['account_id'], $item['type'], $item['amount'], $item['description'] ?? $description
                ]);
            }
        } elseif (isset($data['account_id']) && isset($data['contra_account_id'])) {
            $side1_type = ($transaction_type === 'expense') ? 'debit' : 'debit';
            $side2_type = ($side1_type === 'debit') ? 'credit' : 'debit';

            $sql_s1 = "INSERT INTO books_transactions (transaction_id, account_id, type, amount, description) VALUES (?, ?, ?, ?, ?)";
            $pdo->prepare($sql_s1)->execute([$transaction_id, $data['account_id'], $side1_type, $amount, $description]);

            $sql_s2 = "INSERT INTO books_transactions (transaction_id, account_id, type, amount, description) VALUES (?, ?, ?, ?, ?)";
            $pdo->prepare($sql_s2)->execute([$transaction_id, $data['contra_account_id'], $side2_type, $amount, $description]);
        }

        return ['success' => true];

    } catch (Exception $e) {
        error_log("Error in updateGlobalTransaction: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Deletes a transaction and its associated ledger entries.
 */
function deleteGlobalTransaction($transaction_id, $pdo) {
    try {
        // Delete ledger entries first (foreign key constraints friendly)
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([$transaction_id]);
        
        // Delete main header
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$transaction_id]);

        return ['success' => true];
    } catch (Exception $e) {
        error_log("Error in deleteGlobalTransaction: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
