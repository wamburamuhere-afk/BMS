<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('warehouses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid warehouse ID']); exit; }

try {
    // Non-admins may only view warehouses in their scope
    if (!isAdmin() && !userCan('warehouse', $id)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied: this shop is not in your scope']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT warehouse_id, warehouse_name, warehouse_code, address, city, state, country,
               postal_code, phone, email, contact_person, capacity,
               pos_mode, status, is_primary, notes, created_at, updated_at
          FROM warehouses
         WHERE warehouse_id = ? AND status != 'deleted'
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Shop not found']); exit; }

    echo json_encode(['success'=>true,'data'=>$row]);
} catch (Throwable $e) {
    error_log('mobile/warehouses/get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
