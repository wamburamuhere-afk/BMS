<?php
/**
 * API: recent restaurant purchases (restaurant focus pass) — what was bought through Purchases.
 * GET: warehouse_id (required) → { success, data: [{ date, item, quantity, unit, unit_cost, total, reference, by }] } (latest 100)
 * Read-only. Gate: Restaurant POS + Smart POS on, a Restaurant/Hybrid shop in the user's scope.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => true, 'data' => []]); exit; }

global $pdo;
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if (!restaurantShopActive($pdo, $warehouse_id)) { echo json_encode(['success' => true, 'data' => []]); exit; }

$st = $pdo->prepare("
    SELECT sm.movement_date AS d, p.product_name AS item, sm.quantity AS qty,
           COALESCE(NULLIF(p.unit,''), NULLIF(p.unit_of_measure,''), 'pcs') AS unit,
           sm.unit_cost, ROUND(sm.quantity * sm.unit_cost, 2) AS total, sm.reference_number AS ref,
           TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS by_name
      FROM stock_movements sm
      JOIN products p ON p.product_id = sm.product_id
 LEFT JOIN users u ON u.user_id = sm.created_by
     WHERE sm.warehouse_id = ? AND sm.notes LIKE 'Restaurant purchase:%'
  ORDER BY sm.movement_id DESC LIMIT 100");
$st->execute([$warehouse_id]);
echo json_encode(['success' => true, 'data' => array_map(fn($r) => [
    'date' => $r['d'], 'item' => $r['item'], 'quantity' => (float)$r['qty'], 'unit' => $r['unit'],
    'unit_cost' => (float)$r['unit_cost'], 'total' => (float)$r['total'], 'reference' => $r['ref'], 'by' => $r['by_name'],
], $st->fetchAll(PDO::FETCH_ASSOC))]);
