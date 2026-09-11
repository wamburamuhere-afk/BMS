<?php
/**
 * API: Update a Reservation's Status
 * POST: id, status (booked|seated|completed|cancelled|no_show)
 * Seating a reservation occupies its table; completing/cancelling/no-show
 * frees it back to available (unless the table has since been otherwise
 * occupied by a walk-in, which this deliberately does not override).
 * Gate: restaurant_pos + canEdit.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!canEdit('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

if ($id <= 0 || !in_array($status, ['booked', 'seated', 'completed', 'cancelled', 'no_show'], true)) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

$row = $pdo->prepare("SELECT warehouse_id, table_id FROM restaurant_reservations WHERE id = ?");
$row->execute([$id]);
$res = $row->fetch(PDO::FETCH_ASSOC);
if (!$res || !userCan('warehouse', (int)$res['warehouse_id'])) {
    echo json_encode(['success' => false, 'message' => t('Reservation not found.')]);
    exit;
}

$pdo->prepare("UPDATE restaurant_reservations SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $id]);

if ($status === 'seated') {
    $pdo->prepare("UPDATE restaurant_tables SET status = 'occupied', updated_at = NOW() WHERE table_id = ?")->execute([$res['table_id']]);
} elseif (in_array($status, ['completed', 'cancelled', 'no_show'], true)) {
    $pdo->prepare("UPDATE restaurant_tables SET status = 'available', updated_at = NOW() WHERE table_id = ? AND status = 'reserved'")->execute([$res['table_id']]);
}

logActivity($pdo, $_SESSION['user_id'], "Reservation #$id -> $status");
echo json_encode(['success' => true, 'message' => t('Reservation status updated.')]);
