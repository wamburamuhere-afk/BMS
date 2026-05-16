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
$purchase_order_id = isset($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : 0;
$is_update = ($purchase_order_id > 0);

if ($is_update) {
    if (!canEdit('purchase_orders')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to edit purchase orders']);
        exit;
    }
} else {
    if (!canCreate('purchase_orders')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create purchase orders']);
        exit;
    }
}

try {
    global $pdo;
    $pdo->beginTransaction();

    $purchase_order_id = isset($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : 0;
    $supplier_id = $_POST['supplier_id'] ?? 0;
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $order_date = $_POST['order_date'] ?? '';
    $expected_date = $_POST['expected_delivery_date'] ?? null;
    $warehouse_id = $_POST['warehouse_id'] ?? 0;
    $currency = $_POST['currency'] ?? 'TZS';
    $payment_terms = $_POST['payment_terms'] ?? '';
    $shipping_address = $_POST['shipping_address'] ?? '';
    $shipping_method = $_POST['shipping_method_id'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $terms_conditions = $_POST['terms_conditions'] ?? '';
    $rfq_id = !empty($_POST['rfq_reference']) ? intval($_POST['rfq_reference']) : null;
    $proforma_invoice_ref = $_POST['proforma_invoice_ref'] ?? '';
    $status = $_POST['status'] ?? 'pending';
    if (empty($status)) $status = 'pending';
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);

    if (empty($supplier_id) || empty($order_date) || empty($items)) {
        throw new Exception("Missing required fields (Supplier, Date, or Items)");
    }

    // Calculate totals based on actual items
    $subtotal = 0;
    $tax_total = 0;
    
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $price = floatval($item['unit_price'] ?? 0);
        $tax_rate_percentage = 0;
        
        if (!empty($item['tax_rate_id'])) {
            $tax_stmt = $pdo->prepare("SELECT rate_percentage FROM tax_rates WHERE rate_id = ?");
            $tax_stmt->execute([$item['tax_rate_id']]);
            $tax_rate_percentage = floatval($tax_stmt->fetchColumn());
        }
        
        $line_subtotal = $qty * $price;
        $line_tax = $line_subtotal * ($tax_rate_percentage / 100);
        
        $subtotal += $line_subtotal;
        $tax_total += $line_tax;
    }
    
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $grand_total = $subtotal + $tax_total + $shipping_cost;

    if ($purchase_order_id > 0) {
        // Update existing
        $stmt = $pdo->prepare("
            UPDATE purchase_orders SET
                supplier_id = ?, project_id = ?, warehouse_id = ?, order_date = ?, expected_date = ?,
                total_amount = ?, tax_amount = ?, grand_total = ?, shipping_cost = ?,
                currency = ?, payment_terms = ?, shipping_method = ?, notes = ?,
                terms_conditions = ?, rfq_id = ?, proforma_invoice_ref = ?,
                status = ?, updated_at = NOW()
            WHERE purchase_order_id = ?
        ");
        $stmt->execute([
            $supplier_id, $project_id, $warehouse_id, $order_date, $expected_date,
            $subtotal, $tax_total, $grand_total, $shipping_cost,
            $currency, $payment_terms, $shipping_method, $notes,
            $terms_conditions, $rfq_id, $proforma_invoice_ref,
            $status, $purchase_order_id
        ]);
        
        // Clear existing items
        $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$purchase_order_id]);
        
    } else {
        // Generate order number
        $stmt = $pdo->query("SELECT MAX(purchase_order_id) FROM purchase_orders");
        $max_id = $stmt->fetchColumn();
        $order_number = 'PO-' . date('Ymd') . '-' . str_pad(($max_id + 1), 4, '0', STR_PAD_LEFT);

        // Snapshot creator info
        $creator_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        if (empty($creator_name)) $creator_name = $_SESSION['username'] ?? 'System';
        $creator_role = $_SESSION['user_role'] ?? 'Staff';

        $stmt = $pdo->prepare("
            INSERT INTO purchase_orders (
                order_number, supplier_id, project_id, warehouse_id, order_date, expected_date,
                total_amount, tax_amount, grand_total, shipping_cost,
                currency, payment_terms, shipping_method, notes,
                terms_conditions, rfq_id, proforma_invoice_ref,
                status, created_by, prepared_by_name, prepared_by_role, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $order_number, $supplier_id, $project_id, $warehouse_id, $order_date, $expected_date,
            $subtotal, $tax_total, $grand_total, $shipping_cost,
            $currency, $payment_terms, $shipping_method, $notes,
            $terms_conditions, $rfq_id, $proforma_invoice_ref,
            $status, $_SESSION['user_id'], $creator_name, $creator_role
        ]);
        $purchase_order_id = $pdo->lastInsertId();
    }

    // Insert Items
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $price = floatval($item['unit_price'] ?? 0);
        
        // Get product details if not provided
        $product_name = $item['product_name'] ?? '';
        if (empty($product_name) && !empty($item['product_id'])) {
            $p_stmt = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?");
            $p_stmt->execute([$item['product_id']]);
            $product_name = $p_stmt->fetchColumn();
        }

        $tax_rate_percentage = 0;
        if (!empty($item['tax_rate_id'])) {
            $tax_stmt = $pdo->prepare("SELECT rate_percentage FROM tax_rates WHERE rate_id = ?");
            $tax_stmt->execute([$item['tax_rate_id']]);
            $tax_rate_percentage = floatval($tax_stmt->fetchColumn());
        }

        $line_subtotal = $qty * $price;
        $line_tax = $line_subtotal * ($tax_rate_percentage / 100);
        $line_total = $line_subtotal + $line_tax;

        $itemStmt = $pdo->prepare("
            INSERT INTO purchase_order_items (
                purchase_order_id, product_id, item_name, quantity, 
                unit_price, tax_rate, tax_amount, line_total, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $itemStmt->execute([
            $purchase_order_id, $item['product_id'] ?: null, $product_name,
            $qty, $price, $tax_rate_percentage, $line_tax, $line_total
        ]);
    }

    // RFQ Status Auto-Update Logic
    if ($rfq_id > 0) {
        $is_fully_consumed = true;
        $has_any_consumption = false;

        // Fetch RFQ items to compare with PO consumption
        $rfq_items_stmt = $pdo->prepare("SELECT product_id, description as item_name, qty FROM rfq_items WHERE rfq_id = ?");
        $rfq_items_stmt->execute([$rfq_id]);
        $rfq_items = $rfq_items_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rfq_items as $ri) {
            // Sum consumed for this specific item across all POs linked to this RFQ
            if ($ri['product_id']) {
                $c_stmt = $pdo->prepare("
                    SELECT SUM(poi.quantity) 
                    FROM purchase_order_items poi
                    JOIN purchase_orders po ON poi.purchase_order_id = po.purchase_order_id
                    WHERE po.rfq_id = ? AND poi.product_id = ? AND po.status != 'cancelled'
                ");
                $c_stmt->execute([$rfq_id, $ri['product_id']]);
            } else {
                $c_stmt = $pdo->prepare("
                    SELECT SUM(poi.quantity) 
                    FROM purchase_order_items poi
                    JOIN purchase_orders po ON poi.purchase_order_id = po.purchase_order_id
                    WHERE po.rfq_id = ? AND poi.item_name COLLATE utf8mb4_general_ci = ? AND po.status != 'cancelled'
                ");
                $c_stmt->execute([$rfq_id, $ri['item_name']]);
            }
            $consumed = floatval($c_stmt->fetchColumn());
            
            if ($consumed < $ri['qty']) {
                $is_fully_consumed = false;
            }
            if ($consumed > 0) {
                $has_any_consumption = true;
            }
        }

        // Determine new status based on consumption levels
        $new_rfq_status = 'approved';
        if ($is_fully_consumed && count($rfq_items) > 0) {
            $new_rfq_status = 'completed';
        } elseif ($has_any_consumption) {
            $new_rfq_status = 'partially';
        }

        $pdo->prepare("UPDATE rfq SET status = ? WHERE rfq_id = ?")->execute([$new_rfq_status, $rfq_id]);
    }

    // Handle Attachments
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $upload_dir = __DIR__ . '/../../uploads/purchase_orders/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $attachment_names = $_POST['attachment_names'] ?? [];

        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                $original_name = $_FILES['attachments']['name'][$i];
                $extension = pathinfo($original_name, PATHINFO_EXTENSION);
                $file_name = 'PO_' . $purchase_order_id . '_' . time() . '_' . $i . '.' . $extension;
                $file_path = 'uploads/purchase_orders/' . $file_name;
                $dest_path = $upload_dir . $file_name;

                if (!@move_uploaded_file($tmp_name, $dest_path)) {
                    throw new Exception("Failed to save attachment \"{$original_name}\". The uploads directory may not be writable on the server.");
                }

                $doc_name = !empty($attachment_names[$i]) ? $attachment_names[$i] : $original_name;

                $attStmt = $pdo->prepare("
                    INSERT INTO purchase_order_attachments (
                        purchase_order_id, file_name, file_path, file_type, file_size,
                        uploaded_by, uploaded_at, description
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                $attStmt->execute([
                    $purchase_order_id, $doc_name, $file_path,
                    $_FILES['attachments']['type'][$i], $_FILES['attachments']['size'][$i],
                    $_SESSION['user_id'], $doc_name
                ]);
            }
        }
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => 'Purchase Order saved successfully', 
        'purchase_order_id' => $purchase_order_id,
        'order_number' => $order_number ?? ''
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving purchase order: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
