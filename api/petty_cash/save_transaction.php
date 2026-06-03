<?php
ob_start();
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';

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
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;
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
    if ($type === 'expense' && !$category_id) {
        throw new Exception("Category is required for expenses");
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

        if (!is_dir($upload_dir)) {
            throw new Exception("Upload directory does not exist. Please contact the administrator.");
        }
        if (!is_writable($upload_dir)) {
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

    // ── UPDATE existing transaction ──────────────────────────────────
    if ($transaction_id > 0) {

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
                receipt_type = ?, receipt_number = ?
                " . ($receipt_file ? ", receipt_file = ?" : "") . "
            WHERE id = ?
        ";

        $params = [
            $date, $type, $category_id, $amount,
            $description, $reference, $received_by,
            $department, $payment_mode, $cheque_number,
            $receipt_type, $receipt_number
        ];
        if ($receipt_file) $params[] = $receipt_file;
        $params[] = $transaction_id;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Re-sync the consolidated outflow for a petty-cash expense (imprest:
        // Dr Accounts Payable, Cr Petty Cash — source fixed, no dropdown).
        $oldTxn = $pdo->prepare("SELECT transaction_id FROM petty_cash_transactions WHERE id = ?");
        $oldTxn->execute([$transaction_id]);
        reverseOutflow($pdo, (int)($oldTxn->fetchColumn() ?: 0));
        $petty_txn = ($type === 'expense')
            ? postOutflow($pdo, 'petty_cash', pettyCashAccountId($pdo), defaultPayableAccountId($pdo),
                          (float)$amount, $date, ($reference ?: $receipt_number),
                          "Petty cash: " . ($description ?: 'expense'), null)
            : null;
        $pdo->prepare("UPDATE petty_cash_transactions SET transaction_id = ? WHERE id = ?")
            ->execute([$petty_txn, $transaction_id]);
        $message = 'Transaction updated successfully';

    // ── INSERT new transaction ───────────────────────────────────────
    } else {

        $stmt = $pdo->prepare("
            INSERT INTO petty_cash_transactions
                (transaction_date, type, category_id, amount, description, reference_number,
                 received_by, department, payment_mode, cheque_number,
                 receipt_type, receipt_number, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $date, $type, $category_id, $amount, $description, $reference,
            $received_by, $department, $payment_mode, $cheque_number,
            $receipt_type, $receipt_number, $user_id
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

        // Consolidated outflow for a petty-cash expense (Dr AP, Cr Petty Cash).
        if ($type === 'expense') {
            $petty_txn = postOutflow($pdo, 'petty_cash', pettyCashAccountId($pdo), defaultPayableAccountId($pdo),
                                     (float)$amount, $date, ($reference ?: $receipt_number),
                                     "Petty cash: " . ($description ?: 'expense'), null);
            if ($petty_txn) {
                $pdo->prepare("UPDATE petty_cash_transactions SET transaction_id = ? WHERE id = ?")
                    ->execute([$petty_txn, $new_id]);
            }
        }

        $message = 'Transaction saved successfully';
    }

    // Phase 3b — petty cash writes are high-sensitivity financial events.
    $isUpdate = ($transaction_id > 0);
    $logId    = $isUpdate ? $transaction_id : ($new_id ?? 0);
    logActivity(
        $pdo,
        $user_id,
        $isUpdate ? "Updated Petty Cash Transaction" : "Created Petty Cash Transaction",
        "Transaction ID: $logId, type: $type, amount: $amount"
    );

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    // Clean up temp file on any error
    if ($temp_path && file_exists($temp_path)) {
        @unlink($temp_path);
    }
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
