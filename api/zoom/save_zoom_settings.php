<?php
/**
 * api/zoom/save_zoom_settings.php — persist Zoom integration config (admin only).
 * The Client Secret is encrypted before storage; a blank field keeps the existing secret.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/crypto.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!isAdmin())         { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Only an administrator can change Zoom settings']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

try {
    $enabled   = isset($_POST['zoom_enabled']) && $_POST['zoom_enabled'] === '1' ? '1' : '0';
    $accountId = trim($_POST['zoom_account_id'] ?? '');
    $clientId  = trim($_POST['zoom_client_id'] ?? '');
    $newSecret = trim($_POST['zoom_client_secret'] ?? '');

    if ($enabled === '1' && ($accountId === '' || $clientId === '')) {
        echo json_encode(['success' => false, 'message' => 'Account ID and Client ID are required to enable Zoom.']);
        exit;
    }

    $upsert = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, updated_at)
        VALUES (:k, :v, 'zoom', '0', NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $set = function (string $k, string $v) use ($upsert) { $upsert->execute([':k' => $k, ':v' => $v]); };

    $set('zoom_enabled', $enabled);
    $set('zoom_account_id', $accountId);
    $set('zoom_client_id', $clientId);

    // Only replace the secret when a new one is actually entered.
    if ($newSecret !== '') {
        $set('zoom_client_secret_enc', encryptSecret($newSecret));
    }

    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Zoom Integration settings (enabled: $enabled)");
    if (function_exists('logAudit')) {
        logAudit($pdo, $_SESSION['user_id'] ?? 0, 'update_zoom_settings', [
            'entity_type' => 'settings', 'entity_id' => 0,
            'description' => "Zoom settings updated — enabled=$enabled, secret_changed=" . ($newSecret !== '' ? 'yes' : 'no'),
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Zoom settings saved.']);
} catch (Throwable $e) {
    error_log('save_zoom_settings: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save Zoom settings.']);
}
