<?php
// File: api/operations/get_do_items.php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('do')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to view delivery orders']);
    exit;
}

$do_id = intval($_GET['do_id'] ?? 0);
if (!$do_id) { echo json_encode(['success'=>false,'message'=>'DO ID is required']); exit; }

// Phase C — block reads against DOs on projects not in user scope
assertScopeForRecord('delivery_orders', 'do_id', $do_id);

$stmt = $pdo->prepare("
    SELECT item_id, product_id, product_name, available_qty, qty_to_issue, unit
    FROM delivery_order_items
    WHERE do_id = ?
    ORDER BY item_id
");
$stmt->execute([$do_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'items' => $items]);
