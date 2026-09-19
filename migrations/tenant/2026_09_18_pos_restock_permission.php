<?php
/**
 * migrations/tenant/2026_09_18_pos_restock_permission.php
 *
 * Adds the 'pos_restock' permission entry so admins can grant
 * the POS Restock Product button to non-admin roles via Roles &
 * Permissions — previously the feature was gated on 'adjust_stock'
 * which had no row in the permissions table, making it admin-only
 * with no way to delegate.
 * Idempotent — INSERT IGNORE skips if the row already exists.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: pos_restock permission...\n";

try {
    $pdo->exec("
        INSERT IGNORE INTO permissions (page_key, page_name, description, module_name)
        VALUES (
            'pos_restock',
            'POS Restock Product',
            'Allow restocking (adjusting stock quantity) of a product directly from the POS terminal',
            'Sales'
        )
    ");
    echo "  pos_restock permission row ensured.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
