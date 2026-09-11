<?php
/**
 * API: Update a Restaurant Table's Status
 * POST: table_id, status (available|occupied|reserved|cleaning)
 * Gate: restaurant_pos (view is enough — status changes as part of the
 * normal service flow, not an admin-only action; canCreate('pos') covers a
 * server marking a table cleaning/available during shift work).
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

$table_id = (int)($_POST['table_id'] ?? 0);
$status   = $_POST['status'] ?? '';

if ($table_id <= 0 || !in_array($status, ['available', 'occupied', 'reserved', 'cleaning'], true)) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

$row = $pdo->prepare("SELECT warehouse_id, table_number FROM restaurant_tables WHERE table_id = ?");
$row->execute([$table_id]);
$table = $row->fetch(PDO::FETCH_ASSOC);
if (!$table || !userCan('warehouse', (int)$table['warehouse_id'])) {
    echo json_encode(['success' => false, 'message' => t('Table not found.')]);
    exit;
}

$pdo->prepare("UPDATE restaurant_tables SET status = ?, updated_at = NOW() WHERE table_id = ?")
    ->execute([$status, $table_id]);
logActivity($pdo, $_SESSION['user_id'], "Set table {$table['table_number']} status to $status");

echo json_encode(['success' => true, 'message' => t('Table status updated.')]);
