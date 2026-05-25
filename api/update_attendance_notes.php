<?php
// File: api/update_attendance_notes.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('attendance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit attendance notes']);
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
    $notes = trim($_POST['notes']);
    
    if (!$employee_id || !$attendance_date) {
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
            SET notes = ?, updated_by = ?, updated_at = NOW()
            WHERE employee_id = ? AND attendance_date = ?
        ");
        $update_stmt->execute([$notes, $_SESSION['user_id'], $employee_id, $attendance_date]);
        
        // Log Activity
        logAudit($pdo, $_SESSION['user_id'], 'update_attendance_notes', [
            'activity_type' => 'update',
            'entity_type' => 'employee',
            'entity_id' => $employee_id,
            'description' => "Updated attendance notes for employee ID {$employee_id} on {$attendance_date}",
            'new_values' => ['notes' => $notes]
        ]);

        echo json_encode(['success' => true, 'message' => 'Notes updated successfully']);
    } else {
        // Create new record
        $insert_stmt = $pdo->prepare("
            INSERT INTO attendance (
                employee_id, attendance_date, notes, status, created_by, created_at
            ) VALUES (?, ?, ?, 'absent', ?, NOW())
        ");
        $insert_stmt->execute([$employee_id, $attendance_date, $notes, $_SESSION['user_id']]);
        
        // Log Activity
        logAudit($pdo, $_SESSION['user_id'], 'update_attendance_notes', [
            'activity_type' => 'create',
            'entity_type' => 'employee',
            'entity_id' => $employee_id,
            'description' => "Created attendance record with notes for employee ID {$employee_id} on {$attendance_date}",
            'new_values' => ['notes' => $notes, 'status' => 'absent']
        ]);

        echo json_encode(['success' => true, 'message' => 'Attendance created successfully']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
