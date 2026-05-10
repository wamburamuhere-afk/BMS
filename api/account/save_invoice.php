<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);

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
$invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
$is_update = ($invoice_id > 0);

if ($is_update) {
    if (!canEdit('invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to edit invoices']);
        exit;
    }
} else {
    if (!canCreate('invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create invoices']);
        exit;
    }
}

try {
    global $pdo;
    $pdo->beginTransaction();

    $customer_id = $_POST['customer_id'] ?? 0;
    $invoice_date = $_POST['invoice_date'] ?? '';
    $due_date = $_POST['due_date'] ?? '';
    $currency = $_POST['currency'] ?? 'TZS';
    $notes = $_POST['notes'] ?? '';
    $terms = $_POST['terms_conditions'] ?? '';
    $discount = $_POST['discount_amount'] ?? 0;
    $shipping = $_POST['shipping_cost'] ?? 0;
    $status = $_POST['status'] ?? 'pending';
    $order_id = $_POST['order_id'] ?? null;
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $items = $_POST['items'] ?? [];

    if (empty($customer_id) || empty($invoice_date) || empty($items)) {
        throw new Exception("Missing required fields");
    }

    // Calculate totals
    $subtotal = 0;
    $tax_total = 0;
    
    // Validate items and calculate
    foreach ($items as $item) {
        $qty = floatval($item['quantity']);
        $price = floatval($item['unit_price']);
        $tax_rate = floatval($item['tax_rate'] ?? 0);
        
        $line_subtotal = $qty * $price;
        $line_tax = $line_subtotal * ($tax_rate / 100);
        
        $subtotal += $line_subtotal;
        $tax_total += $line_tax;
    }
    
    $grand_total = $subtotal + $tax_total - $discount + $shipping;

    if ($invoice_id > 0) {
        // Update existing
        $stmt = $pdo->prepare("
            UPDATE invoices SET 
                customer_id = ?, order_id = ?, project_id = ?, invoice_date = ?, due_date = ?,
                subtotal = ?, tax_amount = ?, discount_amount = ?, shipping_cost = ?, grand_total = ?,
                currency = ?, notes = ?, terms_conditions = ?, status = ?, updated_by = ?, updated_at = NOW()
            WHERE invoice_id = ?
        ");
        $stmt->execute([
            $customer_id, $order_id ?: null, $project_id, $invoice_date, $due_date,
            $subtotal, $tax_total, $discount, $shipping, $grand_total,
            $currency, $notes, $terms, $status, $_SESSION['user_id'], $invoice_id
        ]);
        
        // Clear existing items to re-insert (simplest approach for now)
        $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoice_id]);
        
    } else {
        // Create new
        $invoice_number = $_POST['invoice_number'] ?? ('INV-' . time()); // Fallback
        
        // Verify unique invoice number
        $stmt = $pdo->prepare("SELECT count(*) FROM invoices WHERE invoice_number = ?");
        $stmt->execute([$invoice_number]);
        if ($stmt->fetchColumn() > 0) {
            $invoice_number = 'INV-' . date('Ymd') . '-' . mt_rand(1000, 9999);
        }

        $stmt = $pdo->prepare("
            INSERT INTO invoices (
                invoice_number, customer_id, order_id, project_id, invoice_date, due_date,
                subtotal, tax_amount, discount_amount, shipping_cost, grand_total,
                paid_amount, balance_due,
                currency, notes, terms_conditions, status, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $invoice_number, $customer_id, $order_id ?: null, $project_id, $invoice_date, $due_date,
            $subtotal, $tax_total, $discount, $shipping, $grand_total,
            $grand_total,
            $currency, $notes, $terms, $status, $_SESSION['user_id']
        ]);
        $invoice_id = $pdo->lastInsertId();
    }

    // Insert Items - NOTE: column name must match table `invoice_items` (line_total)
    $itemStmt = $pdo->prepare("
        INSERT INTO invoice_items (
            invoice_id, order_item_id, product_id, product_name, description,
            quantity, unit, unit_price, tax_rate, tax_amount, line_total
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($items as $item) {
        $qty = floatval($item['quantity']);
        $price = floatval($item['unit_price']);
        $tax_rate = floatval($item['tax_rate'] ?? 0);
        $line_subtotal = $qty * $price;
        $line_tax = $line_subtotal * ($tax_rate / 100);
        $line_total = $line_subtotal + $line_tax;

        $itemStmt->execute([
            $invoice_id,
            $item['order_item_id'] ?: null,
            $item['product_id'] ?: null,
            $item['product_name'],
            $item['description'] ?? '',
            $qty,
            $item['unit'] ?? 'pcs',
            $price,
            $tax_rate,
            $line_tax,
            $line_total
        ]);
    }
    
    // Update Sales Order Status if linked
    if ($order_id) {
         // Logic to update sales order status/invoiced amount could go here
    }

    $pdo->commit();

    // Log activity
    require_once __DIR__ . '/../../helpers.php';
    $action_verb = $is_update ? "Updated" : "Created";
    $invoice_num = $invoice_number ?? ($_POST['invoice_number'] ?? "ID: $invoice_id");
    logActivity($pdo, $_SESSION['user_id'], "$action_verb Invoice: $invoice_num (Amount: " . number_format($grand_total, 2) . ")");

    echo json_encode(['success' => true, 'message' => 'Invoice saved successfully', 'invoice_id' => $invoice_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
