<?php
// scope-audit: skip — POS sale processing; project association via project dropdown at sale time
/**
 * API: Process POS Sale
 * Complete sale transaction with inventory update
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canCreate('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to process POS sales']);
    exit();
}

try {
    global $pdo;
    $pdo->beginTransaction();
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception("Invalid input data");
    }

    $user_id = $_SESSION['user_id'];
    $customer_id = $input['customer_id'] ?? null;
    $warehouse_id = $input['warehouse_id'] ?? null;
    $project_id = $input['project_id'] ?? null;
    $payment_method = $input['payment_method'] ?? 'cash';
    $amount_tendered = floatval($input['amount_tendered'] ?? 0);
    $items = $input['items'] ?? [];
    $subtotal = floatval($input['subtotal'] ?? 0);
    $discount_percentage = floatval($input['discount_percentage'] ?? 0);
    $discount_amount = floatval($input['discount_amount'] ?? 0);
    $tax = floatval($input['tax'] ?? 0);
    $total = floatval($input['total'] ?? 0);
    $receipt_number = $input['receipt_number'] ?? ('RCP-' . date('Ymd') . '-' . mt_rand(1000, 9999));
    $split_details = $input['split_details'] ?? null;
    
    if (empty($items)) {
        throw new Exception("No items in cart");
    }
    
    // Check for active shift
    $stmt = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$user_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    $shift_id = $shift['shift_id'] ?? null;
    
    // Insert sale
    $stmt = $pdo->prepare("
        INSERT INTO pos_sales (
            receipt_number, shift_id, user_id, customer_id, warehouse_id, project_id,
            subtotal, discount_percentage, discount_amount, tax_amount, grand_total,
            payment_method, amount_tendered, change_given,
            sale_status, payment_status, sale_date, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', 'paid', NOW(), NOW())
    ");
    
    $change = $input['change_given'] ?? ($amount_tendered - $total);
    
    $stmt->execute([
        $receipt_number,
        $shift_id,
        $user_id,
        $customer_id,
        $warehouse_id,
        $project_id,
        $subtotal,
        $discount_percentage,
        $discount_amount,
        $tax,
        $total,
        $payment_method,
        $amount_tendered,
        $change
    ]);
    
    $sale_id = $pdo->lastInsertId();
    
    // Insert sale items and update inventory
    // Recalculate totals server-side for security
    $calculated_subtotal = 0;
    $calculated_discount = 0;
    $calculated_tax = 0;
    $calculated_total = 0;
    
    // Build project stock subquery for validation
    $product_ids = array_column($items, 'product_id');
    $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
    
    $project_stock_subquery = "0";
    if ($project_id > 0) {
        $project_stock_subquery = "(SELECT COALESCE(SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END), 0) 
                                    FROM stock_movements sm 
                                    WHERE sm.product_id = p.product_id 
                                    AND sm.project_id = ? " . ($warehouse_id ? "AND sm.warehouse_id = ?" : "") . ")";
    }

    $fetchStmt = $pdo->prepare("
        SELECT p.product_id, p.product_name, p.selling_price, p.min_selling_price, p.is_service, 
               COALESCE(SUM(ps.stock_quantity - IFNULL(ps.reserved_quantity, 0)), 0) as general_available,
               $project_stock_subquery as project_available,
               COALESCE(SUM(IFNULL(ps.reserved_quantity, 0)), 0) as current_warehouse_reserved
        FROM products p
        LEFT JOIN product_stocks ps ON p.product_id = ps.product_id " . ($warehouse_id ? "AND ps.warehouse_id = ?" : "") . "
        WHERE p.product_id IN ($placeholders)
        GROUP BY p.product_id
    ");

    $fetchParams = [];
    if ($project_id > 0) {
        $fetchParams[] = $project_id;
        if ($warehouse_id) $fetchParams[] = $warehouse_id;
    }
    if ($warehouse_id) $fetchParams[] = $warehouse_id;
    foreach ($product_ids as $id) $fetchParams[] = $id;

    $fetchStmt->execute($fetchParams);
    $products_db = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);
    $products_map = array_column($products_db, null, 'product_id');
    
    // Insert sale items and update inventory
    $itemStmt = $pdo->prepare("
        INSERT INTO pos_sale_items (
            sale_id, product_id, product_name, quantity, unit_price, 
            tax_rate, tax_amount, discount_rate, discount_amount, line_total
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stockStmt = $pdo->prepare("
        UPDATE products 
        SET stock_quantity = stock_quantity - ?,
            current_stock = current_stock - ?
        WHERE product_id = ?
    ");
    
    foreach ($items as $item) {
        $pid = $item['product_id'];
        $db_product = $products_map[$pid] ?? null;
        
        if (!$db_product) {
            throw new Exception("Product ID $pid not found");
        }
        
        // Validate Price
        $requested_price = floatval($item['discounted_price'] ?? $item['price'] ?? 0);
        $min_price = floatval($db_product['min_selling_price']);
        
        // Allow a small epsilon for float comparison
        if ($requested_price < ($min_price - 0.01)) {
            throw new Exception("Price for '{$db_product['product_name']}' is below minimum selling price.");
        }
        
        // Calculate item values
        $qty = floatval($item['quantity']);

        // RESERVATION CHECK: Ensure we don't sell project-reserved stock (unless it's THIS project)
        if (!$db_product['is_service']) {
            $general_avail = floatval($db_product['general_available']);
            $project_avail = floatval($db_product['project_available'] ?? 0);
            $total_allowed = $general_avail + ($project_id > 0 ? $project_avail : 0);

            if ($qty > $total_allowed) {
                $msg = "Insufficient stock for '{$db_product['product_name']}'. ";
                if ($project_id > 0) {
                    $msg .= "Available for this project: $total_allowed (General: $general_avail, Project Reserved: $project_avail)";
                } else {
                    $msg .= "Available (excluding projects): $general_avail";
                }
                throw new Exception($msg);
            }
        }

        $original_price = floatval($item['price']); // Original selling price
        $tax_rate = floatval($item['tax_rate']);
        $discount_percent = floatval($item['discount_percent']);
        
        $item_original_total = $original_price * $qty;
        $item_discounted_total = $requested_price * $qty;
        
        $item_discount_amount = $item_original_total - $item_discounted_total;
        $item_tax_amount = $item_discounted_total * ($tax_rate / 100);
        
        // Update global sums
        $calculated_subtotal += $item_original_total;
        $calculated_discount += $item_discount_amount;
        $calculated_tax += $item_tax_amount;
        
        // Prepare DB record
        $itemStmt->execute([
            $sale_id,
            $pid,
            $db_product['product_name'], // Use DB name to be safe
            $qty,
            $original_price,
            $tax_rate,
            $item_tax_amount,
            $discount_percent,
            $item_discount_amount,
            $item_discounted_total // line_total (excluding tax usually, or including? let's assume excluding since tax is separate)
        ]);
        
        // Update stock (Only for non-service products)
        if (!$db_product['is_service']) {
            // 1. Global Update
            $stockStmt->execute([ $qty, $qty, $pid ]);

            // 2. Warehouse Update
            if ($warehouse_id) {
                 // Logic: If it's a project sale, we deduct from reserved_quantity first.
                 $reserved_deduction = 0;
                 if ($project_id > 0) {
                     $current_reserved = floatval($db_product['current_warehouse_reserved']);
                     $reserved_deduction = min($qty, $current_reserved);
                 }
                 
                 $psStmt = $pdo->prepare("
                    UPDATE product_stocks 
                    SET stock_quantity = IFNULL(stock_quantity, 0) - ?,
                        reserved_quantity = GREATEST(0, IFNULL(reserved_quantity, 0) - ?)
                    WHERE product_id = ? AND warehouse_id = ?
                 ");
                 $psStmt->execute([ $qty, $reserved_deduction, $pid, $warehouse_id ]);
            }

            // 3. Log Stock Movement
            $smStmt = $pdo->prepare("
                INSERT INTO stock_movements (
                    product_id, warehouse_id, project_id, movement_type, 
                    quantity, reference_id, reference_type, movement_date, created_by, notes
                ) VALUES (?, ?, ?, 'out', ?, ?, 'pos_sale', NOW(), ?, ?)
            ");
            $smStmt->execute([
                $pid, 
                $warehouse_id, 
                $project_id, 
                $qty, 
                $sale_id, 
                $_SESSION['user_id'], 
                "POS Sale #$receipt_number"
            ]);
        }
    }
    
    $calculated_total = ($calculated_subtotal - $calculated_discount) + $calculated_tax;
    
    // Update the main sale record with calculated values
    $updateSaleStmt = $pdo->prepare("
        UPDATE pos_sales 
        SET subtotal = ?, discount_amount = ?, tax_amount = ?, grand_total = ?
        WHERE sale_id = ?
    ");
    $updateSaleStmt->execute([
        $calculated_subtotal,
        $calculated_discount,
        $calculated_tax,
        $calculated_total,
        $sale_id
    ]);
    
    // Record cash transaction if shift exists
    if ($shift_id) {
        if ($payment_method === 'cash') {
            $pdo->prepare("
                INSERT INTO cash_register_transactions (
                    shift_id, transaction_type, amount, payment_method, reference_number, created_at
                ) VALUES (?, 'sale', ?, 'cash', ?, NOW())
            ")->execute([$shift_id, $total, $receipt_number]);
        } elseif ($payment_method === 'split' && isset($split_details['cash']) && $split_details['cash'] > 0) {
            $pdo->prepare("
                INSERT INTO cash_register_transactions (
                    shift_id, transaction_type, amount, payment_method, reference_number, created_at
                ) VALUES (?, 'sale', ?, 'cash', ?, NOW())
            ")->execute([$shift_id, $split_details['cash'], $receipt_number]);
        }
    }
    
    $pdo->commit();

    // Log the activity
    $username = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $user_id, 'Create POS Sale', "$username created POS Sale #$receipt_number (Total: " . number_format($calculated_total, 2) . ")");
    
    echo json_encode([
        'success' => true,
        'message' => 'Sale completed successfully',
        'sale_id' => $sale_id,
        'receipt_number' => $receipt_number
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
