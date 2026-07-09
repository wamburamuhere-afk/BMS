<?php
// API: Get a single Leave Type (edit modal) or the active list (dropdown refresh).
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// The leave form refreshes its dropdown after a type is added, so viewing the
// list only needs 'leaves' view rights; editing a type needs 'leave_types'.
$listOnly = ($_GET['list'] ?? '') === '1';

if ($listOnly) {
    if (!canView('leaves') && !canView('leave_types')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }
    $rows = $pdo->query("
        SELECT type_id, type_name, max_days_per_year, max_consecutive_days,
               requires_document, is_paid
          FROM leave_types
         WHERE status = 'active'
         ORDER BY type_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if (!canView('leave_types')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$type_id = intval($_GET['id'] ?? 0);
if ($type_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

$st = $pdo->prepare("SELECT * FROM leave_types WHERE type_id = ?");
$st->execute([$type_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Leave type not found']);
    exit;
}

require_once __DIR__ . '/../core/leave_type_validation.php';
$row['usage_count'] = leaveTypeUsageCount($pdo, $type_id);

echo json_encode(['success' => true, 'data' => $row]);
