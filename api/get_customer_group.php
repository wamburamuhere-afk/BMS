<?php
// api/get_customer_group.php — one customer group for the edit form
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/customer_groups.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('customer_groups')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
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
    $rules = $g['rules'] ? json_decode($g['rules'], true) : null;
    echo json_encode(['success' => true, 'data' => [
        'group_id'    => (int)$g['group_id'],
        'group_name'  => $g['group_name'],
        'description' => (string)$g['description'],
        'group_type'  => $g['group_type'],
        'color'       => $g['color'] ?: '#007bff',
        'status'      => $g['status'],
        'rules'       => is_array($rules) ? $rules : null,
    ]]);
} catch (PDOException $e) {
    error_log('get_customer_group: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
