<?php
/**
 * actions/superadmin_module_requests.php — approve/decline a pending module
 * request (tenant_module_control_plan.md, Phase C).
 *
 * Guard order mirrors actions/superadmin_platform_settings.php exactly:
 * correct host, signed-in operator, POST only, CSRF.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/module_requests.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$me = currentSuperadmin();
if ($me === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();

$action = (string)($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);

if (!in_array($action, ['approve', 'decline'], true) || $id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$decisionNote = trim((string)($_POST['decision_note'] ?? ''));

$r = decideModuleRequest($id, (int)$me['id'], $action === 'approve', $decisionNote !== '' ? $decisionNote : null);

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => $action === 'approve' ? 'Request approved.' : 'Request declined.',
]);
