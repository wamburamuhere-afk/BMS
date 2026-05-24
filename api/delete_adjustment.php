<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../app/core/session.php';
require_once __DIR__ . '/../app/core/database.php';
require_once __DIR__ . '/../app/core/utils.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// canDelete('stock_adjustments') admin-bypasses internally — replaces the
// legacy role-string check so non-admin roles can be delegated via user_roles.php
if (!canDelete('stock_adjustments')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete stock adjustments']);
    exit;
}

if (!isset($_POST['adjustment_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Adjustment ID is required']);
    exit;
}

$adjustment_id = intval($_POST['adjustment_id']);

try {
    $pdo->beginTransaction();

    // Get the adjustment details
    $stmt = $pdo->prepare("SELECT * FROM stock_movements WHERE movement_id = ?");
    $stmt->execute([$adjustment_id]);
    $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adjustment) {
        throw new Exception("Adjustment not found");
    }

    $product_id = $adjustment['product_id'];
    $quantity = $adjustment['quantity'];
    $type = $adjustment['movement_type'];
    $warehouse_id = $adjustment['warehouse_id'];

    // Determine the effect on stock to reverse
    // If type was 'adjustment_in' or 'found', it added to stock. To reverse, we subtract.
    // If type was 'adjustment_out', 'damaged', 'theft', 'expired', it removed from stock. To reverse, we add.
    
    $reverse_quantity = 0;
    if (in_array($type, ['adjustment_in', 'found'])) {
        $reverse_quantity = -$quantity;
    } else {
        $reverse_quantity = $quantity;
    }

    // Update product stock
    // We need to update both the main `product_stocks` table (if using warehouses) and potentially `products` table if it stores total stock.
    // Assuming `product_stocks` is the main source of truth for warehouse-specific stock.

    // Check if stock entry exists
    $check_stock = $pdo->prepare("SELECT * FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
    $check_stock->execute([$product_id, $warehouse_id]);
    $stock_entry = $check_stock->fetch(PDO::FETCH_ASSOC);

    if ($stock_entry) {
        $update_stock = $pdo->prepare("UPDATE product_stocks SET stock_quantity = stock_quantity + ? WHERE product_id = ? AND warehouse_id = ?");
        $update_stock->execute([$reverse_quantity, $product_id, $warehouse_id]);
    } else {
        // If reversing adds stock and no entry exists, create one
        if ($reverse_quantity > 0) {
            $insert_stock = $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, ?)");
            $insert_stock->execute([$product_id, $warehouse_id, $reverse_quantity]);
        }
        // If reversing subtracts and no entry exists, we can't really subtract below zero but let's assume valid state.
    }

    // Delete the movement record
    $delete_stmt = $pdo->prepare("DELETE FROM stock_movements WHERE movement_id = ?");
    $delete_stmt->execute([$adjustment_id]);

    $pdo->commit();
    
    // Log activity
    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], "Deleted stock adjustment #$adjustment_id", "Reversed quantity: $reverse_quantity for product #$product_id");
    logAudit($pdo, $_SESSION['user_id'], "delete", [
        'entity_type' => 'stock_adjustment',
        'entity_id' => $adjustment_id,
        'old_values' => $adjustment,
        'description' => "Deleted stock adjustment #$adjustment_id (Product: $product_id, Warehouse: $warehouse_id)"
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Adjustment deleted and stock reversed successfully']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
