<?php
/**
 * API: Update Goods Received Note (GRN)
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Please login to continue']);
    exit();
}

if (!canEdit('grn') && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied: You do not have permission to edit GRN']);
    exit();
}

try {
    $receipt_id = intval($_POST['receipt_id'] ?? 0);
    if ($receipt_id <= 0) throw new Exception('Invalid GRN ID');

    // AUTO-MIGRATION: Ensure columns exist (for online server stability)
    $columns_to_check = [
        'purchase_order_item_id' => "INT NULL AFTER receipt_id",
        'unit'                   => "VARCHAR(20) DEFAULT 'pcs' AFTER expiry_date"
    ];
    foreach ($columns_to_check as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM receipt_items LIKE '$col'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE receipt_items ADD COLUMN $col $definition");
        }
    }

    // Get old GRN data to compare stock
    $stmtOld = $pdo->prepare("SELECT * FROM purchase_receipts WHERE receipt_id = ?");
    $stmtOld->execute([$receipt_id]);
    $oldGrn = $stmtOld->fetch(PDO::FETCH_ASSOC);
    if (!$oldGrn) throw new Exception('GRN not found');

    // Phase E — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('purchase_receipts', 'receipt_id', $receipt_id);
    }

    // Ledger lock — a posted or approved GRN is immutable (it has posted to the
    // ledger and moved stock). Corrections go through void/reverse, not edit.
    require_once __DIR__ . '/../core/code_generator.php';
    if (documentGlPosted($pdo, 'grn', $receipt_id) || ($oldGrn['status'] ?? '') === 'approved') {
        echo json_encode(['success' => false, 'message' => 'This GRN is posted/approved and locked. Void or reverse it to make changes.']);
        exit();
    }

    $pdo->beginTransaction();

    // Get form data
    $receipt_date = $_POST['receipt_date'] ?? date('Y-m-d');
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
    $delivery_note = $_POST['delivery_note'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $items = json_decode($_POST['items'], true);
    
    if (empty($items)) throw new Exception('No items provided');

    // Re-code on edit, but only while this GRN is not yet posted to the GL.
    require_once __DIR__ . '/../core/code_generator.php';
    $grn_number = codeForEditUnlessPosted(
        $pdo, 'GRN', (string)$oldGrn['receipt_number'], 'GRN-[0-9].*',
        'grn', (int)$receipt_id, 'purchase_receipts'
    );

    // Update purchase_receipts
    $stmt = $pdo->prepare("
        UPDATE purchase_receipts SET
            receipt_number = ?, supplier_id = ?, warehouse_id = ?, receipt_date = ?,
            delivery_note = ?, notes = ?
        WHERE receipt_id = ?
    ");
    $stmt->execute([$grn_number, $supplier_id, $warehouse_id, $receipt_date, $delivery_note, $notes, $receipt_id]);

    // Handle Stock Reversal if old GRN was completed
    if ($oldGrn['status'] === 'completed') {
        $stmtOldItems = $pdo->prepare("SELECT product_id, quantity_received FROM receipt_items WHERE receipt_id = ?");
        $stmtOldItems->execute([$receipt_id]);
        $oldItems = $stmtOldItems->fetchAll(PDO::FETCH_ASSOC);

        foreach ($oldItems as $oi) {
            // Reverse stock from old warehouse
            $pdo->prepare("UPDATE products SET current_stock = current_stock - ?, stock_quantity = stock_quantity - ? WHERE product_id = ?")
                ->execute([$oi['quantity_received'], $oi['quantity_received'], $oi['product_id']]);
            
            $pdo->prepare("UPDATE product_stocks SET stock_quantity = stock_quantity - ? WHERE product_id = ? AND warehouse_id = ?")
                ->execute([$oi['quantity_received'], $oi['product_id'], $oldGrn['warehouse_id']]);
        }
    }

    // Delete old items
    $pdo->prepare("DELETE FROM receipt_items WHERE receipt_id = ?")->execute([$receipt_id]);

    // Insert New Items and Update Stock
    $stmtItem = $pdo->prepare("
        INSERT INTO receipt_items (
            receipt_id, purchase_order_item_id, product_id,
            quantity_received, unit_price, batch_number, expiry_date, unit
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($items as $item) {
        $product_id = intval($item['product_id']);
        $qty = floatval($item['quantity_received']);
        $price = floatval($item['unit_price']);
        $po_item_id = !empty($item['purchase_order_item_id']) ? intval($item['purchase_order_item_id']) : null;

        $stmtItem->execute([
            $receipt_id, $po_item_id, $product_id,
            $qty, $price, $item['batch_number'] ?? null,
            !empty($item['expiry_date']) ? $item['expiry_date'] : null,
            $item['unit'] ?? 'pcs'
        ]);

        // Update new stock if status is completed
        if ($oldGrn['status'] === 'completed') {
            $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, stock_quantity = stock_quantity + ? WHERE product_id = ?")
                ->execute([$qty, $qty, $product_id]);

            // Check if stock record exists for this warehouse
            $stmtCheck = $pdo->prepare("SELECT stock_id FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
            $stmtCheck->execute([$product_id, $warehouse_id]);
            $stockId = $stmtCheck->fetchColumn();

            if ($stockId) {
                $pdo->prepare("UPDATE product_stocks SET stock_quantity = stock_quantity + ?, last_updated = NOW() WHERE stock_id = ?")
                    ->execute([$qty, $stockId]);
            } else {
                $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, last_updated) VALUES (?, ?, ?, NOW())")
                    ->execute([$product_id, $warehouse_id, $qty]);
            }
        }
    }

    // Unified Attachment Handling
    $current_attachment_ids = $_POST['attachment_ids'] ?? [];
    $attachment_names = $_POST['attachment_names'] ?? [];
    
    // 1. Handle Deletions (Remove attachments that are no longer in the form)
    $stmtExisting = $pdo->prepare("SELECT attachment_id, file_path FROM purchase_receipt_attachments WHERE receipt_id = ?");
    $stmtExisting->execute([$receipt_id]);
    $dbAttachments = $stmtExisting->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dbAttachments as $dbAtt) {
        if (!in_array($dbAtt['attachment_id'], $current_attachment_ids)) {
            // Delete file from disk
            $full_path = __DIR__ . '/../' . $dbAtt['file_path'];
            if (file_exists($full_path)) @unlink($full_path);
            
            // Delete from DB
            $pdo->prepare("DELETE FROM purchase_receipt_attachments WHERE attachment_id = ?")->execute([$dbAtt['attachment_id']]);
        }
    }

    // 2. Handle Updates and New Attachments
    if (!empty($current_attachment_ids)) {
        $upload_dir = __DIR__ . '/../uploads/finance/grn/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        foreach ($current_attachment_ids as $i => $att_id) {
            $doc_name = !empty($attachment_names[$i]) ? $attachment_names[$i] : '';
            $has_new_file = isset($_FILES['attachments']['name'][$i]) && !empty($_FILES['attachments']['name'][$i]);

            if ($att_id > 0) {
                // UPDATE Existing
                if ($has_new_file) {
                    // Replace File
                    $extension = pathinfo($_FILES['attachments']['name'][$i], PATHINFO_EXTENSION);
                    $file_name = 'GRN_UP_' . $receipt_id . '_' . $att_id . '_' . time() . '.' . $extension;
                    $file_path = 'uploads/finance/grn/' . $file_name;
                    
                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $upload_dir . $file_name)) {
                        // Delete old file
                        $stmtOldPath = $pdo->prepare("SELECT file_path FROM purchase_receipt_attachments WHERE attachment_id = ?");
                        $stmtOldPath->execute([$att_id]);
                        $old_path = $stmtOldPath->fetchColumn();
                        if ($old_path && file_exists(__DIR__ . '/../' . $old_path)) @unlink(__DIR__ . '/../' . $old_path);

                        $pdo->prepare("
                            UPDATE purchase_receipt_attachments SET 
                                file_name = ?, file_path = ?, file_type = ?, file_size = ?
                            WHERE attachment_id = ?
                        ")->execute([
                            $doc_name, $file_path, $_FILES['attachments']['type'][$i], 
                            $_FILES['attachments']['size'][$i], $att_id
                        ]);
                    }
                } else {
                    // Only update Name
                    $pdo->prepare("UPDATE purchase_receipt_attachments SET file_name = ? WHERE attachment_id = ?")
                        ->execute([$doc_name, $att_id]);
                }
            } else {
                // INSERT New
                if ($has_new_file) {
                    $extension = pathinfo($_FILES['attachments']['name'][$i], PATHINFO_EXTENSION);
                    $file_name = 'GRN_NEW_' . $receipt_id . '_' . time() . '_' . $i . '.' . $extension;
                    $file_path = 'uploads/finance/grn/' . $file_name;

                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $upload_dir . $file_name)) {
                        $pdo->prepare("
                            INSERT INTO purchase_receipt_attachments (
                                receipt_id, file_name, file_path, file_type, file_size, uploaded_by, uploaded_at, description
                            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                        ")->execute([
                            $receipt_id, $doc_name, $file_path, 
                            $_FILES['attachments']['type'][$i], $_FILES['attachments']['size'][$i],
                            $_SESSION['user_id'], $doc_name
                        ]);
                    }
                }
            }
        }
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Updated GRN", "GRN Receipt ID: $receipt_id, Items: " . count($items));

    echo json_encode(['success' => true, 'message' => 'GRN updated successfully', 'receipt_id' => $receipt_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error updating GRN: ' . $e->getMessage()]);
}
