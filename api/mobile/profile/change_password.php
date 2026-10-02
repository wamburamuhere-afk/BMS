<?php
// scope-audit: skip — the caller's own user row only
// Same rules as the web My Profile page: current password must match, new ≥ 8 chars,
// confirmation must match, and it cannot equal the current password.
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();

$fail = function (int $c, string $m) { http_response_code($c); echo json_encode(['success'=>false,'message'=>$m]); exit; };

$uid     = (int)$_SESSION['user_id'];
$current = (string)($_POST['current_password'] ?? '');
$new     = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

$st = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
$st->execute([$uid]);
$hash = (string)$st->fetchColumn();

if ($current === '' || !password_verify($current, $hash)) $fail(422, 'Current password is incorrect');
if ($new === '') $fail(422, 'New password is required');
if (strlen($new) < 8) $fail(422, 'New password must be at least 8 characters long');
if ($new !== $confirm) $fail(422, 'New passwords do not match');
if (password_verify($new, $hash)) $fail(422, 'New password cannot be the same as current password');

try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE user_id = ?")
        ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
    // Sign out every OTHER device; the token making this request stays valid.
    // (Unknown when authenticated by a session cookie — then leave tokens alone.)
    if (!empty($GLOBALS['BMS_MOBILE_TOKEN_ID'])) {
        $pdo->prepare("DELETE FROM mobile_tokens WHERE user_id = ? AND token_id <> ?")
            ->execute([$uid, (int)$GLOBALS['BMS_MOBILE_TOKEN_ID']]);
    }

    $pdo->commit();
    logActivity($pdo, $uid, 'Changed own password (mobile)');
    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('mobile/profile/change_password.php: ' . $e->getMessage());
    $fail(500, 'Server error');
}
