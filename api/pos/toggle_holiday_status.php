<?php
// api/pos/toggle_holiday_status.php — activate/deactivate a public holiday.
// No hard delete: matches the active/inactive convention used across the other
// new Org Structure / Calendar lookups. No "in use" guard needed — a holiday
// isn't referenced by foreign key anywhere; deactivating one only stops it being
// excluded from future working-day leave counts, it never rewrites history.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canDelete('company_calendar')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    $id     = (int)($_POST['holiday_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id <= 0) throw new Exception('Missing holiday.');
    if (!in_array($status, ['active', 'inactive'], true)) throw new Exception('Invalid status.');

    $pdo->prepare("UPDATE public_holidays SET status = ? WHERE holiday_id = ?")->execute([$status, $id]);
    logActivity($pdo, $_SESSION['user_id'], "Holiday $status", "holiday id $id set to $status");
    echo json_encode(['success' => true, 'message' => 'Status updated.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
