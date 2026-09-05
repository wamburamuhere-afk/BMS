<?php
/**
 * actions/superadmin_plans.php — create/update/retire a plan. Control
 * database only. Guard order mirrors every other superadmin action: host,
 * session, POST, CSRF.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/plans.php';
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

if (currentSuperadmin() === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();

$action = (string)($_POST['action'] ?? '');

/** '' or non-numeric -> null (unlimited); otherwise the int. Same rule as superadmin_tenant_quotas.php. */
$toLimit = function (string $raw): ?int {
    $raw = trim($raw);
    return ($raw !== '' && ctype_digit($raw)) ? (int)$raw : null;
};

if ($action === 'create' || $action === 'update') {
    $in = [
        'name'           => (string)($_POST['name'] ?? ''),
        'description'    => (string)($_POST['description'] ?? ''),
        'max_users'      => $toLimit((string)($_POST['max_users'] ?? '')),
        'max_storage_mb' => $toLimit((string)($_POST['max_storage_mb'] ?? '')),
        'sort_order'     => (int)($_POST['sort_order'] ?? 0),
        'feature_keys'   => array_map('strval', (array)($_POST['feature_keys'] ?? [])),
    ];

    if ($action === 'create') {
        $r = createPlan($in);
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'No plan specified.']);
            exit;
        }
        $r = updatePlan($id, $in);
    }

    if (!$r['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => $action === 'create' ? 'Plan created.' : 'Plan updated.']);
    exit;
}

if ($action === 'toggle_active') {
    $id     = (int)($_POST['id'] ?? 0);
    $active = (string)($_POST['active'] ?? '') === '1';
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No plan specified.']);
        exit;
    }
    $r = setPlanActive($id, $active);
    if (!$r['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => $active ? 'Plan restored.' : 'Plan retired.']);
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
