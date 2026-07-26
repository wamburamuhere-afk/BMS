<?php
// File: api/quick_mark_attendance.php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/employee_status.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canCreate('attendance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to quick-mark attendance']);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $employee_id = intval($_POST['employee_id']);
    $attendance_date = trim($_POST['attendance_date']);
    $status = trim($_POST['status']);
    $check_in_time = !empty($_POST['check_in_time']) ? trim($_POST['check_in_time']) : null;
    $check_out_time = !empty($_POST['check_out_time']) ? trim($_POST['check_out_time']) : null;

    if (!$employee_id || !$attendance_date || !$status) {
        throw new Exception('Missing required fields');
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }
    assertEmployeeActive($pdo, $employee_id);

    // Check if attendance record already exists
    $check_stmt = $pdo->prepare("
        SELECT * FROM attendance
        WHERE employee_id = ? AND attendance_date = ?
    ");
    $check_stmt->execute([$employee_id, $attendance_date]);
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

    // Server-side backstop (mirrors api/mark_attendance.php): a caller that didn't
    // supply a time falls back to whatever is already saved, rather than clearing
    // it — protects against any future/other caller of this endpoint doing what the
    // quick-mark buttons used to do (silently overwrite a time someone already
    // entered). 'absent' is the one status that should always clear both times,
    // since presence and absence are mutually exclusive.
    if ($status !== 'absent' && $existing) {
        if ($check_in_time === null)  $check_in_time  = $existing['check_in_time'];
        if ($check_out_time === null) $check_out_time = $existing['check_out_time'];
    }

    // Calculate total hours if times provided
    $total_hours = 0;
    if ($check_in_time && $check_out_time) {
        $check_in = strtotime($check_in_time);
        $check_out = strtotime($check_out_time);
        $total_hours = ($check_out - $check_in) / 3600;
    }
    
    if ($existing) {
        // Update existing record
        $update_stmt = $pdo->prepare("
            UPDATE attendance SET
                check_in_time = ?,
                check_out_time = ?,
                total_hours = ?,
                status = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE employee_id = ? AND attendance_date = ?
        ");
        
        $update_stmt->execute([
            $check_in_time,
            $check_out_time,
            $total_hours,
            $status,
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
                status,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $insert_stmt->execute([
            $employee_id,
            $attendance_date,
            $check_in_time,
            $check_out_time,
            $total_hours,
            $status,
            $_SESSION['user_id']
        ]);
        
        // Log Activity
        logAudit($pdo, $_SESSION['user_id'], 'mark_attendance', [
            'activity_type' => 'create',
            'entity_type' => 'employee',
            'entity_id' => $employee_id,
            'description' => "Marked attendance as {$status} for employee ID {$employee_id} on {$attendance_date}",
            'new_values' => [
                'status' => $status,
                'check_in' => $check_in_time,
                'check_out' => $check_out_time,
                'date' => $attendance_date
            ]
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
