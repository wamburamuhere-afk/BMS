<?php
// File: api/get_rfqs.php
require_once __DIR__ . '/../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');

    $supplier  = isset($_GET['supplier'])  ? intval($_GET['supplier'])  : 0;
    $project   = isset($_GET['project'])   ? intval($_GET['project'])   : 0;
    $warehouse = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;
    $status       = $_GET['status']       ?? '';
    $status_group = $_GET['status_group'] ?? '';
    $date_from    = $_GET['date_from']    ?? '';
    $date_to      = $_GET['date_to']      ?? '';

    $where  = ['1=1'];
    $params = [];

    if ($supplier)  { $where[] = 'r.supplier_id = ?';  $params[] = $supplier; }
    if ($project)   { $where[] = 'r.project_id = ?';   $params[] = $project; }
    if ($warehouse) { $where[] = 'r.warehouse_id = ?'; $params[] = $warehouse; }
    if ($status) { 
        $where[] = 'r.status = ?';       
        $params[] = $status; 
    } elseif ($status_group === 'po_ready') {
        $where[] = "r.status IN ('approved','partially')";
        // Intelligent filter: Only show RFQs that have at least one item with a remaining balance > 0
        $where[] = "EXISTS (
            SELECT 1 FROM rfq_items ri
            WHERE ri.rfq_id = r.rfq_id
            AND (ri.qty - COALESCE((
                SELECT SUM(poi.quantity)
                FROM purchase_order_items poi
                JOIN purchase_orders po ON poi.purchase_order_id = po.purchase_order_id
                WHERE po.rfq_id = ri.rfq_id 
                  AND (
                      (ri.product_id IS NOT NULL AND poi.product_id = ri.product_id)
                      OR (ri.product_id IS NULL AND poi.item_name COLLATE utf8mb4_general_ci = ri.description COLLATE utf8mb4_general_ci)
                  )
                  AND po.status != 'cancelled'
            ), 0)) > 0
        )";
    }
    
    if ($date_from) { $where[] = 'r.rfq_date >= ?';    $params[] = $date_from; }
    if ($date_to)   { $where[] = 'r.rfq_date <= ?';    $params[] = $date_to; }

    $sql = "SELECT r.rfq_id, r.rfq_number, r.rfq_date, r.deadline_date, r.status,
                s.supplier_name, w.warehouse_name, p.project_name
            FROM rfq r
            LEFT JOIN suppliers s ON r.supplier_id = s.supplier_id
            LEFT JOIN warehouses w ON r.warehouse_id = w.warehouse_id
            LEFT JOIN projects p ON r.project_id = p.project_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.rfq_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $sr = $pdo->query("SELECT
        COUNT(*) as total,
        SUM(status IN ('pending','draft','sent')) as pending,
        SUM(status IN ('approved','partially')) as approved,
        SUM(status IN ('awarded','completed','cancelled')) as closed
        FROM rfq")->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true,'data'=>$data,'stats'=>$sr]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage(),'data'=>[],'stats'=>['total'=>0,'pending'=>0,'approved'=>0,'closed'=>0]]);
}