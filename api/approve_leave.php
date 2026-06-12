<?php
// File: api/approve_leave.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_leave.log');

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/leave_balance.php';   // Plan H3 — balance enforcement

ob_clean();
header('Content-Type: application/json');

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canApprove('leaves') && !canEdit('leaves')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to approve leaves']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $leave_id = isset($_POST['leave_id']) ? intval($_POST['leave_id']) : 0;
    
    if (!$leave_id) {
        throw new Exception("Leave ID is required");
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployeeRecord')) {
        assertScopeForEmployeeRecord('leaves', 'leave_id', $leave_id);
    }

    // Plan H3 — enforce the leave balance. Approving must not push a PAID leave type
    // over its entitlement (+ carry-over) for the year. Untracked types (no matching
    // leave_types config) and unpaid leave are not blocked — they degrade to allow.
    $lv = $pdo->prepare("SELECT employee_id, leave_type, total_days, YEAR(start_date) AS yr FROM leaves WHERE leave_id = ?");
    $lv->execute([$leave_id]);
    $lvRow = $lv->fetch(PDO::FETCH_ASSOC);
    if ($lvRow) {
        $bal = leaveBalanceFor($pdo, (int)$lvRow['employee_id'], (string)$lvRow['leave_type'], (int)$lvRow['yr'], $leave_id);
        $requested = (float)$lvRow['total_days'];
        if ($bal['tracked'] && $bal['is_paid'] && ($bal['used'] + $requested) > ($bal['entitled'] + $bal['carried_over'] + 0.001)) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot approve: this would exceed the leave balance. Available '
                    . number_format($bal['available'], 1) . ' day(s), requested ' . number_format($requested, 1)
                    . '. Reduce the days, or record it as Unpaid Leave.',
            ]);
            exit();
        }
    }

    $pdo->beginTransaction();

    $query = "UPDATE leaves SET
        status = 'approved',
        approved_by = ?,
        approved_date = NOW(),
        approved_at = NOW(),
        updated_at = NOW()
    WHERE leave_id = ? AND status = 'pending'";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$_SESSION['user_id'], $leave_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Leave record not found or already processed");
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Approved Leave", "Leave ID: $leave_id");

    echo json_encode([
        'success' => true,
        'message' => 'Leave application approved successfully'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
