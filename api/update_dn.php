<?php
// File: api/update_dn.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

try {
    $delivery_id     = intval($_POST['delivery_id']     ?? 0);
    $project_id      = intval($_POST['project_id']      ?? 0);
    $warehouse_id    = intval($_POST['warehouse_id']    ?? 0);
    $supplier_id     = intval($_POST['supplier_id']     ?? 0);
    $delivery_date   = trim($_POST['delivery_date']     ?? date('Y-m-d'));
    $contact_person  = trim($_POST['contact_person']    ?? '');
    $contact_phone   = trim($_POST['contact_phone']     ?? '');
    $delivery_address= trim($_POST['delivery_address']  ?? '');
    $notes           = trim($_POST['notes']             ?? '');
    $do_id           = intval($_POST['do_id'] ?? 0) ?: null;
    $items_json      = $_POST['items'] ?? '[]';
    $items           = json_decode($items_json, true);
    $purchase_order_id = intval($_POST['purchase_order_id'] ?? 0) ?: null;
    $user_id         = $_SESSION['user_id'];

    if ($delivery_id <= 0)  throw new Exception('DN ID is required.');
    if ($warehouse_id <= 0) throw new Exception('Warehouse is required.');
    if ($supplier_id <= 0)  throw new Exception('Supplier is required.');
    if (empty($items))      throw new Exception('At least one item is required.');

    // Check DN exists and is editable
    $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
    $stmt->execute([$delivery_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn) throw new Exception('Delivery Note not found.');
    if ($dn['status'] === 'approved') throw new Exception('Cannot edit an approved Delivery Note.');

    // Validate items
    foreach ($items as &$item) {
        $item['product_id'] = intval($item['product_id']);
        $item['quantity']   = floatval($item['quantity']);
        if ($item['product_id'] <= 0) throw new Exception('Invalid product.');
        if ($item['quantity'] <= 0)   throw new Exception('Quantity must be > 0.');
        $item['unit'] = $item['unit'] ?? 'pcs';
    }
    unset($item);

    $pdo->beginTransaction();

    // 1. Update DN header
    $pdo->prepare("
        UPDATE deliveries
        SET delivery_date=?, contact_person=?, contact_phone=?, delivery_address=?, notes=?,
            warehouse_id=?, supplier_id=?, project_id=?, do_id=?, purchase_order_id=?, updated_by=?
        WHERE delivery_id=?
    ")->execute([$delivery_date, $contact_person ?: null, $contact_phone ?: null,
                 $delivery_address ?: null, $notes ?: null, $warehouse_id, $supplier_id,
                 $project_id ?: null, $do_id, $purchase_order_id, $user_id, $delivery_id]);

    // 2. Delete old items and re-insert
    $pdo->prepare("DELETE FROM delivery_items WHERE delivery_id = ?")->execute([$delivery_id]);
    $item_stmt = $pdo->prepare("
        INSERT INTO delivery_items (delivery_id, product_id, product_name, sku, quantity_delivered, unit)
        SELECT ?, p.product_id, p.product_name, p.sku, ?, ?
        FROM products p WHERE p.product_id = ?
    ");
    foreach ($items as $item) {
        $item_stmt->execute([$delivery_id, $item['quantity'], $item['unit'], $item['product_id']]);
    }

    // 3. Handle Attachments
    $upload_dir = __DIR__ . '/../uploads/deliveries/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    // 3a. Delete Attachments
    if (!empty($_POST['delete_attachment_ids'])) {
        foreach ($_POST['delete_attachment_ids'] as $del_id) {
            $del_id = intval($del_id);
            $stmt = $pdo->prepare("SELECT file_path FROM delivery_attachments WHERE attachment_id = ? AND delivery_id = ?");
            $stmt->execute([$del_id, $delivery_id]);
            $fpath = $stmt->fetchColumn();
            if ($fpath && file_exists(__DIR__ . '/../' . $fpath)) {
                unlink(__DIR__ . '/../' . $fpath);
            }
            $pdo->prepare("DELETE FROM delivery_attachments WHERE attachment_id = ?")->execute([$del_id]);
        }
    }

    // 3b. Update/Replace Existing Attachments
    if (!empty($_POST['existing_attachment_ids'])) {
        foreach ($_POST['existing_attachment_ids'] as $idx => $att_id) {
            $att_id = intval($att_id);
            $new_name = $_POST['existing_attachment_names'][$idx] ?? 'Document';
            
            // Check for file replacement
            $file_key = "replace_attachments_{$att_id}";
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                // Remove old file
                $stmt = $pdo->prepare("SELECT file_path FROM delivery_attachments WHERE attachment_id = ?");
                $stmt->execute([$att_id]);
                $old_path = $stmt->fetchColumn();
                if ($old_path && file_exists(__DIR__ . '/../' . $old_path)) {
                    unlink(__DIR__ . '/../' . $old_path);
                }

                // Upload new file
                $tmp_name = $_FILES[$file_key]['tmp_name'];
                $orig_name = $_FILES[$file_key]['name'];
                $ext = pathinfo($orig_name, PATHINFO_EXTENSION);
                $new_filename = 'DN_REP_' . $delivery_id . '_' . $att_id . '_' . time() . '.' . $ext;
                $target_path = $upload_dir . $new_filename;

                if (move_uploaded_file($tmp_name, $target_path)) {
                    $rel_path = 'uploads/deliveries/' . $new_filename;
                    $pdo->prepare("UPDATE delivery_attachments SET file_name=?, file_path=?, file_type=?, file_size=? WHERE attachment_id=?")
                        ->execute([$new_name, $rel_path, $_FILES[$file_key]['type'], $_FILES[$file_key]['size'], $att_id]);
                }
            } else {
                // Just update the name
                $pdo->prepare("UPDATE delivery_attachments SET file_name=? WHERE attachment_id=?")
                    ->execute([$new_name, $att_id]);
            }
        }
    }

    // 3c. Add New Attachments
    if (!empty($_FILES['attachments']['name'])) {
        $file_count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                $orig_name = $_FILES['attachments']['name'][$i];
                $ext = pathinfo($orig_name, PATHINFO_EXTENSION);
                $custom_name = !empty($_POST['attachment_names'][$i]) ? $_POST['attachment_names'][$i] : pathinfo($orig_name, PATHINFO_FILENAME);
                $new_filename = 'DN_NEW_' . $delivery_id . '_' . $i . '_' . time() . '.' . $ext;
                $target_path = $upload_dir . $new_filename;

                if (move_uploaded_file($tmp_name, $target_path)) {
                    $rel_path = 'uploads/deliveries/' . $new_filename;
                    $pdo->prepare("INSERT INTO delivery_attachments (delivery_id, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$delivery_id, $custom_name, $rel_path, $_FILES['attachments']['type'][$i], $_FILES['attachments']['size'][$i], $user_id]);
                }
            }
        }
    }

    logActivity($pdo, $user_id, "Updated Delivery Note #" . $dn['delivery_number']);
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => "Delivery Note updated successfully.", 'delivery_id' => $delivery_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
