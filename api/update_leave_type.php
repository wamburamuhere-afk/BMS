<?php
// API: Update Leave Type
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/leave_type_validation.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('leave_types')) {
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
    $type_id = intval($_POST['type_id'] ?? 0);
    if ($type_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid leave type']);
        exit;
    }

    $st = $pdo->prepare("SELECT * FROM leave_types WHERE type_id = ?");
    $st->execute([$type_id]);
    $old = $st->fetch(PDO::FETCH_ASSOC);
    if (!$old) {
        echo json_encode(['success' => false, 'message' => 'Leave type not found']);
        exit;
    }

    $data = validateLeaveTypeInput($pdo, $_POST, $type_id);

    $pdo->prepare("
        UPDATE leave_types SET
            type_name = ?, description = ?, max_days_per_year = ?, min_days_before_apply = ?,
            max_consecutive_days = ?, requires_document = ?, is_paid = ?, carry_over_days = ?,
            color = ?, status = ?, updated_at = NOW()
        WHERE type_id = ?
    ")->execute([
        $data['type_name'], $data['description'], $data['max_days_per_year'],
        $data['min_days_before_apply'], $data['max_consecutive_days'],
        $data['requires_document'], $data['is_paid'], $data['carry_over_days'],
        $data['color'], $data['status'], $type_id,
    ]);

    // leaves.is_paid is a snapshot taken when the leave was applied for, so
    // re-classifying a type here never rewrites the paid/unpaid history of
    // leaves already booked against it.
    $note = '';
    if ((int)$old['is_paid'] !== $data['is_paid']) {
        $used = leaveTypeUsageCount($pdo, $type_id);
        if ($used > 0) {
            $note = " Existing $used leave record(s) keep the paid/unpaid status they were approved under.";
        }
    }

    logActivity($pdo, $_SESSION['user_id'], "Updated leave type: {$data['type_name']}");
    logAudit($pdo, $_SESSION['user_id'], 'update', [
        'activity_type' => 'update',
        'entity_type'   => 'leave_type',
        'entity_id'     => $type_id,
        'description'   => "Updated leave type '{$data['type_name']}'",
        'old_values'    => $old,
        'new_values'    => $data,
    ]);

    echo json_encode(['success' => true, 'message' => 'Leave type updated successfully.' . $note]);

} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('update_leave_type: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
