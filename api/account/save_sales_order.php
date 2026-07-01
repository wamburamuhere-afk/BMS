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
$sales_order_id = isset($_POST['sales_order_id']) ? intval($_POST['sales_order_id']) : 0;
$is_update = ($sales_order_id > 0);

if ($is_update) {
    if (!canEdit('sales_orders')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to edit sales orders']);
        exit;
    }
    assertScopeForRecord('sales_orders', 'sales_order_id', $sales_order_id);
} else {
    if (!canCreate('sales_orders')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create sales orders']);
        exit;
    }
}

try {
    global $pdo;
    $pdo->beginTransaction();

    $sales_order_id = isset($_POST['sales_order_id']) ? intval($_POST['sales_order_id']) : 0;
    $is_update = ($sales_order_id > 0);
    $customer_id = $_POST['customer_id'] ?? 0;
    $order_date = $_POST['order_date'] ?? '';
    $delivery_date = $_POST['delivery_date'] ?? null;
    $salesperson_id = $_POST['salesperson_id'] ?? $_SESSION['user_id'];
    $currency = $_POST['currency'] ?? 'TZS';
    $payment_terms = $_POST['payment_terms'] ?? '';
    $reference = $_POST['reference'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $terms_conditions = $_POST['terms_conditions'] ?? '';
    $is_quote = isset($_POST['is_quote']) && $_POST['is_quote'] == '1' ? 1 : 0;
    // Three-approval rule: every newly created sales order starts at 'pending'.
    // On update, the existing row's status is preserved unless the caller
    // explicitly sends 'cancelled' (legitimate workflow exit at any point).
    // The Review/Approve transitions are handled by their dedicated APIs.
    if ($is_update) {
        $posted = $_POST['status'] ?? '';
        if ($posted === 'cancelled') {
            $status = 'cancelled';
        } else {
            $existing = $pdo->prepare("SELECT status FROM sales_orders WHERE sales_order_id = ?");
            $existing->execute([$sales_order_id]);
            $status = $existing->fetchColumn() ?: 'pending';
        }
    } else {
        $status = 'pending';
    }
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null;
    $po_no = isset($_POST['po_no']) ? trim((string)$_POST['po_no']) : null;

    if (empty($customer_id) || empty($order_date) || empty($items)) {
        throw new Exception("Missing required fields (Customer, Date, or Items)");
    }

    // ---------------------------------------------------------------------
    // STOCK VALIDATION (Warehouse-based)
    // ---------------------------------------------------------------------
    // If a warehouse is selected, prevent saving quantities above available stock.
    // Aggregate item quantities by product_id to avoid bypass via duplicate rows.
    if (!empty($warehouse_id)) {
        $requested = [];
        foreach ($items as $it) {
            $pid = isset($it['product_id']) ? (int)$it['product_id'] : 0;
            if ($pid <= 0) continue; // manual/service items may not have product_id
            $qty = (float)($it['quantity'] ?? 0);
            if ($qty <= 0) continue;
            $requested[$pid] = ($requested[$pid] ?? 0) + $qty;
        }

        if ($requested) {
            $ids = array_keys($requested);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$warehouse_id], $ids);

            $stmtStock = $pdo->prepare("
                SELECT
                    p.product_id,
                    p.product_name,
                    p.sku,
                    COALESCE(ps.stock_quantity, 0) AS stock_quantity
                FROM products p
                LEFT JOIN product_stocks ps
                    ON ps.product_id = p.product_id
                   AND ps.warehouse_id = ?
                WHERE p.product_id IN ($placeholders)
            ");
            $stmtStock->execute($params);
            $rows = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

            $stockMap = [];
            foreach ($rows as $r) {
                $stockMap[(int)$r['product_id']] = $r;
            }

            $shortages = [];
            foreach ($requested as $pid => $qtyNeeded) {
                $available = isset($stockMap[$pid]) ? (float)$stockMap[$pid]['stock_quantity'] : 0.0;
                if ($available + 1e-9 < $qtyNeeded) {
                    $name = $stockMap[$pid]['product_name'] ?? ('Product #' . $pid);
                    $shortages[] = $name . " (available: " . $available . ", requested: " . $qtyNeeded . ")";
                }
            }

            if ($shortages) {
                throw new Exception("Insufficient stock for one or more items:\n- " . implode("\n- ", $shortages));
            }
        }
    }

    // Calculate totals
    $subtotal = 0;
    $tax_total = 0;
    $total_ordered = 0;
    
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $price = floatval($item['unit_price'] ?? 0);
        $tax_rate = floatval($item['tax_rate'] ?? 0);
        $discount_percent = floatval($item['discount_percent'] ?? 0);
        
        $line_subtotal = $qty * $price;
        $discount_amount = $line_subtotal * ($discount_percent / 100);
        $taxable_amount = $line_subtotal - $discount_amount;
        $line_tax = $taxable_amount * ($tax_rate / 100);
        
        $subtotal += $line_subtotal; // Subtotal before discount
        $tax_total += $line_tax;
        $total_ordered += $qty;
    }
    
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $discount_amount_total = array_reduce($items, function($carry, $item) {
        $line_subtotal = floatval($item['quantity'] ?? 0) * floatval($item['unit_price'] ?? 0);
        return $carry + ($line_subtotal * (floatval($item['discount_percent'] ?? 0) / 100));
    }, 0);
    
    $grand_total = ($subtotal - $discount_amount_total) + $tax_total + $shipping_cost;

    // Optional column support: po_no (some installs may not have it yet)
    $hasPoNoColumn = false;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'po_no'");
        $hasPoNoColumn = (bool)$colStmt->fetch();
    } catch (Exception $e) {
        $hasPoNoColumn = false;
    }

    if ($sales_order_id > 0) {
        // Re-code a legacy SO/QT number on edit (sales orders don't post to the GL).
        require_once __DIR__ . '/../../core/code_generator.php';
        $curSo = $pdo->prepare("SELECT order_number FROM sales_orders WHERE sales_order_id = ?");
        $curSo->execute([$sales_order_id]);
        $oldSo = (string)$curSo->fetchColumn();
        $newSo = codeForEdit($pdo, $is_quote ? 'QT' : 'SO', $oldSo, '(SO|QT)-[0-9].*', 'sales_orders', (int)$sales_order_id);
        if ($newSo !== $oldSo) {
            $pdo->prepare("UPDATE sales_orders SET order_number = ? WHERE sales_order_id = ?")->execute([$newSo, $sales_order_id]);
        }

        // Update
        if ($hasPoNoColumn) {
            $stmt = $pdo->prepare("
                UPDATE sales_orders SET 
                    customer_id = ?, order_date = ?, delivery_date = ?, salesperson_id = ?,
                    currency = ?, payment_terms = ?, reference = ?, po_no = ?,
                    subtotal = ?, tax_amount = ?, discount_amount = ?, shipping_cost = ?, grand_total = ?,
                    total_ordered = ?, notes = ?, terms_conditions = ?, status = ?, is_quote = ?, 
                    project_id = ?, warehouse_id = ?,
                    updated_at = NOW(), updated_by = ?
                WHERE sales_order_id = ?
            ");
            $stmt->execute([
                $customer_id, $order_date, $delivery_date, $salesperson_id,
                $currency, $payment_terms, $reference, $po_no,
                $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
                $total_ordered, $notes, $terms_conditions, $status, $is_quote, 
                $project_id, $warehouse_id,
                $_SESSION['user_id'], $sales_order_id
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE sales_orders SET 
                    customer_id = ?, order_date = ?, delivery_date = ?, salesperson_id = ?,
                    currency = ?, payment_terms = ?, reference = ?, 
                    subtotal = ?, tax_amount = ?, discount_amount = ?, shipping_cost = ?, grand_total = ?,
                    total_ordered = ?, notes = ?, terms_conditions = ?, status = ?, is_quote = ?, 
                    project_id = ?, warehouse_id = ?,
                    updated_at = NOW(), updated_by = ?
                WHERE sales_order_id = ?
            ");
            $stmt->execute([
                $customer_id, $order_date, $delivery_date, $salesperson_id,
                $currency, $payment_terms, $reference,
                $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
                $total_ordered, $notes, $terms_conditions, $status, $is_quote, 
                $project_id, $warehouse_id,
                $_SESSION['user_id'], $sales_order_id
            ]);
        }
        
        $pdo->prepare("DELETE FROM sales_order_items WHERE order_id = ?")->execute([$sales_order_id]);
    } else {
        // Insert — company-prefixed sequential number (BFS-SO-0001 / BFS-QT-0001).
        require_once __DIR__ . '/../../core/code_generator.php';
        $order_number = nextCode($pdo, $is_quote ? 'QT' : 'SO');

        if ($hasPoNoColumn) {
            $stmt = $pdo->prepare("
                INSERT INTO sales_orders (
                    order_number, customer_id, order_date, delivery_date, salesperson_id,
                    currency, payment_terms, reference, po_no,
                    subtotal, tax_amount, discount_amount, shipping_cost, grand_total,
                    total_ordered, notes, terms_conditions, status, is_quote, project_id, warehouse_id,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $order_number, $customer_id, $order_date, $delivery_date, $salesperson_id,
                $currency, $payment_terms, $reference, $po_no,
                $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
                $total_ordered, $notes, $terms_conditions, $status, $is_quote, $project_id, $warehouse_id,
                $_SESSION['user_id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO sales_orders (
                    order_number, customer_id, order_date, delivery_date, salesperson_id,
                    currency, payment_terms, reference,
                    subtotal, tax_amount, discount_amount, shipping_cost, grand_total,
                    total_ordered, notes, terms_conditions, status, is_quote, project_id, warehouse_id,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $order_number, $customer_id, $order_date, $delivery_date, $salesperson_id,
                $currency, $payment_terms, $reference,
                $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
                $total_ordered, $notes, $terms_conditions, $status, $is_quote, $project_id, $warehouse_id,
                $_SESSION['user_id']
            ]);
        }
        $sales_order_id = $pdo->lastInsertId();

        // ── e-signature capture (Created By) ─ Issue 1 fix
        if (!function_exists('workflowCaptureSignature')) {
            require_once __DIR__ . '/../../core/workflow.php';
        }
        $wfActor = workflowActorSnapshot();
        workflowCaptureSignature(
            $pdo, 'sales_order', (int)$sales_order_id, 'created',
            (int)$_SESSION['user_id'], $wfActor['name'], $wfActor['role']
        );
    }

    // Insert Items
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $price = floatval($item['unit_price'] ?? 0);
        $tax_rate = floatval($item['tax_rate'] ?? 0);
        $discount_percent = floatval($item['discount_percent'] ?? 0);
        
        $line_subtotal = $qty * $price;
        $discount_amount = $line_subtotal * ($discount_percent / 100);
        $taxable_amount = $line_subtotal - $discount_amount;
        $line_tax = $taxable_amount * ($tax_rate / 100);
        $line_total = $taxable_amount + $line_tax;

        $itemStmt = $pdo->prepare("
            INSERT INTO sales_order_items (
                order_id, product_id, product_name, sku, quantity, unit,
                unit_price, tax_rate, discount_percent, line_total, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $itemStmt->execute([
            $sales_order_id, $item['product_id'] ?: null, $item['product_name'], $item['sku'],
            $qty, $item['unit'] ?? 'pcs', $price, $tax_rate, $discount_percent, $line_total
        ]);
    }

    $pdo->commit();
    
    // Log Activity
    $type_label = ($is_quote) ? 'quotation' : 'sales order';

    $log_order_num = $order_number ?? '';
    if ($is_update && empty($log_order_num)) {
        $stmt_num = $pdo->prepare("SELECT order_number FROM sales_orders WHERE sales_order_id = ?");
        $stmt_num->execute([$sales_order_id]);
        $log_order_num = $stmt_num->fetchColumn();
    }

    if ($is_update) {
        logActivity($pdo, $_SESSION['user_id'], "Edit $type_label", "User edited $type_label: $log_order_num (ID $sales_order_id)");
    } else {
        logActivity($pdo, $_SESSION['user_id'], "Create $type_label", "User created a new $type_label: $log_order_num (ID $sales_order_id)");
    }

    echo json_encode([
        'success' => true, 
        'message' => 'Sales Order saved successfully', 
        'order_id' => $sales_order_id,
        'order_number' => $order_number ?? ($log_order_num ?? '')
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving sales order: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}