<?php
/**
 * API: List Restaurant Tables
 * GET ?warehouse_id= (required), optional &floor_id=
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
$floor_id = (int)($_GET['floor_id'] ?? 0);

$sql = "SELECT t.table_id, t.warehouse_id, t.floor_id, t.table_number, t.seats, t.status, f.name AS floor_name
        FROM restaurant_tables t
        JOIN restaurant_floors f ON f.floor_id = t.floor_id
        WHERE t.warehouse_id = ?";
$params = [$warehouse_id];
if ($floor_id > 0) { $sql .= " AND t.floor_id = ?"; $params[] = $floor_id; }
$sql .= " ORDER BY f.sort_order, t.table_number";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
