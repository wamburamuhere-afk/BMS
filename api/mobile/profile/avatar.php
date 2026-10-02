<?php
// scope-audit: skip — the caller's own user row only
// Profile photo upload — same checks as the web My Profile page (security.md §19):
// extension + real MIME whitelist, 2 MB, random filename under uploads/avatars/.
// POST multipart: avatar (file). POST {"remove": true} clears the photo.
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();

$fail = function (int $c, string $m) { http_response_code($c); echo json_encode(['success'=>false,'message'=>$m]); exit; };
$uid = (int)$_SESSION['user_id'];
$dir = ROOT_DIR . '/uploads/avatars';

$st = $pdo->prepare("SELECT avatar FROM users WHERE user_id = ?");
$st->execute([$uid]);
$oldAvatar = (string)($st->fetchColumn() ?: '');

if (!empty($_POST['remove']) && $_POST['remove'] !== 'false') {
    $pdo->prepare("UPDATE users SET avatar = NULL, updated_at = NOW() WHERE user_id = ?")->execute([$uid]);
    if ($oldAvatar !== '' && is_file($dir . '/' . basename($oldAvatar))) @unlink($dir . '/' . basename($oldAvatar));
    echo json_encode(['success' => true, 'message' => 'Photo removed', 'avatar_url' => '']);
    exit;
}

if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) $fail(422, 'Please select a valid image file (field: avatar)');
$f = $_FILES['avatar'];
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) $fail(422, 'Please upload a JPG, PNG, GIF, WEBP or BMP image');
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-ms-bmp'], true)) $fail(422, 'That file is not a valid image');
if ($f['size'] > 2 * 1024 * 1024) $fail(422, 'Image size must be less than 2MB');

$name = 'avatar_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!is_dir($dir)) mkdir($dir, 0755, true);
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) $fail(500, 'Failed to save the uploaded image');
if ($oldAvatar !== '' && is_file($dir . '/' . basename($oldAvatar))) @unlink($dir . '/' . basename($oldAvatar));

$pdo->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE user_id = ?")->execute([$name, $uid]);
logActivity($pdo, $uid, 'Updated profile avatar (mobile)');
echo json_encode(['success' => true, 'message' => 'Avatar updated successfully', 'avatar_url' => 'uploads/avatars/' . $name]);
