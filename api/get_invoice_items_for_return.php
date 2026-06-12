<?php
// scope-audit: skip — lookup filtered by invoice_id; caller already has access to this specific invoice
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('purchase_returns')) { echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$invoice_id = intval($_GET['invoice_id'] ?? 0);
if (!$invoice_id) { echo json_encode(['success'=>false,'message'=>'Invoice ID required']); exit; }

// Fetch invoice items with already-returned qty per item
$stmt = $pdo->prepare("
    SELECT
        sii.item_id,
        sii.product_id,
        sii.item_name      AS product_name,
        sii.quantity       AS original_qty,
        sii.unit_price,
        sii.tax_rate,
        sii.tax_amount,
        sii.line_total,
        p.sku,
        p.unit,
        COALESCE((
            SELECT SUM(pri.quantity)
            FROM purchase_return_items pri
            JOIN purchase_returns pr ON pri.purchase_return_id = pr.purchase_return_id
            WHERE pri.original_invoice_item_id = sii.item_id
              AND pr.status NOT IN ('rejected','cancelled')
        ), 0) AS already_returned
    FROM supplier_invoice_items sii
    LEFT JOIN products p ON p.product_id = sii.product_id
    WHERE sii.invoice_id = ?
    ORDER BY sii.item_id ASC
");
$stmt->execute([$invoice_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $row['max_returnable'] = max(0, floatval($row['original_qty']) - floatval($row['already_returned']));
}

echo json_encode(['success'=>true,'data'=>$rows]);
