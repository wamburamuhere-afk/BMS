<?php
/**
 * API: online shop orders, staff side — Smart POS retail (retail_online_shop_plan.md Phase 5).
 *
 * GET  action=list  warehouse_id      → waiting / in progress / done-today orders of one shop
 * GET  action=count                   → waiting orders across the shops this user may serve (alert badge)
 * GET  action=customer_hint order_id  → customers already using the buyer's phone under another name
 * POST action=accept  order_id [, link_customer_id] → stock re-checked, customer matched/created,
 *                                       a held order made for the till (any staff of the shop can load it)
 * POST action=reject  order_id, reason
 * POST action=ready   order_id        → ready for collection / on the way (the buyer sees it)
 * POST action=cancel  order_id, reason → accepted but never paid / collected
 *
 * There is no "done" button: the order closes itself when the cashier completes its sale at the
 * till (core/online_order_record.php). Gate: Smart POS on, canCreate('pos'), warehouse scope.
 * POST needs CSRF. A status only moves forward, checked under a row lock.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) loadLanguage($_SESSION['user_lang']);
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/retail_online.php';
require_once __DIR__ . '/../../core/online_order_record.php';

const RS_STAFF_TYPES = "('shop_pickup','shop_delivery')";

$out = function (int $code, array $b) { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; };

if (!isAuthenticated())      $out(401, ['success' => false, 'message' => t('Unauthorized')]);
if (!canCreate('pos'))       $out(403, ['success' => false, 'message' => t('Permission denied')]);
if (!posSimpleModeEnabled()) $out(403, ['success' => false, 'message' => t('The online shop is available in Smart POS.')]);
if (!rsSchemaReady($pdo))    $out(200, ['success' => false, 'message' => t('The online shop is being set up for your account — please check back shortly.')]);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? 'list'));
$uid    = (int)$_SESSION['user_id'];
$denied = fn() => $out(403, ['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);

$present = function (array $o): array {
    $lines = [];
    foreach ((array)json_decode((string)$o['items_json'], true) as $it) {
        $lines[] = ['name' => (string)($it['product_name'] ?? ''), 'qty' => (float)($it['quantity'] ?? 0),
                    'unit' => (string)($it['unit'] ?? ''), 'price' => (float)($it['price'] ?? 0)];
    }
    return [
        'order_id' => (int)$o['order_id'], 'code' => (string)$o['order_code'],
        'type'     => $o['order_type'] === 'shop_delivery' ? 'delivery' : 'pickup',
        'name'     => (string)($o['customer_name'] ?? ''), 'phone' => (string)($o['customer_phone'] ?? ''),
        'address'  => (string)($o['delivery_address'] ?? ''), 'note' => (string)($o['note'] ?? ''),
        'price_type' => (string)$o['price_type'], 'subtotal' => (float)$o['subtotal'], 'status' => (string)$o['status'],
        'reason'   => (string)($o['reject_reason'] ?? ''), 'cancelled_by' => (string)($o['cancelled_by'] ?? ''),
        'created_at' => (string)$o['created_at'], 'minutes' => (int)$o['minutes'],
        'handled_by' => (string)($o['handled_name'] ?? ''), 'customer' => (string)($o['linked_customer'] ?? ''),
        'receipt'  => (string)($o['receipt_number'] ?? ''), 'lines' => $lines,
    ];
};

try {
    if ($method === 'GET' && $action === 'count') {
        $ids = [];
        foreach ($pdo->query("SELECT warehouse_id FROM warehouses WHERE online_shop_enabled = 1 AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $w) {
            if (userCan('warehouse', (int)$w)) $ids[] = (int)$w;
        }
        if (!$ids) $out(200, ['success' => true, 'pending' => 0, 'latest' => null]);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT order_id, order_code, order_type, customer_name FROM restaurant_online_orders
                              WHERE status = 'pending' AND order_type IN " . RS_STAFF_TYPES . " AND warehouse_id IN ($ph) ORDER BY order_id DESC");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $latest = $rows ? ['order_id' => (int)$rows[0]['order_id'], 'code' => (string)$rows[0]['order_code'],
                           'type' => $rows[0]['order_type'] === 'shop_delivery' ? 'delivery' : 'pickup', 'name' => (string)$rows[0]['customer_name']] : null;
        $out(200, ['success' => true, 'pending' => count($rows), 'latest' => $latest]);
    }

    if ($method === 'GET' && $action === 'list') {
        $wid = (int)($_GET['warehouse_id'] ?? 0);
        if ($wid <= 0 || !userCan('warehouse', $wid)) $denied();
        rsExpireWaiting($pdo, $wid);
        $st = $pdo->prepare("SELECT o.*, TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS minutes,
                                    TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS handled_name,
                                    c.customer_name AS linked_customer, s.receipt_number
                               FROM restaurant_online_orders o
                          LEFT JOIN users u ON u.user_id = o.handled_by
                          LEFT JOIN customers c ON c.customer_id = o.customer_id
                          LEFT JOIN pos_sales s ON s.sale_id = o.sale_id
                              WHERE o.warehouse_id = ? AND o.order_type IN " . RS_STAFF_TYPES . "
                                AND (o.status IN ('pending','accepted','ready') OR o.created_at >= CURDATE() OR o.completed_at >= CURDATE())
                           ORDER BY FIELD(o.status, 'pending','accepted','ready','completed','rejected','cancelled'), o.order_id DESC
                              LIMIT 200");
        $st->execute([$wid]);
        $out(200, ['success' => true, 'data' => array_map($present, $st->fetchAll(PDO::FETCH_ASSOC))]);
    }

    if ($method === 'GET' && $action === 'customer_hint') {
        $hs = $pdo->prepare("SELECT warehouse_id, customer_name, customer_phone FROM restaurant_online_orders WHERE order_id = ? AND order_type IN " . RS_STAFF_TYPES);
        $hs->execute([(int)($_GET['order_id'] ?? 0)]);
        $h = $hs->fetch(PDO::FETCH_ASSOC);
        if (!$h) $out(404, ['success' => false, 'message' => t('Order not found.')]);
        if (!userCan('warehouse', (int)$h['warehouse_id'])) $denied();
        $out(200, ['success' => true, 'data' => ocPhoneHint($pdo, (string)$h['customer_name'], (string)$h['customer_phone'])]);
    }

    if ($method !== 'POST') $out(405, ['success' => false, 'message' => t('Method not allowed')]);
    csrf_check();
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0 || !in_array($action, ['accept', 'reject', 'ready', 'cancel'], true)) $out(400, ['success' => false, 'message' => t('Unknown action.')]);

    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT * FROM restaurant_online_orders WHERE order_id = ? AND order_type IN " . RS_STAFF_TYPES . " FOR UPDATE");
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) { $pdo->rollBack(); $out(404, ['success' => false, 'message' => t('Order not found.')]); }
    $wid = (int)$o['warehouse_id'];
    if (!userCan('warehouse', $wid)) { $pdo->rollBack(); $denied(); }
    $from = ['accept' => ['pending'], 'reject' => ['pending'], 'ready' => ['accepted'], 'cancel' => ['accepted', 'ready']][$action];
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
        logActivity($pdo, $uid, "Rejected online shop order $code");
        $audit('reject', 'rejected', ['reason' => $reason]);
        $out(200, ['success' => true, 'message' => t('Order rejected. The customer can see the reason.')]);
    }
    if ($action === 'cancel') {
        $reason = roCleanText($_POST['reason'] ?? '', 200);
        if ($reason === '') $reason = t('Not collected.');
        try { oorCancelNotCollected($pdo, $o, $reason, $uid); }
        catch (RuntimeException $e) { $pdo->rollBack(); $out(422, ['success' => false, 'message' => $e->getMessage()]); }
        $pdo->commit();
        logActivity($pdo, $uid, "Cancelled online shop order $code as not collected");
        $audit('cancel', 'cancelled', ['reason' => $reason, 'cancelled_by' => 'staff']);
        $out(200, ['success' => true, 'message' => t('Order cancelled. It was removed from the till and the customer can see the reason.')]);
    }
    if ($action === 'ready') {
        $pdo->prepare("UPDATE restaurant_online_orders SET status = 'ready', handled_by = COALESCE(handled_by, ?) WHERE order_id = ?")->execute([$uid, $orderId]);
        $pdo->commit();
        $audit('ready', 'ready');
        $out(200, ['success' => true, 'message' => $o['order_type'] === 'shop_delivery' ? t('Marked on the way. The customer can see it.') : t('Marked ready. The customer can see it.')]);
    }

    // ── accept: stock is re-checked now (it may have sold at the till since the order was sent) ──
    $items = (array)json_decode((string)$o['items_json'], true);
    if (!$items) { $pdo->rollBack(); $out(422, ['success' => false, 'message' => t('This order has no items.')]); }
    $need = [];
    foreach ($items as $it) $need[(int)$it['product_id']] = ($need[(int)$it['product_id']] ?? 0) + (float)$it['quantity'];
    $ph = implode(',', array_fill(0, count($need), '?'));
    $ss = $pdo->prepare("SELECT p.product_id, p.product_name, p.track_inventory, COALESCE(ps.available_quantity, 0) AS available
                           FROM products p LEFT JOIN product_stocks ps ON ps.product_id = p.product_id AND ps.warehouse_id = ?
                          WHERE p.product_id IN ($ph)");
    $ss->execute(array_merge([$wid], array_keys($need)));
    $short = [];
    foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $q = $need[(int)$p['product_id']] ?? 0;
        if ((int)$p['track_inventory'] === 1 && $q > (float)$p['available'] + 0.0000001) {
            $short[] = sprintf(t('%1$s (only %2$s left)'), $p['product_name'], rtrim(rtrim(number_format((float)$p['available'], 3, '.', ''), '0'), '.'));
        }
    }
    if ($short) {
        $pdo->rollBack();
        $out(422, ['success' => false, 'message' => t('Not enough stock to accept this order:') . ' ' . implode(', ', $short) . '. ' . t('Reject it or call the customer.')]);
    }
    try {
        $acc = oorAcceptToHeld($pdo, $o, $uid, (int)($_POST['link_customer_id'] ?? 0));
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        $out(422, ['success' => false, 'message' => $e->getMessage()]);
    }
    $pdo->commit();
    logActivity($pdo, $uid, "Accepted online shop order $code" . ($acc['customer_id'] ? ($acc['customer_created'] ? ' — new customer ' : ' — customer ') . $acc['customer_name'] : ''));
    $audit('accept', 'accepted', ['customer_id' => $acc['customer_id'], 'customer_created' => $acc['customer_created'], 'hold_id' => $acc['hold_id']]);
    $out(200, ['success' => true, 'message' => t('Accepted. It is in Held orders on the till.'), 'hold_id' => $acc['hold_id'],
               'warehouse_id' => $wid, 'customer_created' => $acc['customer_created']]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('shop_online_orders: ' . $e->getMessage());
    $out(500, ['success' => false, 'message' => t('Database error.')]);
}
