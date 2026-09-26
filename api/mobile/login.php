<?php
/**
 * api/mobile/login.php
 *
 * POST  username (or phone), password, device_name
 *
 * Returns a Bearer token plus the user/company context the Flutter app needs
 * to initialise its home screen without a separate /me call.
 *
 * The login credential is the BMS `users.username` field.  At tenant
 * registration the owner's phone number is stored there, but an admin can
 * change any user's username to any free-form value via Settings → Users.
 * Accept both "username" and "phone" so older app builds keep working.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Accept "username" (preferred) or "phone" (legacy alias)
$phone       = trim($_POST['username'] ?? $_POST['phone'] ?? '');
$password    = $_POST['password'] ?? '';
$device_name = trim($_POST['device_name'] ?? 'Unknown device');

if ($phone === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Username and password are required']);
    exit;
}

global $pdo;

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
        exit;
    }

    if ((int)($user['is_active'] ?? 1) !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This account has been deactivated. Please contact an administrator.']);
        exit;
    }

    // Generate a 64-hex-char opaque token — no expiry; revoked on logout or account deactivation
    $token      = bin2hex(random_bytes(32));
    $expires_at = null;

    $pdo->prepare("
        INSERT INTO mobile_tokens (token, user_id, device_name, expires_at)
        VALUES (?, ?, ?, ?)
    ")->execute([$token, $user['user_id'], $device_name, $expires_at]);

    // Stamp last_login the same way the web login does
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")
        ->execute([$user['user_id']]);

    // Language per-user from system_settings
    $language = get_setting('user_language_' . $user['user_id'], 'en');

    require_once __DIR__ . '/../../helpers.php';
    logActivity($pdo, $user['user_id'], 'Mobile Login', "Mobile login from device: {$device_name}");

    echo json_encode([
        'success'    => true,
        'token'      => $token,
        'expires_at' => null,
        'user'       => [
            'id'           => (int)$user['user_id'],
            'name'         => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'first_name'   => $user['first_name'] ?? '',
            'last_name'    => $user['last_name']  ?? '',
            'role'         => $user['user_role']  ?? $user['role'] ?? 'user',
            'role_id'      => (int)($user['role_id'] ?? 0),
            'phone'        => $user['username'] ?? '',
            'email'        => $user['email']    ?? '',
            'language'     => $language,
            'currency'     => get_setting('company_currency', 'TZS'),
        ],
        'company'    => [
            'name'         => get_setting('company_name',     ''),
            'logo_url'     => get_setting('company_logo',     ''),
            'currency'     => get_setting('company_currency', 'TZS'),
            'address'      => get_setting('company_address',  ''),
            'phone'        => get_setting('company_phone',    ''),
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
