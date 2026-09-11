<?php
/**
 * API: List Kitchen Tickets (for the Kitchen Display)
 * GET ?warehouse_id= (required), &station_id= (optional), &status= (optional
 * comma-list, default queued,preparing — an active-queue view).
 * Gate: restaurant_pos.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }

global $pdo;

$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}
$station_id = (int)($_GET['station_id'] ?? 0);
$statusList = array_filter(array_map('trim', explode(',', $_GET['status'] ?? 'queued,preparing')));
$statusList = array_values(array_intersect($statusList, ['queued', 'preparing', 'ready', 'served']));
if (empty($statusList)) $statusList = ['queued', 'preparing'];

$sql = "SELECT kt.ticket_id, kt.hold_id, kt.warehouse_id, kt.station_id, kt.table_id, kt.status, kt.created_at, kt.updated_at,
               ks.name AS station_name, rt.table_number
        FROM kitchen_tickets kt
        JOIN kitchen_stations ks ON ks.station_id = kt.station_id
        LEFT JOIN restaurant_tables rt ON rt.table_id = kt.table_id
        WHERE kt.warehouse_id = ? AND kt.status IN (" . implode(',', array_fill(0, count($statusList), '?')) . ")";
$params = array_merge([$warehouse_id], $statusList);
if ($station_id > 0) { $sql .= " AND kt.station_id = ?"; $params[] = $station_id; }
$sql .= " ORDER BY kt.created_at";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($tickets)) {
    $ticketIds = array_column($tickets, 'ticket_id');
    $ph = implode(',', array_fill(0, count($ticketIds), '?'));
    $itemStmt = $pdo->prepare("
        SELECT kti.id, kti.ticket_id, kti.product_id, kti.quantity, kti.modifiers_summary, kti.status, p.product_name
        FROM kitchen_ticket_items kti
        JOIN products p ON p.product_id = kti.product_id
        WHERE kti.ticket_id IN ($ph)
        ORDER BY kti.id
    ");
    $itemStmt->execute($ticketIds);
    $itemsByTicket = [];
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $itemsByTicket[(int)$it['ticket_id']][] = $it;
    }
    foreach ($tickets as &$t) {
        $t['items'] = $itemsByTicket[(int)$t['ticket_id']] ?? [];
    }
    unset($t);
}

echo json_encode(['success' => true, 'data' => $tickets]);
