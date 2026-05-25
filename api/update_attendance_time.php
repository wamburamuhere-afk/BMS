<?php
// File: api/update_attendance_time.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('attendance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit attendance time']);
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
    $field = trim($_POST['field']); // 'check_in' or 'check_out'
    $value = trim($_POST['value']);
    
    if (!$employee_id || !$attendance_date || !$field) {
        throw new Exception('Missing required fields');
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }

    // Determine which field to update
    $column = ($field === 'check_in') ? 'check_in_time' : 'check_out_time';
    
    // Check if record exists
    $check_stmt = $pdo->prepare("
        SELECT attendance_id, check_in_time, check_out_time 
        FROM attendance 
        WHERE employee_id = ? AND attendance_date = ?
    ");
    $check_stmt->execute([$employee_id, $attendance_date]);
    $existing = $check_stmt->fetch();
    
    if ($existing) {
        // Update existing record
        $update_stmt = $pdo->prepare("
            UPDATE attendance 
            SET $column = ?, updated_by = ?, updated_at = NOW()
            WHERE employee_id = ? AND attendance_date = ?
        ");
        $update_stmt->execute([$value, $_SESSION['user_id'], $employee_id, $attendance_date]);
        
        // Recalculate total hours if both times are set
        $check_in = ($field === 'check_in') ? $value : $existing['check_in_time'];
        $check_out = ($field === 'check_out') ? $value : $existing['check_out_time'];
        
        if ($check_in && $check_out) {
            $hours = (strtotime($check_out) - strtotime($check_in)) / 3600;
            $pdo->prepare("
                UPDATE attendance 
                SET total_hours = ? 
                WHERE employee_id = ? AND attendance_date = ?
            ")->execute([$hours, $employee_id, $attendance_date]);
        }
        
        // Log the change
        logAudit($pdo, $_SESSION['user_id'], 'update_attendance_time', [
            'activity_type' => 'update',
            'entity_type' => 'attendance',
            'entity_id' => $employee_id,
            'description' => "Updated $field time to '$value' for employee ID $employee_id on $attendance_date"
        ]);

        echo json_encode(['success' => true, 'message' => 'Time updated successfully']);
    } else {
        // Create new record
        $insert_stmt = $pdo->prepare("
            INSERT INTO attendance (
                employee_id, attendance_date, $column, status, created_by, created_at
            ) VALUES (?, ?, ?, 'present', ?, NOW())
        ");
        $insert_stmt->execute([$employee_id, $attendance_date, $value, $_SESSION['user_id']]);
        
        // Log the change
        logAudit($pdo, $_SESSION['user_id'], 'update_attendance_time', [
            'activity_type' => 'create',
            'entity_type' => 'employee',
            'entity_id' => $employee_id,
            'description' => "Created attendance record with $field time '$value' for employee ID $employee_id on $attendance_date",
            'new_values' => [$column => $value, 'status' => 'present']
        ]);

        echo json_encode(['success' => true, 'message' => 'Attendance created successfully']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
