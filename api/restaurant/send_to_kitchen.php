<?php
/**
 * API: Send an order to the kitchen
 *
 * POST: hold_id (a table's / pickup's held order) OR sale_id (a paid take-away / delivery), warehouse_id,
 *       [reason] (only when a dish the kitchen already started was removed — see below)
 *
 * restaurant_order_flow_plan.md — the rules live in core/restaurant_order_flow.php:
 *  - only what the kitchen does not have yet is sent (a second round never re-sends the first);
 *  - dishes removed on the till since the last send are taken off the kitchen (cooked food needs the
 *    cashier + a reason in the team flow → error_code cancel_needs_reason / cancel_needs_approval);
 *  - team flow ON and the sender cannot confirm → the new lines wait for the cashier (status pending).
 * Deliberately does NOT touch pos_sales — the bill is not finalised here.
 *
 * Gate: restaurant_pos (plan) + taking orders (canCreate('pos') or Restaurant Orders create);
 *       a paid sale's ticket needs canCreate('pos') (only the till that took the money sends it).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/restaurant_order_flow.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo) || !restaurantMenuSchemaReady($pdo) || !rofReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if (!rofCanTake($pdo))          { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$hold_id      = (int)($_POST['hold_id'] ?? 0);
$sale_id      = (int)($_POST['sale_id'] ?? 0);
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
$reason       = trim((string)($_POST['reason'] ?? ''));
$uid          = (int)$_SESSION['user_id'];

if (($hold_id <= 0 && $sale_id <= 0) || $warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
    exit;
}
if ($hold_id <= 0 && !canCreate('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Permission denied')]);
    exit;
}

$pdo->beginTransaction();
try {
    if ($hold_id > 0) {
        $r = rofSendHold($pdo, $hold_id, $warehouse_id, $uid, $reason);
        $pdo->commit();
        if ($r['nothing_new']) {
            echo json_encode(['success' => true, 'nothing_new' => true, 'status' => null, 'ticket_ids' => [],
                              'message' => t('Nothing new to send — the kitchen already has this order.')]);
            exit;
        }
        logActivity($pdo, $uid, "Sent order ({$r['label']}) to kitchen: " . count($r['ticket_ids']) . ' station(s)' . ($r['status'] === 'pending' ? ' — waiting for the cashier' : ''));
        echo json_encode(['success' => true, 'status' => $r['status'], 'ticket_ids' => $r['ticket_ids'],
                          'message' => $r['status'] === 'pending' ? t('Sent to the cashier to confirm.') : t('Order sent to the kitchen.')]);
    } else {
        $ids = rofSendSale($pdo, $sale_id, $warehouse_id, $uid);
        $pdo->commit();
        logActivity($pdo, $uid, "Sent paid order (sale #$sale_id) to kitchen: " . count($ids) . ' station(s)');
        echo json_encode(['success' => true, 'status' => 'queued', 'ticket_ids' => $ids, 'message' => t('Order sent to the kitchen.')]);
    }
} catch (RofException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode($e->toArray());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('send_to_kitchen: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
