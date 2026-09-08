<?php
/**
 * migrations/tenant/2026_09_08_pos_override_permissions.php
 *
 * Phase 16 (pos_upgrade_plan.md §8) — two new permission page_keys so a
 * cashier's ability to change a cart line's price or apply a discount can be
 * gated separately from general POS access (canCreate('pos')). Mirrors the
 * exact seeding pattern used by migrations/tenant/2026_09_07_pos_advanced_permission.php.
 *
 * Purely additive: two new permissions rows. Per-tenant because `permissions`
 * is a tenant-scoped table (each tenant DB has its own).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: seed POS override permissions...\n";

try {
    $rows = [
        ['pos_price_override',    'POS Price Override',    'Allow changing a cart line\'s unit price away from the product\'s selling price at the POS terminal'],
        ['pos_discount_override', 'POS Discount Override',  'Allow applying a discount to a cart line or sale at the POS terminal'],
    ];

    foreach ($rows as [$page_key, $page_name, $description]) {
        $exists = $pdo->prepare("SELECT 1 FROM permissions WHERE page_key = ?");
        $exists->execute([$page_key]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare("
                INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
                VALUES ('', ?, ?, ?, 'Settings', 0, NOW())
            ")->execute([$page_key, $page_name, $description]);
            echo "  + permission '$page_key' seeded.\n";
        } else {
            echo "  · permission '$page_key' already present.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
