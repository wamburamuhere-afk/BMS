<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$so_id = intval($_GET['so_id'] ?? 0);
if (!$so_id) { echo json_encode(['success'=>false,'message'=>'Sales Order ID required']); exit(); }

try {
    $stmt = $pdo->prepare("
        SELECT soi.product_name, soi.quantity, soi.unit, soi.unit_price, soi.tax_rate
        FROM sales_order_items soi
        WHERE soi.order_id = ?
        ORDER BY soi.order_item_id ASC
    ");
    $stmt->execute([$so_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'items'=>$items]);
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
