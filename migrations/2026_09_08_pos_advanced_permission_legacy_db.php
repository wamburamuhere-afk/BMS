<?php
/**
 * migrations/2026_09_08_pos_advanced_permission_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_07_pos_advanced_permission.php onto the
 * LEGACY / non-tenant database. Found while scouting after the two
 * 2026-09-08 incidents. Lower severity than the loyalty-program column fix
 * next to it — canX() auto-grants every permission to an admin regardless
 * of whether its row exists, so this doesn't crash anything — but without
 * this row, no non-admin role can ever be granted 'pos_advanced' (Registers/
 * Tills, Loyalty Program) on a legacy database, because it never appears in
 * the Roles & Permissions list. Mirrored for the same reason its sibling
 * tenant migration was written: the two features it gates should be
 * configurable per role everywhere this codebase runs, not just for
 * registered tenants.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: seed 'pos_advanced' permission on the legacy database...\n";

try {
    $exists = $pdo->prepare("SELECT 1 FROM permissions WHERE page_key = ?");
    $exists->execute(['pos_advanced']);
    if (!$exists->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
            VALUES ('', 'pos_advanced', 'POS Advanced', 'Multi-register/till management and the customer loyalty points program', 'Settings', 0, NOW())
        ")->execute();
        echo "  + permission 'pos_advanced' seeded.\n";
    } else {
        echo "  · permission 'pos_advanced' already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
