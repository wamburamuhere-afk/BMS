<?php
/**
 * API: Save POS Simple Mode.
 * ----------------------------------------------------------------------------
 * Single-purpose sibling of app/constant/settings/pos_config_settings.php's
 * own POST handler — both write the same system_settings key
 * ('pos_simple_mode'), so this exists only to let the "More" button on the
 * Point of Sale card (app/constant/settings/available_modules.php) save
 * instantly without loading the full POS Settings form. See
 * core/pos_nav.php::posSimpleModeEnabled().
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit;
}

if (!canEdit('pos_config_settings')) {
    echo json_encode(['success' => false, 'message' => t('Permission denied')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

// Server-side enforcement, not just the "More" button being hidden client-side
// — a superadmin can lock this tenant out of self-managing it
// (app/superadmin/tenant_view.php > Point of Sale > More).
$tenantRowLock = function_exists('bmsCurrentTenant') ? bmsCurrentTenant() : null;
if ($tenantRowLock && !empty($tenantRowLock['pos_simple_mode_locked'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Your platform administrator manages this setting for your account.')]);
    exit;
}

$enabled = isset($_POST['enabled']) && (int)$_POST['enabled'] === 1;

try {
    save_setting('pos_simple_mode', $enabled ? '1' : '0');
    logActivity($pdo, $_SESSION['user_id'], $enabled ? 'Enabled POS Simple Mode' : 'Disabled POS Simple Mode');
    echo json_encode(['success' => true, 'message' => t('POS settings updated successfully')]);
} catch (Exception $e) {
    error_log('save_simple_mode.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
