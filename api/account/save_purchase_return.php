<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

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

// Check permissions
$return_id = isset($_POST['return_id']) ? intval($_POST['return_id']) : 0;
$is_update = ($return_id > 0);

if ($is_update) {
    if (!canEdit('purchase_returns')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to edit purchase returns']);
        exit;
    }
} else {
    if (!canCreate('purchase_returns')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create purchase returns']);
        exit;
    }
}

try {
    global $pdo;
    $pdo->beginTransaction();

    $return_id = isset($_POST['return_id']) ? intval($_POST['return_id']) : 0;
    $supplier_id = $_POST['supplier_id'] ?? 0;
    $purchase_order_id = $_POST['purchase_order_id'] ?? null;
    $return_date = $_POST['return_date'] ?? date('Y-m-d');
    $reason = $_POST['reason'] ?? '';
    $reason_details = $_POST['reason_details'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'pending';
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);

    if (empty($supplier_id) || empty($reason) || empty($items)) {
        throw new Exception("Missing required fields (Supplier, Reason, or Items)");
    }

    // Calculate total
    $total_amount = 0;
    foreach ($items as $item) {
        $total_amount += floatval($item['quantity'] ?? 0) * floatval($item['unit_price'] ?? 0);
    }

    if ($return_id > 0) {
        // Update
        $stmt = $pdo->prepare("
            UPDATE purchase_returns SET 
                supplier_id = ?, purchase_order_id = ?, return_date = ?, 
                reason = ?, reason_details = ?, notes = ?, total_amount = ?,
                status = ?, updated_at = NOW(), updated_by = ?
            WHERE purchase_return_id = ?
        ");
        $stmt->execute([
            $supplier_id, $purchase_order_id, $return_date,
            $reason, $reason_details, $notes, $total_amount,
            $status, $_SESSION['user_id'], $return_id
        ]);
        
        $pdo->prepare("DELETE FROM purchase_return_items WHERE purchase_return_id = ?")->execute([$return_id]);
    } else {
        // Insert
        // Generate return number
        $stmt = $pdo->query("SELECT MAX(purchase_return_id) FROM purchase_returns");
        $max_id = $stmt->fetchColumn();
        $return_number = 'PR-' . date('Ymd') . '-' . str_pad(($max_id + 1), 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO purchase_returns (
                return_number, supplier_id, purchase_order_id, return_date,
                reason, reason_details, notes, total_amount, status,
                created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $return_number, $supplier_id, $purchase_order_id, $return_date,
            $reason, $reason_details, $notes, $total_amount, $status,
            $_SESSION['user_id']
        ]);
        $return_id = $pdo->lastInsertId();
    }

    // Insert Items
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 0);
        $price = floatval($item['unit_price'] ?? 0);
        $line_total = $qty * $price;

        $itemStmt = $pdo->prepare("
            INSERT INTO purchase_return_items (
                purchase_return_id, product_id, item_name, quantity, 
                unit_price, reason, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $itemStmt->execute([
            $return_id, $item['product_id'] ?: null, $item['name'], 
            $qty, $price, $item['item_reason'] ?? ''
        ]);
    }

    $pdo->commit();

    // Phase 3a — financial-write audit trail.
    logActivity(
        $pdo,
        $_SESSION['user_id'] ?? 0,
        $is_update ? "Updated Purchase Return" : "Created Purchase Return",
        "Purchase Return ID: $return_id" . (!empty($return_number) ? ", number: $return_number" : '')
    );

    echo json_encode([
        'success' => true,
        'message' => 'Purchase Return saved successfully',
        'return_id' => $return_id,
        'return_number' => $return_number ?? ''
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving purchase return: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
