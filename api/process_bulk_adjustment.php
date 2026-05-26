<?php
// scope-audit: skip — bulk stock adjustment; project-specific adjustment scope deferred to Phase G-2
/**
 * API: Process Bulk Stock Adjustment
 * Processes CSV file and performs bulk stock adjustments.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

// Suppress errors to ensure only clean JSON is returned
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canCreate('stock_adjustments')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to process bulk stock adjustments']);
    exit();
}

$user_id = $_SESSION['user_id'];
$default_type = $_POST['default_type'] ?? 'adjustment_in';
$default_reason = $_POST['default_reason'] ?? 'Bulk adjustment';
$default_warehouse = intval($_POST['default_warehouse'] ?? 0);

try {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload failed");
    }

    $file = $_FILES['file']['tmp_name'];
    $handle = fopen($file, 'r');
    
    // Skip BOM if present
    $bom = fread($handle, 3);
    if ($bom != "\xEF\xBB\xBF") {
        rewind($handle);
    }
    
    $headers = fgetcsv($handle);
    if (!$headers) {
        throw new Exception("Empty or invalid CSV file");
    }
    
    // Normalize headers
    $headers = array_map('trim', array_map('strtolower', $headers));
    
    $processed = 0;
    $success_count = 0;
    $failed_count = 0;
    $errors = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (empty(array_filter($row))) continue; // Skip empty rows
        
        $processed++;
        $data = array_combine($headers, $row);
        
        $sku = trim($data['sku'] ?? '');
        $quantity = floatval($data['quantity'] ?? 0);
        $movement_type = trim($data['movement_type'] ?? $default_type);
        $reason = trim($data['reason'] ?? $default_reason);
        $warehouse_id = intval($data['warehouse_id'] ?? $default_warehouse);
        $unit_cost_input = isset($data['unit_cost']) ? floatval($data['unit_cost']) : 0;
        $notes = trim($data['notes'] ?? '');

        if (empty($sku)) {
            $failed_count++;
            $errors[] = "Row $processed: Missing SKU";
            continue;
        }

        if ($warehouse_id <= 0) {
            $failed_count++;
            $errors[] = "Row $processed: Invalid Warehouse ID for SKU $sku";
            continue;
        }

        try {
            $pdo->beginTransaction();

            // Get product details
            $stmt = $pdo->prepare("SELECT product_id, product_name, unit, cost_price FROM products WHERE sku = ? OR barcode = ?");
            $stmt->execute([$sku, $sku]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception("Product not found for SKU: $sku");
            }

            $product_id = $product['product_id'];

            // Get current stock
            $stmt = $pdo->prepare("
                SELECT COALESCE(stock_quantity, 0) as stock_quantity,
                       COALESCE(reserved_quantity, 0) as reserved_quantity
                FROM product_stocks 
                WHERE product_id = ? AND warehouse_id = ?
            ");
            $stmt->execute([$product_id, $warehouse_id]);
            $stock_data = $stmt->fetch(PDO::FETCH_ASSOC);

            $current_stock = $stock_data ? $stock_data['stock_quantity'] : 0;
            $reserved_stock = $stock_data ? $stock_data['reserved_quantity'] : 0;

            // Calculate new stock
            if (in_array($movement_type, ['adjustment_in', 'found'])) {
                $new_stock = $current_stock + $quantity;
                $adj_quantity = $quantity;
            } else {
                $new_stock = $current_stock - $quantity;
                $adj_quantity = -$quantity;
            }

            $unit_cost = $unit_cost_input > 0 ? $unit_cost_input : floatval($product['cost_price']);
            $reference_number = 'BULK-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

            // Insert stock movement
            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (
                    product_id, movement_type, quantity, unit, unit_cost,
                    reference_type, reference_number, warehouse_id,
                    stock_before, stock_after, reason, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, 'manual', ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $product_id, $movement_type, abs($quantity), $product['unit'], $unit_cost,
                $reference_number, $warehouse_id, $current_stock, $new_stock,
                $reason, $notes, $user_id
            ]);

            // Update product_stocks
            $stmt = $pdo->prepare("
                INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity)
            ");
            $stmt->execute([$product_id, $warehouse_id, $new_stock, $reserved_stock]);

            // Sync aggregate products table
            $stmt = $pdo->prepare("
                UPDATE products p
                SET p.stock_quantity = (SELECT COALESCE(SUM(stock_quantity), 0) FROM product_stocks WHERE product_id = p.product_id),
                    p.current_stock = (SELECT COALESCE(SUM(stock_quantity), 0) FROM product_stocks WHERE product_id = p.product_id)
                WHERE p.product_id = ?
            ");
            $stmt->execute([$product_id]);

            $pdo->commit();
            $success_count++;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $failed_count++;
            $errors[] = "Row $processed ($sku): " . $e->getMessage();
        }
    }

    fclose($handle);

    // Activity Log
    if ($success_count > 0) {
        logActivity($pdo, $user_id, "Bulk Stock Adjustment", "Processed $processed rows, $success_count successful, $failed_count failed");
    }

    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'success_count' => $success_count,
        'failed_count' => $failed_count,
        'errors' => $errors,
        'message' => "Bulk processing finished."
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
