<?php
// File: api/update_leave.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_leave.log');

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/leave_rules.php';

ob_clean();
header('Content-Type: application/json');

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('leaves')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit leave records']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    if (empty($_POST['leave_id'])) {
        throw new Exception("Leave ID is required");
    }

    $leave_id = intval($_POST['leave_id']);

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployeeRecord')) {
        assertScopeForEmployeeRecord('leaves', 'leave_id', $leave_id);
    }

    // Safety check: ensure leave is still pending
    $stmt = $pdo->prepare("SELECT status FROM leaves WHERE leave_id = ?");
    $stmt->execute([$leave_id]);
    $current_status = $stmt->fetchColumn();
    
    if ($current_status !== 'pending') {
        throw new Exception("Only pending leave applications can be updated");
    }

    $required_fields = ['leave_type_id', 'start_date', 'end_date', 'reason'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field '$field' is required.");
        }
    }

    $start_date = trim($_POST['start_date']);
    $end_date   = trim($_POST['end_date']);
    $reason     = trim($_POST['reason']);

    // The leave type is now a real FK. The legacy ENUM is dual-written for the
    // readers still on it (leave_reports, export_leaves, project_view).
    $type       = leaveTypeForApply($pdo, $_POST['leave_type_id']);
    $leave_type = legacyLeaveTypeEnum($type);

    $hd          = normaliseHalfDay($_POST);
    $half_day    = $hd['half_day'];
    $leave_hours = $hd['leave_hours'];

    // Days are computed server-side from the dates + half-day, never trusted from
    // the posted total_days, and then checked against the type's limits.
    $total_days = leaveDaysFor($start_date, $end_date, $half_day, $leave_hours);

    $emp = $pdo->prepare("SELECT employee_id FROM leaves WHERE leave_id = ?");
    $emp->execute([$leave_id]);
    $employee_id = (int)$emp->fetchColumn();
    assertLeaveWithinTypeLimits($pdo, $type, $employee_id, $start_date, $total_days, $leave_id);

    // `notes` is deliberately NOT updated. The Additional Notes field was removed
    // from the form, so it is no longer posted; writing `notes = ?` here would
    // blank the notes stored on 26 of the 27 existing leave records.
    $query = "UPDATE leaves SET
        leave_type_id = ?,
        leave_type = ?,
        start_date = ?,
        end_date = ?,
        total_days = ?,
        days_count = ?,
        half_day = ?,
        leave_hours = ?,
        is_paid = ?,
        reason = ?,
        updated_at = NOW()
    WHERE leave_id = ?";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        (int)$type['type_id'],
        $leave_type,
        $start_date,
        $end_date,
        $total_days,
        $total_days,
        $half_day,
        $leave_hours,
        (int)$type['is_paid'],
        $reason,
        $leave_id
    ]);

    logActivity($pdo, $_SESSION['user_id'], 'Edit leave request', "User edited leave request (ID $leave_id)");

    echo json_encode([
        'success' => true,
        'message' => 'Leave application updated successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
