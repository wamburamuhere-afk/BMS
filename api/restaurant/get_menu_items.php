<?php
/**
 * API: Menu items of one restaurant shop (restaurant_plan.md Phase 1).
 * GET: warehouse_id
 * Items = active products flagged is_menu_item for this shop: dishes / items
 * created here carry products.warehouse_id = shop; bought items appear too when
 * the shop holds stock of them.
 * Gate: restaurant module on (Restaurant POS + Smart POS) + this shop is
 * Restaurant/Hybrid + warehouse scope.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }

global $pdo;
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
    exit;
}
if (!restaurantShopActive($pdo, $warehouse_id)) {
    echo json_encode(['success' => false, 'message' => t('This shop is not set up as a restaurant.')]);
    exit;
}

try {
    $st = $pdo->prepare("
        SELECT p.product_id, p.product_name, p.category_id, c.category_name,
               p.selling_price, p.cost_price, p.is_service, p.is_available, p.image_url,
               p.kitchen_station_id, ks.name AS station_name,
               COALESCE((SELECT SUM(ps.stock_quantity) FROM product_stocks ps
                          WHERE ps.product_id = p.product_id AND ps.warehouse_id = :w1), 0) AS stock
          FROM products p
     LEFT JOIN categories c        ON c.category_id = p.category_id
     LEFT JOIN kitchen_stations ks ON ks.station_id = p.kitchen_station_id
         WHERE p.status = 'active' AND p.is_menu_item = 1
           AND (p.warehouse_id = :w2
                OR EXISTS (SELECT 1 FROM product_stocks ps2 WHERE ps2.product_id = p.product_id AND ps2.warehouse_id = :w3))
      ORDER BY COALESCE(c.sort_order, 999), c.category_name, p.product_name
    ");
    $st->execute([':w1' => $warehouse_id, ':w2' => $warehouse_id, ':w3' => $warehouse_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['product_id']    = (int)$r['product_id'];
        $r['is_service']    = (int)$r['is_service'];
        $r['is_available']  = (int)$r['is_available'];
        $r['selling_price'] = (float)$r['selling_price'];
        $r['cost_price']    = (float)$r['cost_price'];
        $r['stock']         = (float)$r['stock'];
        $r['kitchen_station_id'] = $r['kitchen_station_id'] !== null ? (int)$r['kitchen_station_id'] : null;
        // Auto-created areas are stored in English: give the form a stable key and show them translated.
        $r['station_key'] = $r['station_name'] === 'Kitchen' ? 'kitchen' : ($r['station_name'] === 'Bar' ? 'bar' : null);
        if ($r['station_key'] !== null) $r['station_name'] = t($r['station_name']);
    }
    unset($r);
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    error_log('get_menu_items: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
