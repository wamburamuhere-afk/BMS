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
 * POST action=cancel   order_id, reason   → pickup order accepted but never paid/collected
 * GET  action=customer_hint order_id      → customers already using the buyer's phone under another name
 *
 * Pickup accept links a customer (reused only when name AND phone match — core/online_order_record.php);
 * the till closes the order itself when the sale is completed. Retail online-shop orders share the
 * table but are never shown or handled here (order_type table / pickup only).
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
require_once __DIR__ . '/../../core/online_order_record.php';
require_once __DIR__ . '/../../core/restaurant_order_flow.php';

const RO_STAFF_TYPES = "('table','pickup')";   // restaurant orders only — retail shop orders have their own screen

$out = function (int $code, array $b) { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; };

if (!isAuthenticated())         $out(401, ['success' => false, 'message' => t('Unauthorized')]);
if (!canView('restaurant_pos')) $out(403, ['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]);
// restaurant_order_flow_plan.md — accepting an order is taking an order: the waiter may too.
if (!rofCanTake($pdo))          $out(403, ['success' => false, 'message' => t('Permission denied')]);
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
        'cancelled_by' => (string)($o['cancelled_by'] ?? ''),
        'customer_id'  => isset($o['customer_id']) ? (int)$o['customer_id'] : null,
        'customer'     => (string)($o['linked_customer'] ?? ''),
        'receipt'      => (string)($o['receipt_number'] ?? ''),
        'sale_id'      => isset($o['sale_id']) ? (int)$o['sale_id'] : null,
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
                              WHERE o.status = 'pending' AND o.order_type IN " . RO_STAFF_TYPES . " AND o.warehouse_id IN ($ph) ORDER BY o.order_id DESC");
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
        // Tidy-up, so the screen never fills with yesterday: a TABLE order nobody finished is closed after
        // 12 hours (its bill lives on the table). A PICKUP order is never closed silently — it waits for
        // its sale at the till or for "Cancel – not collected" (a reminder alert fires meanwhile).
        $pdo->prepare("UPDATE restaurant_online_orders SET status = 'completed', completed_at = COALESCE(completed_at, NOW())
                        WHERE warehouse_id = ? AND order_type = 'table' AND status IN ('accepted','ready') AND created_at < NOW() - INTERVAL 12 HOUR")->execute([$wid]);
        $pdo->prepare("UPDATE restaurant_online_orders SET status = 'cancelled', cancelled_by = 'system', reject_reason = ?
                        WHERE warehouse_id = ? AND order_type IN " . RO_STAFF_TYPES . " AND status = 'pending' AND created_at < NOW() - INTERVAL 12 HOUR")
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
                $pdo->prepare("UPDATE restaurant_online_orders SET status = 'completed', completed_at = COALESCE(completed_at, NOW()) WHERE order_id = ? AND status IN ('accepted','ready')")->execute([(int)$ot['order_id']]);
            }
        }
        $st = $pdo->prepare("SELECT o.*, t.table_number, TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS minutes,
                                    TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS handled_name,
                                    c.customer_name AS linked_customer, s.receipt_number
                               FROM restaurant_online_orders o
                          LEFT JOIN restaurant_tables t ON t.table_id = o.table_id
                          LEFT JOIN users u ON u.user_id = o.handled_by
                          LEFT JOIN customers c ON c.customer_id = o.customer_id
                          LEFT JOIN pos_sales s ON s.sale_id = o.sale_id
                              WHERE o.warehouse_id = ? AND o.order_type IN " . RO_STAFF_TYPES . "
                                AND (o.status IN ('pending','accepted','ready') OR o.created_at >= CURDATE())
                           ORDER BY FIELD(o.status, 'pending','accepted','ready','completed','rejected','cancelled'), o.order_id DESC
                              LIMIT 200");
        $st->execute([$wid]);
        $out(200, ['success' => true, 'data' => array_map($present, $st->fetchAll(PDO::FETCH_ASSOC)),
                   'kitchen' => restaurantKitchenMode() !== 'none']);
    }

    if ($method === 'GET' && $action === 'customer_hint') {
        $hid = (int)($_GET['order_id'] ?? 0);
        $hs = $pdo->prepare("SELECT warehouse_id, customer_name, customer_phone, order_type FROM restaurant_online_orders WHERE order_id = ? AND order_type IN " . RO_STAFF_TYPES);
        $hs->execute([$hid]);
        $h = $hs->fetch(PDO::FETCH_ASSOC);
        if (!$h) $out(404, ['success' => false, 'message' => t('Order not found.')]);
        if (!userCan('warehouse', (int)$h['warehouse_id'])) $denied();
        $out(200, ['success' => true, 'data' => $h['order_type'] === 'pickup' ? ocPhoneHint($pdo, (string)$h['customer_name'], (string)$h['customer_phone']) : []]);
    }

    if ($method !== 'POST') $out(405, ['success' => false, 'message' => t('Method not allowed')]);
    csrf_check();
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0 || !in_array($action, ['accept', 'reject', 'ready', 'complete', 'cancel'], true)) $out(400, ['success' => false, 'message' => t('Unknown action.')]);

    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT o.*, t.table_number FROM restaurant_online_orders o LEFT JOIN restaurant_tables t ON t.table_id = o.table_id
                          WHERE o.order_id = ? AND o.order_type IN " . RO_STAFF_TYPES . " FOR UPDATE");
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) { $pdo->rollBack(); $out(404, ['success' => false, 'message' => t('Order not found.')]); }
    $wid = (int)$o['warehouse_id'];
    if (!userCan('warehouse', $wid)) { $pdo->rollBack(); $denied(); }
    if (!restaurantShopActive($pdo, $wid)) { $pdo->rollBack(); $out(200, ['success' => false, 'message' => t('This shop is not set up as a restaurant.')]); }

    $from = ['accept' => ['pending'], 'reject' => ['pending'], 'ready' => ['accepted'], 'complete' => ['accepted', 'ready'], 'cancel' => ['accepted', 'ready']][$action];
    if (!in_array($o['status'], $from, true)) {
        $pdo->rollBack();
        $out(409, ['success' => false, 'message' => t('This order was already handled. The list has been refreshed.'), 'refresh' => true]);
    }
    $code = (string)$o['order_code'];
    $audit = function (string $verb, string $to, array $extra = []) use ($pdo, $uid, $orderId, $o): void {
        logAudit($pdo, $uid, 'online_order_' . $verb, [
            'entity_type' => 'online_order', 'entity_id' => $orderId,
            'old_values'  => ['status' => $o['status']],
            'new_values'  => array_merge(['status' => $to, 'order_code' => $o['order_code']], $extra),
        ]);
    };

    if ($action === 'reject') {
        $reason = roCleanText($_POST['reason'] ?? '', 200);
        if ($reason === '') $reason = t('Sorry, we cannot take this order right now.');
        $pdo->prepare("UPDATE restaurant_online_orders SET status = 'rejected', reject_reason = ?, handled_by = ? WHERE order_id = ?")->execute([$reason, $uid, $orderId]);
        $pdo->commit();
        logActivity($pdo, $uid, "Rejected online order $code");
        $audit('reject', 'rejected', ['reason' => $reason]);
        $out(200, ['success' => true, 'message' => t('Order rejected. The customer can see the reason.')]);
    }
    if ($action === 'cancel') {
        $reason = roCleanText($_POST['reason'] ?? '', 200);
        if ($reason === '') $reason = t('Not collected.');
        try {
            oorCancelNotCollected($pdo, $o, $reason, $uid);
            // Its food stops in the kitchen (cooked already → recorded as waste).
            rofOnPickupCancelled($pdo, (int)($o['hold_id'] ?? 0), $uid, $reason);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $out(422, ['success' => false, 'message' => $e->getMessage()]);
        }
        $pdo->commit();
        logActivity($pdo, $uid, "Cancelled online order $code as not collected");
        $audit('cancel', 'cancelled', ['reason' => $reason, 'cancelled_by' => 'staff']);
        $out(200, ['success' => true, 'message' => t('Order cancelled. It was removed from the till and the customer can see the reason.')]);
    }
    if ($action === 'ready' || $action === 'complete') {
        $to = $action === 'ready' ? 'ready' : 'completed';
        $pdo->prepare("UPDATE restaurant_online_orders SET status = ?, handled_by = COALESCE(handled_by, ?)" . ($to === 'completed' ? ", completed_at = NOW()" : '') . " WHERE order_id = ?")
            ->execute([$to, $uid, $orderId]);
        $pdo->commit();
        $audit($action, $to);
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

    // Pickup: customer matched/created (both name AND phone must match to reuse), held order linked to
    // that customer and to this online order, so any staff at the till can load and complete it.
    if (!$tableId) {
        try {
            $acc = oorAcceptToHeld($pdo, $o, $uid, (int)($_POST['link_customer_id'] ?? 0));
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $out(422, ['success' => false, 'message' => $e->getMessage()]);
        }
        $pdo->commit();
        logActivity($pdo, $uid, "Accepted online order $code (pickup)" . ($acc['customer_id'] ? ($acc['customer_created'] ? ' — new customer ' : ' — customer ') . $acc['customer_name'] : ''));
        $audit('accept', 'accepted', ['customer_id' => $acc['customer_id'], 'customer_created' => $acc['customer_created'], 'hold_id' => $acc['hold_id']]);
        $out(200, [
            'success'          => true,
            'message'          => t('Accepted. It is in Held orders on the till.'),
            'hold_id'          => $acc['hold_id'],
            'warehouse_id'     => $wid,
            'customer_created' => $acc['customer_created'],
            'kitchen'          => restaurantKitchenMode() !== 'none',
        ]);
    }

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
    $audit('accept', 'accepted', ['table_id' => $tableId, 'hold_id' => $holdId]);
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
