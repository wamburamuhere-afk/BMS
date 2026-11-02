<?php
/**
 * API: recent restaurant purchases (restaurant focus pass) — what was bought through Purchases.
 * GET: warehouse_id (required) → { success, data: [{ date, item, quantity, unit, kind: stock|grocery, unit_cost, total, reference, by }] } (latest 100;
 * a grocery row has no quantity / unit_cost — it is the amount only)
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
           TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS by_name, sm.movement_id AS sort_id
      FROM stock_movements sm
      JOIN products p ON p.product_id = sm.product_id
 LEFT JOIN users u ON u.user_id = sm.created_by
     WHERE sm.warehouse_id = ? AND sm.notes LIKE 'Restaurant purchase:%'
  ORDER BY sm.movement_id DESC LIMIT 100");
$st->execute([$warehouse_id]);
$rows = array_map(fn($r) => [
    'date' => $r['d'], 'item' => $r['item'], 'quantity' => (float)$r['qty'], 'unit' => $r['unit'], 'kind' => 'stock',
    'unit_cost' => (float)$r['unit_cost'], 'total' => (float)$r['total'], 'reference' => $r['ref'], 'by' => $r['by_name'], '_s' => (int)$r['sort_id'],
], $st->fetchAll(PDO::FETCH_ASSOC));

// Groceries (not counted) are listed too, so everything bought is in one place. They are expenses saved by "Something else".
$ge = $pdo->prepare("
    SELECT e.expense_date AS d, e.description, e.amount, e.reference_number AS ref, e.expense_id,
           TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS by_name
      FROM expenses e
 LEFT JOIN users u ON u.user_id = e.created_by
     WHERE e.warehouse_id = ? AND e.status IN ('paid', 'approved', 'pending', 'reviewed')
       AND (e.expense_account_id = ? OR e.description LIKE 'Ingredients:%')
  ORDER BY e.expense_id DESC LIMIT 100");
// By the cost-of-food account, not by a word: the description is saved in the user's language ("Viungo: …" in Swahili).
$ge->execute([$warehouse_id, (int)(restaurantIngredientsAccountId($pdo) ?: 0)]);
$gPrefixes = array_unique(['Ingredients', t('Ingredients')]);
foreach ($ge->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [
        'date' => $r['d'], 'item' => trim(preg_replace('/^(' . implode('|', array_map(fn($p) => preg_quote($p, '/'), $gPrefixes)) . '):\s*/iu', '', (string)$r['description'])), 'quantity' => null, 'unit' => '', 'kind' => 'grocery',
        'unit_cost' => null, 'total' => (float)$r['amount'], 'reference' => $r['ref'], 'by' => $r['by_name'], '_s' => 1000000000 + (int)$r['expense_id'],
    ];
}
usort($rows, fn($a, $b) => [$b['date'], $b['_s']] <=> [$a['date'], $a['_s']]);
$rows = array_slice($rows, 0, 100);
foreach ($rows as &$row) unset($row['_s']);
unset($row);
echo json_encode(['success' => true, 'data' => $rows]);
