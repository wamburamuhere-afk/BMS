<?php
// File: api/update_attendance_status.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('attendance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change attendance status']);
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
    
    if (!$employee_id || !$attendance_date || !$status) {
        throw new Exception('Missing required fields');
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }

    // Check if record exists
    $check_stmt = $pdo->prepare("
        SELECT attendance_id FROM attendance
        WHERE employee_id = ? AND attendance_date = ?
    ");
    $check_stmt->execute([$employee_id, $attendance_date]);
    $existing = $check_stmt->fetch();

    if ($existing) {
        // Update existing record
        $update_stmt = $pdo->prepare("
            UPDATE attendance
            SET status = ?, updated_by = ?, updated_at = NOW()
            WHERE employee_id = ? AND attendance_date = ?
        ");
        $update_stmt->execute([$status, $_SESSION['user_id'], $employee_id, $attendance_date]);
        
        // Log the change
    logAudit($pdo, $_SESSION['user_id'], 'update_attendance_status', [
        'activity_type' => 'update',
        'entity_type' => 'attendance',
        'entity_id' => $employee_id,
        'description' => "Updated attendance status to '$status' for employee ID $employee_id on $attendance_date"
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully'
    ]);
    } else {
        // Create new record
        $insert_stmt = $pdo->prepare("
            INSERT INTO attendance (
                employee_id, attendance_date, status, created_by, created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");
        $insert_stmt->execute([$employee_id, $attendance_date, $status, $_SESSION['user_id']]);
        
        // Log Activity
        logAudit($pdo, $_SESSION['user_id'], 'update_attendance_status', [
            'activity_type' => 'create',
            'entity_type' => 'employee',
            'entity_id' => $employee_id,
            'description' => "Created attendance record with status '$status' for employee ID $employee_id on $attendance_date",
            'new_values' => ['status' => $status]
        ]);

        echo json_encode(['success' => true, 'message' => 'Attendance created successfully']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
