<?php
// api/refresh_dynamic_group.php — re-apply a dynamic customer group's rule to its members
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

$id = (int)($_POST['group_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid group']);
    exit;
}

try {
    $g = customerGroupFind($pdo, $id);
    if (!$g) {
        echo json_encode(['success' => false, 'message' => 'Group not found']);
        exit;
    }
    if ($g['group_type'] !== 'dynamic') {
        echo json_encode(['success' => false, 'message' => 'Only a dynamic group can be refreshed']);
        exit;
    }
    $rules = $g['rules'] ? json_decode($g['rules'], true) : null;
    $r = customerGroupRefresh($pdo, $id, is_array($rules) ? $rules : null, (int)$_SESSION['user_id']);
    if (isset($r['error'])) {
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }

    logActivity($pdo, $_SESSION['user_id'], "Refreshed customer group #$id: {$g['group_name']} (+{$r['added']} / -{$r['removed']})");
    echo json_encode(['success' => true,
        'message' => "Group refreshed: {$r['total']} member(s) — {$r['added']} added, {$r['removed']} removed.",
        'added' => $r['added'], 'removed' => $r['removed'], 'total' => $r['total']]);
} catch (PDOException $e) {
    error_log('refresh_dynamic_group: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
