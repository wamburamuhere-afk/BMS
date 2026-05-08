<?php
// File: api/get_dos.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
try {
    $project_id = intval($_GET['project_id'] ?? 0);
    if ($project_id <= 0) throw new Exception('Project ID required.');

    $stmt = $pdo->prepare("
        SELECT do.do_id, do.do_number, do.do_date, do.expected_date, do.status,
               do.driver_name, do.vehicle_number, do.delivered_at,
               dn.delivery_number as dn_number, dn.delivery_id as dn_id,
               s.supplier_name, w.warehouse_name,
               (SELECT COUNT(*) FROM delivery_items di WHERE di.delivery_id = do.dn_id) as total_items,
               (SELECT COALESCE(SUM(di.quantity_delivered),0) FROM delivery_items di WHERE di.delivery_id = do.dn_id) as total_qty
        FROM delivery_orders do
        LEFT JOIN deliveries dn ON do.dn_id       = dn.delivery_id
        LEFT JOIN suppliers s   ON do.supplier_id  = s.supplier_id
        LEFT JOIN warehouses w  ON do.warehouse_id = w.warehouse_id
        WHERE do.project_id = ?
        ORDER BY do.created_at DESC
    ");
    $stmt->execute([$project_id]);
    $dos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true, 'data'=>$dos]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
