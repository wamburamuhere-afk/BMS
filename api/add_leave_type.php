<?php
// API: Add Leave Type
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/leave_type_validation.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canCreate('leave_types')) {
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
    $data = validateLeaveTypeInput($pdo, $_POST, null);

    $stmt = $pdo->prepare("
        INSERT INTO leave_types (
            type_name, description, max_days_per_year, min_days_before_apply,
            max_consecutive_days, requires_document, is_paid, carry_over_days,
            color, status, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $data['type_name'], $data['description'], $data['max_days_per_year'],
        $data['min_days_before_apply'], $data['max_consecutive_days'],
        $data['requires_document'], $data['is_paid'], $data['carry_over_days'],
        $data['color'], $data['status'], $_SESSION['user_id'],
    ]);
    $new_id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Created leave type: {$data['type_name']}");
    logAudit($pdo, $_SESSION['user_id'], 'create', [
        'activity_type' => 'create',
        'entity_type'   => 'leave_type',
        'entity_id'     => $new_id,
        'description'   => "Added leave type '{$data['type_name']}'",
        'new_values'    => $data,
    ]);

    echo json_encode(['success' => true, 'message' => 'Leave type created successfully.', 'id' => $new_id]);

} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('add_leave_type: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
