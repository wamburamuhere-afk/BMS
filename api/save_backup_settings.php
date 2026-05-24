<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

// Check permissions
/*if (!has_permission('manage_settings')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}*/

// Import save_setting if it's not available (it's defined in system_settings.php, not here)
// However, it's better to define it in a central place or just implement it here since it's simple.

function api_save_setting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
    return $stmt->execute([$key, $value, $value]);
}

if (!function_exists('isAuthenticated') || !isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!function_exists('canEdit') || !canEdit('backup_restore')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit backup settings']);
    exit;
}

if ($_POST) {
    try {
        $frequency = $_POST['backup_frequency'] ?? 'weekly';
        $retention = $_POST['backup_retention'] ?? '30';
        
        api_save_setting('backup_frequency', $frequency);
        api_save_setting('backup_retention', $retention);

        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Backup Settings", "frequency=$frequency, retention=$retention");

        echo json_encode(['success' => true, 'message' => 'Backup settings saved successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No data received']);
}
