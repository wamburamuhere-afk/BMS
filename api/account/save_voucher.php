<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    // Lazy migration for expense_id and attachment if missing
    $existing_cols = $pdo->query("SHOW COLUMNS FROM payment_vouchers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('expense_id', $existing_cols)) {
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN expense_id INT NULL DEFAULT NULL AFTER project_id"); } catch (PDOException $e) {}
    }
    if (!in_array('attachment', $existing_cols)) {
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN attachment VARCHAR(255) NULL DEFAULT NULL AFTER reference_number"); } catch (PDOException $e) {}
    }

    $voucher_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    $payee_name = trim($_POST['payee_name'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $date = $_POST['date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $reference = trim($_POST['reference'] ?? '');
    $category_id = isset($_POST['category_id']) && !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $expense_id = !empty($_POST['expense_id']) ? intval($_POST['expense_id']) : null;
    $amount_in_words = trim($_POST['amount_in_words'] ?? '');
    
    if (empty($payee_name) || $amount <= 0) {
        throw new Exception("Payee Name and valid Amount are required.");
    }

    // Handle File Upload
    $attachment_path = $_POST['existing_attachment'] ?? null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/finance/vouchers/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        $file_name = 'pv_' . time() . '_' . uniqid() . '.' . $file_ext;
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $file_name)) {
            $attachment_path = 'uploads/finance/vouchers/' . $file_name;
            registerFileInLibrary($pdo, $attachment_path, $_FILES['attachment']['name'], $_FILES['attachment']['size'], 'Payment Voucher Attachment', 'voucher,finance', $_SESSION['user_id']);
        }
    }

    if ($voucher_id > 0) {
        // Update
        $stmt = $pdo->prepare("
            UPDATE payment_vouchers 
            SET vouch_date=?, payee_name=?, amount=?, amount_in_words=?, description=?, 
                payment_method=?, reference_number=?, expense_category_id=?, project_id=?, 
                expense_id=?, attachment=?
            WHERE id=?
        ");
        $stmt->execute([
            $date, $payee_name, $amount, $amount_in_words, $description, 
            $payment_method, $reference, $category_id, $project_id, 
            $expense_id, $attachment_path, $voucher_id
        ]);
        $message = "Voucher updated successfully";
    } else {
        // Generate Voucher Number
        $last = $pdo->query("SELECT voucher_number FROM payment_vouchers ORDER BY id DESC LIMIT 1")->fetchColumn();
        $nextNum = 1;
        if ($last && preg_match('/PV-(\d+)/', $last, $matches)) {
            $nextNum = intval($matches[1]) + 1;
        }
        $voucher_number = 'PV-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        // Insert
        $stmt = $pdo->prepare("
            INSERT INTO payment_vouchers 
            (voucher_number, vouch_date, payee_name, amount, amount_in_words, description, 
             payment_method, reference_number, expense_category_id, project_id, 
             expense_id, attachment, prepared_by, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW())
        ");
        $stmt->execute([
            $voucher_number, $date, $payee_name, $amount, $amount_in_words, $description, 
            $payment_method, $reference, $category_id, $project_id, 
            $expense_id, $attachment_path, $_SESSION['user_id']
        ]);
        $voucher_id = $pdo->lastInsertId();
        $message = "Voucher created successfully ($voucher_number)";
    }

    // If linked to an expense, check if it's fully paid
    if ($expense_id) {
        $sum_stmt = $pdo->prepare("SELECT SUM(amount) FROM payment_vouchers WHERE expense_id = ? AND status IN ('approved', 'paid')");
        $sum_stmt->execute([$expense_id]);
        $total_paid = $sum_stmt->fetchColumn() ?: 0;

        $exp_stmt = $pdo->prepare("SELECT amount FROM expenses WHERE expense_id = ?");
        $exp_stmt->execute([$expense_id]);
        $exp_amount = $exp_stmt->fetchColumn() ?: 0;

        // Optionally update expense status if logic allows
        // if ($total_paid >= $exp_amount) {
        //    $pdo->prepare("UPDATE expenses SET status='paid' WHERE expense_id=?")->execute([$expense_id]);
        // }
    }

    // Phase 3a — payment-voucher writes are high-sensitivity financial events.
    $isUpdate = ($_POST['voucher_id'] ?? 0) > 0;
    logActivity(
        $pdo,
        $_SESSION['user_id'] ?? 0,
        $isUpdate ? "Updated Payment Voucher" : "Created Payment Voucher",
        "Voucher ID: $voucher_id, payee: '$payee_name', amount: $amount"
    );

    echo json_encode(['success' => true, 'message' => $message, 'id' => $voucher_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
