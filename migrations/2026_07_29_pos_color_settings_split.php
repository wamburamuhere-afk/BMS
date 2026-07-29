<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS Settings + Color Setting permission split...\n";

/**
 * "POS Settings" and "Color Setting" moved out of the shared system_settings.php
 * tab list into their own standalone pages (app/constant/settings/
 * pos_config_settings.php and color_settings.php), so they can be delegated
 * independently via Roles & Permissions rather than being locked behind the
 * single 'system_settings' permission. Purely additive: two new permission
 * rows, mirroring the pattern already used for zoom_settings/ai_assistant.
 */
try {
    $newPermissions = [
        ['pos_config_settings', 'POS Settings',  'Configure Point of Sale discount preferences'],
        ['color_settings',      'Color Setting', 'Configure print template accent colors (Sales + Purchase side documents)'],
    ];

    $exists = $pdo->prepare("SELECT 1 FROM permissions WHERE page_key = ?");
    $insert = $pdo->prepare("
        INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
        VALUES ('', ?, ?, ?, 'Settings', 0, NOW())
    ");

    foreach ($newPermissions as [$pageKey, $pageName, $description]) {
        $exists->execute([$pageKey]);
        if (!$exists->fetchColumn()) {
            $insert->execute([$pageKey, $pageName, $description]);
            echo "  + permission '$pageKey' seeded.\n";
        } else {
            echo "  · permission '$pageKey' already present.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
