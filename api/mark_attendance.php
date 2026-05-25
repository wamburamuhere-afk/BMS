<?php
// File: api/mark_attendance.php
require_once __DIR__ . '/../roots.php';

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
    
    if (empty($_POST['status'])) {
        throw new Exception('Status is required');
    }
    
    $employee_id = intval($_POST['employee_id']);

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }

    $attendance_date = trim($_POST['attendance_date']);
    $status = trim($_POST['status']);
    $check_in_time = !empty($_POST['check_in_time']) ? trim($_POST['check_in_time']) : null;
    $check_out_time = !empty($_POST['check_out_time']) ? trim($_POST['check_out_time']) : null;
    $total_hours = !empty($_POST['total_hours']) ? floatval($_POST['total_hours']) : null;
    $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : '';
    
    // Calculate total hours if not provided
    if ($total_hours === null && $check_in_time && $check_out_time) {
        $check_in = strtotime($check_in_time);
        $check_out = strtotime($check_out_time);
        $total_hours = ($check_out - $check_in) / 3600;
    }
    
    // Check if attendance record already exists
    $check_stmt = $pdo->prepare("
        SELECT attendance_id FROM attendance 
        WHERE employee_id = ? AND attendance_date = ?
    ");
    $check_stmt->execute([$employee_id, $attendance_date]);
    $existing = $check_stmt->fetch();
    
    if ($existing) {
        // Update existing record
        $update_stmt = $pdo->prepare("
            UPDATE attendance SET
                check_in_time = ?,
                check_out_time = ?,
                total_hours = ?,
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
                status,
                notes,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $insert_stmt->execute([
            $employee_id,
            $attendance_date,
            $check_in_time,
            $check_out_time,
            $total_hours,
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
