<?php
/**
 * api/request_module_access.php — a tenant admin asks for a module their
 * plan doesn't include (tenant_module_control_plan.md, Phase C).
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/module_requests.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Same "hard isAdmin() gate, no delegable permission" as the page itself —
// this is a decision about the company's OWN subscription, not a role-
// configurable action.
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

$tenantId = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;
if ($tenantId === null) {
    // Single-tenant installs have nothing to request — every module is
    // already on. Not an error the visitor caused.
    echo json_encode(['success' => false, 'message' => 'Every module is already available on this installation.']);
    exit;
}

$featureKey = trim((string)($_POST['feature_key'] ?? ''));
$note       = trim((string)($_POST['note'] ?? ''));
if ($featureKey === '') {
    echo json_encode(['success' => false, 'message' => 'Choose a module to request.']);
    exit;
}

$r = createModuleRequest($tenantId, $featureKey, (int)$_SESSION['user_id'], $note !== '' ? $note : null);

if (!$r['ok']) {
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

logActivity($pdo, (int)$_SESSION['user_id'], 'Requested module: ' . $featureKey);

echo json_encode([
    'success' => true,
    'message' => $r['already_updated']
        ? 'Your existing request was updated.'
        : 'Your request has been sent to the platform administrator.',
]);
