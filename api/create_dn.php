<?php
// File: api/create_dn.php
// Creates a Delivery Note — either an inbound "Record DN" (goods received FROM a
// supplier/sub-contractor) or an outbound "Create DN" (goods sent TO one).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/dn_attachment_helper.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

if (!canCreate('dn')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to create delivery notes']);
    exit;
}

try {
    $dn_type      = (($_POST['dn_type'] ?? 'inbound') === 'outbound') ? 'outbound' : 'inbound';
    $party_type_raw = $_POST['party_type'] ?? 'supplier';
    $party_type   = in_array($party_type_raw, ['subcontractor', 'customer'], true) ? $party_type_raw : 'supplier';
    $party_id     = intval($_POST['party_id'] ?? 0);
    $project_id   = intval($_POST['project_id'] ?? 0);
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
    $delivery_date    = trim($_POST['delivery_date']    ?? date('Y-m-d'));
    $contact_person   = trim($_POST['contact_person']   ?? '');
    $contact_phone    = trim($_POST['contact_phone']    ?? '');
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $notes            = trim($_POST['notes']            ?? '');
    $vehicle_number   = trim($_POST['vehicle_number']   ?? '');
    $driver_name      = trim($_POST['driver_name']      ?? '');
    $shipping_method  = trim($_POST['shipping_method']  ?? '');
    $manual_dn        = trim($_POST['dn_number']        ?? '');
    $items            = json_decode($_POST['items'] ?? '[]', true);
    $purchase_order_id = intval($_POST['purchase_order_id'] ?? 0) ?: null;
    $customer_lpo_id  = intval($_POST['customer_lpo_id'] ?? 0) ?: null;
    $order_id         = intval($_POST['order_id'] ?? 0) ?: null;
    $user_id          = $_SESSION['user_id'];

    if ($warehouse_id <= 0) throw new Exception('Warehouse is required.');

    // Phase C — when project_id is supplied, it must be in user scope.
    if ($project_id && !userCan('project', $project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this project is not in your scope.');
    }

    $party_label = $party_type === 'subcontractor' ? 'Sub-contractor' : ($party_type === 'customer' ? 'Customer' : 'Supplier');
    if ($party_id <= 0) {
        throw new Exception($party_label . ' is required.');
    }
    if (empty($items)) throw new Exception('At least one item is required.');

    // A customer-party outbound DN must be linked to an approved/partially-fulfilled LPO
    // or an approved/processing/shipped Sales Order.
    if ($party_type === 'customer') {
        if (!$customer_lpo_id && !$order_id) {
            throw new Exception('A Customer LPO or Sales Order reference is required for customer delivery notes.');
        }
        if ($customer_lpo_id) {
            $lpoChk = $pdo->prepare("SELECT customer_id, status FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
            $lpoChk->execute([$customer_lpo_id]);
            $lpoRow = $lpoChk->fetch(PDO::FETCH_ASSOC);
            if (!$lpoRow) throw new Exception('Linked LPO not found.');
            if (!in_array($lpoRow['status'], ['approved', 'partially_fulfilled'], true)) {
                throw new Exception('The linked LPO must be approved or partially fulfilled.');
            }
            if ((int)$lpoRow['customer_id'] !== $party_id) {
                throw new Exception('Customer does not match the linked LPO.');
            }
        }
        if ($order_id) {
            $soChk = $pdo->prepare("SELECT customer_id, status FROM sales_orders WHERE sales_order_id = ?");
            $soChk->execute([$order_id]);
            $soRow = $soChk->fetch(PDO::FETCH_ASSOC);
            if (!$soRow) throw new Exception('Linked Sales Order not found.');
            if (!in_array($soRow['status'], ['approved', 'processing', 'shipped'], true)) {
                throw new Exception('The linked Sales Order must be approved, processing, or shipped.');
            }
            if ((int)$soRow['customer_id'] !== $party_id) {
                throw new Exception('Customer does not match the linked Sales Order.');
            }
        }
    }

    // Three-approval rule: every new Delivery Note starts at 'pending'.
    // Status transitions happen via dedicated review_dn / approve_dn APIs.
    // The legacy direct-to-approved path is intentionally disabled here so
    // stock side-effects only fire from the canonical approval flow.
    $status = 'pending';

    // Record DN (inbound): the number on the supplier's physical note is mandatory.
    if ($dn_type === 'inbound' && $manual_dn === '') {
        throw new Exception("Please enter the supplier's Delivery Note number.");
    }

    // Validate the counterparty against the correct table
    if ($party_type === 'subcontractor') {
        $chk = $pdo->prepare("SELECT supplier_id FROM sub_contractors WHERE supplier_id = ? AND status = 'active'");
    } elseif ($party_type === 'customer') {
        $chk = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = ? AND status = 'active'");
    } else {
        $chk = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND status = 'active'");
    }
    $chk->execute([$party_id]);
    if (!$chk->fetch()) {
        throw new Exception('Invalid or inactive ' . strtolower($party_label) . '.');
    }

    // Validate warehouse
    $wh = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
    $wh->execute([$warehouse_id]);
    if (!$wh->fetch()) throw new Exception('Invalid or inactive warehouse.');

    // Validate items — block non-inventory services
    $soItemChk = $order_id ? $pdo->prepare("SELECT 1 FROM sales_order_items WHERE order_item_id = ? AND order_id = ?") : null;
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

        // order_item_id is optional per line, but if present it must genuinely
        // belong to the linked Sales Order (defense against a tampered request).
        $item['order_item_id'] = !empty($item['order_item_id']) ? intval($item['order_item_id']) : null;
        if ($item['order_item_id']) {
            if (!$soItemChk) throw new Exception('order_item_id supplied without a linked Sales Order.');
            $soItemChk->execute([$item['order_item_id'], $order_id]);
            if (!$soItemChk->fetch()) throw new Exception('One of the items does not belong to the linked Sales Order.');
        }
    }
    unset($item);

    // Inbound requires at least one attachment (scan of the supplier's DN)
    $att_pairs = dn_collect_attachment_pairs();
    if ($dn_type === 'inbound' && count($att_pairs) === 0) {
        throw new Exception("Please attach at least one scan of the supplier's Delivery Note.");
    }

    // Internal reference number — company-prefixed sequential (BFS-DN-0001).
    require_once __DIR__ . '/../core/code_generator.php';
    $delivery_number = nextCode($pdo, 'DN');
    // dn_number: inbound = supplier's hand-written number; outbound = system number.
    $dn_number = ($dn_type === 'inbound') ? $manual_dn : $delivery_number;

    $pdo->beginTransaction();

    $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $user_role = $_SESSION['user_role'] ?? 'Staff';

    $supplier_id      = ($party_type === 'supplier')      ? $party_id : null;
    $subcontractor_id = ($party_type === 'subcontractor') ? $party_id : null;
    $customer_id      = ($party_type === 'customer')      ? $party_id : null;

    $pdo->prepare("
        INSERT INTO deliveries
            (delivery_number, dn_number, dn_type, party_type, supplier_id, subcontractor_id, customer_id,
             delivery_date, status, created_by, project_id, warehouse_id, purchase_order_id, customer_lpo_id, order_id,
             contact_person, contact_phone, delivery_address, notes,
             vehicle_number, driver_name, shipping_method,
             prepared_by_name, prepared_by_role, prepared_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $delivery_number, $dn_number, $dn_type, $party_type, $supplier_id, $subcontractor_id, $customer_id,
        $delivery_date, $status, $user_id, $project_id ?: null, $warehouse_id, $purchase_order_id, $customer_lpo_id, $order_id,
        $contact_person ?: null, $contact_phone ?: null, $delivery_address ?: null, $notes ?: null,
        $vehicle_number ?: null, $driver_name ?: null, $shipping_method ?: null,
        $user_name, $user_role,
    ]);
    $delivery_id = $pdo->lastInsertId();

    // ── e-signature capture (Created By) ─ Issue 1 fix
    if (!function_exists('workflowCaptureSignature')) {
        require_once __DIR__ . '/../core/workflow.php';
    }
    $wfActor = workflowActorSnapshot();
    workflowCaptureSignature(
        $pdo, 'delivery', (int)$delivery_id, 'created',
        (int)$_SESSION['user_id'], $wfActor['name'], $wfActor['role']
    );

    // Insert items
    $item_stmt = $pdo->prepare("
        INSERT INTO delivery_items (delivery_id, order_item_id, product_id, product_name, sku, quantity_delivered, unit, `condition`)
        SELECT ?, ?, p.product_id, p.product_name, p.sku, ?, ?, ?
        FROM products p WHERE p.product_id = ?
    ");

    foreach ($items as $item) {
        $cond = in_array($item['condition'] ?? 'good', ['good','damaged','expired'], true) ? ($item['condition'] ?? 'good') : 'good';
        $item_stmt->execute([$delivery_id, $item['order_item_id'], $item['quantity'], $item['unit'], $cond, $item['product_id']]);
        // Stock side-effects (inbound add / outbound reserve) now fire from
        // api/approve_dn.php when the DN reaches 'approved' status, so they
        // only occur once the canonical three_approval.md gate is passed.
    }

    // Attachments — only for inbound Record DNs
    if ($dn_type === 'inbound') {
        dn_save_attachments($pdo, $delivery_id, $att_pairs, $user_id, $project_id ?: null);
    }

    logActivity($pdo, $user_id, 'Create delivery note', "User created a new delivery note: $dn_number (ID $delivery_id)");

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
