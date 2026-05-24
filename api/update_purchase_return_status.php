<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!canCreate('purchase_returns')) { // Or specific permission like 'approve_purchase_returns'
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $returnId = $_POST['return_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    $allowed_statuses = ['pending', 'approved', 'rejected', 'completed', 'cancelled'];
    
    if (!$returnId || !in_array($status, $allowed_statuses)) {
        throw new Exception("Invalid parameters");
    }

    $pdo->beginTransaction();

    // Get current state
    $stmt = $pdo->prepare("SELECT warehouse_id, status, stock_updated FROM purchase_returns WHERE purchase_return_id = ?");
    $stmt->execute([$returnId]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$return) {
        throw new Exception("Return record not found");
    }

    $userId = $_SESSION['user_id'] ?? 0;

    // Update status
    $stmt = $pdo->prepare("
        UPDATE purchase_returns 
        SET status = ?, updated_by = ?, updated_at = NOW() 
        WHERE purchase_return_id = ?
    ");
    $stmt->execute([$status, $userId, $returnId]);

    // Handle stock adjustments if status is 'approved' or 'completed' and not already updated
    if (($status === 'approved' || $status === 'completed') && $return['stock_updated'] == 0) {
        adjustStock($returnId, $return['warehouse_id'], 'deduct');
        // Mark as stock updated
        $stmtMark = $pdo->prepare("UPDATE purchase_returns SET stock_updated = 1 WHERE purchase_return_id = ?");
        $stmtMark->execute([$returnId]);
    } 
    // Handle stock reversal if status moves to 'rejected', 'cancelled' or back to 'pending' and was already updated
    elseif (in_array($status, ['rejected', 'cancelled', 'pending']) && $return['stock_updated'] == 1) {
        adjustStock($returnId, $return['warehouse_id'], 'add');
        // Mark as stock NOT updated
        $stmtMark = $pdo->prepare("UPDATE purchase_returns SET stock_updated = 0 WHERE purchase_return_id = ?");
        $stmtMark->execute([$returnId]);
    }

    $pdo->commit();

    logActivity($pdo, $userId, "Updated Purchase Return Status", "Return ID: $returnId, Status: $status");

    echo json_encode(['success' => true, 'message' => 'Return status updated to ' . ucfirst($status) . ' and stock adjusted.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

/**
 * Helper function to adjust stock
 */
function adjustStock($returnId, $warehouse_id, $action = 'deduct') {
    global $pdo;
    
    if (!$warehouse_id) {
        throw new Exception("Warehouse not specified for this return. Cannot adjust stock.");
    }

    // Fetch return items
    $itemStmt = $pdo->prepare("SELECT product_id, quantity FROM purchase_return_items WHERE purchase_return_id = ?");
    $itemStmt->execute([$returnId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $operator = ($action === 'deduct') ? '-' : '+';
    $calc_qty = ($action === 'deduct') ? -1 : 1;

    foreach ($items as $item) {
        $product_id = intval($item['product_id']);
        $qty = floatval($item['quantity']);

        if ($product_id <= 0 || $qty <= 0) continue;

        // 2. Update specific warehouse stock level & Validate
        $stmtCheck = $pdo->prepare("SELECT stock_id, stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
        $stmtCheck->execute([$product_id, $warehouse_id]);
        $stockRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($action === 'deduct') {
            $currentVal = $stockRow ? floatval($stockRow['stock_quantity']) : 0;
            if ($currentVal < $qty) {
                $stmtPName = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?");
                $stmtPName->execute([$product_id]);
                $pName = $stmtPName->fetchColumn();
                throw new Exception("Insufficient stock for '$pName' in this warehouse. Required: $qty, Available: $currentVal.");
            }
        }

        // 1. Update global product stock level (Deduction or Addition)
        $stmtStock = $pdo->prepare("
            UPDATE products 
            SET current_stock = current_stock $operator ?,
                stock_quantity = stock_quantity $operator ?
            WHERE product_id = ?
        ");
        $stmtStock->execute([$qty, $qty, $product_id]);

        if ($stockRow) {
            $stmtUpdatePS = $pdo->prepare("
                UPDATE product_stocks 
                SET stock_quantity = stock_quantity $operator ?,
                    last_updated = NOW()
                WHERE stock_id = ?
            ");
            $stmtUpdatePS->execute([$qty, $stockRow['stock_id']]);
        } else {
            // This part only hits for 'add' (reversal) because 'deduct' is blocked above if not exists
            $final_qty = $qty * $calc_qty;
            $stmtInsertPS = $pdo->prepare("
                INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, last_updated)
                VALUES (?, ?, ?, NOW())
            ");
            $stmtInsertPS->execute([$product_id, $warehouse_id, $final_qty]);
        }
    }
}


?>
