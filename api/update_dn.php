<?php
// File: api/update_dn.php
// Updates a draft/review Delivery Note (inbound Record or outbound Create).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/dn_attachment_helper.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

if (!canEdit('dn')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to edit delivery notes']);
    exit;
}

try {
    $delivery_id  = intval($_POST['delivery_id'] ?? 0);

    // Phase C — block edits against DNs on projects not in user scope,
    // and verify the incoming project_id is also in user scope.
    if ($delivery_id) {
        assertScopeForRecord('deliveries', 'delivery_id', $delivery_id);
    }
    if (!empty($_POST['project_id']) && !userCan('project', (int)$_POST['project_id'])) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied: this project is not in your scope.']);
        exit;
    }

    $party_type   = (($_POST['party_type'] ?? 'supplier') === 'subcontractor') ? 'subcontractor' : 'supplier';
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
    $user_id          = $_SESSION['user_id'];

    if ($delivery_id <= 0)  throw new Exception('DN ID is required.');
    if ($warehouse_id <= 0) throw new Exception('Warehouse is required.');
    if ($party_id <= 0) {
        throw new Exception(($party_type === 'subcontractor' ? 'Sub-contractor' : 'Supplier') . ' is required.');
    }
    if (empty($items)) throw new Exception('At least one item is required.');

    // Check DN exists and is still editable
    $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
    $stmt->execute([$delivery_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn) throw new Exception('Delivery Note not found.');
    if ($dn['status'] === 'approved') throw new Exception('Cannot edit an approved Delivery Note.');

    $dn_type = $dn['dn_type'] ?? 'inbound';

    // Record DN keeps a hand-written supplier number
    if ($dn_type === 'inbound' && $manual_dn === '') {
        throw new Exception("Please enter the supplier's Delivery Note number.");
    }

    // Validate the counterparty
    if ($party_type === 'subcontractor') {
        $chk = $pdo->prepare("SELECT supplier_id FROM sub_contractors WHERE supplier_id = ? AND status = 'active'");
    } else {
        $chk = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND status = 'active'");
    }
    $chk->execute([$party_id]);
    if (!$chk->fetch()) {
        throw new Exception('Invalid or inactive ' . ($party_type === 'subcontractor' ? 'sub-contractor' : 'supplier') . '.');
    }

    // Validate items
    foreach ($items as &$item) {
        $item['product_id'] = intval($item['product_id']);
        $item['quantity']   = floatval($item['quantity']);
        if ($item['product_id'] <= 0) throw new Exception('Invalid product.');
        if ($item['quantity'] <= 0)   throw new Exception('Quantity must be > 0.');
        $item['unit'] = $item['unit'] ?? 'pcs';
    }
    unset($item);

    $att_pairs = dn_collect_attachment_pairs();

    $supplier_id      = ($party_type === 'supplier')      ? $party_id : null;
    $subcontractor_id = ($party_type === 'subcontractor') ? $party_id : null;
    $dn_number        = ($dn_type === 'inbound') ? $manual_dn : ($dn['dn_number'] ?: $dn['delivery_number']);

    $pdo->beginTransaction();

    // 1. Update DN header
    $pdo->prepare("
        UPDATE deliveries
        SET dn_number=?, party_type=?, supplier_id=?, subcontractor_id=?,
            delivery_date=?, contact_person=?, contact_phone=?, delivery_address=?, notes=?,
            vehicle_number=?, driver_name=?, shipping_method=?,
            warehouse_id=?, project_id=?, purchase_order_id=?, updated_by=?
        WHERE delivery_id=?
    ")->execute([
        $dn_number, $party_type, $supplier_id, $subcontractor_id,
        $delivery_date, $contact_person ?: null, $contact_phone ?: null, $delivery_address ?: null, $notes ?: null,
        $vehicle_number ?: null, $driver_name ?: null, $shipping_method ?: null,
        $warehouse_id, $project_id ?: null, $purchase_order_id, $user_id, $delivery_id,
    ]);

    // 2. Replace items
    $pdo->prepare("DELETE FROM delivery_items WHERE delivery_id = ?")->execute([$delivery_id]);
    $item_stmt = $pdo->prepare("
        INSERT INTO delivery_items (delivery_id, product_id, product_name, sku, quantity_delivered, unit, `condition`)
        SELECT ?, p.product_id, p.product_name, p.sku, ?, ?, ?
        FROM products p WHERE p.product_id = ?
    ");
    foreach ($items as $item) {
        $cond = in_array($item['condition'] ?? 'good', ['good','damaged','expired'], true) ? ($item['condition'] ?? 'good') : 'good';
        $item_stmt->execute([$delivery_id, $item['quantity'], $item['unit'], $cond, $item['product_id']]);
    }

    // 3. Append any newly uploaded attachments (existing ones kept; removed via delete_dn_attachment.php)
    if ($dn_type === 'inbound' && !empty($att_pairs)) {
        dn_save_attachments($pdo, $delivery_id, $att_pairs, $user_id, $project_id ?: null);
    }

    logActivity($pdo, $user_id, "Updated Delivery Note #" . ($dn['dn_number'] ?: $dn['delivery_number']));
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Delivery Note updated successfully.', 'delivery_id' => $delivery_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
