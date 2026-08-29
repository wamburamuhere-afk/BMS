<?php
/**
 * api/revoke_session.php
 * Admin action: force-end a live session from the Login History page.
 * Strictly admin-only, same lock as login_history.php itself.
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$id     = intval($_POST['id'] ?? 0);
$reason = ($_POST['reason'] ?? 'revoked') === 'admin_ended' ? 'admin_ended' : 'revoked';

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid session id']);
    exit;
}

$row = $pdo->prepare("SELECT us.user_id, us.logout_at, u.username FROM user_sessions us LEFT JOIN users u ON u.user_id = us.user_id WHERE us.id = ?");
$row->execute([$id]);
$target = $row->fetch(PDO::FETCH_ASSOC);

if (!$target) {
    echo json_encode(['success' => false, 'message' => 'Session not found']);
    exit;
}
if ($target['logout_at'] !== null) {
    echo json_encode(['success' => false, 'message' => 'That session has already ended']);
    exit;
}

$ok = revokeUserSession($pdo, $id, (int) $_SESSION['user_id'], $reason);

if ($ok) {
    $verb  = $reason === 'revoked' ? 'Revoked' : 'Ended';
    $label = $verb . ' session #' . $id . ' for ' . ($target['username'] ?? 'user #' . $target['user_id']);
    logActivity($pdo, (int) $_SESSION['user_id'], $label);
    logAudit($pdo, (int) $_SESSION['user_id'], 'session_' . $reason, [
        'entity_type' => 'user_session',
        'entity_id'   => $id,
        'new_values'  => ['target_user_id' => $target['user_id'], 'reason' => $reason],
    ]);
}

echo json_encode([
    'success' => $ok,
    'message' => $ok ? 'Session ended.' : 'Could not end that session (it may have already ended).',
]);
