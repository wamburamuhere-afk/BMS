<?php
/**
 * api/account/update_recurring_status.php
 * Pause / resume / end a recurring profile.
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canEdit('expenses')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id <= 0) throw new Exception('Missing profile.');
    $map = ['pause' => 'paused', 'resume' => 'active', 'end' => 'ended'];
    if (!isset($map[$action])) throw new Exception('Invalid action.');

    $pdo->prepare("UPDATE recurring_profiles SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$map[$action], $id]);
    logActivity($pdo, $_SESSION['user_id'], "Recurring profile $action", "Profile ID: $id");
    echo json_encode(['success' => true, 'message' => 'Profile ' . $map[$action] . '.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
