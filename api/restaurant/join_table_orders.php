<?php
/**
 * API: join two tables' open orders into one bill (restaurant_plan.md Phase 4).
 * POST: warehouse_id, from_table_id, to_table_id
 * The "from" table's items are added to the "to" table's open order (totals recalculated),
 * the "from" order is closed (cancelled — its items now live on the "to" order), its open
 * kitchen tickets follow, and the "from" table becomes free. Both tables must have an open
 * order (to move an order onto a free table use move_table_order.php).
 * Gate: Restaurant POS + Smart POS on, Restaurant/Hybrid shop, canCreate('pos').
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
if (!canCreate('pos'))          { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
$from = (int)($_POST['from_table_id'] ?? 0);
$to   = (int)($_POST['to_table_id'] ?? 0);

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
    if ($from <= 0 || $to <= 0 || $from === $to) throw new Exception(t('Choose another table.'));
    $tbl = $pdo->prepare("SELECT table_number FROM restaurant_tables WHERE table_id = ? AND warehouse_id = ?");
    $tbl->execute([$from, $warehouse_id]); $fromNum = $tbl->fetchColumn();
    $tbl->execute([$to, $warehouse_id]);   $toNum   = $tbl->fetchColumn();
    if ($fromNum === false || $toNum === false) throw new Exception(t('Table not found.'));

    $openSt = $pdo->prepare("SELECT * FROM pos_held_sales WHERE table_id = ? AND status = 'held' ORDER BY hold_id DESC LIMIT 1");
    $openSt->execute([$from]); $a = $openSt->fetch(PDO::FETCH_ASSOC);
    $openSt->execute([$to]);   $b = $openSt->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new Exception(t('This table has no open order.'));
    if (!$b) throw new Exception(t('That table has no open order. Use Move instead.'));

    $items = array_merge(json_decode((string)$b['items_data'], true) ?: [], json_decode((string)$a['items_data'], true) ?: []);
    $sub = 0.0; $tax = 0.0;
    foreach ($items as $it) {
        $sub += (float)($it['price'] ?? 0) * (float)($it['quantity'] ?? 0);
        $tax += (float)($it['discounted_price'] ?? $it['price'] ?? 0) * (float)($it['quantity'] ?? 0) * ((float)($it['tax_rate'] ?? 0) / 100);
    }

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE pos_held_sales SET items_data = ?, item_count = ?, subtotal = ?, tax_amount = ?, total_amount = ? WHERE hold_id = ?")
        ->execute([json_encode($items), count($items), $sub, $tax, $sub + $tax, $b['hold_id']]);
    $pdo->prepare("UPDATE pos_held_sales SET status = 'cancelled' WHERE hold_id = ?")->execute([$a['hold_id']]);
    $pdo->prepare("UPDATE kitchen_tickets SET table_id = ?, updated_at = NOW() WHERE table_id = ? AND status IN ('queued','preparing','ready')")->execute([$to, $from]);
    $pdo->prepare("UPDATE restaurant_tables SET status = 'available', updated_at = NOW() WHERE table_id = ?")->execute([$from]);
    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Joined table $fromNum into table $toNum");
    echo json_encode(['success' => true, 'message' => sprintf(t('Table %1$s joined into table %2$s.'), $fromNum, $toNum)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) {
        error_log('join_table_orders: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('Database error.')]);
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
