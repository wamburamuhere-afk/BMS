<?php
/**
 * migrations/2026_06_26_login_history_permission.php
 * Seeds the login_history permission row under System Settings.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Migration: login_history permission seed...\n";

$exists = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'login_history' LIMIT 1")->fetch();
if (!$exists) {
    $moduleId   = $pdo->query("SELECT COALESCE(MAX(module_id),0) FROM permissions WHERE module_name='System Settings'")->fetchColumn();
    $pdo->prepare("
        INSERT INTO permissions (permission_name, page_key, page_name, description, module_id, module_name, is_hidden)
        VALUES (?, 'login_history', 'Login History', 'View user login history with device and location data', ?, 'System Settings', 0)
    ")->execute(['Login History', $moduleId]);
    echo "  + login_history permission seeded.\n";
} else {
    echo "  ~ login_history permission already exists, skipped.\n";
}

echo "Migration complete.\n";
