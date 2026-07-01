<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('customers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$lpo_id      = intval($_POST['lpo_id'] ?? 0);
$lpo_number  = trim($_POST['lpo_number'] ?? '');
$issue_date  = trim($_POST['issue_date'] ?? '');
$expiry_date = trim($_POST['expiry_date'] ?? '') ?: null;
$amount      = floatval($_POST['amount'] ?? 0);
$currency    = trim($_POST['currency'] ?? 'TZS');
$description = trim($_POST['description'] ?? '') ?: null;
$status      = trim($_POST['status'] ?? 'open');
$notes       = trim($_POST['notes'] ?? '') ?: null;

if (!$lpo_id) {
    echo json_encode(['success' => false, 'message' => 'LPO ID is required']);
    exit;
}
if (empty($lpo_number)) {
    echo json_encode(['success' => false, 'message' => 'LPO number is required']);
    exit;
}
if (empty($issue_date)) {
    echo json_encode(['success' => false, 'message' => 'Issue date is required']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit;
}
$allowed_statuses = ['pending', 'reviewed', 'approved', 'open', 'partially_fulfilled', 'fulfilled', 'cancelled'];
if (!in_array($status, $allowed_statuses, true)) {
    $status = 'pending';
}

try {
    $check = $pdo->prepare("SELECT lpo_id, lpo_number, document_path FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
    $check->execute([$lpo_id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        echo json_encode(['success' => false, 'message' => 'LPO not found']);
        exit;
    }

    // Re-code on edit: convert a legacy auto "LPO-YYYY-NNNNN" to the company format.
    // A custom number the user typed (not the auto pattern) is preserved as-is.
    require_once __DIR__ . '/../../core/code_generator.php';
    $lpo_number = codeForEdit($pdo, 'LPO', $lpo_number, 'LPO-[0-9].*', 'customer_lpos', (int)$lpo_id, 5);

    $document_path = $existing['document_path'];
    if (!empty($_FILES['document']['name'])) {
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed_ext, true)) {
            echo json_encode(['success' => false, 'message' => 'File type not allowed. Use PDF, DOC, DOCX, JPG, or PNG.']);
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $real_mime = $finfo->file($_FILES['document']['tmp_name']);
        $allowed_mime = [
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg', 'image/png'
        ];
        if (!in_array($real_mime, $allowed_mime, true)) {
            echo json_encode(['success' => false, 'message' => 'File content does not match allowed types']);
            exit;
        }
        if ($_FILES['document']['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File exceeds 10MB limit']);
            exit;
        }
        $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
        $target_dir = __DIR__ . '/../../uploads/finance/customer_lpos/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        if (!move_uploaded_file($_FILES['document']['tmp_name'], $target_dir . $safe_name)) {
            echo json_encode(['success' => false, 'message' => 'File upload failed']);
            exit;
        }
        $document_path = 'uploads/finance/customer_lpos/' . $safe_name;
    }

    $stmt = $pdo->prepare("
        UPDATE customer_lpos
        SET lpo_number = ?, issue_date = ?, expiry_date = ?, amount = ?, currency = ?,
            description = ?, status = ?, document_path = ?, notes = ?
        WHERE lpo_id = ?
    ");
    $stmt->execute([
        $lpo_number, $issue_date, $expiry_date, $amount, $currency,
        $description, $status, $document_path, $notes, $lpo_id
    ]);

    // Replace line items
    try { $pdo->prepare("DELETE FROM customer_lpo_items WHERE lpo_id = ?")->execute([$lpo_id]); } catch (PDOException $e) {}
    if (!empty($_POST['items']) && is_array($_POST['items'])) {
        $iStmt = $pdo->prepare("INSERT INTO customer_lpo_items (lpo_id, sort_order, product_name, quantity, unit_price, tax_rate, total) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $item_total = 0;
        foreach ($_POST['items'] as $i => $item) {
            $pname = trim($item['product_name'] ?? '');
            if ($pname === '') continue;
            $qty       = max(0.001, (float)($item['quantity']   ?? 1));
            $price     = max(0,     (float)($item['unit_price'] ?? 0));
            $tax       = max(0, min(100, (float)($item['tax_rate'] ?? 0)));
            $row_total = round($qty * $price * (1 + $tax / 100), 2);
            $item_total += $row_total;
            $iStmt->execute([$lpo_id, (int)$i + 1, $pname, $qty, $price, $tax, $row_total]);
        }
        if ($item_total > 0) {
            $pdo->prepare("UPDATE customer_lpos SET amount = ? WHERE lpo_id = ?")->execute([$item_total, $lpo_id]);
            $amount = $item_total;
        }
    }

    // Save new attachments (attach_files[i] + attach_names[i])
    if (!empty($_FILES['attach_files']['name'])) {
        $allowed_ext  = ['pdf','doc','docx','jpg','jpeg','png'];
        $allowed_mime = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','image/jpeg','image/png'];
        $target_dir   = __DIR__ . '/../../uploads/finance/customer_lpos/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $aStmt = $pdo->prepare("INSERT INTO customer_lpo_attachments (lpo_id, file_path, original_name, file_size, created_by) VALUES (?,?,?,?,?)");
        foreach ($_FILES['attach_files']['name'] as $i => $fname) {
            if ($_FILES['attach_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) continue;
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            if (!in_array($finfo->file($_FILES['attach_files']['tmp_name'][$i]), $allowed_mime, true)) continue;
            if ($_FILES['attach_files']['size'][$i] > 10 * 1024 * 1024) continue;
            $safe  = bin2hex(random_bytes(16)) . '.' . $ext;
            $label = trim($_POST['attach_names'][$i] ?? '') ?: $fname;
            if (!move_uploaded_file($_FILES['attach_files']['tmp_name'][$i], $target_dir . $safe)) continue;
            $aStmt->execute([$lpo_id, 'uploads/finance/customer_lpos/' . $safe, $label, $_FILES['attach_files']['size'][$i], $_SESSION['user_id']]);
        }
    }

    logActivity($pdo, $_SESSION['user_id'], 'Edit LPO', "User edited LPO: $lpo_number (ID $lpo_id)");

    echo json_encode(['success' => true, 'message' => 'LPO updated successfully.']);
} catch (PDOException $e) {
    error_log("update_lpo error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
