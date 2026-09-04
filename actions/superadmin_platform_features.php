<?php
/**
 * actions/superadmin_platform_features.php — change one feature for the whole
 * platform: remove it from every tenant (`is_available`), or change what a newly
 * registered company starts with (`default_enabled`).
 *
 * Separate endpoint from the per-tenant one on purpose. These two decisions have
 * very different blast radii — one affects a single company, the other affects
 * every company at once — and sharing an endpoint would mean one careless
 * parameter could turn the small action into the large one.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
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

$key = trim((string)($_POST['feature_key'] ?? ''));
if ($key === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No feature specified.']);
    exit;
}

// Absent field = leave that column alone. Only what the operator actually
// touched is changed, so the two switches never overwrite each other.
$available = array_key_exists('is_available', $_POST)
    ? ((string)$_POST['is_available'] === '1') : null;
$default   = array_key_exists('default_enabled', $_POST)
    ? ((string)$_POST['default_enabled'] === '1') : null;

if ($available === null && $default === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Nothing to change.']);
    exit;
}

$r = setPlatformFeature($key, $available, $default);

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

$msg = [];
if ($available !== null) {
    $msg[] = $available
        ? 'Restored platform-wide.'
        : 'Removed platform-wide — no tenant can reach it, including any that had it granted.';
}
if ($default !== null) {
    $msg[] = 'Newly registered companies now start with it ' . ($default ? 'on' : 'off') . '.';
}

echo json_encode(['success' => true, 'message' => implode(' ', $msg)]);
