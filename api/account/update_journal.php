<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $entry_id = $_POST['entry_id'] ?? 0;
    $entry_date = $_POST['entry_date'] ?? '';
    $description = $_POST['description'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'draft';
    
    $debit_accounts = $_POST['debit_accounts'] ?? [];
    $debit_amounts = $_POST['debit_amounts'] ?? [];
    $debit_descriptions = $_POST['debit_descriptions'] ?? [];
    
    $credit_accounts = $_POST['credit_accounts'] ?? [];
    $credit_amounts = $_POST['credit_amounts'] ?? [];
    $credit_descriptions = $_POST['credit_descriptions'] ?? [];

    if ($entry_id <= 0 || empty($entry_date) || empty($description) || empty($debit_accounts) || empty($credit_accounts)) {
        throw new Exception('Missing required fields');
    }

    $total_debits = array_sum(array_map('floatval', $debit_amounts));
    $total_credits = array_sum(array_map('floatval', $credit_amounts));

    if (abs($total_debits - $total_credits) > 0.01) {
        throw new Exception('Journal entry is not balanced');
    }

    $pdo->beginTransaction();

    // Update header
    $sql = "UPDATE journal_entries SET 
            entry_date = ?, 
            description = ?, 
            notes = ?, 
            status = ?, 
            amount = ?, 
            updated_at = NOW(), 
            updated_by = ? 
            WHERE entry_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$entry_date, $description, $notes, $status, $total_debits, $_SESSION['user_id'], $entry_id]);

    // Delete old items
    $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$entry_id]);

    // Insert new items to journal_entry_items
    $ins_stmt = $pdo->prepare("INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?, ?, ?, ?, ?)");
    foreach ($debit_accounts as $i => $acc_id) {
        if (empty($acc_id)) continue;
        $ins_stmt->execute([$entry_id, $acc_id, 'debit', $debit_amounts[$i], $debit_descriptions[$i] ?? '']);
    }
    foreach ($credit_accounts as $i => $acc_id) {
        if (empty($acc_id)) continue;
        $ins_stmt->execute([$entry_id, $acc_id, 'credit', $credit_amounts[$i], $credit_descriptions[$i] ?? '']);
    }

    // Fetch transaction_id for synchronization
    $getTxn = $pdo->prepare("SELECT transaction_id, reference_number FROM journal_entries WHERE entry_id = ?");
    $getTxn->execute([$entry_id]);
    $journal_data = $getTxn->fetch(PDO::FETCH_ASSOC);
    $transactionId = $journal_data['transaction_id'] ?? null;
    $reference_number = $journal_data['reference_number'] ?? ('JRNL-' . $entry_id);

    // Prepare for Global Transaction
    $journal_items = [];
    foreach ($debit_accounts as $i => $acc_id) {
        if (empty($acc_id)) continue;
        $journal_items[] = [
            'type' => 'debit',
            'account_id' => $acc_id,
            'amount' => $debit_amounts[$i],
            'description' => $debit_descriptions[$i] ?? $description
        ];
    }
    foreach ($credit_accounts as $i => $acc_id) {
        if (empty($acc_id)) continue;
        $journal_items[] = [
            'type' => 'credit',
            'account_id' => $acc_id,
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

    if ($transactionId) {
        $txnRes = updateGlobalTransaction($transactionId, $transactionData, $pdo);
        if (!$txnRes['success']) {
            throw new Exception("Global Transaction Update Failed: " . $txnRes['error']);
        }
    } else {
        $txnRes = recordGlobalTransaction($transactionData, $pdo);
        if ($txnRes['success']) {
            $pdo->prepare("UPDATE journal_entries SET transaction_id = ? WHERE entry_id = ?")
                ->execute([$txnRes['transaction_id'], $entry_id]);
        } else {
            throw new Exception("Global Transaction Recording Failed: " . $txnRes['error']);
        }
    }

    logActivity($pdo, $_SESSION['user_id'], "Updated complex journal entry ID: $entry_id - $description (Total: " . number_format($total_debits, 2) . ")");

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Journal entry updated successfully']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error in update_journal.php: " . $e->getMessage());
        $message = $e->getMessage();
        if (strpos($message, '1062') !== false && strpos($message, 'reference_number') !== false) {
            $message = "A journal entry with this reference number already exists. Please use a unique reference.";
        }
        echo json_encode(['success' => false, 'message' => $message]);
    }
