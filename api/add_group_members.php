<?php
// api/add_group_members.php — add customers to a static customer group
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
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['customer_ids'] ?? [])))));
if ($id <= 0 || !$ids) {
    echo json_encode(['success' => false, 'message' => 'Select at least one customer to add']);
    exit;
}

try {
    $g = customerGroupFind($pdo, $id);
    if (!$g) {
        echo json_encode(['success' => false, 'message' => 'Group not found']);
        exit;
    }
    if ($g['group_type'] !== 'static') {
        echo json_encode(['success' => false, 'message' => 'Members of a dynamic group follow its rule — use Refresh instead']);
        exit;
    }

    // Only live customers; already-members are skipped by the unique key.
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id IN ($in) AND status != 'deleted'");
    $st->execute($ids);
    $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $uid = (int)$_SESSION['user_id'];
    $ins = $pdo->prepare("INSERT IGNORE INTO customer_group_customers (group_id, customer_id, added_by, added_at) VALUES (?, ?, ?, NOW())");
    $added = 0;
    foreach ($valid as $cid) {
        $ins->execute([$id, $cid, $uid]);
        $added += $ins->rowCount();
    }

    logActivity($pdo, $uid, "Added $added customer(s) to customer group #$id: {$g['group_name']}");
    echo json_encode(['success' => true, 'message' => "$added customer(s) added to the group.", 'added' => $added]);
} catch (PDOException $e) {
    error_log('add_group_members: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
