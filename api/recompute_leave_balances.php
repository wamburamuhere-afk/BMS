<?php
// api/recompute_leave_balances.php — manual "recompute balances" trigger (Plan H3).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/leave_balance.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit(); }
csrf_check();
if (!canEdit('leaves')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit(); }

try {
    $year = (int)($_POST['year'] ?? date('Y'));
    $summary = leaveYearRollover($pdo, $year);
    if (function_exists('save_setting')) save_setting('leave_accrual_last_run', date('Y-m-d'));
    logActivity($pdo, $_SESSION['user_id'], "Recomputed leave balances", "Year $year: {$summary['rows']} rows");
    echo json_encode(['success' => true, 'message' => "Recomputed {$summary['rows']} balance(s) for {$year}.", 'summary' => $summary]);
} catch (Throwable $e) {
    error_log('recompute_leave_balances error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not recompute balances.']);
}
