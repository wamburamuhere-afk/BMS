<?php
/**
 * API: live Tables board for one restaurant shop (restaurant_plan.md Phase 2b).
 * GET: warehouse_id → tables with area, status and the table's open order
 *      (the one 'held' pos_held_sales row — hold_sale.php keeps one per table).
 * Gate: Restaurant POS + Smart POS on, shop is Restaurant/Hybrid, warehouse scope.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }

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
        SELECT t.table_id, t.table_number, t.seats, t.status, f.name AS area,
               h.hold_id, h.total_amount, h.items_data, h.held_at,
               TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS served_by,
               TIMESTAMPDIFF(MINUTE, h.held_at, NOW()) AS minutes_open
          FROM restaurant_tables t
     LEFT JOIN restaurant_floors f ON f.floor_id = t.floor_id
     LEFT JOIN pos_held_sales h ON h.hold_id = (
                SELECT MAX(h2.hold_id) FROM pos_held_sales h2 WHERE h2.table_id = t.table_id AND h2.status = 'held')
     LEFT JOIN users u ON u.user_id = h.user_id
         WHERE t.warehouse_id = ?
      ORDER BY COALESCE(f.sort_order, 0), f.floor_id, LENGTH(t.table_number), t.table_number
    ");
    $st->execute([$warehouse_id]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items = $r['hold_id'] ? (json_decode((string)$r['items_data'], true) ?: []) : [];
        $count = 0;
        foreach ($items as $it) $count += (float)($it['quantity'] ?? 1);
        $rows[] = [
            'table_id'     => (int)$r['table_id'],
            'table_number' => $r['table_number'],
            'seats'        => (int)$r['seats'],
            'area'         => $r['area'] === 'Main Area' ? t('Main Area') : $r['area'],
            'status'       => $r['hold_id'] ? 'occupied' : ($r['status'] === 'occupied' ? 'available' : $r['status']),
            'hold_id'      => $r['hold_id'] ? (int)$r['hold_id'] : null,
            'total'        => (float)$r['total_amount'],
            'item_count'   => $count,
            'minutes_open' => $r['hold_id'] ? (int)$r['minutes_open'] : null,
            'served_by'    => $r['hold_id'] ? $r['served_by'] : null,
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    error_log('get_tables_board: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
