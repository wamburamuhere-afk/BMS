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

        // 4. Mirror the same legs into the canonical journal_entries ledger that every
        //    report + the Chart of Accounts read. Manual-journal callers (which write
        //    journal_entries themselves) pass skip_journal_mirror=true to avoid a
        //    duplicate entry — for those, mirroring is correctly never attempted.
        //
        //    For every other caller (postOutflow/postInflow's 2-leg payments, and
        //    add_bank_transfer.php's compound journal_items), a failed or skipped
        //    mirror here used to be swallowed and reported as success=true — the
        //    legacy transactions/books_transactions write would go through while the
        //    canonical journal_entries ledger silently never received the entry.
        //    Every caller already checks this function's success flag and rolls back
        //    its own transaction on failure (record_payment, add_expense/pay,
        //    add_supplier_payment, save petty cash transaction, add_bank_transfer all
        //    wrap this call in $pdo->beginTransaction()/commit()) — that safety net
        //    was just never wired to the one signal that actually matters. Failing
        //    here now makes it real: no mirror, no "posted".
        if (empty($data['skip_journal_mirror'])) {
            try {
                $mirrorEntryId = mirrorTransactionToJournal($pdo, (int)$transaction_id, $description, $transaction_date, $data['project_id'] ?? null);
            } catch (Throwable $e) {
                error_log("recordGlobalTransaction: journal mirror failed for txn $transaction_id: " . $e->getMessage());
                return [
                    'success' => false,
                    'error' => 'Ledger mirror failed: ' . $e->getMessage(),
                    'transaction_id' => $transaction_id,
                ];
            }
            if (!$mirrorEntryId) {
                error_log("recordGlobalTransaction: journal mirror returned no entry for txn $transaction_id (malformed/unbalanced legs)");
                return [
                    'success' => false,
                    'error' => 'Ledger mirror did not post — the double entry was missing or unbalanced.',
                    'transaction_id' => $transaction_id,
                ];
            }
        }

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

        // Keep the canonical journal mirror in sync with the rewritten legs.
        if (empty($data['skip_journal_mirror'])) {
            try {
                unmirrorTransactionFromJournal($pdo, (int)$transaction_id);
                mirrorTransactionToJournal($pdo, (int)$transaction_id, $description, $transaction_date, $data['project_id'] ?? null);
            } catch (Throwable $e) {
                error_log("updateGlobalTransaction: journal re-mirror failed for txn $transaction_id: " . $e->getMessage());
            }
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
        // Remove the mirrored canonical journal entry too (best-effort).
        try { unmirrorTransactionFromJournal($pdo, (int)$transaction_id); }
        catch (Throwable $e) { error_log("deleteGlobalTransaction: unmirror failed for txn $transaction_id: " . $e->getMessage()); }

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

/**
 * Mirror a books_transactions transaction's legs into the canonical
 * journal_entries / journal_entry_items ledger — the one every financial report
 * AND the Chart of Accounts read. This is the bridge that makes a bank/petty-cash
 * transaction visible in the chart account view and the reports.
 *
 * - Idempotent: keyed on (entity_type='books_transaction', entity_id=transaction_id);
 *   a second call returns the existing entry_id, never double-posts.
 * - Balance-neutral: it does NOT touch accounts.current_balance (the money engine
 *   already moved that). Pure reporting-layer mirror.
 * - Only mirrors a balanced entry with >=2 legs; otherwise returns null.
 *
 * @return int|null new (or existing) journal entry_id.
 */
if (!function_exists('mirrorTransactionToJournal')) {
    function mirrorTransactionToJournal(PDO $pdo, int $transaction_id, ?string $description, ?string $transaction_date, $project_id = null, ?int $user_id = null): ?int
    {
        require_once __DIR__ . '/../../core/ledger_post.php';
        if ($transaction_id <= 0) return null;

        // Already mirrored? (idempotent)
        $chk = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ? LIMIT 1");
        $chk->execute([$transaction_id]);
        $existing = $chk->fetchColumn();
        if ($existing) return (int)$existing;

        // Build journal lines from the legs that were just written to books_transactions.
        $rows = $pdo->prepare("SELECT account_id, type, amount, description FROM books_transactions WHERE transaction_id = ?");
        $rows->execute([$transaction_id]);
        $legs = $rows->fetchAll(PDO::FETCH_ASSOC);
        if (count($legs) < 2) return null;

        $lines = [];
        $dr = 0.0; $cr = 0.0;
        foreach ($legs as $l) {
            $amt  = round((float)$l['amount'], 2);
            $type = $l['type'];
            if ($amt <= 0 || !in_array($type, ['debit', 'credit'], true) || (int)$l['account_id'] <= 0) {
                return null;   // malformed leg → don't mirror (caller logs)
            }
            $lines[] = ['account_id' => (int)$l['account_id'], 'type' => $type, 'amount' => $amt, 'description' => $l['description'] ?? $description];
            if ($type === 'debit') $dr += $amt; else $cr += $amt;
        }
        if (abs($dr - $cr) > 0.01) return null;   // only mirror balanced entries

        $uid = $user_id ?: (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) $uid = (int)($pdo->query("SELECT MIN(user_id) FROM users")->fetchColumn() ?: 1);
        $desc = (trim((string)$description) !== '') ? (string)$description : 'Transaction';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$transaction_date) ? (string)$transaction_date : date('Y-m-d');
        $pid  = ($project_id !== null && $project_id !== '') ? (int)$project_id : null;

        return postLedgerEntry($pdo, $desc, $lines, $pid, $transaction_id, 'books_transaction', $date, $uid);
    }
}

/**
 * Remove the mirrored canonical journal entry for a books_transactions
 * transaction (used on reverse / void / update so the two stay in sync).
 * Safe with null/0; only ever deletes mirror rows (entity_type='books_transaction').
 */
if (!function_exists('unmirrorTransactionFromJournal')) {
    function unmirrorTransactionFromJournal(PDO $pdo, int $transaction_id): void
    {
        if ($transaction_id <= 0) return;
        $je = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ?");
        $je->execute([$transaction_id]);
        foreach ($je->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([(int)$eid]);
            $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([(int)$eid]);
        }
    }
}
