<?php
// api/pos/save_working_days.php — set which weekdays count as company working days.
// Stored in system_settings as 'company_working_days' (comma list of ISO weekday
// numbers, 1=Mon..7=Sun). Read by core/company_calendar.php::companyWorkingDays(),
// which feeds any leave type with count_working_days_only=1.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canEdit('company_calendar')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot manage the company calendar']); exit; }

try {
    $days = $_POST['working_days'] ?? [];
    if (!is_array($days)) $days = [];
    $days = array_values(array_unique(array_filter(array_map('intval', $days), fn($d) => $d >= 1 && $d <= 7)));
    sort($days);

    if (empty($days)) {
        throw new Exception('At least one working day is required.');
    }

    save_setting('company_working_days', implode(',', $days));
    logActivity($pdo, $_SESSION['user_id'], "Updated company working days", implode(',', $days));
    echo json_encode(['success' => true, 'message' => 'Working days saved.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
