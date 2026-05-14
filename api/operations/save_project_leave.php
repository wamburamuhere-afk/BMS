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
    $leave_id   = intval($_POST['leave_id']   ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $leave_type  = trim($_POST['leave_type']   ?? '');
    $start_date  = trim($_POST['start_date']   ?? '');
    $end_date    = trim($_POST['end_date']     ?? '');
    $total_days  = floatval($_POST['total_days'] ?? 0);
    $reason      = trim($_POST['reason']       ?? '');
    $status      = trim($_POST['status']       ?? 'pending');
    $notes       = trim($_POST['notes']        ?? '');

    if (!$employee_id || !$leave_type || !$start_date || !$end_date || !$reason) {
        throw new Exception('Required fields missing');
    }

    // Verify employee belongs to this project
    $chk = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ? AND project_id = ?");
    $chk->execute([$employee_id, $project_id]);
    if (!$chk->fetch()) {
        throw new Exception('Staff member does not belong to this project');
    }

    $valid_enums = ['annual','sick','maternity','paternity','study','unpaid','other'];
    if (!in_array($leave_type, $valid_enums)) {
        throw new Exception('Invalid leave type');
    }

    if ($leave_id) {
        $pdo->prepare("UPDATE leaves SET employee_id=?, leave_type=?, start_date=?, end_date=?, total_days=?, reason=?, status=?, notes=?, updated_at=NOW() WHERE leave_id=?")
            ->execute([$employee_id, $leave_type, $start_date, $end_date, $total_days, $reason, $status, $notes, $leave_id]);
        echo json_encode(['success' => true, 'message' => 'Leave updated successfully']);
    } else {
        $pdo->prepare("INSERT INTO leaves (employee_id, leave_type, start_date, end_date, total_days, reason, status, notes, applied_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$employee_id, $leave_type, $start_date, $end_date, $total_days, $reason, $status, $notes, $_SESSION['user_id']]);
        echo json_encode(['success' => true, 'message' => 'Leave applied successfully']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
