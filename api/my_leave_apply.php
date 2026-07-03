<?php
// API: Employee Self-Service leave application (Tier 4, Phase 4.6 — D24).
// Inserts into the SAME `leaves` table + workflow the admin leave module uses,
// but the employee_id is FORCED from the session link — never accepted as input.
// The resulting application appears in the existing approval screens unchanged.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('my_hr')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

// employee_id from the session link ONLY (D24)
$eid = (int)($pdo->query("SELECT employee_id FROM users WHERE user_id = " . (int)$_SESSION['user_id'])->fetchColumn() ?: 0);
if (!$eid) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Your account is not linked to an employee record']); exit; }

try {
    $leave_type_input = trim($_POST['leave_type'] ?? '');
    $start = trim($_POST['start_date'] ?? '');
    $end = trim($_POST['end_date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($leave_type_input === '') throw new Exception('Leave type is required');
    if (!strtotime($start) || !strtotime($end)) throw new Exception('Valid start and end dates are required');
    if (strtotime($end) < strtotime($start)) throw new Exception('End date must be on or after the start date');
    if ($reason === '') throw new Exception('A reason is required');

    // Same enum-mapping the admin apply_leave.php uses
    $type_map = ['Annual Leave'=>'annual','Sick Leave'=>'sick','Maternity Leave'=>'maternity','Paternity Leave'=>'paternity','Study Leave'=>'study','Unpaid Leave'=>'unpaid','Compassionate Leave'=>'other','Emergency Leave'=>'other','Other'=>'other'];
    $valid = ['annual','sick','maternity','paternity','study','unpaid','other'];
    $leave_type = $type_map[$leave_type_input] ?? $leave_type_input;
    if (!in_array($leave_type, $valid, true)) {
        $leave_type = strtolower(explode(' ', $leave_type_input)[0]);
        if (!in_array($leave_type, $valid, true)) $leave_type = 'other';
    }

    $days = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;

    $pdo->prepare("INSERT INTO leaves (employee_id, leave_type, start_date, end_date, total_days, days_count, reason, status, created_by, applied_by, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())")
        ->execute([$eid, $leave_type, $start, $end, $days, $days, $reason, $_SESSION['user_id'], $_SESSION['user_id']]);
    $leave_id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], 'Apply for leave (ESS)', "self-service $leave_type leave for employee #$eid ($days day(s))");

    echo json_encode(['success' => true, 'message' => 'Leave application submitted for approval', 'leave_id' => $leave_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
