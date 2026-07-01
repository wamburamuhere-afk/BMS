<?php
// File: api/apply_leave.php
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

if (!canCreate('leaves')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to apply for leave']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $required_fields = ['employee_id', 'leave_type', 'start_date', 'end_date', 'total_days', 'reason'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field '$field' is required.");
        }
    }

    $employee_id = intval($_POST['employee_id']);

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }

    $leave_type_input = trim($_POST['leave_type']);
    $start_date = trim($_POST['start_date']);
    $end_date = trim($_POST['end_date']);
    $total_days = floatval($_POST['total_days']);
    $reason = trim($_POST['reason']);
    $notes = trim($_POST['notes'] ?? '');
    $contact_during_leave = trim($_POST['contact_during_leave'] ?? '');
    $handover_to = !empty($_POST['handover_to']) ? intval($_POST['handover_to']) : null;
    $half_day = trim($_POST['half_day'] ?? '');
    $is_paid = isset($_POST['is_paid']) ? intval($_POST['is_paid']) : 1;

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
    // Final check against enum keys
    $valid_enums = ['annual', 'sick', 'maternity', 'paternity', 'study', 'unpaid', 'other'];
    if (!in_array($leave_type, $valid_enums)) {
        // Fallback: lowercase first word
        $leave_type = strtolower(explode(' ', $leave_type_input)[0]);
        if (!in_array($leave_type, $valid_enums)) {
            $leave_type = 'other';
        }
    }

    // Generate reference number: LEV-Year-RandomString
    $reference_number = 'LEV-' . date('Y') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

    // Handle document upload if present
    $document_path = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/hr/leaves/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_ext = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION);
        $file_name = $reference_number . '.' . $file_ext;
        if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir . $file_name)) {
            $document_path = 'uploads/hr/leaves/' . $file_name;
            registerFileInLibrary($pdo, $document_path, $_FILES['document']['name'], $_FILES['document']['size'], 'Leave Application Document - ' . $reference_number, 'leave,hr', $_SESSION['user_id']);
        }
    }

    $pdo->beginTransaction();

    $query = "INSERT INTO leaves (
        employee_id, leave_type, start_date, end_date, 
        total_days, days_count, reason, notes, status, created_by, applied_by, created_at
    ) VALUES (
        ?, ?, ?, ?, 
        ?, ?, ?, ?, 'pending', ?, ?, NOW()
    )";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $employee_id,
        $leave_type,
        $start_date,
        $end_date,
        $total_days,
        $total_days,
        $reason,
        $notes,
        $_SESSION['user_id'],
        $_SESSION['user_id']
    ]);

    $leave_id = $pdo->lastInsertId();

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], 'Create leave request', "User created a new leave request: $reference_number (ID $leave_id)");

    echo json_encode([
        'success' => true,
        'message' => 'Leave application submitted successfully. Reference: ' . $reference_number,
        'leave_id' => $leave_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
