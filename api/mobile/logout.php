<?php
/**
 * api/mobile/logout.php
 *
 * DELETE (or POST) — revokes the Bearer token used in this request.
 * The Flutter app should call this before clearing local storage.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Extract token directly (don't use mobileBearerAuth — we want to delete the
// token regardless of expiry, so an expired token can still be cleaned up)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '' && function_exists('getallheaders')) {
    $hdrs       = getallheaders();
    $authHeader = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
}
if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authHeader), $m)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$token = strtolower($m[1]);

global $pdo;

try {
    // Lookup before delete so we can log the user_id
    $find = $pdo->prepare("SELECT user_id FROM mobile_tokens WHERE token = ? LIMIT 1");
    $find->execute([$token]);
    $row = $find->fetch(PDO::FETCH_ASSOC);

    $pdo->prepare("DELETE FROM mobile_tokens WHERE token = ?")->execute([$token]);

    if ($row) {
        require_once __DIR__ . '/../../helpers.php';
        logActivity($pdo, (int)$row['user_id'], 'Mobile Logout', 'Mobile session token revoked');
    }

    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
