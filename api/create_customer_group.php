<?php
// api/create_customer_group.php — create a customer group (app/bms/customer/customer_groups.php)
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/customer_groups.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canCreate('customer_groups')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

[$g, $err] = customerGroupFromPost($_POST);
if ($err) {
    echo json_encode(['success' => false, 'message' => $err]);
    exit;
}

try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM customer_groups WHERE LOWER(group_name) = LOWER(?) AND status != 'deleted'");
    $st->execute([$g['group_name']]);
    if ((int)$st->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'A group with this name already exists']);
        exit;
    }

    $uid = (int)$_SESSION['user_id'];
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO customer_groups (group_name, description, group_type, color, status, rules, created_by, created_at, updated_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)")
        ->execute([$g['group_name'], $g['description'] !== '' ? $g['description'] : null, $g['group_type'], $g['color'],
                   $g['status'], $g['rules'] ? json_encode($g['rules']) : null, $uid, $uid]);
    $id = (int)$pdo->lastInsertId();

    $message = 'Customer group created successfully.';
    if ($g['group_type'] === 'dynamic') {
        $r = customerGroupRefresh($pdo, $id, $g['rules'], $uid);
        $message .= ' ' . (int)($r['total'] ?? 0) . ' customer(s) match its rule.';
    }
    $pdo->commit();

    logActivity($pdo, $uid, "Created customer group: {$g['group_name']}");
    echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('create_customer_group: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
