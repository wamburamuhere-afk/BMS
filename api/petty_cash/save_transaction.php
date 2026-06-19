<?php
ob_start();
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';   // postPettyCashLedger / reversePettyCashLedger
require_once __DIR__ . '/../../core/money_guard.php';      // accountFundsWarning (I3 warn-but-allow)

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

if (!isAuthenticated()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canCreate('petty_cash') && !canEdit('petty_cash')) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to save petty cash transactions']);
    exit();
}

try {
    // Original fields
    $type        = $_POST['type'] ?? '';
    $amount      = floatval($_POST['amount'] ?? 0);
    $date        = $_POST['date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    $reference   = trim($_POST['reference'] ?? '');
    $category_id = (isset($_POST['category_id']) && $_POST['category_id'] !== '') ? intval($_POST['category_id']) : null;
    $source_account_id = (isset($_POST['source_account_id']) && $_POST['source_account_id'] !== '') ? intval($_POST['source_account_id']) : null;
    // The expense "category" is now a real EXPENSE ACCOUNT (Dr expense / Cr petty cash).
    $expense_account_id = (isset($_POST['expense_account_id']) && $_POST['expense_account_id'] !== '') ? intval($_POST['expense_account_id']) : null;
    // Which petty cash fund this entry belongs to (null → configured default fund).
    $fund_account_id = (isset($_POST['fund_account_id']) && $_POST['fund_account_id'] !== '') ? intval($_POST['fund_account_id']) : null;
    $user_id     = $_SESSION['user_id'];

    // New fields
    $received_by    = trim($_POST['received_by']   ?? '') ?: null;
    $department     = trim($_POST['department']    ?? '') ?: null;
    $payment_mode   = in_array($_POST['payment_mode'] ?? '', ['cash', 'cheque']) ? $_POST['payment_mode'] : 'cash';
    $cheque_number  = trim($_POST['cheque_number'] ?? '') ?: null;
    $receipt_type   = in_array($_POST['receipt_type'] ?? '', ['receipt', 'invoice', 'other']) ? $_POST['receipt_type'] : null;
    $receipt_number = trim($_POST['receipt_number'] ?? '') ?: null;

    $transaction_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    // Validations
    if ($amount <= 0) {
        throw new Exception("Amount must be greater than zero");
    }
    if ($type === 'expense' && !$expense_account_id) {
        throw new Exception("Select the expense account (category) the money was spent on.");
    }
    if ($type === 'deposit' && !$source_account_id) {
        throw new Exception("Select a funding account (bank/cash) for the top-up so it posts to the ledger.");
    }

    // ── File Upload Handling ─────────────────────────────────────────
    $receipt_file = null;
    $temp_path    = null;

    if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
        $allowed_mime = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        $max_size     = 5 * 1024 * 1024; // 5 MB
        $upload_dir   = __DIR__ . '/../../uploads/finance/petty_cash/';

        $file_mime = mime_content_type($_FILES['receipt_file']['tmp_name']);
        $file_size = $_FILES['receipt_file']['size'];
        $ext       = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($file_mime, $allowed_mime)) {
            throw new Exception("Invalid file type. Only JPG, PNG, and PDF are allowed.");
        }
        if ($file_size > $max_size) {
            throw new Exception("File size exceeds the 5MB limit.");
        }

        // Create the upload directory if it doesn't exist yet (with a hardening
        // .htaccess), rather than failing the whole save.
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
            $ht = $upload_dir . '.htaccess';
            if (!file_exists($ht)) {
                @file_put_contents($ht,
                    "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\n"
                    . "Options -ExecCGI\nRemoveHandler .php .phtml .php5\nRemoveType .php .phtml .php5\n");
            }
        }
        if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
            throw new Exception("Upload directory is not writable. Please contact the administrator.");
        }

        // Save with temp name — will be renamed once we have the transaction ID
        $temp_filename = 'tmp_' . uniqid() . '.' . $ext;
        $temp_path     = $upload_dir . $temp_filename;

        if (!move_uploaded_file($_FILES['receipt_file']['tmp_name'], $temp_path)) {
            throw new Exception("File upload failed. Please try again.");
        }

        $receipt_file = $temp_filename;
    }

    // MONEY-SAFETY (Step 9): resolve the petty cash fund up front with a CLEAR error,
    // and compute the I3 "warn but allow" note on the account money LEAVES (the fund for
    // an expense, the funding bank for a top-up).
    $resolvedFund = $fund_account_id ?: pettyCashAccountId($pdo);
    if (!$resolvedFund) {
        throw new Exception('No petty cash fund is configured. Set a default Petty Cash account (or pick a fund) before recording petty cash, so it posts to the books.');
    }
    $out_account = ($type === 'expense') ? (int)$resolvedFund : (int)$source_account_id;
    $funds_warn  = accountFundsWarning($pdo, $out_account, (float)$amount);

    // Wrap the record write + ledger posting in ONE transaction (the handler had none),
    // so a posting failure leaves NOTHING half-recorded.
    $pdo->beginTransaction();

    // ── UPDATE existing transaction ──────────────────────────────────
    if ($transaction_id > 0) {

        // Snapshot the OLD posting (type + ledger txn) so we reverse it with the
        // matching method before re-posting the edited values.
        $snap = $pdo->prepare("SELECT type, transaction_id FROM petty_cash_transactions WHERE id = ?");
        $snap->execute([$transaction_id]);
        $snapRow  = $snap->fetch(PDO::FETCH_ASSOC) ?: [];
        $old_type = (string)($snapRow['type'] ?? '');
        $old_txn  = (int)($snapRow['transaction_id'] ?? 0);

        // Delete old file if a new one is being uploaded
        if ($receipt_file) {
            $oldStmt = $pdo->prepare("SELECT receipt_file FROM petty_cash_transactions WHERE id = ?");
            $oldStmt->execute([$transaction_id]);
            $old_file = $oldStmt->fetchColumn();

            if ($old_file) {
                $old_path = __DIR__ . '/../../uploads/finance/petty_cash/' . $old_file;
                if (file_exists($old_path)) {
                    @unlink($old_path);
                }
            }

            // Rename temp file to final name
            $ext            = strtolower(pathinfo($receipt_file, PATHINFO_EXTENSION));
            $final_filename = $transaction_id . '_' . time() . '.' . $ext;
            $upload_dir     = __DIR__ . '/../../uploads/finance/petty_cash/';
            @rename($upload_dir . $receipt_file, $upload_dir . $final_filename);
            $receipt_file   = $final_filename;
            $temp_path      = null;
        }

        $sql = "
            UPDATE petty_cash_transactions SET
                transaction_date = ?, type = ?, category_id = ?, amount = ?,
                description = ?, reference_number = ?, received_by = ?,
                department = ?, payment_mode = ?, cheque_number = ?,
                receipt_type = ?, receipt_number = ?,
                fund_account_id = ?, expense_account_id = ?, source_account_id = ?,
                needs_review = 0
                " . ($receipt_file ? ", receipt_file = ?" : "") . "
            WHERE id = ?
        ";

        $params = [
            $date, $type, $category_id, $amount,
            $description, $reference, $received_by,
            $department, $payment_mode, $cheque_number,
            $receipt_type, $receipt_number,
            $fund_account_id, $expense_account_id, $source_account_id
        ];
        if ($receipt_file) $params[] = $receipt_file;
        $params[] = $transaction_id;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Reverse the OLD ledger posting (matching its type), then post the edited one.
        reversePettyCashLedger($pdo, $old_type, $old_txn);
        // FAIL LOUDLY: a null post means nothing reached the books — roll back, don't save.
        $petty_txn = postPettyCashLedger($pdo, $type, (float)$amount, $date, ($reference ?: $receipt_number), $description, $source_account_id, $expense_account_id, $fund_account_id);
        if (!$petty_txn) {
            throw new Exception('The petty cash entry could not be posted to the ledger — check the fund and the chosen ' . ($type === 'expense' ? 'expense' : 'funding') . ' account. Nothing was saved.');
        }
        $pdo->prepare("UPDATE petty_cash_transactions SET transaction_id = ? WHERE id = ?")
            ->execute([$petty_txn, $transaction_id]);
        $message = 'Transaction updated successfully';

    // ── INSERT new transaction ───────────────────────────────────────
    } else {

        $stmt = $pdo->prepare("
            INSERT INTO petty_cash_transactions
                (transaction_date, type, category_id, amount, description, reference_number,
                 received_by, department, payment_mode, cheque_number,
                 receipt_type, receipt_number, fund_account_id, expense_account_id,
                 source_account_id, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $date, $type, $category_id, $amount, $description, $reference,
            $received_by, $department, $payment_mode, $cheque_number,
            $receipt_type, $receipt_number, $fund_account_id, $expense_account_id,
            $source_account_id, $user_id
        ]);

        $new_id = $pdo->lastInsertId();

        // Rename temp file with actual transaction ID
        if ($receipt_file) {
            $ext            = strtolower(pathinfo($receipt_file, PATHINFO_EXTENSION));
            $final_filename = $new_id . '_' . time() . '.' . $ext;
            $upload_dir     = __DIR__ . '/../../uploads/finance/petty_cash/';
            @rename($upload_dir . $receipt_file, $upload_dir . $final_filename);
            $temp_path = null;

            $updStmt = $pdo->prepare("UPDATE petty_cash_transactions SET receipt_file = ? WHERE id = ?");
            $updStmt->execute([$final_filename, $new_id]);
        }

        // Post the ledger effect: expense (Dr chosen Expense account / Cr Petty Cash
        // fund) or top-up (Dr Petty Cash fund / Cr funding account) — both balances
        // move and are mirrored to the journal.
        $petty_txn = postPettyCashLedger($pdo, $type, (float)$amount, $date, ($reference ?: $receipt_number), $description, $source_account_id, $expense_account_id, $fund_account_id);
        // FAIL LOUDLY: a null post means nothing reached the books — roll back, don't save.
        if (!$petty_txn) {
            throw new Exception('The petty cash entry could not be posted to the ledger — check the fund and the chosen ' . ($type === 'expense' ? 'expense' : 'funding') . ' account. Nothing was saved.');
        }
        $pdo->prepare("UPDATE petty_cash_transactions SET transaction_id = ? WHERE id = ?")
            ->execute([$petty_txn, $new_id]);

        $message = 'Transaction saved successfully';
    }

    $pdo->commit();

    // Phase 3b — petty cash writes are high-sensitivity financial events.
    $isUpdate = ($transaction_id > 0);
    $logId    = $isUpdate ? $transaction_id : ($new_id ?? 0);
    logActivity(
        $pdo,
        $user_id,
        $isUpdate ? "Updated Petty Cash Transaction" : "Created Petty Cash Transaction",
        "Transaction ID: $logId, type: $type, amount: $amount"
    );

    if ($funds_warn) $message .= ' ' . $funds_warn;

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => $message, 'funds_warning' => $funds_warn ?? null]);

} catch (Exception $e) {
    // MONEY-SAFETY: roll back so a failed posting leaves NOTHING half-recorded.
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // Clean up temp file on any error
    if ($temp_path && file_exists($temp_path)) {
        @unlink($temp_path);
    }
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
