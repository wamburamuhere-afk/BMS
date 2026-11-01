<?php
/**
 * API: restaurant quick start (restaurant_plan.md Phase 1c, "Getting started").
 * POST: warehouse_id, action
 *   action=tables  count=N         → creates tables T1…TN in the shop's default area (existing numbers skipped)
 *   action=menu    items=JSON      → [{name, price, type: dish|bought, stock}] via restaurantCreateMenuItem();
 *                                    names that already exist are skipped, not failed
 * Gate: Restaurant POS + Smart POS on, shop is Restaurant/Hybrid, canEdit('restaurant_pos').
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if (!canEdit('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;
$uid          = (int)$_SESSION['user_id'];
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
$action       = (string)($_POST['action'] ?? '');

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
    if ($action === 'tables') {
        $n = (int)($_POST['count'] ?? 0);
        if ($n < 1 || $n > 100) throw new Exception(t('Enter a number of tables between 1 and 100.'));
        $pdo->beginTransaction();
        $floorId = restaurantDefaultFloorId($pdo, $warehouse_id);
        $exists  = $pdo->prepare("SELECT 1 FROM restaurant_tables WHERE warehouse_id = ? AND table_number = ?");
        $ins     = $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status, created_at, updated_at) VALUES (?, ?, ?, 4, 'available', NOW(), NOW())");
        $made = 0;
        for ($i = 1; $i <= $n; $i++) {
            $num = 'T' . $i;
            $exists->execute([$warehouse_id, $num]);
            if ($exists->fetchColumn()) continue;
            $ins->execute([$warehouse_id, $floorId, $num]);
            $made++;
        }
        $pdo->commit();
        logActivity($pdo, $uid, "Restaurant quick start: created $made table(s)");
        echo json_encode(['success' => true, 'message' => sprintf(t('%d table(s) added.'), $made), 'created' => $made]);
        exit;
    }

    if ($action === 'menu') {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        if (!is_array($items) || !$items) throw new Exception(t('Tick at least one item.'));
        $pdo->beginTransaction();
        $made = 0; $skipped = [];
        $dup = $pdo->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))");
        foreach (array_slice($items, 0, 50) as $it) {
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '') continue;
            $dup->execute([$name]);
            if ((int)$dup->fetchColumn() > 0) { $skipped[] = $name; continue; }
            $type = ($it['type'] ?? 'dish') === 'bought' ? 'bought' : 'dish';
            restaurantCreateMenuItem($pdo, $warehouse_id, [
                'type'    => $type,
                'name'    => $name,
                'price'   => (float)($it['price'] ?? 0),
                'cost'    => (float)($it['cost'] ?? 0),
                'opening' => (float)($it['stock'] ?? 0),
                'prep_area' => $type === 'dish' ? 'kitchen' : 'none',
            ], $uid);
            $made++;
        }
        $pdo->commit();
        logActivity($pdo, $uid, "Restaurant quick start: added $made menu item(s)");
        $msg = sprintf(t('%d item(s) added to the menu.'), $made);
        if ($skipped) $msg .= ' ' . sprintf(t('Already there: %s.'), implode(', ', $skipped));
        echo json_encode(['success' => true, 'message' => $msg, 'created' => $made, 'skipped' => $skipped]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => t('Unknown action.')]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) {
        error_log('restaurant quick_setup: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('Database error.')]);
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
