<?php
/**
 * API: Advance a Kitchen Ticket's status
 * POST: ticket_id, status (queued|preparing|ready|served) — enforces the
 * forward-only sequence queued -> preparing -> ready -> served (kitchen
 * staff correct a mistake by re-sending from the terminal, not by
 * rewinding a ticket).
 * Gate: restaurant_pos.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$sequence = ['queued' => 1, 'preparing' => 2, 'ready' => 3, 'served' => 4];

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$status    = $_POST['status'] ?? '';

if ($ticket_id <= 0 || !isset($sequence[$status])) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

$row = $pdo->prepare("SELECT warehouse_id, status FROM kitchen_tickets WHERE ticket_id = ?");
$row->execute([$ticket_id]);
$ticket = $row->fetch(PDO::FETCH_ASSOC);
if (!$ticket || !userCan('warehouse', (int)$ticket['warehouse_id'])) {
    echo json_encode(['success' => false, 'message' => t('Ticket not found.')]);
    exit;
}
if ($sequence[$status] < $sequence[$ticket['status']]) {
    echo json_encode(['success' => false, 'message' => t('A kitchen ticket cannot move backward — it can only advance.')]);
    exit;
}

$pdo->prepare("UPDATE kitchen_tickets SET status = ?, updated_at = NOW() WHERE ticket_id = ?")->execute([$status, $ticket_id]);
$pdo->prepare("UPDATE kitchen_ticket_items SET status = ? WHERE ticket_id = ?")->execute([$status, $ticket_id]);
logActivity($pdo, $_SESSION['user_id'], "Kitchen ticket #$ticket_id -> $status");

echo json_encode(['success' => true, 'message' => t('Ticket status updated.')]);
