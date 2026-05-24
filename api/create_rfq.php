<?php
// File: api/create_rfq.php
require_once __DIR__ . '/../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    csrf_check();

    if (!canCreate('rfq')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to create RFQs');
    }

    $supplier_id  = intval($_POST['supplier_id'] ?? 0);
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
    $project_id   = intval($_POST['project_id'] ?? 0) ?: null;
    $rfq_date     = $_POST['rfq_date'] ?? date('Y-m-d');
    $deadline     = $_POST['deadline_date'] ?? null ?: null;
    $items        = json_decode($_POST['items'] ?? '[]', true);

    if (!$supplier_id)  throw new Exception('Supplier is required');
    if (!$warehouse_id) throw new Exception('Warehouse is required');
    if (empty($items))  throw new Exception('At least one item is required');

    // ── Generate RFQ number ─────────────────────────────────────────────
    $year  = date('Y');
    $month = date('m');
    $last  = $pdo->query("SELECT MAX(CAST(SUBSTRING(rfq_number, -4) AS UNSIGNED)) FROM rfq WHERE rfq_number LIKE 'RFQ-{$year}{$month}-%'")->fetchColumn() ?: 0;
    $rfq_number = 'RFQ-' . $year . $month . '-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();

    $prepared_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (!$prepared_name) $prepared_name = $_SESSION['username'] ?? 'Unknown';
    $prepared_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Staff';

    $stmt = $pdo->prepare("INSERT INTO rfq
        (rfq_number, supplier_id, warehouse_id, project_id, rfq_date, deadline_date,
         status, created_by, prepared_by_name, prepared_by_role)
        VALUES (?,?,?,?,?,?,'draft',?,?,?)");
    $stmt->execute([$rfq_number, $supplier_id, $warehouse_id, $project_id,
                    $rfq_date, $deadline, $_SESSION['user_id'], $prepared_name, $prepared_role]);
    $rfq_id = $pdo->lastInsertId();

    $si = $pdo->prepare("INSERT INTO rfq_items (rfq_id, description, unit, qty, item_order, product_id) VALUES (?,?,?,?,?,?)");
    foreach ($items as $k => $item) {
        $si->execute([$rfq_id, $item['description'], $item['unit'] ?? '', $item['qty'] ?? 1, $k + 1, $item['product_id'] ?? null]);
    }

    $pdo->commit();

    // ── File attachments (after commit, so rfq_id is stable) ───────────
    $allowed_ext  = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif'];
    $allowed_mime = [
        'application/pdf','application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg','image/png','image/gif',
    ];
    $upload_dir = __DIR__ . '/../uploads/procurement/rfq/';

    $att_names = $_POST['attachment_name'] ?? [];
    $att_files = $_FILES['attachment_file'] ?? [];

    if (!empty($att_files['name'])) {
        $ins = $pdo->prepare("INSERT INTO rfq_attachments
            (rfq_id, attachment_name, file_path, original_name, file_size, uploaded_by)
            VALUES (?,?,?,?,?,?)");

        foreach ($att_files['name'] as $i => $orig_name) {
            if (empty($orig_name) || $att_files['error'][$i] !== UPLOAD_ERR_OK) continue;

            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) continue;

            $finfo     = new finfo(FILEINFO_MIME_TYPE);
            $real_mime = $finfo->file($att_files['tmp_name'][$i]);
            if (!in_array($real_mime, $allowed_mime, true)) continue;

            if ($att_files['size'][$i] > 10 * 1024 * 1024) continue;

            $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            if (!move_uploaded_file($att_files['tmp_name'][$i], $upload_dir . $safe_name)) continue;

            $file_path  = 'uploads/procurement/rfq/' . $safe_name;
            $label      = trim($att_names[$i] ?? '') ?: $orig_name;

            registerFileInLibrary($pdo, $file_path, $orig_name,
                $att_files['size'][$i], $label, 'rfq,procurement', $_SESSION['user_id']);

            $ins->execute([$rfq_id, $label, $file_path, $orig_name,
                           $att_files['size'][$i], $_SESSION['user_id']]);
        }
    }

    logActivity($pdo, $_SESSION['user_id'], "Created RFQ #{$rfq_number}");
    echo json_encode(['success' => true, 'message' => "RFQ #{$rfq_number} created successfully.", 'rfq_id' => $rfq_id]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
