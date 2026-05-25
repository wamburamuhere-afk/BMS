<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!canCreate('purchase_returns')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $returnId = $_POST['return_id'] ?? 0;

    // Phase C — block edits against returns on projects not in user scope
    if ($returnId) {
        assertScopeForRecord('purchase_returns', 'purchase_return_id', $returnId);
    }

    $pdo->beginTransaction();

    // Only allow editing if pending (usually)
    // Check status
    $stmt = $pdo->prepare("SELECT status FROM purchase_returns WHERE purchase_return_id = ?");
    $stmt->execute([$returnId]);
    $status = $stmt->fetchColumn();
    
    if (!$status) {
        throw new Exception("Return record not found");
    }
    
    if ($status != 'pending') {
        throw new Exception("Cannot edit a return that is not pending");
    }

    // Update main record fields
    $supplierId = $_POST['supplier_id'] ?? null;
    $warehouseId = !empty($_POST['warehouse_id']) ? $_POST['warehouse_id'] : null;
    $receiptId = !empty($_POST['receipt_id']) ? $_POST['receipt_id'] : null;
    $returnDate = $_POST['return_date'] ?? date('Y-m-d');
    $reason = $_POST['reason'] ?? '';
    $reasonDetails = $_POST['reason_details'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $items = $_POST['items'] ?? [];
    $userId = $_SESSION['user_id'] ?? 0;

    if (empty($supplierId) || empty($returnDate) || empty($items)) {
        throw new Exception("Please fill in all required fields and add at least one item.");
    }

    // Calculate Grand Total
    $totalAmount = 0;
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 0);
        $price = floatval($item['unit_price'] ?? 0);
        if (!empty($item['name']) && $qty > 0) {
            $totalAmount += ($qty * $price);
        }
    }

    // Handle optional file attachment
    $attachmentPath = null;
    if (!empty($_FILES['attachment']['name'])) {
        $uploadDir = __DIR__ . '/../uploads/procurement/purchase_returns/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $allowed = ['pdf','jpg','jpeg','png'];
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) throw new Exception('Invalid file type. Only PDF, JPG, PNG allowed.');
        if ($_FILES['attachment']['size'] > 10 * 1024 * 1024) throw new Exception('File too large. Max 10MB.');
        $filename = 'RET_' . time() . '_' . uniqid() . '.' . $ext;
        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $filename)) {
            throw new Exception('Failed to upload attachment.');
        }
        $attachmentPath = 'uploads/procurement/purchase_returns/' . $filename;
    }

    if ($attachmentPath) {
        $updateStmt = $pdo->prepare("
            UPDATE purchase_returns
            SET warehouse_id = ?, supplier_id = ?, receipt_id = ?, return_date = ?,
                reason = ?, reason_details = ?, notes = ?, total_amount = ?,
                attachment = ?, updated_by = ?, updated_at = NOW()
            WHERE purchase_return_id = ?
        ");
        $updateStmt->execute([
            $warehouseId, $supplierId, $receiptId, $returnDate,
            $reason, $reasonDetails, $notes, $totalAmount,
            $attachmentPath, $userId, $returnId
        ]);
    } else {
        $updateStmt = $pdo->prepare("
            UPDATE purchase_returns
            SET warehouse_id = ?, supplier_id = ?, receipt_id = ?, return_date = ?,
                reason = ?, reason_details = ?, notes = ?, total_amount = ?,
                updated_by = ?, updated_at = NOW()
            WHERE purchase_return_id = ?
        ");
        $updateStmt->execute([
            $warehouseId, $supplierId, $receiptId, $returnDate,
            $reason, $reasonDetails, $notes, $totalAmount,
            $userId, $returnId
        ]);
    }

    // Update Items: Delete and Re-insert
    $pdo->prepare("DELETE FROM purchase_return_items WHERE purchase_return_id = ?")->execute([$returnId]);
    
    $itemStmt = $pdo->prepare("
        INSERT INTO purchase_return_items (
            purchase_return_id, product_id, product_name, quantity, unit_price, reason, line_total
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?
        )
    ");

    foreach ($items as $item) {
        $productId = !empty($item['product_id']) ? intval($item['product_id']) : null;
        $productName = $item['name'] ?? '';
        $quantity = floatval($item['quantity'] ?? 0);
        $unitPrice = floatval($item['unit_price'] ?? 0);
        $itemReason = $item['item_reason'] ?? '';
        $lineTotal = $quantity * $unitPrice;

        if (empty($productName) || $quantity <= 0) {
            continue;
        }

        $itemStmt->execute([
            $returnId, $productId, $productName, $quantity, $unitPrice, $itemReason, $lineTotal
        ]);
    }

    $pdo->commit();

    logActivity($pdo, $userId, "Updated Purchase Return", "Return ID: $returnId, Total: $totalAmount");

    echo json_encode(['success' => true, 'message' => 'Purchase return updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
