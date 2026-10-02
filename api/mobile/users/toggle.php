<?php
// scope-audit: skip — admin-only user management; delegates to ajax/toggle_user.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/_common.php';
mobileBearerAuth();
mobileUsersBootstrap('POST');

$user_id = (int)($_POST['user_id'] ?? 0);
$action  = $_POST['action'] ?? '';
if ($user_id <= 0) mobileUsersFail(400, 'Invalid user ID');
if (!in_array($action, ['activate', 'deactivate'], true)) mobileUsersFail(422, "action must be 'activate' or 'deactivate'");
if ($user_id === (int)$_SESSION['user_id']) mobileUsersFail(409, 'You cannot deactivate your own account');
$st = $pdo->prepare("SELECT is_active FROM users WHERE user_id = ?");
$st->execute([$user_id]);
$cur = $st->fetchColumn();
if ($cur === false) mobileUsersFail(404, 'User not found');
if ($action === 'activate' && (int)$cur === 0 && function_exists('tenantWithinUserLimit') && !tenantWithinUserLimit($pdo)) {
    mobileUsersFail(409, "You have reached your plan's user limit. Deactivate a different user to free a seat, or ask the platform to raise your limit.");
}

// Same code as the web Users page: ends their web sessions, emails them on reactivation, audits.
$_POST = ['user_id' => $user_id, 'action' => $action];
mobileRun($pdo, 'users/toggle', null, function () {
    global $pdo;
    require __DIR__ . '/../../../ajax/toggle_user.php';
});
// Deactivation also signs the user out of the app on every device.
if ($action === 'deactivate') {
    try { $pdo->prepare("DELETE FROM mobile_tokens WHERE user_id = ?")->execute([$user_id]); } catch (Throwable $e) {}
}
