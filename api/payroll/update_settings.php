<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permissions
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $settings_json = $_POST['settings'] ?? '';
    if (empty($settings_json)) {
        throw new Exception('No settings provided');
    }
    
    $settings = json_decode($settings_json, true);
    if (!is_array($settings)) {
        throw new Exception('Invalid settings format');
    }
    
    $pdo->beginTransaction();
    
    $updated_count = 0;
    $user_id = $_SESSION['user_id'];
    
    foreach ($settings as $setting) {
        $key = $setting['key'] ?? '';
        $value = $setting['value'] ?? '';
        
        if (empty($key)) continue;
        
        // Update or insert setting
        $stmt = $pdo->prepare("
            INSERT INTO payroll_settings (setting_key, setting_value, updated_by) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([$key, $value, $user_id]);
        $updated_count++;
    }
    
    $pdo->commit();

    logActivity($pdo, $user_id, "Updated Payroll Settings", "Settings updated: $updated_count");

    echo json_encode([
        'success' => true,
        'message' => "$updated_count setting(s) updated successfully"
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
