<?php
// File: api/create_stock_adjustment.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/stock_posting.php';

// Suppress errors to ensure only clean JSON is returned
error_reporting(0);
ini_set('display_errors', 0);

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Validate required parameters
$required = ['product_id', 'warehouse_id', 'quantity', 'movement_type'];
foreach ($required as $field) {
    if (!isset($_POST[$field]) || $_POST[$field] === '') {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Check permission using system standard functions
if (!isAdmin() && !canEdit('products')) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Phase D — project-scope gate on the product being adjusted
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('products', 'product_id', (int)$_POST['product_id']);
}

try {
    $pdo->beginTransaction();

    // Get product details
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([$_POST['product_id']]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        throw new Exception('Product not found');
    }
    
    // Get current stock
    $stmt = $pdo->prepare("
        SELECT COALESCE(stock_quantity, 0) as stock_quantity,
               COALESCE(reserved_quantity, 0) as reserved_quantity
        FROM product_stocks 
        WHERE product_id = ? AND warehouse_id = ?
    ");
    $stmt->execute([$_POST['product_id'], $_POST['warehouse_id']]);
    $stock_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $current_stock = $stock_data ? $stock_data['stock_quantity'] : 0;
    $reserved_stock = $stock_data ? $stock_data['reserved_quantity'] : 0;
    $available_stock = $current_stock - $reserved_stock;
    
    // Calculate new stock
    $quantity = floatval($_POST['quantity']);
    $movement_type = $_POST['movement_type'];
    
    if ($movement_type === 'set') {
        $new_stock = $quantity;
        // Adjust quantity for the movement record (how much we actually added/removed)
        $quantity = $new_stock - $current_stock;
    } elseif (in_array($movement_type, ['adjustment_in', 'found'])) {
        $new_stock = $current_stock + $quantity;
    } else {
        $new_stock = $current_stock - $quantity;
    }
    
    // Unit cost - use provided or product cost
    $unit_cost = floatval($_POST['unit_cost']) > 0 ? floatval($_POST['unit_cost']) : floatval($product['cost_price']);
    
    // Use the supplied reference, else a company-prefixed sequential one (BFS-ADJ-0001).
    require_once __DIR__ . '/../core/code_generator.php';
    $reference_number = !empty($_POST['reference_number']) ? $_POST['reference_number'] : nextCode($pdo, 'ADJ');
    
    // Insert stock movement
    $stmt = $pdo->prepare("
        INSERT INTO stock_movements (
            product_id, movement_type, quantity, unit, unit_cost, total_cost,
            reference_type, reference_number, warehouse_id, project_id,
            stock_before, stock_after, reason, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, 'manual', ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $_POST['product_id'],
        $movement_type,
        $quantity,
        $product['unit'],
        $unit_cost,
        abs($quantity) * (float)$unit_cost,
        $reference_number,
        $_POST['warehouse_id'],
        !empty($_POST['project_id']) ? $_POST['project_id'] : null,
        $current_stock,
        $new_stock,
        $_POST['reason'],
        $_POST['notes'] ?? '',
        $user_id
    ]);
    $movement_id = (int)$pdo->lastInsertId();

    // Update product_stocks
    $stmt = $pdo->prepare("
        INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity)
    ");
    
    $stmt->execute([
        $_POST['product_id'],
        $_POST['warehouse_id'],
        $new_stock,
        $reserved_stock
    ]);
    
    // Synchronize cumulative stock in products table
    $stmt = $pdo->prepare("
        UPDATE products p
        SET 
            p.stock_quantity = (SELECT COALESCE(SUM(stock_quantity), 0) FROM product_stocks WHERE product_id = p.product_id),
            p.current_stock = (SELECT COALESCE(SUM(stock_quantity), 0) FROM product_stocks WHERE product_id = p.product_id)
        WHERE p.product_id = ?
    ");
    $stmt->execute([$_POST['product_id']]);
    
    // GL posting (6-part: two-sided, on create, inventory + equity, Balance Sheet)
    $project_id_gl = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    postStockAdjustmentGl($pdo, $movement_id, $quantity, $movement_type, $unit_cost,
        $project_id_gl, $user_id, date('Y-m-d'), $reference_number);

    $pdo->commit();

    // Log activity
    require_once HELPERS_FILE;
    $action = "Stock Adjustment (" . ucfirst(str_replace('_', ' ', $movement_type)) . ")";
    $description = "Adjusted stock for product: " . $product['product_name'] . " (Qty: $quantity, Ref: $reference_number)";
    logActivity($pdo, $user_id, $action, $description);
    
    // Log for Audit report
    logAudit($pdo, $user_id, "create", [
        'activity_type' => 'create',
        'entity_type' => 'stock_adjustment',
        'entity_id' => $pdo->lastInsertId(),
        'description' => $description,
        'new_values' => [
            'product_id' => $_POST['product_id'],
            'warehouse_id' => $_POST['warehouse_id'],
            'quantity' => $quantity,
            'reference' => $reference_number,
            'type' => $movement_type
        ]
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Stock adjustment recorded successfully',
        'reference_number' => $reference_number
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>