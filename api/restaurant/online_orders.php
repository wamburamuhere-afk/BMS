<?php
/**
 * API: customer online orders, staff side (restaurant_online_menu_plan.md Phase 5).
 *
 * GET  action=list  warehouse_id      → new / in-progress / done-today orders of one shop
 * GET  action=count                   → pending orders across the shops this user may serve (alert badge)
 * POST action=accept   order_id       → table order: added to that table's open order (the same
 *                                       held order a waiter uses); pickup: a new held order for this
 *                                       staff member. Reply says whether to send it to the kitchen.
 * POST action=reject   order_id, reason
 * POST action=ready    order_id
 * POST action=complete order_id
 *
 * Gate: Restaurant module on, canCreate('pos') (taking an order), warehouse scope,
 * shop is Restaurant/Hybrid. POST needs CSRF. A status only moves forward
 * (pending → accepted → ready → completed; pending → rejected), checked under a
 * row lock so two staff pressing at once cannot both accept.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) loadLanguage($_SESSION['user_lang']);
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/restaurant_online.php';

$out = function (int $code, array $b) { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; };

if (!isAuthenticated())         $out(401, ['success' => false, 'message' => t('Unauthorized')]);
if (!canView('restaurant_pos')) $out(403, ['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]);
if (!canCreate('pos'))          $out(403, ['success' => false, 'message' => t('Permission denied')]);
if (!restaurantSchemaReady($pdo) || !restaurantModuleOn($pdo) || !roSchemaReady($pdo)) $out(200, ['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? 'list'));
$uid    = (int)$_SESSION['user_id'];
$denied = fn() => $out(403, ['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);

/** Orders as the staff screen shows them. */
$present = function (array $o): array {
    $lines = [];
    foreach ((array)json_decode((string)$o['items_json'], true) as $it) {
        $lines[] = [
            'name'    => (string)($it['product_name'] ?? ''),
            'qty'     => (int)($it['quantity'] ?? 0),
            'price'   => (float)($it['price'] ?? 0),
            'options' => array_values(array_map(fn($m) => (string)($m['option_name'] ?? ''), (array)($it['modifiers'] ?? []))),
            'note'    => (string)($it['note'] ?? ''),
        ];
    }
    return [
        'order_id' => (int)$o['order_id'], 'code' => (string)$o['order_code'], 'type' => (string)$o['order_type'],
        'table'    => $o['table_number'] !== null ? (string)$o['table_number'] : null,
        'name'     => (string)($o['customer_name'] ?? ''), 'phone' => (string)($o['customer_phone'] ?? ''),
        'note'     => (string)($o['note'] ?? ''), 'subtotal' => (float)$o['subtotal'], 'status' => (string)$o['status'],
        'reason'   => (string)($o['reject_reason'] ?? ''), 'created_at' => (string)$o['created_at'],
        'minutes'  => (int)$o['minutes'], 'handled_by' => (string)($o['handled_name'] ?? ''), 'lines' => $lines,
    ];
};

try {
    if ($method === 'GET' && $action === 'count') {
        $ids = array_values(array_filter(array_map(fn($w) => (int)$w['warehouse_id'], restaurantWarehousesForSelect($pdo)),
                                         fn($id) => userCan('warehouse', $id)));
        if (!$ids) $out(200, ['success' => true, 'pending' => 0, 'latest' => null]);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT o.order_id, o.order_code, o.order_type, t.table_number
                               FROM restaurant_online_orders o LEFT JOIN restaurant_tables t ON t.table_id = o.table_id
                              WHERE o.status = 'pending' AND o.warehouse_id IN ($ph) ORDER BY o.order_id DESC");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $latest = $rows ? ['order_id' => (int)$rows[0]['order_id'], 'code' => (string)$rows[0]['order_code'],
                           'table' => $rows[0]['table_number'] !== null ? (string)$rows[0]['table_number'] : null] : null;
        $out(200, ['success' => true, 'pending' => count($rows), 'latest' => $latest]);
    }

    if ($method === 'GET' && $action === 'list') {
        $wid = (int)($_GET['warehouse_id'] ?? 0);
        if ($wid <= 0 || !userCan('warehouse', $wid)) $denied();
        if (!restaurantShopActive($pdo, $wid)) $out(200, ['success' => false, 'message' => t('This shop is not set up as a restaurant.')]);
        // Tidy-up: an order nobody finished is closed after 12 hours, so the screen never fills with yesterday.
        $pdo->prepare("UPDATE restaurant_online_orders SET status = 'completed' WHERE warehouse_id = ? AND status IN ('accepted','ready') AND created_at < NOW() - INTERVAL 12 HOUR")->execute([$wid]);
        $pdo->prepare("UPDATE restaurant_online_orders SET status = 'cancelled', reject_reason = ? WHERE warehouse_id = ? AND status = 'pending' AND created_at < NOW() - INTERVAL 12 HOUR")
            ->execute([t('Not confirmed in time.'), $wid]);
        // A table order whose lines are on no open (held) order of its table any more was paid
        // or closed at the till → done, so the customer's page stops saying "being prepared".
        $openT = $pdo->prepare("SELECT order_id, table_id FROM restaurant_online_orders WHERE warehouse_id = ? AND table_id IS NOT NULL AND status IN ('accepted','ready')");
        $openT->execute([$wid]);
        $heldSt = $pdo->prepare("SELECT items_data FROM pos_held_sales WHERE table_id = ? AND status = 'held'");
        $heldCache = [];
        foreach ($openT->fetchAll(PDO::FETCH_ASSOC) as $ot) {
            $tid = (int)$ot['table_id'];
            if (!isset($heldCache[$tid])) {
                $heldSt->execute([$tid]);
                $ids = [];
                foreach ($heldSt->fetchAll(PDO::FETCH_COLUMN) as $json) foreach ((array)json_decode((string)$json, true) as $it) if (!empty($it['online_order_id'])) $ids[(int)$it['online_order_id']] = true;
                $heldCache[$tid] = $ids;
            }
            if (!isset($heldCache[$tid][(int)$ot['order_id']])) {
                $pdo->prepare("UPDATE restaurant_online_orders SET status = 'completed' WHERE order_id = ? AND status IN ('accepted','ready')")->execute([(int)$ot['order_id']]);
            }
        }
        $st = $pdo->prepare("SELECT o.*, t.table_number, TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS minutes,
                                    TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS handled_name
                               FROM restaurant_online_orders o
                          LEFT JOIN restaurant_tables t ON t.table_id = o.table_id
                          LEFT JOIN users u ON u.user_id = o.handled_by
                              WHERE o.warehouse_id = ?
                                AND (o.status IN ('pending','accepted','ready') OR o.created_at >= CURDATE())
                           ORDER BY FIELD(o.status, 'pending','accepted','ready','completed','rejected','cancelled'), o.order_id DESC
                              LIMIT 200");
        $st->execute([$wid]);
        $out(200, ['success' => true, 'data' => array_map($present, $st->fetchAll(PDO::FETCH_ASSOC)),
                   'kitchen' => restaurantKitchenMode() !== 'none']);
    }

    if ($method !== 'POST') $out(405, ['success' => false, 'message' => t('Method not allowed')]);
    csrf_check();
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0 || !in_array($action, ['accept', 'reject', 'ready', 'complete'], true)) $out(400, ['success' => false, 'message' => t('Unknown action.')]);

    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT o.*, t.table_number FROM restaurant_online_orders o LEFT JOIN restaurant_tables t ON t.table_id = o.table_id
                          WHERE o.order_id = ? FOR UPDATE");
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) { $pdo->rollBack(); $out(404, ['success' => false, 'message' => t('Order not found.')]); }
    $wid = (int)$o['warehouse_id'];
    if (!userCan('warehouse', $wid)) { $pdo->rollBack(); $denied(); }
    if (!restaurantShopActive($pdo, $wid)) { $pdo->rollBack(); $out(200, ['success' => false, 'message' => t('This shop is not set up as a restaurant.')]); }

    $from = ['accept' => ['pending'], 'reject' => ['pending'], 'ready' => ['accepted'], 'complete' => ['accepted', 'ready']][$action];
    if (!in_array($o['status'], $from, true)) {
        $pdo->rollBack();
        $out(409, ['success' => false, 'message' => t('This order was already handled. The list has been refreshed.'), 'refresh' => true]);
    }
    $code = (string)$o['order_code'];

    if ($action === 'reject') {
        $reason = roCleanText($_POST['reason'] ?? '', 200);
        if ($reason === '') $reason = t('Sorry, we cannot take this order right now.');
        $pdo->prepare("UPDATE restaurant_online_orders SET status = 'rejected', reject_reason = ?, handled_by = ? WHERE order_id = ?")->execute([$reason, $uid, $orderId]);
        $pdo->commit();
        logActivity($pdo, $uid, "Rejected online order $code");
        $out(200, ['success' => true, 'message' => t('Order rejected. The customer can see the reason.')]);
    }
    if ($action === 'ready' || $action === 'complete') {
        $to = $action === 'ready' ? 'ready' : 'completed';
        $pdo->prepare("UPDATE restaurant_online_orders SET status = ?, handled_by = COALESCE(handled_by, ?) WHERE order_id = ?")->execute([$to, $uid, $orderId]);
        $pdo->commit();
        $out(200, ['success' => true, 'message' => $to === 'ready' ? t('Marked ready. The customer can see it.') : t('Order done.')]);
    }

    // ── accept ──
    $items = (array)json_decode((string)$o['items_json'], true);
    if (!$items) { $pdo->rollBack(); $out(422, ['success' => false, 'message' => t('This order has no items.')]); }
    // Mark each line with its online order, so a till saving an older copy of the
    // table cannot drop it (api/pos/hold_sale.php puts such lines back).
    foreach ($items as &$it) { $it['online_order_id'] = $orderId; }
    unset($it);
    $subtotal = (float)$o['subtotal'];
    $tableId  = $o['table_id'] !== null ? (int)$o['table_id'] : null;

    // A held order needs a shift unless the column allows none (table orders, restaurant simplicity pass).
    $shiftSt = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active' LIMIT 1");
    $shiftSt->execute([$uid]);
    $shiftId = $shiftSt->fetchColumn() ?: null;
    $shiftCol = $pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'shift_id'")->fetch(PDO::FETCH_ASSOC);
    $shiftNullable = $shiftCol && strtoupper((string)$shiftCol['Null']) === 'YES';

    $holdId = null;
    if ($tableId) {
        $hs = $pdo->prepare("SELECT hold_id, user_id, shift_id, customer_id, hold_reference, items_data, subtotal, tax_amount FROM pos_held_sales
                              WHERE table_id = ? AND warehouse_id = ? AND status = 'held' ORDER BY held_at DESC, hold_id DESC LIMIT 1 FOR UPDATE");
        $hs->execute([$tableId, $wid]);
        $h = $hs->fetch(PDO::FETCH_ASSOC);
        if ($h) {
            // The table already has an order: add these lines to it (the waiter sees one bill).
            // Saved as a NEW version (old row cancelled, same as hold_sale.php does), so a till
            // still showing the old version is recognised as out of date when it saves.
            $merged = array_merge((array)json_decode((string)$h['items_data'], true), $items);
            $newSub = round((float)$h['subtotal'] + $subtotal, 2);
            $pdo->prepare("UPDATE pos_held_sales SET status = 'cancelled' WHERE table_id = ? AND status = 'held'")->execute([$tableId]);
            $pdo->prepare("INSERT INTO pos_held_sales (user_id, shift_id, customer_id, warehouse_id, table_id, hold_reference, items_data, subtotal, tax_amount, total_amount, held_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([(int)$h['user_id'], $h['shift_id'], $h['customer_id'], $wid, $tableId, $h['hold_reference'],
                           json_encode($merged, JSON_UNESCAPED_UNICODE), $newSub, (float)$h['tax_amount'], round($newSub + (float)$h['tax_amount'], 2)]);
            $holdId = (int)$pdo->lastInsertId();
        }
    }
    if ($holdId === null) {
        if (!$shiftId && !($shiftNullable && ($tableId || restaurantShopActive($pdo, $wid)))) {
            $pdo->rollBack();
            $out(422, ['success' => false, 'message' => t('Please start a cash register shift before accepting this order.')]);
        }
        $ref = 'ONLINE ' . $code . (!$tableId && $o['customer_name'] ? ' · ' . mb_substr((string)$o['customer_name'], 0, 30) : '');
        $pdo->prepare("INSERT INTO pos_held_sales (user_id, shift_id, customer_id, warehouse_id, table_id, hold_reference, items_data, subtotal, tax_amount, total_amount, held_at)
                       VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 0, ?, NOW())")
            ->execute([$uid, $shiftId, $wid, $tableId, $ref, json_encode($items, JSON_UNESCAPED_UNICODE), $subtotal, $subtotal]);
        $holdId = (int)$pdo->lastInsertId();
    }
    if ($tableId) {
        $pdo->prepare("UPDATE restaurant_tables SET status = 'occupied', updated_at = NOW() WHERE table_id = ?")->execute([$tableId]);
    }
    $pdo->prepare("UPDATE restaurant_online_orders SET status = 'accepted', hold_id = ?, handled_by = ?, accepted_at = NOW() WHERE order_id = ?")
        ->execute([$holdId, $uid, $orderId]);
    $pdo->commit();

    logActivity($pdo, $uid, "Accepted online order $code" . ($tableId ? ' for table ' . $o['table_number'] : ' (pickup)'));
    $out(200, [
        'success'      => true,
        'message'      => $tableId ? sprintf(t('Accepted. Added to table %s.'), $o['table_number']) : t('Accepted. It is in your held orders on the till.'),
        'hold_id'      => $holdId,
        'warehouse_id' => $wid,
        'kitchen'      => restaurantKitchenMode() !== 'none',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('online_orders: ' . $e->getMessage());
    $out(500, ['success' => false, 'message' => t('Database error.')]);
}
