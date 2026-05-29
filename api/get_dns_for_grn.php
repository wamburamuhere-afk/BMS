<?php
// scope-audit: skip — read-only DN lookup for GRN create dropdown; filtered by supplier_id supplied by caller; no project-sensitive rows returned directly
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$supplier_id = intval($_GET['supplier_id'] ?? 0);
$po_id       = intval($_GET['po_id'] ?? 0);

if (!$supplier_id) {
    echo json_encode(['success' => false, 'message' => 'supplier_id required']);
    exit;
}

try {
    $params = [$supplier_id];
    $where  = "d.dn_type = 'inbound' AND d.supplier_id = ?";

    if ($po_id) {
        $where   .= " AND d.purchase_order_id = ?";
        $params[] = $po_id;
    }

    $stmt = $pdo->prepare("
        SELECT d.delivery_id,
               d.dn_number,
               d.delivery_number,
               d.delivery_date,
               d.purchase_order_id,
               po.order_number
        FROM deliveries d
        LEFT JOIN purchase_orders po ON d.purchase_order_id = po.purchase_order_id
        WHERE {$where}
        ORDER BY d.delivery_date DESC, d.delivery_id DESC
    ");
    $stmt->execute($params);
    $dns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $dns]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
