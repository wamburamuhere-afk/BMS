<?php
// File: api/delete_attendance.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canDelete('attendance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete attendance records']);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $attendance_date = trim($_POST['attendance_date'] ?? '');
    
    if (!$employee_id || !$attendance_date) {
        throw new Exception('Missing required fields');
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }

    // Check if record exists and get details for logging
    $check_stmt = $pdo->prepare("
        SELECT * FROM attendance 
        WHERE employee_id = ? AND attendance_date = ?
    ");
    $check_stmt->execute([$employee_id, $attendance_date]);
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        throw new Exception('Attendance record not found');
    }
    
    // Delete record
    $delete_stmt = $pdo->prepare("
        DELETE FROM attendance 
        WHERE employee_id = ? AND attendance_date = ?
    ");
    
    if ($delete_stmt->execute([$employee_id, $attendance_date])) {
        
        // Log Activity
        logAudit($pdo, $_SESSION['user_id'], 'delete_attendance', [
            'activity_type' => 'delete',
            'entity_type' => 'employee',
            'entity_id' => $employee_id,
            'description' => "Deleted attendance record for employee ID {$employee_id} on {$attendance_date}",
            'old_values' => $existing
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Attendance record deleted successfully']);
    } else {
        throw new Exception('Failed to delete attendance record');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
