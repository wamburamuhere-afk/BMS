<?php
// File: api/apply_leave.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_leave.log');

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/leave_rules.php';
require_once __DIR__ . '/../core/employee_status.php';

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
    // total_days is no longer required from the client — it is computed server-side
    // from the dates and the half-day selection.
    $required_fields = ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'reason'];
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
    if (function_exists('assertEmployeeActive')) {
        assertEmployeeActive($pdo, $employee_id);
    }

    $start_date = trim($_POST['start_date']);
    $end_date   = trim($_POST['end_date']);
    $reason     = trim($_POST['reason']);
    $contact_during_leave = trim($_POST['contact_during_leave'] ?? '') ?: null;
    $handover_to = !empty($_POST['handover_to']) ? intval($_POST['handover_to']) : null;
    if ($handover_to !== null && function_exists('assertEmployeeActive')) {
        assertEmployeeActive($pdo, $handover_to, 'Handover contact');
    }

    // The leave type is a real FK now. The legacy ENUM is dual-written for the
    // readers still on it (leave_reports, export_leaves, project_view); it is
    // dropped once those are migrated.
    $type       = leaveTypeForApply($pdo, $_POST['leave_type_id'] ?? null);
    $leave_type = legacyLeaveTypeEnum($type);

    // Paid/unpaid is a property of the TYPE, not a choice on the form. Snapshot it
    // so re-classifying the type later never rewrites this leave's history.
    $is_paid = (int)$type['is_paid'];

    $hd          = normaliseHalfDay($_POST);
    $half_day    = $hd['half_day'];
    $leave_hours = $hd['leave_hours'];

    // Days are computed server-side and checked against the type's limits, which
    // used to be a client-side hint only.
    $total_days = leaveDaysFor($start_date, $end_date, $half_day, $leave_hours);
    assertLeaveWithinTypeLimits($pdo, $type, (int)$employee_id, $start_date, $total_days);

    if ((int)$type['requires_document'] === 1
        && (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK)) {
        throw new Exception("{$type['type_name']} requires a supporting document.");
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

    // `notes` is no longer collected (the Additional Notes field was removed) and
    // is left at its column default rather than written as an empty string.
    // half_day / leave_hours / is_paid / contact_during_leave / handover_to were
    // all read from $_POST before and silently dropped — they are stored now.
    $query = "INSERT INTO leaves (
        employee_id, leave_type_id, leave_type, start_date, end_date,
        total_days, days_count, half_day, leave_hours, is_paid,
        reason, contact_during_leave, handover_to, document_path,
        status, created_by, applied_by, created_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        'pending', ?, ?, NOW()
    )";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $employee_id,
        (int)$type['type_id'],
        $leave_type,
        $start_date,
        $end_date,
        $total_days,
        $total_days,
        $half_day,
        $leave_hours,
        $is_paid,
        $reason,
        $contact_during_leave,
        $handover_to,
        $document_path,
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
