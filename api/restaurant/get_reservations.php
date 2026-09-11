<?php
/**
 * API: List Table Reservations
 * GET ?warehouse_id= (required), &date= (optional YYYY-MM-DD, default today),
 *     &status= (optional)
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

global $pdo;

$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
$status = $_GET['status'] ?? '';

$sql = "SELECT r.id, r.table_id, r.warehouse_id, r.customer_id, r.customer_name, r.customer_phone,
               r.reservation_time, r.party_size, r.status, r.notes, rt.table_number
        FROM restaurant_reservations r
        JOIN restaurant_tables rt ON rt.table_id = r.table_id
        WHERE r.warehouse_id = ? AND DATE(r.reservation_time) = ?";
$params = [$warehouse_id, $date];
if (in_array($status, ['booked', 'seated', 'completed', 'cancelled', 'no_show'], true)) {
    $sql .= " AND r.status = ?";
    $params[] = $status;
}
$sql .= " ORDER BY r.reservation_time";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
