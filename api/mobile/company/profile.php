<?php
// scope-audit: skip — company-wide settings (system_settings), admin-only writes
// GET  → company profile (any signed-in user).
// POST → update, admin only — same fields as the web Company Profile page
//        (app/constant/settings/company_profile.php); multipart company_logo upload.
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/permissions.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$fail = function (int $c, string $m) { http_response_code($c); echo json_encode(['success'=>false,'message'=>$m]); exit; };

$FIELDS = ['company_name', 'company_email', 'company_phone', 'company_website', 'company_currency',
           'company_tin', 'company_vrn', 'company_code_prefix', 'company_postal_address',
           'company_physical_address', 'share_capital_paid_in'];

$read = function () use ($FIELDS): array {
    $out = [];
    foreach ($FIELDS as $f) $out[substr($f, 0, 8) === 'company_' ? substr($f, 8) : $f] = (string)get_setting($f, '');
    $out['currency'] = $out['currency'] ?: 'TZS';
    $out['address']  = (string)get_setting('company_address', '') ?: $out['physical_address'];
    $out['logo_url'] = (string)get_setting('company_logo', '');
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'data' => $read(), 'can_edit' => isAdmin()]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'Method not allowed');
if (!isAdmin()) $fail(403, 'Only an administrator can change the company profile');
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();

// Accept both "company_name" and the short "name" form.
$in = [];
foreach ($FIELDS as $f) {
    $short = substr($f, 8);
    if (array_key_exists($f, $_POST))         $in[$f] = trim((string)$_POST[$f]);
    elseif (array_key_exists($short, $_POST)) $in[$f] = trim((string)$_POST[$short]);
}
if (isset($in['company_name']) && $in['company_name'] === '') $fail(422, 'Company name cannot be empty');
if (!empty($in['company_email']) && !filter_var($in['company_email'], FILTER_VALIDATE_EMAIL)) $fail(422, 'Invalid company email');
if (isset($in['company_code_prefix'])) $in['company_code_prefix'] = substr(strtoupper(preg_replace('/[^A-Za-z]/', '', $in['company_code_prefix'])), 0, 5);
if (isset($in['company_currency'])) {
    $in['company_currency'] = strtoupper($in['company_currency']);
    if (!preg_match('/^[A-Z]{3}$/', $in['company_currency'])) $fail(422, 'Currency must be a 3-letter code, e.g. TZS');
}
if (isset($in['share_capital_paid_in']) && $in['share_capital_paid_in'] !== '' && !is_numeric($in['share_capital_paid_in'])) $fail(422, 'share_capital_paid_in must be a number');

// Logo (optional, multipart): extension + real MIME + size — stricter than the web form.
$logoRel = null; $logoAbs = null;
if (!empty($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['company_logo'];
    if ($f['error'] !== UPLOAD_ERR_OK) $fail(422, 'Logo upload failed');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) $fail(422, 'Invalid file type. Only JPG, PNG, and GIF allowed.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) $fail(422, 'That file is not a valid image');
    if ($f['size'] > 2 * 1024 * 1024) $fail(422, 'Logo must be less than 2MB');
    $dir  = bmsUploadsDir('system/logo');
    $name = 'company_logo_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) $fail(500, 'Failed to upload logo');
    $logoAbs = $dir . $name;
    $logoRel = bmsUploadsRel('system/logo') . $name;
}
if (!$in && $logoRel === null) $fail(422, 'Nothing to update');

$oldLogo = (string)get_setting('company_logo', '');
try {
    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
    $ins = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public) VALUES (?, ?, 'company', 1)");
    $has = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
    if ($logoRel !== null) $in['company_logo'] = $logoRel;
    foreach ($in as $k => $v) {
        $has->execute([$k]);
        if ((int)$has->fetchColumn() > 0) $upd->execute([$v, $k]); else $ins->execute([$k, $v]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($logoAbs && is_file($logoAbs)) @unlink($logoAbs);
    error_log('mobile/company/profile.php: ' . $e->getMessage());
    $fail(500, 'Server error');
}
if ($logoRel !== null && $oldLogo !== '' && $oldLogo !== $logoRel) {
    $oldAbs = ROOT_DIR . '/' . ltrim($oldLogo, '/');
    if (str_starts_with(str_replace('\\', '/', $oldAbs), str_replace('\\', '/', rtrim(bmsUploadsDir('system/logo'), '/'))) && is_file($oldAbs)) @unlink($oldAbs);
}
// get_setting() caches per request, so overlay what was just written.
$data = $read();
foreach ($in as $k => $v) {
    if ($k === 'company_logo') { $data['logo_url'] = $v; continue; }
    $data[substr($k, 0, 8) === 'company_' ? substr($k, 8) : $k] = $v;
}
if (isset($in['company_physical_address']) && (string)get_setting('company_address', '') === '') $data['address'] = $in['company_physical_address'];
logActivity($pdo, $_SESSION['user_id'], 'Updated company profile (mobile): ' . implode(', ', array_keys($in)));
echo json_encode(['success' => true, 'message' => 'Company profile updated successfully!', 'data' => $data]);
