<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('stock_adjustments')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit stock adjustments']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$movement_id = intval($_POST['movement_id'] ?? 0);
$product_id = intval($_POST['product_id'] ?? 0);
$warehouse_id = intval($_POST['warehouse_id'] ?? 0);
$project_id = intval($_POST['project_id'] ?? 0);
$movement_type = $_POST['movement_type'] ?? '';
$quantity = floatval($_POST['quantity'] ?? 0);
$unit_cost = floatval($_POST['unit_cost'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$movement_id || !$product_id || !$warehouse_id || !$movement_type || $quantity <= 0 || empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Phase E — project-scope gate on the product being adjusted
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('products', 'product_id', $product_id);
}

try {
    $pdo->beginTransaction();

    // Get old adjustment to reverse previous stock change
    $stmt = $pdo->prepare("SELECT * FROM stock_movements WHERE movement_id = ?");
    $stmt->execute([$movement_id]);
    $old_adj = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old_adj) {
        throw new Exception("Adjustment not found");
    }

    // 1. Reverse the old stock movement
    // If it was IN/FOUND, we now SUBTRACT. If it was OUT, we ADD properly.
    // However, the simplest way is to fetch current stock, REVERSE the old logic, then APPLY new logic.
    
    // Actually, stock_movements table is a log. Editing an entry is tricky because it affects running balance.
    // BUT, for simple current stock updates:
    // Update stock = Current - Old_Change + New_Change
    
    // Get product current stock in warehouse from product_stocks
    $stmt = $pdo->prepare("SELECT stock_quantity as quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
    $stmt->execute([$old_adj['product_id'], $old_adj['warehouse_id']]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_qty = $inv ? $inv['quantity'] : 0;

    // Calculate old change
    $old_change = 0;
    if (in_array($old_adj['movement_type'], ['adjustment_in', 'found'])) {
        $old_change = $old_adj['quantity'];
    } else {
        $old_change = -$old_adj['quantity'];
    }

    // Calculate new change
    $new_change = 0;
    if (in_array($movement_type, ['adjustment_in', 'found'])) {
        $new_change = $quantity;
    } else {
        $new_change = -$quantity;
    }

    // Revert old, apply new
    // We must handle warehouse change too!
    if ($old_adj['warehouse_id'] != $warehouse_id || $old_adj['product_id'] != $product_id) {
        throw new Exception("Changing product or warehouse is not supported directly. Please delete and recreate.");
    }

    $final_stock = $current_qty - $old_change + $new_change;

    if ($final_stock < 0) {
        throw new Exception("Adjustment would result in negative stock.");
    }

    // Update product_stocks
    $stmt = $pdo->prepare("UPDATE product_stocks SET stock_quantity = ? WHERE product_id = ? AND warehouse_id = ?");
    $stmt->execute([$final_stock, $product_id, $warehouse_id]);
    
    // Also update cumulative stock in products table
    $stmt = $pdo->prepare("UPDATE products SET stock_quantity = (SELECT SUM(stock_quantity) FROM product_stocks WHERE product_id = ?), current_stock = (SELECT SUM(stock_quantity) FROM product_stocks WHERE product_id = ?) WHERE product_id = ?");
    $stmt->execute([$product_id, $product_id, $product_id]);

    // Update movement record
    $stmt = $pdo->prepare("
        UPDATE stock_movements SET 
            movement_type = ?,
            quantity = ?,
            unit_cost = ?,
            reason = ?,
            notes = ?,
            project_id = ?,
            stock_before = ?,
            stock_after = ?
        WHERE movement_id = ?
    ");
    
    // Recalculate stock_before/after for this specific record is complex if we want historical accuracy,
    // but typically "stock_after" means "after this transaction at the time of transaction".
    // For simplicity in a basic edit, we update based on current state or keep relative.
    // Let's rely on the simple logic: stock_before = current - old_change (revert) -> wait, this is confusing if other tx happened.
    // Let's just update the record details for now and the inventory balance.
    // Ideally, stock_before should be what it was *before this specific tx*, which hasn't changed unless we want to rewrite history.
    // Let's update stock_after to reflect the NEW impact relative to the original stock_before?
    // Or better: stock_after = stock_before + new_change
    
    $new_stock_after = $old_adj['stock_before'] + $new_change;

    $stmt->execute([
        $movement_type,
        $quantity,
        $unit_cost,
        $reason,
        $notes,
        $project_id ?: null,
        $old_adj['stock_before'], // Keep original before state?
        $new_stock_after,
        $movement_id
    ]);

    // Log action
    logAudit($pdo, $_SESSION['user_id'], 'update_adjustment', ['description' => "Updated adjustment #$movement_id"]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Adjustment updated successfully']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
