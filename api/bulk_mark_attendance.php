<?php
// File: api/bulk_mark_attendance.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    // Validate required fields
    if (empty($_POST['employee_ids']) || !is_array($_POST['employee_ids'])) {
        throw new Exception('Please select at least one employee');
    }
    
    if (empty($_POST['attendance_date'])) {
        throw new Exception('Attendance date is required');
    }
    
    if (empty($_POST['status'])) {
        throw new Exception('Status is required');
    }
    
    $employee_ids = $_POST['employee_ids'];
    $attendance_date = trim($_POST['attendance_date']);
    $status = trim($_POST['status']);
    
    // Set default times based on status
    $check_in_time = null;
    $check_out_time = null;
    $total_hours = 0;
    
    switch ($status) {
        case 'present':
            $check_in_time = '09:00:00';
            $check_out_time = '17:00:00';
            $total_hours = 8.0;
            break;
        case 'late':
            $check_in_time = '10:00:00';
            $check_out_time = '17:00:00';
            $total_hours = 7.0;
            break;
        case 'half_day':
            $check_in_time = '09:00:00';
            $check_out_time = '13:00:00';
            $total_hours = 4.0;
            break;
        case 'absent':
        case 'leave':
        case 'weekend':
        case 'holiday':
            $check_in_time = null;
            $check_out_time = null;
            $total_hours = 0;
            break;
    }
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    foreach ($employee_ids as $employee_id) {
        try {
            $employee_id = intval($employee_id);
            
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
            }
            
            $success_count++;
            
        } catch (Exception $e) {
            $error_count++;
            $error_msg = "Employee ID $employee_id Error: " . $e->getMessage();
            $errors[] = $error_msg;
            error_log("Bulk Attendance Error: " . $error_msg); // Log to PHP error log
        }
    }
    
    if ($success_count > 0) {
        $message = "Successfully marked $success_count employee(s) as " . str_replace('_', ' ', $status);
        
        // Log bulk action
        logAudit($pdo, $_SESSION['user_id'], 'bulk_mark_attendance', [
            'activity_type' => 'process',
            'entity_type' => 'attendance',
            'description' => "Bulk updated status to '$status' for $success_count employees on $attendance_date"
        ]);

        if ($error_count > 0) {
            $message .= ". $error_count failed.";
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'details' => [
                'success_count' => $success_count,
                'error_count' => $error_count,
                'errors' => $errors
            ]
        ]);
    } else {
        // Log the first error to help debugging
        $first_error = !empty($errors) ? $errors[0] : 'Unknown error';
        error_log("All Bulk Attendance Attempts Failed. First error: " . $first_error);
        
        throw new Exception('Failed to mark attendance for all selected employees. Error: ' . $first_error);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
