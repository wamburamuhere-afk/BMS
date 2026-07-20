<?php
// File: api/operations/create_do_full.php
// Creates a Delivery Order directly from a project (supplier + warehouse +
// items + optional attachments), independent of any source Delivery Note.
// Mirrors api/create_rfq.php's item + secure multi-attachment pattern.
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    csrf_check();

    if (!canCreate('do')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to create delivery orders');
    }

    $project_id     = intval($_POST['project_id']     ?? 0);
    $supplier_id    = intval($_POST['supplier_id']     ?? 0);
    $warehouse_id   = intval($_POST['warehouse_id']    ?? 0);
    $do_date        = trim($_POST['do_date']           ?? date('Y-m-d'));
    $expected_date  = trim($_POST['expected_date']     ?? '') ?: null;
    $contact_person = trim($_POST['contact_person']    ?? '') ?: null;
    $contact_phone  = trim($_POST['contact_phone']     ?? '') ?: null;
    $notes          = trim($_POST['notes']             ?? '') ?: null;
    $items          = json_decode($_POST['items'] ?? '[]', true) ?: [];

    if ($project_id <= 0)   throw new Exception('Project is required.');
    if ($supplier_id <= 0)  throw new Exception('Supplier is required.');
    if ($warehouse_id <= 0) throw new Exception('Warehouse is required.');
    if (!$do_date)           throw new Exception('DO Date is required.');
    if (empty($items))       throw new Exception('At least one delivered item is required.');

    // Phase C — block creates against projects not in user scope
    if (!userCan('project', $project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this project is not in your scope.');
    }

    // Company-prefixed sequential DO number (BFS-DO-0001).
    require_once __DIR__ . '/../../core/code_generator.php';
    $do_number = nextCode($pdo, 'DO');

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO delivery_orders
            (do_number, dn_id, project_id, warehouse_id, supplier_id, do_date,
             expected_date, contact_person, contact_phone, notes, status, created_by)
        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $stmt->execute([
        $do_number, $project_id, $warehouse_id, $supplier_id, $do_date,
        $expected_date, $contact_person, $contact_phone, $notes, $_SESSION['user_id']
    ]);
    $do_id = $pdo->lastInsertId();

    $si = $pdo->prepare("
        INSERT INTO delivery_order_items (do_id, product_id, product_name, available_qty, qty_to_issue, unit)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($items as $item) {
        $pname = trim($item['product_name'] ?? '');
        if ($pname === '') continue;
        $si->execute([
            $do_id,
            !empty($item['product_id']) ? (int)$item['product_id'] : null,
            $pname,
            (float)($item['available_qty'] ?? 0),
            (float)($item['qty_to_issue'] ?? 1),
            trim($item['unit'] ?? '') ?: 'pcs',
        ]);
    }

    $pdo->commit();

    // ── File attachments (after commit, so do_id is stable) ────────────
    $allowed_ext  = ['pdf', 'jpg', 'jpeg', 'png'];
    $allowed_mime = ['application/pdf', 'image/jpeg', 'image/png'];
    $upload_dir   = __DIR__ . '/../../uploads/procurement/delivery_orders/';

    $att_names = $_POST['attachment_names'] ?? [];
    $att_files = $_FILES['attachments'] ?? [];

    if (!empty($att_files['name'])) {
        $ins = $pdo->prepare("
            INSERT INTO do_attachments (do_id, attachment_name, file_path, original_name, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
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

            $file_path = 'uploads/procurement/delivery_orders/' . $safe_name;
            $label     = trim($att_names[$i] ?? '') ?: $orig_name;

            registerFileInLibrary($pdo, $file_path, $orig_name,
                $att_files['size'][$i], $label, 'do,procurement', $_SESSION['user_id']);

            $ins->execute([$do_id, $label, $file_path, $orig_name, $att_files['size'][$i], $_SESSION['user_id']]);
        }
    }

    logActivity($pdo, $_SESSION['user_id'], 'Create DO', "User created a new Delivery Order: $do_number (ID $do_id)");

    echo json_encode([
        'success'   => true,
        'message'   => "Delivery Order #$do_number created successfully.",
        'do_id'     => $do_id,
        'do_number' => $do_number,
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
