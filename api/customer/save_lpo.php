<?php
// File: api/customer/save_lpo.php
// Unified create+update endpoint for the standalone Customer LPO module
// (mirrors api/account/save_purchase_order.php). Replaces add_lpo.php + update_lpo.php.
// Does NOT accept a status field — status changes only via review_lpo.php / approve_lpo.php
// (and change_lpo_status.php for post-approval fulfillment/cancellation).
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';
require_once __DIR__ . '/../../core/code_generator.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$lpo_id    = isset($_POST['lpo_id']) ? intval($_POST['lpo_id']) : 0;
$is_update = ($lpo_id > 0);

if ($is_update) {
    if (!canEdit('lpo')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to edit LPOs']);
        exit;
    }
    assertScopeForRecord('customer_lpos', 'lpo_id', $lpo_id);
} else {
    if (!canCreate('lpo')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create LPOs']);
        exit;
    }
}

if (!empty($_POST['project_id']) && !userCan('project', (int)$_POST['project_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
    exit;
}

try {
    global $pdo;

    $project_id   = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null;
    $issue_date  = trim($_POST['issue_date'] ?? '');
    $expiry_date = trim($_POST['expiry_date'] ?? '') ?: null;
    $currency    = trim($_POST['currency'] ?? 'TZS');
    $description = trim($_POST['description'] ?? '') ?: null;
    $notes       = trim($_POST['notes'] ?? '') ?: null;
    $items_json  = $_POST['items'] ?? '[]';
    $items       = json_decode($items_json, true);

    if (empty($issue_date) || empty($items)) {
        throw new Exception('Missing required fields (Issue Date or Items)');
    }
    if (!$warehouse_id) {
        throw new Exception('Warehouse is required');
    }

    $pdo->beginTransaction();

    // Compute amount from items (subtotal + tax) — same rule as the legacy add/update_lpo.php.
    $item_total = 0;
    foreach ($items as $item) {
        $qty   = max(0.001, (float)($item['quantity']   ?? 1));
        $price = max(0,     (float)($item['unit_price'] ?? 0));
        $tax   = max(0, min(100, (float)($item['tax_rate'] ?? 0)));
        $item_total += round($qty * $price * (1 + $tax / 100), 2);
    }

    if ($is_update) {
        $cur = $pdo->prepare("SELECT lpo_number, customer_id FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
        $cur->execute([$lpo_id]);
        $existing = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$existing) throw new Exception('LPO not found');

        $lpo_number = codeForEdit($pdo, 'LPO', (string)$existing['lpo_number'], 'LPO-[0-9].*', 'customer_lpos', $lpo_id, 5);

        $stmt = $pdo->prepare("
            UPDATE customer_lpos SET
                lpo_number = ?, project_id = ?, warehouse_id = ?, issue_date = ?, expiry_date = ?,
                amount = ?, currency = ?, description = ?, notes = ?, updated_at = NOW()
            WHERE lpo_id = ?
        ");
        $stmt->execute([$lpo_number, $project_id, $warehouse_id, $issue_date, $expiry_date, $item_total, $currency, $description, $notes, $lpo_id]);

        $customer_id = $existing['customer_id'];

        $pdo->prepare("DELETE FROM customer_lpo_items WHERE lpo_id = ?")->execute([$lpo_id]);
    } else {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        if (!$customer_id) throw new Exception('Customer is required');

        $lpo_number = nextCode($pdo, 'LPO');

        $actor = workflowActorSnapshot();

        $stmt = $pdo->prepare("
            INSERT INTO customer_lpos (
                lpo_number, customer_id, project_id, warehouse_id, issue_date, expiry_date,
                amount, currency, description, status, notes,
                created_by, prepared_by_name, prepared_by_role, prepared_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $lpo_number, $customer_id, $project_id, $warehouse_id, $issue_date, $expiry_date,
            $item_total, $currency, $description, $notes,
            $_SESSION['user_id'], $actor['name'], $actor['role']
        ]);
        $lpo_id = (int)$pdo->lastInsertId();

        workflowCaptureSignature($pdo, 'customer_lpo', $lpo_id, 'created', (int)$_SESSION['user_id'], $actor['name'], $actor['role']);
    }

    // Insert items
    $iStmt = $pdo->prepare("INSERT INTO customer_lpo_items (lpo_id, product_id, sort_order, product_name, quantity, unit_price, tax_rate, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($items as $i => $item) {
        $pname = trim($item['product_name'] ?? '');
        if ($pname === '') continue;
        $qty       = max(0.001, (float)($item['quantity']   ?? 1));
        $price     = max(0,     (float)($item['unit_price'] ?? 0));
        $tax       = max(0, min(100, (float)($item['tax_rate'] ?? 0)));
        $row_total = round($qty * $price * (1 + $tax / 100), 2);
        $iStmt->execute([$lpo_id, $item['product_id'] ?: null, (int)$i + 1, $pname, $qty, $price, $tax, $row_total]);
    }

    // New attachments (attach_files[] + attach_names[])
    if (!empty($_FILES['attach_files']['name'][0])) {
        $allowed_ext  = ['pdf','doc','docx','jpg','jpeg','png'];
        $allowed_mime = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','image/jpeg','image/png'];
        $target_dir   = __DIR__ . '/../../uploads/finance/customer_lpos/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $aStmt = $pdo->prepare("INSERT INTO customer_lpo_attachments (lpo_id, file_path, original_name, file_size, created_by) VALUES (?,?,?,?,?)");
        foreach ($_FILES['attach_files']['name'] as $i => $fname) {
            if (empty($fname) || $_FILES['attach_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
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

    $pdo->commit();

    if ($is_update) {
        logActivity($pdo, $_SESSION['user_id'], 'Edit Customer LPO', "User edited LPO: $lpo_number (ID $lpo_id)");
    } else {
        logActivity($pdo, $_SESSION['user_id'], 'Create Customer LPO', "User created a new LPO: $lpo_number (ID $lpo_id)");
    }

    echo json_encode(['success' => true, 'message' => 'LPO saved successfully', 'lpo_id' => $lpo_id, 'lpo_number' => $lpo_number]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('save_lpo error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
