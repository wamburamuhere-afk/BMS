<?php
/**
 * API: search the items a restaurant counts — bought Menu items and ingredients (restaurant focus pass).
 * GET: warehouse_id (required), q (optional) → Select2 { results: [{ id, text, unit, cost_price, stock }] }
 * Ordinary retail stock (it has a selling price and is not on the Menu or in a recipe) is never listed.
 * Gate: Restaurant POS + Smart POS on, a Restaurant/Hybrid shop in the user's scope. Read-only.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/restaurant_purchases.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['results' => [], 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['results' => [], 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['results' => []]); exit; }

global $pdo;
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) { http_response_code(403); echo json_encode(['results' => [], 'message' => t('Permission denied')]); exit; }
if (!restaurantShopActive($pdo, $warehouse_id)) { echo json_encode(['results' => []]); exit; }

$q = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT p.product_id, p.product_name, COALESCE(NULLIF(p.unit,''), NULLIF(p.unit_of_measure,''), 'pcs') AS unit,
               COALESCE(p.cost_price, 0) AS cost_price,
               (SELECT COALESCE(SUM(ps.stock_quantity), 0) FROM product_stocks ps WHERE ps.product_id = p.product_id AND ps.warehouse_id = ?) AS stock
          FROM products p
         WHERE " . restaurantStockItemSql('p') . "
           AND (p.warehouse_id = ? OR EXISTS (SELECT 1 FROM product_stocks px WHERE px.product_id = p.product_id AND px.warehouse_id = ?))";
$params = [$warehouse_id, $warehouse_id, $warehouse_id];
if ($q !== '') { $sql .= " AND p.product_name LIKE ?"; $params[] = '%' . $q . '%'; }
$sql .= " ORDER BY p.product_name LIMIT 30";
$st = $pdo->prepare($sql);
$st->execute($params);
echo json_encode(['results' => array_map(fn($r) => [
    'id' => (int)$r['product_id'], 'text' => $r['product_name'] . ' (' . $r['unit'] . ')', 'unit' => $r['unit'],
    'cost_price' => (float)$r['cost_price'], 'stock' => (float)$r['stock'],
], $st->fetchAll(PDO::FETCH_ASSOC))]);
