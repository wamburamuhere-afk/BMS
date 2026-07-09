<?php
// API: Delete Leave Type
//
// A type with leaves booked against it is NEVER removed — the FK is ON DELETE
// RESTRICT and, more importantly, historical leaves must keep resolving to their
// type name on reports and on leave_details.php. Such a type is deactivated
// (status='inactive'): it disappears from the leave form's dropdown while every
// past leave still reads correctly.
//
// A type nothing references has no history to protect, so it is removed outright.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/leave_type_validation.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canDelete('leave_types')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    $type_id = intval($_POST['type_id'] ?? $_POST['id'] ?? 0);
    if ($type_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid leave type']);
        exit;
    }

    $st = $pdo->prepare("SELECT type_id, type_name, status FROM leave_types WHERE type_id = ?");
    $st->execute([$type_id]);
    $type = $st->fetch(PDO::FETCH_ASSOC);
    if (!$type) {
        echo json_encode(['success' => false, 'message' => 'Leave type not found']);
        exit;
    }

    $used = leaveTypeUsageCount($pdo, $type_id);

    if ($used > 0) {
        if ($type['status'] === 'inactive') {
            echo json_encode([
                'success' => false,
                'message' => "'{$type['type_name']}' is already deactivated and is used by $used leave record(s), so it cannot be removed.",
            ]);
            exit;
        }
        $pdo->prepare("UPDATE leave_types SET status = 'inactive', updated_at = NOW() WHERE type_id = ?")
            ->execute([$type_id]);

        logActivity($pdo, $_SESSION['user_id'], "Deactivated leave type: {$type['type_name']} ($used leave record(s) reference it)");
        logAudit($pdo, $_SESSION['user_id'], 'update', [
            'activity_type' => 'update',
            'entity_type'   => 'leave_type',
            'entity_id'     => $type_id,
            'description'   => "Deactivated leave type '{$type['type_name']}' — referenced by $used leave record(s)",
            'old_values'    => ['status' => $type['status']],
            'new_values'    => ['status' => 'inactive'],
        ]);

        echo json_encode([
            'success' => true,
            'deactivated' => true,
            'message' => "'{$type['type_name']}' is used by $used leave record(s), so it was deactivated instead of deleted. It no longer appears when applying for leave, and existing records are unchanged.",
        ]);
        exit;
    }

    $pdo->prepare("DELETE FROM leave_types WHERE type_id = ?")->execute([$type_id]);

    logActivity($pdo, $_SESSION['user_id'], "Deleted leave type: {$type['type_name']}");
    logAudit($pdo, $_SESSION['user_id'], 'delete', [
        'activity_type' => 'delete',
        'entity_type'   => 'leave_type',
        'entity_id'     => $type_id,
        'description'   => "Deleted unused leave type '{$type['type_name']}'",
        'old_values'    => $type,
    ]);

    echo json_encode(['success' => true, 'deactivated' => false, 'message' => "'{$type['type_name']}' deleted."]);

} catch (PDOException $e) {
    error_log('delete_leave_type: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
