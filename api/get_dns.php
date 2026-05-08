<?php
// File: api/get_dns.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
try {
    $project_id = intval($_GET['project_id'] ?? 0);
    if ($project_id <= 0) throw new Exception('Project ID required.');

    $stmt = $pdo->prepare("
        SELECT d.delivery_id, d.delivery_number, d.delivery_date, d.status,
               d.contact_person, d.notes,
               s.supplier_name, w.warehouse_name,
               (SELECT COUNT(*) FROM delivery_items di WHERE di.delivery_id = d.delivery_id) as total_items,
               (SELECT COALESCE(SUM(di.quantity_delivered),0) FROM delivery_items di WHERE di.delivery_id = d.delivery_id) as total_qty,
               (SELECT do2.do_number FROM delivery_orders do2 WHERE do2.dn_id = d.delivery_id LIMIT 1) as do_number,
               (SELECT do2.do_id FROM delivery_orders do2 WHERE do2.dn_id = d.delivery_id LIMIT 1) as do_id
        FROM deliveries d
        LEFT JOIN suppliers s  ON d.supplier_id  = s.supplier_id
        LEFT JOIN warehouses w ON d.warehouse_id = w.warehouse_id
        WHERE d.project_id = ?
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$project_id]);
    $dns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true, 'data'=>$dns]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
