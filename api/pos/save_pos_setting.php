<?php
// scope-audit: skip — writes company-wide system_settings; no project/warehouse scope applies
/**
 * API: Save a POS setting from the mobile app
 * ----------------------------------------------------------------------------
 * Mirrors the three settings editable in pos_config_settings.php but over
 * Bearer-auth so the Flutter Settings screen stays in sync with the web POS.
 *
 * POST (form-encoded or JSON): key, value
 * Allowed keys:
 *   pos_discount_type       → "percentage" | "fixed"
 *   pos_receipt_width       → "58" | "80"
 *   pos_auto_print_receipt  → "0" | "1"
 *
 * Permission: canEdit('pos_config_settings')
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('pos_config_settings')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied: you cannot change POS settings']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

// Accept both form-encoded and JSON body
$body = $_POST;
if (empty($body)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true) ?: [];
    }
}

$key   = trim($body['key']   ?? '');
$value = trim($body['value'] ?? '');

// Strict allow-list — no arbitrary key writes
$allowed = [
    'pos_discount_type' => static function (string $v): ?string {
        return in_array($v, ['percentage', 'fixed'], true) ? $v : null;
    },
    'pos_receipt_width' => static function (string $v): ?string {
        return in_array($v, ['58', '80'], true) ? $v : null;
    },
    'pos_auto_print_receipt' => static function (string $v): ?string {
        return in_array($v, ['0', '1', 'true', 'false'], true)
            ? (in_array($v, ['1', 'true'], true) ? '1' : '0')
            : null;
    },
];

if (!isset($allowed[$key])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Unknown setting key: $key"]);
    exit;
}

$clean = ($allowed[$key])($value);
if ($clean === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => "Invalid value for $key: $value"]);
    exit;
}

try {
    save_setting($key, $clean);
    logActivity($pdo, $_SESSION['user_id'], "Updated POS setting $key = $clean via mobile app");
    echo json_encode(['success' => true, 'key' => $key, 'value' => $clean]);
} catch (Throwable $e) {
    error_log('save_pos_setting.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
