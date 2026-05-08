<?php
// File: api/approve_dn.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
try {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $user_id     = $_SESSION['user_id'];
    if ($delivery_id <= 0) throw new Exception('Invalid DN ID.');

    $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
    $stmt->execute([$delivery_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn) throw new Exception('Delivery Note not found.');
    if ($dn['status'] !== 'draft') throw new Exception('Only draft DNs can be approved.');

    // Get items
    $items = $pdo->prepare("SELECT product_id, quantity_delivered as quantity FROM delivery_items WHERE delivery_id = ?");
    $items->execute([$delivery_id]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    // Approve DN
    $pdo->prepare("UPDATE deliveries SET status='approved', approved_by=?, approved_at=NOW() WHERE delivery_id=?")
        ->execute([$user_id, $delivery_id]);

    // Reserve stock
    $reserve = $pdo->prepare("UPDATE product_stocks SET reserved_quantity = reserved_quantity + ? WHERE product_id = ? AND warehouse_id = ?");
    foreach ($items as $item) {
        $reserve->execute([$item['quantity'], $item['product_id'], $dn['warehouse_id']]);
    }

    logActivity($pdo, $user_id, "Approved Delivery Note #" . $dn['delivery_number']);
    $pdo->commit();
    echo json_encode(['success'=>true, 'message'=>'Delivery Note approved successfully.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
