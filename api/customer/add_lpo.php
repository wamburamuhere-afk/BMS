<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canCreate('customers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$customer_id = intval($_POST['customer_id'] ?? 0);
$issue_date  = trim($_POST['issue_date'] ?? '');
$expiry_date = trim($_POST['expiry_date'] ?? '') ?: null;
$amount      = floatval($_POST['amount'] ?? 0);
$currency    = trim($_POST['currency'] ?? 'TZS');
$description = trim($_POST['description'] ?? '') ?: null;
$notes       = trim($_POST['notes'] ?? '') ?: null;
$status      = 'pending';

if (!$customer_id) {
    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
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

// Auto-generate LPO number: LPO-YYYY-NNNNN
$next_id = (int)$pdo->query("SELECT COALESCE(MAX(lpo_id), 0) FROM customer_lpos")->fetchColumn() + 1;
$lpo_number = 'LPO-' . date('Y') . '-' . str_pad($next_id, 5, '0', STR_PAD_LEFT);

$document_path = null;
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

try {
    $stmt = $pdo->prepare("
        INSERT INTO customer_lpos
            (lpo_number, customer_id, issue_date, expiry_date, amount, currency, description, status, document_path, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $lpo_number, $customer_id, $issue_date, $expiry_date,
        $amount, $currency, $description, $status,
        $document_path, $notes, $_SESSION['user_id']
    ]);
    $lpo_id = $pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Added LPO #{$lpo_number} for customer ID {$customer_id}");

    echo json_encode(['success' => true, 'message' => 'LPO added successfully.', 'lpo_id' => $lpo_id]);
} catch (PDOException $e) {
    error_log("add_lpo error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
