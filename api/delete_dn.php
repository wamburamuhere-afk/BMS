<?php
// File: api/delete_dn.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

if (!canDelete('dn')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to delete delivery notes']);
    exit;
}

try {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $user_id     = $_SESSION['user_id'];
    if ($delivery_id <= 0) throw new Exception('Invalid DN ID.');

    // Phase C — block deletes against DNs on projects not in user scope
    assertScopeForRecord('deliveries', 'delivery_id', $delivery_id);

    $dn = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
    $dn->execute([$delivery_id]);
    $dn = $dn->fetch(PDO::FETCH_ASSOC);
    if (!$dn) throw new Exception('Delivery Note not found.');
    if (!in_array($dn['status'], ['draft','cancelled'])) {
        throw new Exception('Only draft or cancelled DNs can be deleted.');
    }

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM delivery_items WHERE delivery_id = ?")->execute([$delivery_id]);
    $pdo->prepare("DELETE FROM deliveries WHERE delivery_id = ?")->execute([$delivery_id]);
    logActivity($pdo, $user_id, "Deleted Delivery Note #" . $dn['delivery_number']);
    $pdo->commit();

    echo json_encode(['success'=>true, 'message'=>'Delivery Note deleted successfully.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
