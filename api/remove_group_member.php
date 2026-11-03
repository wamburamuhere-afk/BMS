<?php
// api/remove_group_member.php — remove one customer from a static customer group
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/customer_groups.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('customer_groups')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$id  = (int)($_POST['group_id'] ?? 0);
$cid = (int)($_POST['customer_id'] ?? 0);
if ($id <= 0 || $cid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid group or customer']);
    exit;
}

try {
    $g = customerGroupFind($pdo, $id);
    if (!$g) {
        echo json_encode(['success' => false, 'message' => 'Group not found']);
        exit;
    }
    if ($g['group_type'] !== 'static') {
        echo json_encode(['success' => false, 'message' => 'Members of a dynamic group follow its rule — edit the rule instead']);
        exit;
    }
    // Membership is a link row (no status of its own) — removing it is the removal.
    $st = $pdo->prepare("DELETE FROM customer_group_customers WHERE group_id = ? AND customer_id = ?");
    $st->execute([$id, $cid]);
    if ($st->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'That customer is not in this group']);
        exit;
    }

    logActivity($pdo, $_SESSION['user_id'], "Removed customer #$cid from customer group #$id: {$g['group_name']}");
    echo json_encode(['success' => true, 'message' => 'Customer removed from the group.']);
} catch (PDOException $e) {
    error_log('remove_group_member: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
