<?php
// File: api/create_dn.php
// Creates a Delivery Note — either an inbound "Record DN" (goods received FROM a
// supplier/sub-contractor) or an outbound "Create DN" (goods sent TO one).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/dn_attachment_helper.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

try {
    $dn_type      = (($_POST['dn_type'] ?? 'inbound') === 'outbound') ? 'outbound' : 'inbound';
    $party_type   = (($_POST['party_type'] ?? 'supplier') === 'subcontractor') ? 'subcontractor' : 'supplier';
    $party_id     = intval($_POST['party_id'] ?? 0);
    $project_id   = intval($_POST['project_id'] ?? 0);
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
    $delivery_date    = trim($_POST['delivery_date']    ?? date('Y-m-d'));
    $contact_person   = trim($_POST['contact_person']   ?? '');
    $contact_phone    = trim($_POST['contact_phone']    ?? '');
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $notes            = trim($_POST['notes']            ?? '');
    $manual_dn        = trim($_POST['dn_number']        ?? '');
    $items            = json_decode($_POST['items'] ?? '[]', true);
    $status           = $_POST['status'] ?? 'draft';
    $purchase_order_id = intval($_POST['purchase_order_id'] ?? 0) ?: null;
    $user_id          = $_SESSION['user_id'];

    if ($warehouse_id <= 0) throw new Exception('Warehouse is required.');
    if ($party_id <= 0) {
        throw new Exception(($party_type === 'subcontractor' ? 'Sub-contractor' : 'Supplier') . ' is required.');
    }
    if (empty($items)) throw new Exception('At least one item is required.');

    if (!in_array($status, ['draft', 'approved', 'pending', 'review'], true)) {
        $status = 'draft';
    }

    // Record DN (inbound): the number on the supplier's physical note is mandatory.
    if ($dn_type === 'inbound' && $manual_dn === '') {
        throw new Exception("Please enter the supplier's Delivery Note number.");
    }

    // Validate the counterparty against the correct table
    if ($party_type === 'subcontractor') {
        $chk = $pdo->prepare("SELECT supplier_id FROM sub_contractors WHERE supplier_id = ? AND status = 'active'");
    } else {
        $chk = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND status = 'active'");
    }
    $chk->execute([$party_id]);
    if (!$chk->fetch()) {
        throw new Exception('Invalid or inactive ' . ($party_type === 'subcontractor' ? 'sub-contractor' : 'supplier') . '.');
    }

    // Validate warehouse
    $wh = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
    $wh->execute([$warehouse_id]);
    if (!$wh->fetch()) throw new Exception('Invalid or inactive warehouse.');

    // Validate items — block non-inventory services
    foreach ($items as &$item) {
        $item['product_id'] = intval($item['product_id']);
        $item['quantity']   = floatval($item['quantity']);
        if ($item['product_id'] <= 0) throw new Exception('Invalid product in items.');
        if ($item['quantity'] <= 0)   throw new Exception('Quantity must be greater than 0.');

        $prod = $pdo->prepare("SELECT is_service, track_inventory, unit, product_name FROM products WHERE product_id = ?");
        $prod->execute([$item['product_id']]);
        $pi = $prod->fetch(PDO::FETCH_ASSOC);
        if (!$pi) throw new Exception("Product ID {$item['product_id']} not found.");
        if ($pi['is_service'] && !$pi['track_inventory']) {
            throw new Exception("'{$pi['product_name']}' is a Non-Inventory service — cannot be added to a Delivery Note.");
        }
        $item['unit'] = $item['unit'] ?? $pi['unit'] ?? 'pcs';
    }
    unset($item);

    // Inbound requires at least one attachment (scan of the supplier's DN)
    $att_pairs = dn_collect_attachment_pairs();
    if ($dn_type === 'inbound' && count($att_pairs) === 0) {
        throw new Exception("Please attach at least one scan of the supplier's Delivery Note.");
    }

    // Internal reference number — always auto-generated, always unique
    $delivery_number = 'DN-' . date('Ymd') . '-' . mt_rand(100, 999);
    $cn = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_number = ?");
    $cn->execute([$delivery_number]);
    while ($cn->fetchColumn() > 0) {
        $delivery_number = 'DN-' . date('Ymd') . '-' . mt_rand(1000, 9999);
        $cn->execute([$delivery_number]);
    }
    // dn_number: inbound = supplier's hand-written number; outbound = system number.
    $dn_number = ($dn_type === 'inbound') ? $manual_dn : $delivery_number;

    $pdo->beginTransaction();

    $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $user_role = $_SESSION['user_role'] ?? 'Staff';

    $supplier_id      = ($party_type === 'supplier')      ? $party_id : null;
    $subcontractor_id = ($party_type === 'subcontractor') ? $party_id : null;

    $pdo->prepare("
        INSERT INTO deliveries
            (delivery_number, dn_number, dn_type, party_type, supplier_id, subcontractor_id,
             delivery_date, status, created_by, project_id, warehouse_id, purchase_order_id,
             contact_person, contact_phone, delivery_address, notes,
             prepared_by_name, prepared_by_role, prepared_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $delivery_number, $dn_number, $dn_type, $party_type, $supplier_id, $subcontractor_id,
        $delivery_date, $status, $user_id, $project_id ?: null, $warehouse_id, $purchase_order_id,
        $contact_person ?: null, $contact_phone ?: null, $delivery_address ?: null, $notes ?: null,
        $user_name, $user_role,
    ]);
    $delivery_id = $pdo->lastInsertId();

    // Insert items
    $item_stmt = $pdo->prepare("
        INSERT INTO delivery_items (delivery_id, product_id, product_name, sku, quantity_delivered, unit)
        SELECT ?, p.product_id, p.product_name, p.sku, ?, ?
        FROM products p WHERE p.product_id = ?
    ");

    foreach ($items as $item) {
        $item_stmt->execute([$delivery_id, $item['quantity'], $item['unit'], $item['product_id']]);

        // Legacy behaviour: an inbound DN created directly as 'approved' adds stock.
        // (The form only ever submits 'draft', so this stays dormant in normal use.)
        if ($status === 'approved' && $dn_type === 'inbound') {
            $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, stock_quantity = stock_quantity + ? WHERE product_id = ?")
                ->execute([$item['quantity'], $item['quantity'], $item['product_id']]);

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

            $pdo->prepare("
                INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, reference_id, reference_type, movement_date, created_by, notes)
                VALUES (?, ?, 'in', ?, ?, 'dn', ?, ?, ?)
            ")->execute([$item['product_id'], $warehouse_id, $item['quantity'], $delivery_id, $delivery_date, $user_id, "DN Receipt: " . $delivery_number]);
        }
    }

    // Attachments — only for inbound Record DNs
    if ($dn_type === 'inbound') {
        dn_save_attachments($pdo, $delivery_id, $att_pairs, $user_id, $project_id ?: null);
    }

    $label = ($dn_type === 'inbound') ? 'Record (inbound)' : 'Create (outbound)';
    logActivity($pdo, $user_id, "Created $label Delivery Note #$dn_number with status $status");

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'message'     => "Delivery Note #$dn_number created successfully.",
        'delivery_id' => $delivery_id,
        'dn_number'   => $dn_number,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
