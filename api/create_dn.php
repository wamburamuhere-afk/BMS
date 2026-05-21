<?php
// File: api/create_dn.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

try {
    $project_id      = intval($_POST['project_id']      ?? 0);
    $warehouse_id    = intval($_POST['warehouse_id']    ?? 0);
    $supplier_id     = intval($_POST['supplier_id']     ?? 0);
    $delivery_date   = trim($_POST['delivery_date']     ?? date('Y-m-d'));
    $contact_person  = trim($_POST['contact_person']    ?? '');
    $contact_phone   = trim($_POST['contact_phone']     ?? '');
    $delivery_address= trim($_POST['delivery_address']  ?? '');
    $notes           = trim($_POST['notes']             ?? '');
    $do_id           = intval($_POST['do_id'] ?? 0) ?: null;
    $items_json      = $_POST['items'] ?? '[]';
    $items           = json_decode($items_json, true);
    $status          = $_POST['status'] ?? 'draft';
    $purchase_order_id = intval($_POST['purchase_order_id'] ?? 0) ?: null;
    $user_id         = $_SESSION['user_id'];

    if ($warehouse_id <= 0) throw new Exception('Warehouse is required.');
    if ($supplier_id <= 0)  throw new Exception('Supplier is required.');
    if (empty($items))      throw new Exception('At least one item is required.');

    // Validate status
    if (!in_array($status, ['draft', 'approved', 'pending', 'review'])) {
        $status = 'draft';
    }

    // Validate warehouse (allow project-specific OR global warehouses)
    if ($project_id > 0) {
        $wh = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ? AND (project_id = ? OR project_id IS NULL OR project_id = 0) AND status = 'active'");
        $wh->execute([$warehouse_id, $project_id]);
    } else {
        $wh = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $wh->execute([$warehouse_id]);
    }
    if (!$wh->fetch()) throw new Exception('Invalid or inactive warehouse.');

    // Validate supplier (allow project-specific OR global suppliers)
    if ($project_id > 0) {
        $sp = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND (project_id = ? OR project_id IS NULL OR project_id = 0) AND status = 'active'");
        $sp->execute([$supplier_id, $project_id]);
    } else {
        $sp = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND status = 'active'");
        $sp->execute([$supplier_id]);
    }
    if (!$sp->fetch()) throw new Exception('Invalid or inactive supplier.');

    // Validate items and check stock
    foreach ($items as &$item) {
        $item['product_id'] = intval($item['product_id']);
        $item['quantity']   = floatval($item['quantity']);
        if ($item['product_id'] <= 0) throw new Exception('Invalid product in items.');
        if ($item['quantity'] <= 0)   throw new Exception('Quantity must be greater than 0.');

        // Get product info — block non-tracked services from DN
        $prod_check = $pdo->prepare("SELECT p.is_service, p.track_inventory, p.unit, p.product_name FROM products p WHERE p.product_id = ?");
        $prod_check->execute([$item['product_id']]);
        $prod_info = $prod_check->fetch(PDO::FETCH_ASSOC);
        if (!$prod_info) throw new Exception("Product ID {$item['product_id']} not found.");
        if ($prod_info['is_service'] && !$prod_info['track_inventory']) throw new Exception("'{$prod_info['product_name']}' is a Non-Inventory service — cannot be added to Delivery Note.");

        $item['unit'] = $item['unit'] ?? $prod_info['unit'] ?? 'pcs';
    }
    unset($item);

    // Generate DN number
    $dn_number = 'DN-' . date('Ymd') . '-' . mt_rand(100, 999);
    $check = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_number = ?");
    $check->execute([$dn_number]);
    while ($check->fetchColumn() > 0) {
        $dn_number = 'DN-' . date('Ymd') . '-' . mt_rand(1000, 9999);
        $check->execute([$dn_number]);
    }

    $pdo->beginTransaction();

    // Prepare snapshot data
    $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $user_role = $_SESSION['user_role'] ?? 'Staff';

    // Insert DN
    $pdo->prepare("
        INSERT INTO deliveries (delivery_number, dn_number, delivery_date, status, created_by, project_id, warehouse_id, supplier_id, do_id, purchase_order_id, contact_person, contact_phone, delivery_address, notes, prepared_by_name, prepared_by_role, prepared_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([$dn_number, null, $delivery_date, $status, $user_id, $project_id ?: null, $warehouse_id, $supplier_id, $do_id, $purchase_order_id,
                 $contact_person ?: null, $contact_phone ?: null, $delivery_address ?: null, $notes ?: null, $user_name, $user_role]);
    $delivery_id = $pdo->lastInsertId();

    // Insert items and update stock if completed
    $item_stmt = $pdo->prepare("
        INSERT INTO delivery_items (delivery_id, product_id, product_name, sku, quantity_delivered, unit)
        SELECT ?, p.product_id, p.product_name, p.sku, ?, ?
        FROM products p WHERE p.product_id = ?
    ");

    foreach ($items as $item) {
        $item_stmt->execute([$delivery_id, $item['quantity'], $item['unit'], $item['product_id']]);

        // IF COMPLETED: Update Stock
        if ($status === 'approved') {
            // Update main product table
            $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, stock_quantity = stock_quantity + ? WHERE product_id = ?")
                ->execute([$item['quantity'], $item['quantity'], $item['product_id']]);

            // Update warehouse specific stock
            $stmtCheck = $pdo->prepare("SELECT stock_id FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
            $stmtCheck->execute([$item['product_id'], $warehouse_id]);
            $stockId = $stmtCheck->fetchColumn();

            if ($stockId) {
                $pdo->prepare("UPDATE product_stocks SET stock_quantity = stock_quantity + ?, last_updated = NOW() WHERE stock_id = ?")
                    ->execute([$item['quantity'], $stockId]);
            } else {
                $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, last_updated) VALUES (?, ?, ?, NOW())")
                    ->execute([$item['product_id'], $warehouse_id, $item['quantity']]);
            }

            // Record Movement
            $pdo->prepare("
                INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, movement_date, created_by, notes)
                VALUES (?, ?, 'in', ?, ?, 'dn', ?, ?, ?)
            ")->execute([
                $item['product_id'], $warehouse_id, $item['quantity'], $delivery_id, $delivery_date, $user_id, "DN Receipt: " . $dn_number
            ]);
        }
    }

    // Log activity
    logActivity($pdo, $user_id, "Created Delivery Note #$dn_number with status $status");

    // Update PO status if applicable
    if ($status === 'completed' && $do_id) {
        // Logic to update PO status could go here (similar to grn logic)
    }

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'message'     => "Delivery Note #$dn_number created successfully.",
        'delivery_id' => $delivery_id,
        'dn_number'   => $dn_number
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
