<?php
// api/operations/save_project_leave.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $leave_id    = intval($_POST['leave_id']    ?? 0);

    if ($leave_id > 0 ? !canEdit('projects') : !canCreate('projects')) {
        throw new Exception('Access Denied: you do not have permission to ' . ($leave_id > 0 ? 'edit' : 'create') . ' project leaves');
    }
    $project_id  = intval($_POST['project_id']  ?? 0);
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $leave_type  = trim($_POST['leave_type']    ?? '');
    $start_date  = trim($_POST['start_date']    ?? '');
    $end_date    = trim($_POST['end_date']      ?? '');
    $total_days  = floatval($_POST['total_days'] ?? 0);
    $reason      = trim($_POST['reason']        ?? '');
    $status      = trim($_POST['status']        ?? 'pending');
    $notes       = trim($_POST['notes']         ?? '');

    if (!$employee_id || !$leave_type || !$start_date || !$end_date || !$reason) {
        throw new Exception('Required fields missing');
    }

    // Phase B (scope) — block writes against projects not in user scope
    if ($project_id > 0 && !userCan('project', $project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this project is not in your scope.');
    }

    // Verify employee belongs to this project
    $chk = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ? AND project_id = ?");
    $chk->execute([$employee_id, $project_id]);
    if (!$chk->fetch()) {
        throw new Exception('Staff member does not belong to this project');
    }

    // Map type names to ENUM if full name was passed
    $type_map = [
        'Annual Leave' => 'annual', 'Sick Leave' => 'sick',
        'Maternity Leave' => 'maternity', 'Paternity Leave' => 'paternity',
        'Study Leave' => 'study', 'Unpaid Leave' => 'unpaid',
        'Compassionate Leave' => 'other', 'Other' => 'other',
    ];
    if (isset($type_map[$leave_type])) {
        $leave_type = $type_map[$leave_type];
    }
    $valid_enums = ['annual', 'sick', 'maternity', 'paternity', 'study', 'unpaid', 'other'];
    if (!in_array($leave_type, $valid_enums)) {
        throw new Exception('Invalid leave type');
    }

    // Handle document upload
    $document_path = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../uploads/hr/leaves/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext       = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        $file_name = 'leave_' . $employee_id . '_' . date('Ymd_His') . '.' . $ext;
        if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir . $file_name)) {
            $document_path = 'uploads/hr/leaves/' . $file_name;
        }
    }

    if ($leave_id) {
        $sql = "UPDATE leaves SET employee_id=?, leave_type=?, start_date=?, end_date=?, total_days=?, days_count=?, reason=?, status=?, notes=?, updated_at=NOW()";
        $params = [$employee_id, $leave_type, $start_date, $end_date, $total_days, $total_days, $reason, $status, $notes];
        if ($document_path) { $sql .= ', document_path=?'; $params[] = $document_path; }
        $sql .= ' WHERE leave_id=?';
        $params[] = $leave_id;
        $pdo->prepare($sql)->execute($params);

        // Phase 3c — leave changes affect attendance + payroll.
        logActivity($pdo, $_SESSION['user_id'], "Updated Project Leave", "Leave ID: $leave_id, employee: $employee_id, status: $status");

        echo json_encode(['success' => true, 'message' => 'Leave updated successfully']);
    } else {
        $sql = "INSERT INTO leaves (employee_id, leave_type, start_date, end_date, total_days, days_count, reason, status, notes, document_path, applied_by, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())";
        $pdo->prepare($sql)->execute([
            $employee_id, $leave_type, $start_date, $end_date, $total_days, $total_days,
            $reason, $status, $notes, $document_path,
            $_SESSION['user_id'], $_SESSION['user_id']
        ]);
        $leave_id = $pdo->lastInsertId();

        // Phase 3c — leave applications create new obligations on the schedule.
        logActivity($pdo, $_SESSION['user_id'], "Applied Project Leave", "Leave ID: $leave_id, employee: $employee_id, type: $leave_type, days: $total_days");

        echo json_encode(['success' => true, 'message' => 'Leave applied successfully']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
