<?php
// File: api/update_leave.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_leave.log');

require_once __DIR__ . '/../roots.php';

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

    $required_fields = ['leave_type', 'start_date', 'end_date', 'total_days', 'reason'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field '$field' is required.");
        }
    }

    $leave_type_input = trim($_POST['leave_type']);
    $start_date = trim($_POST['start_date']);
    $end_date = trim($_POST['end_date']);

    // Map leave_type to DB ENUM values
    $type_map = [
        'Annual Leave' => 'annual',
        'Sick Leave' => 'sick',
        'Maternity Leave' => 'maternity',
        'Paternity Leave' => 'paternity',
        'Study Leave' => 'study',
        'Unpaid Leave' => 'unpaid',
        'Compassionate Leave' => 'other',
        'Emergency Leave' => 'other',
        'Other' => 'other'
    ];
    
    $leave_type = isset($type_map[$leave_type_input]) ? $type_map[$leave_type_input] : $leave_type_input;
    // Basic validation against ENUM
    $valid_enums = ['annual', 'sick', 'maternity', 'paternity', 'study', 'unpaid', 'other'];
    if (!in_array($leave_type, $valid_enums)) {
        // Try lowercase first word
        $leave_type = strtolower(explode(' ', $leave_type_input)[0]);
        if (!in_array($leave_type, $valid_enums)) {
            $leave_type = 'other';
        }
    }
    $total_days = floatval($_POST['total_days']);
    $reason = trim($_POST['reason']);
    $notes = trim($_POST['notes'] ?? '');

    $query = "UPDATE leaves SET 
        leave_type = ?, 
        start_date = ?, 
        end_date = ?, 
        total_days = ?, 
        days_count = ?,
        reason = ?, 
        notes = ?,
        updated_at = NOW()
    WHERE leave_id = ?";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $leave_type,
        $start_date,
        $end_date,
        $total_days,
        $total_days,
        $reason,
        $notes,
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
