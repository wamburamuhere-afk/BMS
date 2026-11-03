<?php
// api/update_customer_group.php — edit a customer group (app/bms/customer/customer_groups.php)
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
[$g, $err] = customerGroupFromPost($_POST);
if ($id <= 0 || $err) {
    echo json_encode(['success' => false, 'message' => $err ?: 'Invalid group']);
    exit;
}

try {
    $old = customerGroupFind($pdo, $id);
    if (!$old) {
        echo json_encode(['success' => false, 'message' => 'Group not found']);
        exit;
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM customer_groups WHERE LOWER(group_name) = LOWER(?) AND group_id != ? AND status != 'deleted'");
    $st->execute([$g['group_name'], $id]);
    if ((int)$st->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'A group with this name already exists']);
        exit;
    }

    $uid = (int)$_SESSION['user_id'];
    $pdo->beginTransaction();
    // A group switched to static keeps its current members (and no rule).
    $pdo->prepare("UPDATE customer_groups SET group_name = ?, description = ?, group_type = ?, color = ?, status = ?, rules = ?, updated_by = ?
                   WHERE group_id = ?")
        ->execute([$g['group_name'], $g['description'] !== '' ? $g['description'] : null, $g['group_type'], $g['color'],
                   $g['status'], $g['rules'] ? json_encode($g['rules']) : null, $uid, $id]);

    $message = 'Customer group updated successfully.';
    if ($g['group_type'] === 'dynamic') {
        $r = customerGroupRefresh($pdo, $id, $g['rules'], $uid);
        $message .= ' ' . (int)($r['total'] ?? 0) . ' customer(s) match its rule.';
    }
    $pdo->commit();

    logActivity($pdo, $uid, "Updated customer group #$id: {$g['group_name']}");
    echo json_encode(['success' => true, 'message' => $message]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('update_customer_group: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
