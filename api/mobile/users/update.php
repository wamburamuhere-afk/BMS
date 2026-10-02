<?php
// scope-audit: skip — admin-only user management (same gate as the web Users page)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/_common.php';
mobileBearerAuth();
mobileUsersBootstrap('POST');

$user_id = (int)($_POST['user_id'] ?? 0);
if ($user_id <= 0) mobileUsersFail(400, 'Invalid user ID');
$st = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$st->execute([$user_id]);
$old = $st->fetch(PDO::FETCH_ASSOC);
if (!$old) mobileUsersFail(404, 'User not found');

// Partial update: omitted fields keep their current value.
$pick = fn(string $k) => array_key_exists($k, $_POST) ? trim((string)$_POST[$k]) : (string)($old[$k] ?? '');
$d = [
    'username'         => $pick('username'),
    'email'            => $pick('email'),
    'first_name'       => $pick('first_name'),
    'last_name'        => $pick('last_name'),
    'phone'            => $pick('phone'),
    'role_id'          => array_key_exists('role_id', $_POST) ? (int)$_POST['role_id'] : (int)$old['role_id'],
    'password'         => (string)($_POST['password'] ?? ''),
    'confirm_password' => (string)($_POST['confirm_password'] ?? ''),
];
$errors = mobileUsersValidate($pdo, $d, $user_id);
if ($errors) mobileUsersFail(422, reset($errors), $errors);
$isMe = $user_id === (int)$_SESSION['user_id'];
if ($isMe && $d['role_id'] !== (int)$old['role_id']) mobileUsersFail(409, 'You cannot change your own role');
$shops = mobileShopsFromRequest();

try {
    $pdo->beginTransaction();
    $sql = "UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, phone = ?, role_id = ?, updated_at = NOW()";
    $p = [$d['username'], $d['email'], $d['first_name'], $d['last_name'], $d['phone'] !== '' ? $d['phone'] : null, $d['role_id']];
    if ($d['password'] !== '') { $sql .= ", password = ?, password_changed_at = NOW()"; $p[] = password_hash($d['password'], PASSWORD_DEFAULT); }
    $p[] = $user_id;
    $pdo->prepare("$sql WHERE user_id = ?")->execute($p);
    if ($shops) mobileSetUserShops($pdo, $user_id, $shops[0], $shops[1]);
    // An admin-set password signs that user out of the app on every device.
    if ($d['password'] !== '' && !$isMe) $pdo->prepare("DELETE FROM mobile_tokens WHERE user_id = ?")->execute([$user_id]);
    $pdo->commit();
} catch (InvalidArgumentException $ie) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    mobileUsersFail(422, $ie->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('mobile/users/update.php: ' . $e->getMessage());
    mobileUsersFail(500, 'Server error');
}
if ($shops && function_exists('refreshScopeCache')) refreshScopeCache($user_id);
logActivity($pdo, $_SESSION['user_id'], 'Edit user', "Mobile: edited user {$d['first_name']} {$d['last_name']} ({$d['username']}, ID $user_id)");
logAudit($pdo, $_SESSION['user_id'], 'update_user', [
    'entity_type' => 'user', 'entity_id' => $user_id,
    'description' => "Updated user: {$d['username']}" . ($d['password'] !== '' ? ' (Password changed)' : '') . ' via mobile',
    'old_values'  => ['username' => $old['username'], 'email' => $old['email'], 'first_name' => $old['first_name'], 'last_name' => $old['last_name'], 'role_id' => $old['role_id']],
    'new_values'  => ['username' => $d['username'], 'email' => $d['email'], 'first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'role_id' => $d['role_id'], 'password_changed' => $d['password'] !== ''],
]);
echo json_encode(['success' => true, 'message' => t('User updated successfully!'), 'user' => mobileUserRow($pdo, $user_id)]);
