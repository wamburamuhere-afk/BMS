<?php
// File: api/update_dn.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
try {
    $delivery_id     = intval($_POST['delivery_id']     ?? 0);
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
    $purchase_order_id = intval($_POST['purchase_order_id'] ?? 0) ?: null;
    $user_id         = $_SESSION['user_id'];

    if ($delivery_id <= 0)  throw new Exception('DN ID is required.');
    if ($warehouse_id <= 0) throw new Exception('Warehouse is required.');
    if ($supplier_id <= 0)  throw new Exception('Supplier is required.');
    if (empty($items))      throw new Exception('At least one item is required.');

    // Check DN exists and is editable
    $dn = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
    $dn->execute([$delivery_id]);
    $dn = $dn->fetch(PDO::FETCH_ASSOC);
    if (!$dn) throw new Exception('Delivery Note not found.');
    if ($dn['status'] === 'approved') throw new Exception('Cannot edit an approved Delivery Note.');

    // Validate items
    foreach ($items as &$item) {
        $item['product_id'] = intval($item['product_id']);
        $item['quantity']   = floatval($item['quantity']);
        if ($item['product_id'] <= 0) throw new Exception('Invalid product.');
        if ($item['quantity'] <= 0)   throw new Exception('Quantity must be > 0.');
        $item['unit'] = $item['unit'] ?? 'pcs';
    }
    unset($item);

    $pdo->beginTransaction();

    // Update DN header
    $pdo->prepare("
        UPDATE deliveries
        SET delivery_date=?, contact_person=?, contact_phone=?, delivery_address=?, notes=?,
            warehouse_id=?, supplier_id=?, project_id=?, do_id=?, purchase_order_id=?, updated_by=?
        WHERE delivery_id=?
    ")->execute([$delivery_date, $contact_person ?: null, $contact_phone ?: null,
                 $delivery_address ?: null, $notes ?: null, $warehouse_id, $supplier_id,
                 $project_id ?: null, $do_id, $purchase_order_id, $user_id, $delivery_id]);

    // Delete old items and re-insert
    $pdo->prepare("DELETE FROM delivery_items WHERE delivery_id = ?")->execute([$delivery_id]);

    $item_stmt = $pdo->prepare("
        INSERT INTO delivery_items (delivery_id, product_id, product_name, sku, quantity_delivered, unit)
        SELECT ?, p.product_id, p.product_name, p.sku, ?, ?
        FROM products p WHERE p.product_id = ?
    ");
    foreach ($items as $item) {
        $item_stmt->execute([$delivery_id, $item['quantity'], $item['unit'], $item['product_id']]);
    }

    logActivity($pdo, $user_id, "Updated Delivery Note #" . $dn['delivery_number']);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Delivery Note updated successfully.",
        'delivery_id' => $delivery_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
