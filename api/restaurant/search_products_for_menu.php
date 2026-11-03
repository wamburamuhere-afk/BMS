<?php
/**
 * API: Select2 search — active products NOT yet on the menu (restaurant_plan.md Phase 1,
 * "Already in my products"). GET: q, warehouse_id → {results: [{id, text}]}
 * Gate: Restaurant POS + Smart POS on, shop is Restaurant/Hybrid.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated() || !canView('restaurant_pos')) { http_response_code(403); echo json_encode(['results' => []]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['results' => []]); exit; }

global $pdo;
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id) || !restaurantShopActive($pdo, $warehouse_id)) {
    echo json_encode(['results' => []]);
    exit;
}
$q = trim((string)($_GET['q'] ?? ''));
$st = $pdo->prepare("SELECT product_id, product_name, selling_price FROM products
                      WHERE status = 'active' AND is_menu_item = 0 AND parent_product_id IS NULL
                        AND product_name LIKE ?
                   ORDER BY product_name LIMIT 30");
$st->execute(['%' . $q . '%']);
$out = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $out[] = ['id' => (int)$r['product_id'], 'text' => $r['product_name'] . ' — ' . number_format((float)$r['selling_price'])];
}
echo json_encode(['results' => $out]);
