<?php
/**
 * API: Create/Update a Table Reservation
 * POST: id (blank = create), warehouse_id, table_id, customer_id (optional),
 *       customer_name, customer_phone, reservation_time, party_size, notes
 * Gate: restaurant_pos + canCreate/canEdit.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$id               = (int)($_POST['id'] ?? 0);
$warehouse_id     = (int)($_POST['warehouse_id'] ?? 0);
$table_id         = (int)($_POST['table_id'] ?? 0);
$customer_id      = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
$customer_name    = trim($_POST['customer_name'] ?? '');
$customer_phone   = trim($_POST['customer_phone'] ?? '');
$reservation_time = trim($_POST['reservation_time'] ?? '');
$party_size       = max(1, (int)($_POST['party_size'] ?? 1));
$notes            = trim($_POST['notes'] ?? '') ?: null;

$gate = $id > 0 ? canEdit('restaurant_pos') : canCreate('restaurant_pos');
if (!$gate) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }

if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}
$tableChk = $pdo->prepare("SELECT 1 FROM restaurant_tables WHERE table_id = ? AND warehouse_id = ?");
$tableChk->execute([$table_id, $warehouse_id]);
if (!$tableChk->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => t('The selected table does not belong to this warehouse.')]);
    exit;
}
$ts = strtotime($reservation_time);
if ($customer_name === '' || !$ts) {
    echo json_encode(['success' => false, 'message' => t('Customer name and a valid reservation time are required.')]);
    exit;
}
$reservation_time = date('Y-m-d H:i:s', $ts);

if ($id > 0) {
    $existing = $pdo->prepare("SELECT warehouse_id FROM restaurant_reservations WHERE id = ?");
    $existing->execute([$id]);
    $existingWid = $existing->fetchColumn();
    if ($existingWid === false || !userCan('warehouse', (int)$existingWid)) {
        echo json_encode(['success' => false, 'message' => t('Reservation not found.')]);
        exit;
    }
    $pdo->prepare("
        UPDATE restaurant_reservations
        SET warehouse_id = ?, table_id = ?, customer_id = ?, customer_name = ?, customer_phone = ?,
            reservation_time = ?, party_size = ?, notes = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$warehouse_id, $table_id, $customer_id, $customer_name, $customer_phone, $reservation_time, $party_size, $notes, $id]);
    logActivity($pdo, $_SESSION['user_id'], "Updated reservation for $customer_name at $reservation_time");
    $message = t('Reservation updated successfully.');
} else {
    $pdo->prepare("
        INSERT INTO restaurant_reservations
            (table_id, warehouse_id, customer_id, customer_name, customer_phone, reservation_time, party_size, status, notes, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'booked', ?, ?, NOW(), NOW())
    ")->execute([$table_id, $warehouse_id, $customer_id, $customer_name, $customer_phone, $reservation_time, $party_size, $notes, $_SESSION['user_id']]);
    $id = (int)$pdo->lastInsertId();
    // A booked reservation reserves the table right away, matching how a
    // held order occupies it — a "reserved" table is visibly unavailable to
    // walk-ins on the floor plan the moment the booking is made.
    $pdo->prepare("UPDATE restaurant_tables SET status = 'reserved', updated_at = NOW() WHERE table_id = ? AND status = 'available'")
        ->execute([$table_id]);
    logActivity($pdo, $_SESSION['user_id'], "Created reservation for $customer_name at $reservation_time");
    $message = t('Reservation created successfully.');
}

echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
