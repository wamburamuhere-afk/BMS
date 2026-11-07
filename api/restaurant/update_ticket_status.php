<?php
/**
 * API: Advance a Kitchen Ticket's status
 * POST: ticket_id, status (preparing|ready|served) — forward only (kitchen staff correct a mistake by
 * re-sending from the till, not by rewinding a ticket).
 *
 * restaurant_order_flow_plan.md — rules in rofAdvance(): Preparing/Ready = the kitchen (Kitchen Screen
 * edit); Served = "Received": in the team flow the waiter/cashier marks it when the kitchen hands the
 * food over (not the cook); with the team flow off the kitchen may still mark it, as before.
 * A ticket waiting for the cashier, or cancelled, cannot move.
 * Gate: restaurant_pos.
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
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$status    = (string)($_POST['status'] ?? '');
$uid       = (int)$_SESSION['user_id'];
if ($ticket_id <= 0 || !in_array($status, ['queued', 'preparing', 'ready', 'served'], true)) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

// Before the order-flow migration has run on this database: today's behaviour exactly.
if (!rofReady($pdo)) {
    $sequence = ['queued' => 1, 'preparing' => 2, 'ready' => 3, 'served' => 4];
    $row = $pdo->prepare("SELECT warehouse_id, status FROM kitchen_tickets WHERE ticket_id = ?");
    $row->execute([$ticket_id]);
    $ticket = $row->fetch(PDO::FETCH_ASSOC);
    if (!$ticket || !userCan('warehouse', (int)$ticket['warehouse_id'])) { echo json_encode(['success' => false, 'message' => t('Ticket not found.')]); exit; }
    if (($sequence[$ticket['status']] ?? 0) > $sequence[$status]) { echo json_encode(['success' => false, 'message' => t('A kitchen ticket cannot move backward — it can only advance.')]); exit; }
    $pdo->prepare("UPDATE kitchen_tickets SET status = ?, updated_at = NOW() WHERE ticket_id = ?")->execute([$status, $ticket_id]);
    $pdo->prepare("UPDATE kitchen_ticket_items SET status = ? WHERE ticket_id = ?")->execute([$status, $ticket_id]);
    logActivity($pdo, $uid, "Kitchen ticket #$ticket_id -> $status");
    if ($status === 'ready' && $ticket['status'] !== 'ready') {
        require_once __DIR__ . '/../../core/restaurant_notify.php';
        rnNotifyKitchenReady($pdo, $ticket_id);
    }
    echo json_encode(['success' => true, 'message' => t('Ticket status updated.')]);
    exit;
}

$pdo->beginTransaction();
try {
    $before = (string)$pdo->query("SELECT status FROM kitchen_tickets WHERE ticket_id = " . $ticket_id)->fetchColumn();
    rofAdvance($pdo, $ticket_id, $status, $uid);
    $pdo->commit();
} catch (RofException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode($e->toArray());
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('update_ticket_status: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
    exit;
}
logActivity($pdo, $uid, "Kitchen ticket #$ticket_id -> $status");
if ($status === 'ready' && $before !== 'ready') {
    // Restaurant notifications: "ready to serve" to the waiter who took the order.
    require_once __DIR__ . '/../../core/restaurant_notify.php';
    rnNotifyKitchenReady($pdo, $ticket_id);
}
echo json_encode(['success' => true, 'message' => $status === 'served' ? t('Marked received.') : t('Ticket status updated.')]);
