<?php
// api/operations/save_project_attendance.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canEdit('projects')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to save project attendance']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $project_id     = intval($_POST['project_id'] ?? 0);
    $employee_id    = intval($_POST['employee_id'] ?? 0);
    $attendance_date = trim($_POST['attendance_date'] ?? '');
    $status         = trim($_POST['status'] ?? '');

    if (!$project_id || !$employee_id || !$attendance_date || !$status) {
        throw new Exception('Required fields missing');
    }

    // Phase B (scope) — block writes against projects not in user scope
    if (!userCan('project', (int)$project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this project is not in your scope.');
    }

    // Verify employee belongs to this project
    $chk = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ? AND project_id = ?");
    $chk->execute([$employee_id, $project_id]);
    if (!$chk->fetch()) {
        throw new Exception('Staff member does not belong to this project');
    }

    $check_in_time  = !empty($_POST['check_in_time'])  ? trim($_POST['check_in_time'])  : null;
    $check_out_time = !empty($_POST['check_out_time']) ? trim($_POST['check_out_time']) : null;
    $notes          = trim($_POST['notes'] ?? '');

    $total_hours = null;
    if ($check_in_time && $check_out_time) {
        $total_hours = round((strtotime($check_out_time) - strtotime($check_in_time)) / 3600, 2);
    }

    $existing = $pdo->prepare("SELECT attendance_id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $existing->execute([$employee_id, $attendance_date]);
    $rec = $existing->fetch();

    if ($rec) {
        $pdo->prepare("UPDATE attendance SET check_in_time=?, check_out_time=?, total_hours=?, status=?, notes=?, updated_by=?, updated_at=NOW() WHERE attendance_id=?")
            ->execute([$check_in_time, $check_out_time, $total_hours, $status, $notes, $_SESSION['user_id'], $rec['attendance_id']]);
        $msg = 'Attendance updated successfully';
    } else {
        $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, check_in_time, check_out_time, total_hours, status, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
            ->execute([$employee_id, $attendance_date, $check_in_time, $check_out_time, $total_hours, $status, $notes, $_SESSION['user_id']]);
        $msg = 'Attendance marked successfully';
    }

    // Phase 3c — attendance drives project payroll calculations.
    logActivity($pdo, $_SESSION['user_id'], "Saved Project Attendance", "Employee ID: $employee_id, date: $attendance_date, status: $status");

    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
