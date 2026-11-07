<?php
/**
 * API: restaurant order flow (restaurant_order_flow_plan.md Phase 3) — the till's kitchen list and the
 * cashier's "Orders to confirm". Rules live in core/restaurant_order_flow.php.
 *
 * GET  action=view     warehouse_id, table_id | hold_id → the order's kitchen lines + what this user may do
 * GET  action=pending  warehouse_id                     → orders waiting for the cashier (confirmers only)
 * GET  action=count                                     → number of waiting orders in this user's restaurant shops
 * POST action=confirm  warehouse_id, table_id | hold_id → the kitchen gets the order (returns ticket_ids for a slip)
 * POST action=return   warehouse_id, table_id | hold_id, reason → not confirmed; the waiter is told
 * POST action=received ticket_id                        → the waiter has the food (Served)
 * POST action=cancel_line  item_id, [qty], [reason]     → one kitchen line cancelled (and off the table's bill)
 * POST action=cancel_order warehouse_id, table_id, [reason] → the customer left before paying
 *
 * Refusals carry error_code (cancel_needs_reason, cancel_needs_approval, cancel_not_allowed, …).
 * Gate: Restaurant POS (plan) + Smart POS restaurant module + this shop in the user's scope; each action
 * checks its own right in the engine.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) loadLanguage($_SESSION['user_lang']);
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/restaurant_order_flow.php';

$out = function (int $code, array $b) { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; };

if (!isAuthenticated())         $out(401, ['success' => false, 'message' => t('Unauthorized')]);
if (!canView('restaurant_pos')) $out(403, ['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]);
if (!restaurantSchemaReady($pdo) || !restaurantModuleOn($pdo) || !rofReady($pdo)) {
    $out(200, ['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]);
}

global $pdo;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
$in     = $method === 'POST' ? $_POST : $_GET;
$uid    = (int)$_SESSION['user_id'];
$wid    = (int)($in['warehouse_id'] ?? 0);
$tid    = !empty($in['table_id']) ? (int)$in['table_id'] : null;
$hid    = !empty($in['hold_id']) ? (int)$in['hold_id'] : null;

$shopOk = function (int $w) use ($pdo): bool { return $w > 0 && userCan('warehouse', $w) && restaurantShopActive($pdo, $w); };
$denied = fn() => $out(403, ['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
$tableOk = function (?int $t, int $w) use ($pdo): bool {
    if (!$t) return true;
    $s = $pdo->prepare("SELECT 1 FROM restaurant_tables WHERE table_id = ? AND warehouse_id = ?");
    $s->execute([$t, $w]);
    return (bool)$s->fetchColumn();
};

if ($method === 'GET') {
    if ($action === 'count') {
        $ids = array_map(fn($w) => (int)$w['warehouse_id'], restaurantWarehousesForSelect($pdo));
        $out(200, ['success' => true, 'count' => rofCanConfirm($pdo) && rofConfirmRequired($pdo) ? rofPendingCount($pdo, $ids) : 0]);
    }
    if (!$shopOk($wid)) $denied();
    if ($action === 'view') {
        if ((!$tid && !$hid) || !$tableOk($tid, $wid)) $out(400, ['success' => false, 'message' => t('Invalid request.')]);
        $out(200, ['success' => true] + rofOrderView($pdo, $wid, $tid, $hid));
    }
    if ($action === 'pending') {
        if (!rofCanConfirm($pdo)) $out(403, ['success' => false, 'message' => t('Only the cashier can confirm orders.')]);
        $out(200, ['success' => true, 'data' => rofPendingOrders($pdo, $wid), 'kitchen_mode' => rofKitchenMode($pdo)]);
    }
    $out(400, ['success' => false, 'message' => t('Unknown action.')]);
}

if ($method !== 'POST') $out(405, ['success' => false, 'message' => t('Method not allowed')]);
csrf_check();
if (!in_array($action, ['confirm', 'return', 'received', 'cancel_line', 'cancel_order'], true)) $out(400, ['success' => false, 'message' => t('Unknown action.')]);

$pdo->beginTransaction();
try {
    $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 200);
    $res = ['success' => true];
    switch ($action) {
        case 'confirm':
        case 'return':
            if (!$shopOk($wid)) { $pdo->rollBack(); $denied(); }
            if ((!$tid && !$hid) || !$tableOk($tid, $wid)) throw new RofException(t('Invalid request.'), 'invalid');
            if ($action === 'confirm') {
                $res['ticket_ids'] = rofConfirm($pdo, $wid, $tid, $hid, $uid);
                $res['kitchen_mode'] = rofKitchenMode($pdo);
                $res['message'] = t('Order confirmed — the kitchen has it.');
            } else {
                $res['ticket_ids'] = rofReturn($pdo, $wid, $tid, $hid, $uid, $reason);
                $res['message'] = t('Order returned to the waiter.');
            }
            $log = ($action === 'confirm' ? 'Confirmed' : 'Returned') . ' kitchen order (' . ($tid ? "table #$tid" : "hold #$hid") . ')' . ($reason !== '' ? ": $reason" : '');
            break;
        case 'received':
            $ticket = (int)($in['ticket_id'] ?? 0);
            rofAdvance($pdo, $ticket, 'served', $uid);
            $res['message'] = t('Marked received.');
            $log = "Kitchen ticket #$ticket received by the waiter";
            break;
        case 'cancel_line':
            $item = (int)($in['item_id'] ?? 0);
            $r = rofCancelLine($pdo, $item, (float)($in['qty'] ?? 0), $uid, $reason);
            $res['message'] = $r['waste'] ? t('Cancelled and recorded as waste.') : t('Cancelled — the kitchen will not make it.');
            $res['waste'] = $r['waste'];
            $log = "Cancelled kitchen line #$item (qty {$r['cancelled']})" . ($reason !== '' ? ": $reason" : '');
            break;
        case 'cancel_order':
            if (!$shopOk($wid)) { $pdo->rollBack(); $denied(); }
            if (!$tid || !$tableOk($tid, $wid)) throw new RofException(t('Invalid request.'), 'invalid');
            $ids = rofCancelOrder($pdo, $wid, $tid, $uid, $reason);
            $res['message'] = t('Order cancelled. The table is free.');
            $log = "Cancelled table #$tid order (" . count($ids) . ' kitchen line(s))' . ($reason !== '' ? ": $reason" : '');
            break;
    }
    $pdo->commit();
    logActivity($pdo, $uid, $log);
    $out(200, $res);
} catch (RofException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $out(200, $e->toArray());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('order_flow: ' . $e->getMessage());
    $out(500, ['success' => false, 'message' => t('Database error.')]);
}
