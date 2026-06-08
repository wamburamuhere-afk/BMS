<?php
/**
 * api/ai/save_ai_settings.php — persist AI Assistant config (admin only).
 * The API key is encrypted before storage; a blank key field keeps the existing key.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/crypto.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!isAdmin())         { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Only an administrator can change AI settings']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

try {
    $enabled  = isset($_POST['ai_enabled']) && $_POST['ai_enabled'] === '1' ? '1' : '0';
    $provider = in_array($_POST['ai_provider'] ?? '', ['openai', 'anthropic', 'gemini', 'openrouter'], true) ? $_POST['ai_provider'] : 'openai';
    $model    = trim($_POST['ai_model'] ?? '');
    $baseUrl  = trim($_POST['ai_base_url'] ?? '');
    $cap      = (string)max(0, (float)($_POST['ai_monthly_cost_cap'] ?? 0));
    $temp     = (string)min(1, max(0, (float)($_POST['ai_temperature'] ?? 0.4)));
    $newKey   = trim($_POST['ai_api_key'] ?? '');

    if ($model === '') { echo json_encode(['success' => false, 'message' => 'Model is required.']); exit; }

    $upsert = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, updated_at)
        VALUES (:k, :v, 'ai', '0', NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $set = function (string $k, string $v) use ($upsert) { $upsert->execute([':k' => $k, ':v' => $v]); };

    $set('ai_enabled', $enabled);
    $set('ai_provider', $provider);
    $set('ai_model', $model);
    $set('ai_base_url', $baseUrl);
    $set('ai_monthly_cost_cap', $cap);
    $set('ai_temperature', $temp);

    // Only replace the key when a new one is actually entered.
    if ($newKey !== '') {
        $set('ai_api_key_enc', encryptSecret($newKey));
    }

    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated AI Assistant settings (provider: $provider, model: $model, enabled: $enabled)");
    if (function_exists('logAudit')) {
        logAudit($pdo, $_SESSION['user_id'] ?? 0, 'update_ai_settings', [
            'entity_type' => 'settings', 'entity_id' => 0,
            'description' => "AI settings updated — provider=$provider, model=$model, enabled=$enabled, key_changed=" . ($newKey !== '' ? 'yes' : 'no'),
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'AI settings saved.']);
} catch (Throwable $e) {
    error_log('save_ai_settings: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save AI settings.']);
}
