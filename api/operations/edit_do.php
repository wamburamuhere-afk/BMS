<?php
// File: api/operations/edit_do.php
// Updates a Delivery Order's core fields + delivered items (replace-all) +
// attachments (rename / replace-file / add-new / remove).
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    csrf_check();

    if (!canEdit('do')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to edit delivery orders');
    }

    $do_id = intval($_POST['do_id'] ?? 0);
    if (!$do_id) throw new Exception('DO ID is required.');

    // Phase C — block edits against DOs on projects not in user scope
    assertScopeForRecord('delivery_orders', 'do_id', $do_id);

    $do = $pdo->prepare("SELECT do_id, do_number, status FROM delivery_orders WHERE do_id = ?");
    $do->execute([$do_id]);
    $do = $do->fetch(PDO::FETCH_ASSOC);
    if (!$do) throw new Exception('Delivery Order not found.');
    if (in_array($do['status'], ['delivered', 'cancelled'], true)) {
        throw new Exception("This Delivery Order is {$do['status']} and can no longer be edited.");
    }

    $supplier_id    = intval($_POST['supplier_id']     ?? 0);
    $warehouse_id   = intval($_POST['warehouse_id']    ?? 0);
    $do_date        = trim($_POST['do_date']           ?? '');
    $expected_date  = trim($_POST['expected_date']     ?? '') ?: null;
    $contact_person = trim($_POST['contact_person']    ?? '') ?: null;
    $contact_phone  = trim($_POST['contact_phone']     ?? '') ?: null;
    $notes          = trim($_POST['notes']             ?? '') ?: null;
    $items          = json_decode($_POST['items'] ?? '[]', true) ?: [];
    $remove_ids     = json_decode($_POST['remove_attachment_ids'] ?? '[]', true) ?: [];

    if ($supplier_id <= 0)  throw new Exception('Supplier is required.');
    if ($warehouse_id <= 0) throw new Exception('Warehouse is required.');
    if (!$do_date)           throw new Exception('DO date is required.');
    if (empty($items))       throw new Exception('At least one delivered item is required.');

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE delivery_orders
        SET supplier_id=?, warehouse_id=?, do_date=?, expected_date=?,
            contact_person=?, contact_phone=?, notes=?, updated_by=?
        WHERE do_id=?
    ")->execute([
        $supplier_id, $warehouse_id, $do_date, $expected_date,
        $contact_person, $contact_phone, $notes, $_SESSION['user_id'], $do_id
    ]);

    // Items — replace-all (the edit modal always resubmits the full current set).
    $pdo->prepare("DELETE FROM delivery_order_items WHERE do_id = ?")->execute([$do_id]);
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

    // Removed attachments — ownership-checked, best-effort file unlink.
    if (!empty($remove_ids)) {
        $ph = implode(',', array_fill(0, count($remove_ids), '?'));
        $rows = $pdo->prepare("SELECT do_attachment_id, file_path FROM do_attachments WHERE do_id = ? AND do_attachment_id IN ($ph)");
        $rows->execute(array_merge([$do_id], array_map('intval', $remove_ids)));
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $full = __DIR__ . '/../../' . $r['file_path'];
            if (is_file($full)) @unlink($full);
        }
        $pdo->prepare("DELETE FROM do_attachments WHERE do_id = ? AND do_attachment_id IN ($ph)")
            ->execute(array_merge([$do_id], array_map('intval', $remove_ids)));
    }

    // Renamed existing attachments — ownership-checked.
    $existing_names = $_POST['existing_att_names'] ?? [];
    if (is_array($existing_names) && !empty($existing_names)) {
        $rn = $pdo->prepare("UPDATE do_attachments SET attachment_name=? WHERE do_attachment_id=? AND do_id=?");
        foreach ($existing_names as $att_id => $name) {
            $att_id = intval($att_id);
            $name   = trim((string)$name);
            if ($att_id <= 0 || $name === '') continue;
            $rn->execute([$name, $att_id, $do_id]);
        }
    }

    $allowed_ext  = ['pdf', 'jpg', 'jpeg', 'png'];
    $allowed_mime = ['application/pdf', 'image/jpeg', 'image/png'];
    $upload_dir   = __DIR__ . '/../../uploads/procurement/delivery_orders/';

    // Replaced files on existing attachments — ownership-checked.
    $existing_files = $_FILES['existing_att_files'] ?? null;
    if ($existing_files && !empty($existing_files['name'])) {
        $own = $pdo->prepare("SELECT file_path FROM do_attachments WHERE do_attachment_id=? AND do_id=?");
        $upd = $pdo->prepare("UPDATE do_attachments SET file_path=?, original_name=?, file_size=? WHERE do_attachment_id=? AND do_id=?");
        foreach ($existing_files['name'] as $att_id => $orig_name) {
            $att_id = intval($att_id);
            if ($att_id <= 0 || empty($orig_name) || $existing_files['error'][$att_id] !== UPLOAD_ERR_OK) continue;

            $own->execute([$att_id, $do_id]);
            $prev = $own->fetch(PDO::FETCH_ASSOC);
            if (!$prev) continue; // not this DO's attachment

            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) continue;

            $finfo     = new finfo(FILEINFO_MIME_TYPE);
            $real_mime = $finfo->file($existing_files['tmp_name'][$att_id]);
            if (!in_array($real_mime, $allowed_mime, true)) continue;

            if ($existing_files['size'][$att_id] > 10 * 1024 * 1024) continue;

            $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            if (!move_uploaded_file($existing_files['tmp_name'][$att_id], $upload_dir . $safe_name)) continue;

            $prevFull = __DIR__ . '/../../' . $prev['file_path'];
            if (is_file($prevFull)) @unlink($prevFull);

            $file_path = 'uploads/procurement/delivery_orders/' . $safe_name;
            $upd->execute([$file_path, $orig_name, $existing_files['size'][$att_id], $att_id, $do_id]);
        }
    }

    // New attachments — same secure-upload pattern as create.
    $att_names = $_POST['new_attachment_names'] ?? [];
    $att_files = $_FILES['new_attachments'] ?? [];
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

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], 'Edit DO', "User updated Delivery Order #{$do['do_number']} (ID $do_id)");

    echo json_encode(['success' => true, 'message' => "Delivery Order #{$do['do_number']} updated successfully."]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
