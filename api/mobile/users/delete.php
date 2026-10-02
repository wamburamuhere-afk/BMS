<?php
// scope-audit: skip — admin-only user management (same rules as ajax/delete_user.php)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/_common.php';
mobileBearerAuth();
mobileUsersBootstrap('POST');

$user_id = (int)($_POST['user_id'] ?? 0);
if ($user_id <= 0) mobileUsersFail(400, 'Invalid user ID');
if ($user_id === (int)$_SESSION['user_id']) mobileUsersFail(409, 'You cannot delete your own account');
$st = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
$st->execute([$user_id]);
$username = $st->fetchColumn();
if ($username === false) mobileUsersFail(404, 'User not found');

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM mobile_tokens WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$user_id]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') mobileUsersFail(409, 'This user has records (sales, stock, etc.) and cannot be deleted — deactivate the account instead.');
    error_log('mobile/users/delete.php: ' . $e->getMessage());
    mobileUsersFail(500, 'Server error');
}
logAudit($pdo, $_SESSION['user_id'], 'delete_user', [
    'entity_type' => 'user', 'entity_id' => $user_id,
    'description' => "Deleted user '{$username}' (ID: $user_id) via mobile",
    'old_values'  => ['username' => $username], 'new_values' => null,
]);
echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
