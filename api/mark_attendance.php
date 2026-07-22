<?php
// File: api/mark_attendance.php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/attendance_payroll.php';   // Plan H2 — overtime calc
require_once __DIR__ . '/../core/employee_status.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canCreate('attendance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to mark attendance']);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    // Validate required fields
    if (empty($_POST['employee_id'])) {
        throw new Exception('Employee is required');
    }

    if (empty($_POST['attendance_date'])) {
        throw new Exception('Attendance date is required');
    }

    $employee_id = intval($_POST['employee_id']);

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }
    assertEmployeeActive($pdo, $employee_id);

    $attendance_date = trim($_POST['attendance_date']);

    // Check if attendance record already exists — fetched BEFORE resolving the fields
    // below, so a partial submission (e.g. just a check-out time, checking out after an
    // earlier check-in-only save) can fall back to what's already saved instead of
    // wiping it. This is what lets "check in now, check out later" work: each call only
    // needs to supply the field it actually has.
    $check_stmt = $pdo->prepare("
        SELECT * FROM attendance
        WHERE employee_id = ? AND attendance_date = ?
    ");
    $check_stmt->execute([$employee_id, $attendance_date]);
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

    // Status is required to create a brand-new record, but optional on an update — a
    // check-out call doesn't need to (and shouldn't have to) restate the status the
    // check-in call already set.
    if (empty($_POST['status']) && !$existing) {
        throw new Exception('Status is required');
    }
    $status = !empty($_POST['status']) ? trim($_POST['status']) : ($existing['status'] ?? 'present');

    $check_in_time  = !empty($_POST['check_in_time'])  ? trim($_POST['check_in_time'])  : ($existing['check_in_time']  ?? null);
    $check_out_time = !empty($_POST['check_out_time']) ? trim($_POST['check_out_time']) : ($existing['check_out_time'] ?? null);
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : ($existing['notes'] ?? '');

    // total_hours always derives from the final check-in/check-out pair (never trust a
    // stale client-submitted value here) so a check-out-only call recalculates against
    // the check-in that was actually saved earlier, not one the browser no longer knows.
    $total_hours = null;
    if ($check_in_time && $check_out_time) {
        $check_in = strtotime($check_in_time);
        $check_out = strtotime($check_out_time);
        $total_hours = ($check_out - $check_in) / 3600;
    }

    // Plan H2 — overtime = hours beyond the shift standard, valued at the hourly rate.
    $rate_stmt = $pdo->prepare("SELECT COALESCE(hourly_rate, 0) FROM employees WHERE employee_id = ?");
    $rate_stmt->execute([$employee_id]);
    $hourly_rate = (float)$rate_stmt->fetchColumn();
    $ot = computeAttendanceOvertime($total_hours, employeeStandardHours($pdo, $employee_id), $hourly_rate);
    $overtime_hours  = $ot['overtime_hours'];
    $overtime_amount = $ot['overtime_amount'];

    if ($existing) {
        // Update existing record
        $update_stmt = $pdo->prepare("
            UPDATE attendance SET
                check_in_time = ?,
                check_out_time = ?,
                total_hours = ?,
                overtime_hours = ?,
                overtime_amount = ?,
                status = ?,
                notes = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE employee_id = ? AND attendance_date = ?
        ");

        $update_stmt->execute([
            $check_in_time,
            $check_out_time,
            $total_hours,
            $overtime_hours,
            $overtime_amount,
            $status,
            $notes,
            $_SESSION['user_id'],
            $employee_id,
            $attendance_date
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Attendance updated successfully'
        ]);
    } else {
        // Insert new record
        $insert_stmt = $pdo->prepare("
            INSERT INTO attendance (
                employee_id,
                attendance_date,
                check_in_time,
                check_out_time,
                total_hours,
                overtime_hours,
                overtime_amount,
                status,
                notes,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $insert_stmt->execute([
            $employee_id,
            $attendance_date,
            $check_in_time,
            $check_out_time,
            $total_hours,
            $overtime_hours,
            $overtime_amount,
            $status,
            $notes,
            $_SESSION['user_id']
        ]);
        
        // Log new attendance
        logAudit($pdo, $_SESSION['user_id'], 'mark_attendance', [
            'activity_type' => 'create',
            'entity_type' => 'attendance',
            'entity_id' => $employee_id,
            'description' => "Marked attendance for employee ID $employee_id on $attendance_date as " . ucfirst($status)
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Attendance marked successfully'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
