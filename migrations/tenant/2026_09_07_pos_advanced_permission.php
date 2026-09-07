<?php
/**
 * migrations/tenant/2026_09_07_pos_advanced_permission.php
 *
 * Phase 13 (pos_upgrade_plan.md §7) — a new 'pos_advanced' permission page_key
 * so the Registers/Tills and Loyalty Program sections added in Phases 8 and 11
 * can be gated per-tenant via the existing module-entitlement system
 * (core/feature_registry.php), rather than being on for every tenant
 * regardless of their plan. Mirrors the exact seeding pattern already used by
 * migrations/2026_07_29_pos_color_settings_split.php for 'pos_config_settings'.
 *
 * Purely additive: one new permissions row. Per-tenant because `permissions`
 * is a tenant-scoped table (each tenant DB has its own).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: seed 'pos_advanced' permission...\n";

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
