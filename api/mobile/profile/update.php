<?php
// scope-audit: skip — the caller's own user row only
// Same rules as the web My Profile page (app/constant/profile/profile.php).
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();

$uid = (int)$_SESSION['user_id'];
$st = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE user_id = ?");
$st->execute([$uid]);
$old = $st->fetch(PDO::FETCH_ASSOC);
if (!$old) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'User not found']); exit; }

$pick = fn(string $k) => array_key_exists($k, $_POST) ? trim((string)$_POST[$k]) : (string)($old[$k] ?? '');
$first = $pick('first_name'); $last = $pick('last_name'); $email = $pick('email'); $phone = $pick('phone');

$errors = [];
if ($first === '') $errors['first_name'] = t('First name is required');
if ($last === '')  $errors['last_name']  = t('Last name is required');
if ($email === '') $errors['email'] = t('Email is required');
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = t('Invalid email format');
if ($errors) { http_response_code(422); echo json_encode(['success'=>false,'message'=>reset($errors),'errors'=>$errors]); exit; }

$dup = $pdo->prepare("SELECT 1 FROM users WHERE email = ? AND user_id <> ?");
$dup->execute([$email, $uid]);
if ($dup->fetchColumn()) { http_response_code(409); echo json_encode(['success'=>false,'message'=>'Email address is already taken by another user']); exit; }

try {
    $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, updated_at = NOW() WHERE user_id = ?")
        ->execute([$first, $last, $email, $phone !== '' ? $phone : null, $uid]);
    logActivity($pdo, $uid, 'Updated own profile details (mobile)');
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully',
        'user' => ['first_name' => $first, 'last_name' => $last, 'name' => trim("$first $last"), 'email' => $email, 'phone' => $phone]]);
} catch (Throwable $e) {
    error_log('mobile/profile/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
