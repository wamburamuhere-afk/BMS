<?php
// File: api/account/save_quotation.php
// Creates or updates a quotation in the dedicated `quotations` table.
// A quotation is the first document issued to a customer — it carries no PO
// reference and does not consume stock. A newly created quotation starts at
// status 'pending' (the entry point of the pending -> reviewed -> approved
// workflow). Editing a quotation never changes its workflow status.
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

$quotation_id = isset($_POST['quotation_id']) ? intval($_POST['quotation_id']) : 0;
$is_update = ($quotation_id > 0);

if ($is_update) {
    if (!canEdit('sales_orders')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to edit quotations']);
        exit;
    }
} else {
    if (!canCreate('sales_orders')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create quotations']);
        exit;
    }
}

try {
    global $pdo;
    $pdo->beginTransaction();

    $quotation_id     = isset($_POST['quotation_id']) ? intval($_POST['quotation_id']) : 0;
    $is_update        = ($quotation_id > 0);
    $customer_id      = $_POST['customer_id'] ?? 0;
    $order_date       = $_POST['order_date'] ?? '';
    $delivery_date    = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
    $salesperson_id   = $_POST['salesperson_id'] ?? $_SESSION['user_id'];
    $currency         = $_POST['currency'] ?? 'TZS';
    $payment_terms    = $_POST['payment_terms'] ?? '';
    $reference        = $_POST['reference'] ?? '';
    $notes            = $_POST['notes'] ?? '';
    $terms_conditions = $_POST['terms_conditions'] ?? '';
    $valid_until      = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
    $items            = json_decode($_POST['items'] ?? '[]', true);
    $project_id       = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $warehouse_id     = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null;

    if (empty($customer_id) || empty($order_date) || empty($items)) {
        throw new Exception("Missing required fields (Customer, Date, or Items)");
    }

    // An approved quotation is locked — it cannot be edited.
    if ($is_update) {
        $cur = $pdo->prepare("SELECT status FROM quotations WHERE sales_order_id = ?");
        $cur->execute([$quotation_id]);
        $curStatus = $cur->fetchColumn();
        if ($curStatus === false) {
            throw new Exception("Quotation not found");
        }
        if ($curStatus === 'approved') {
            throw new Exception("An approved quotation can no longer be edited.");
        }
    }

    // Calculate totals from the submitted items.
    $subtotal = 0; $tax_total = 0; $total_ordered = 0;
    foreach ($items as $item) {
        $qty              = floatval($item['quantity'] ?? 1);
        $price            = floatval($item['unit_price'] ?? 0);
        $tax_rate         = floatval($item['tax_rate'] ?? 0);
        $discount_percent = floatval($item['discount_percent'] ?? 0);

        $line_subtotal   = $qty * $price;
        $discount_amount = $line_subtotal * ($discount_percent / 100);
        $taxable_amount  = $line_subtotal - $discount_amount;
        $line_tax        = $taxable_amount * ($tax_rate / 100);

        $subtotal      += $line_subtotal;
        $tax_total     += $line_tax;
        $total_ordered += $qty;
    }

    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $discount_amount_total = array_reduce($items, function ($carry, $item) {
        $line_subtotal = floatval($item['quantity'] ?? 0) * floatval($item['unit_price'] ?? 0);
        return $carry + ($line_subtotal * (floatval($item['discount_percent'] ?? 0) / 100));
    }, 0);

    $grand_total = ($subtotal - $discount_amount_total) + $tax_total + $shipping_cost;

    if ($is_update) {
        // Editing never changes the workflow status.
        $stmt = $pdo->prepare("
            UPDATE quotations SET
                customer_id = ?, order_date = ?, delivery_date = ?, salesperson_id = ?,
                currency = ?, payment_terms = ?, reference = ?,
                subtotal = ?, tax_amount = ?, discount_amount = ?, shipping_cost = ?, grand_total = ?,
                total_ordered = ?, notes = ?, terms_conditions = ?, is_quote = 1,
                project_id = ?, warehouse_id = ?, quote_valid_until = ?,
                updated_at = NOW(), updated_by = ?
            WHERE sales_order_id = ?
        ");
        $stmt->execute([
            $customer_id, $order_date, $delivery_date, $salesperson_id,
            $currency, $payment_terms, $reference,
            $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
            $total_ordered, $notes, $terms_conditions,
            $project_id, $warehouse_id, $valid_until,
            $_SESSION['user_id'], $quotation_id,
        ]);

        $pdo->prepare("DELETE FROM quotation_items WHERE order_id = ?")->execute([$quotation_id]);
    } else {
        // A new quotation always enters the workflow at 'pending'.
        $max_id       = $pdo->query("SELECT MAX(sales_order_id) FROM quotations")->fetchColumn();
        $order_number = 'QT-' . date('Ymd') . '-' . str_pad(($max_id + 1), 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO quotations (
                order_number, customer_id, order_date, delivery_date, salesperson_id,
                currency, payment_terms, reference,
                subtotal, tax_amount, discount_amount, shipping_cost, grand_total,
                total_ordered, notes, terms_conditions, status, is_quote,
                project_id, warehouse_id, quote_valid_until, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $order_number, $customer_id, $order_date, $delivery_date, $salesperson_id,
            $currency, $payment_terms, $reference,
            $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
            $total_ordered, $notes, $terms_conditions,
            $project_id, $warehouse_id, $valid_until, $_SESSION['user_id'],
        ]);
        $quotation_id = $pdo->lastInsertId();
    }

    // Insert line items into quotation_items.
    $itemStmt = $pdo->prepare("
        INSERT INTO quotation_items (
            order_id, product_id, product_name, sku, quantity, unit,
            unit_price, tax_rate, discount_percent, line_total, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    foreach ($items as $item) {
        $qty              = floatval($item['quantity'] ?? 1);
        $price            = floatval($item['unit_price'] ?? 0);
        $tax_rate         = floatval($item['tax_rate'] ?? 0);
        $discount_percent = floatval($item['discount_percent'] ?? 0);

        $line_subtotal  = $qty * $price;
        $discount       = $line_subtotal * ($discount_percent / 100);
        $taxable        = $line_subtotal - $discount;
        $line_tax       = $taxable * ($tax_rate / 100);
        $line_total     = $taxable + $line_tax;

        $itemStmt->execute([
            $quotation_id, $item['product_id'] ?: null, $item['product_name'], $item['sku'] ?? '',
            $qty, $item['unit'] ?? 'pcs', $price, $tax_rate, $discount_percent, $line_total,
        ]);
    }

    $pdo->commit();

    $action      = $is_update ? 'Edit Quotation' : 'Create Quotation';
    $verb        = $is_update ? 'updated' : 'created';
    $log_number  = $order_number ?? '';
    if ($is_update && $log_number === '') {
        $s = $pdo->prepare("SELECT order_number FROM quotations WHERE sales_order_id = ?");
        $s->execute([$quotation_id]);
        $log_number = $s->fetchColumn();
    }
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], $action, "$user_name $verb Quotation #$log_number");

    echo json_encode([
        'success'      => true,
        'message'      => 'Quotation saved successfully',
        'quotation_id' => $quotation_id,
        'order_number' => $log_number,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving quotation: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
