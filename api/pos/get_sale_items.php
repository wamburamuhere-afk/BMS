<?php
// scope-audit: skip — fetches one POS sale by id then guards with userCan('project'); POS project scope deferred (see pos.php)
/**
 * API: Get a POS sale's returnable lines (for the Return modal)
 * ----------------------------------------------------------------------------
 * Returns the sale header + its lines with the still-returnable quantity
 * (quantity − returned_quantity) so the modal can cap each input.
 *
 * GET: sale_id
 * Permission: canView('pos')
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';   // loads core/project_scope.php

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$sale_id = (int)($_GET['sale_id'] ?? 0);
if ($sale_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid sale.']); exit; }

try {
    global $pdo;

    $st = $pdo->prepare("SELECT sale_id, receipt_number, customer_name, project_id, sale_status, is_return_sale, grand_total
                           FROM pos_sales WHERE sale_id = ?");
    $st->execute([$sale_id]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale) { echo json_encode(['success' => false, 'message' => 'Sale not found.']); exit; }

    // Project-scope guard: a non-admin may only see a sale within their scope.
    $pid = $sale['project_id'] !== null && $sale['project_id'] !== '' ? (int)$sale['project_id'] : null;
    if ($pid !== null && !userCan('project', $pid)) {
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'This sale is not in your project scope.']); exit;
    }

    $li = $pdo->prepare("SELECT sale_item_id, product_id, product_name, quantity, unit_price, tax_rate,
                                returned_quantity, (quantity - returned_quantity) AS returnable
                           FROM pos_sale_items WHERE sale_id = ?");
    $li->execute([$sale_id]);
    $lines = [];
    foreach ($li->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $lines[] = [
            'sale_item_id' => (int)$l['sale_item_id'],
            'product_id'   => (int)$l['product_id'],
            'product_name' => $l['product_name'],
            'quantity'     => (float)$l['quantity'],
            'unit_price'   => (float)$l['unit_price'],
            'tax_rate'     => (float)$l['tax_rate'],
            'returned'     => (float)$l['returned_quantity'],
            'returnable'   => (float)$l['returnable'],
        ];
    }

    echo json_encode([
        'success' => true,
        'sale'    => [
            'sale_id'        => (int)$sale['sale_id'],
            'receipt_number' => $sale['receipt_number'],
            'customer_name'  => $sale['customer_name'] ?: 'Walk-in',
            'sale_status'    => $sale['sale_status'],
            'grand_total'    => (float)$sale['grand_total'],
        ],
        'lines'   => $lines,
    ]);

} catch (Throwable $e) {
    error_log('get_sale_items: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
