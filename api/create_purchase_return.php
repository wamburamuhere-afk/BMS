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
    // Start transaction
    $pdo->beginTransaction();

    // Get input data
    $supplierId = $_POST['supplier_id'] ?? null;
    $warehouseId = !empty($_POST['warehouse_id']) ? $_POST['warehouse_id'] : null;
    $receiptId = !empty($_POST['receipt_id']) ? $_POST['receipt_id'] : null;
    $returnDate = $_POST['return_date'] ?? date('Y-m-d');
    $reason = $_POST['reason'] ?? '';
    $reasonDetails = $_POST['reason_details'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $items = $_POST['items'] ?? [];

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

    // Generate specific return number
    // Format: RET-YYYYMMDD-XXXX
    $prefix = 'RET-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_returns WHERE return_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = $stmt->fetchColumn();
    $returnNumber = $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

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

    // Insert Return Record
    $stmt = $pdo->prepare("
        INSERT INTO purchase_returns (
            warehouse_id, supplier_id, receipt_id, return_number, return_date,
            status, reason, reason_details, notes, total_amount, created_by
        ) VALUES (
            ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?
        )
    ");

    $userId = $_SESSION['user_id'] ?? 0;
    $stmt->execute([
        $warehouseId, $supplierId, $receiptId, $returnNumber, $returnDate,
        $reason, $reasonDetails, $notes, $totalAmount, $userId
    ]);
    
    $returnId = $pdo->lastInsertId();

    // Insert Items
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

        // Validate stock before creating return
        if ($productId && $warehouseId) {
            $stmtCheck = $pdo->prepare("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
            $stmtCheck->execute([$productId, $warehouseId]);
            $available = $stmtCheck->fetchColumn() ?: 0;
            
            if (floatval($available) < $quantity) {
                throw new Exception("Insufficient stock for '$productName' in the selected warehouse. Required: $quantity, Available: $available.");
            }
        }

        $itemStmt->execute([
            $returnId, $productId, $productName, $quantity, $unitPrice, $itemReason, $lineTotal
        ]);
    }

    $pdo->commit();

    logActivity($pdo, $userId, "Created Purchase Return", "Return: $returnNumber (ID: $returnId), Supplier ID: $supplierId, Total: $totalAmount");

    echo json_encode(['success' => true, 'message' => 'Purchase return created successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
