<?php
/**
 * migrations/tenant/2026_10_31_restaurant_menu_columns.php
 *
 * restaurant_plan.md Phase 1 — the restaurant Menu page.
 *   products.is_menu_item  TINYINT(1) NOT NULL DEFAULT 0  — item shows on a restaurant shop's Menu
 *   products.is_available  TINYINT(1) NOT NULL DEFAULT 1  — "Available today"; 0 hides it from selling
 * Defaults leave every existing product exactly as before (not on a menu, available).
 * Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: restaurant menu columns on products...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'products'")->fetch()) {
        echo "  ~ products table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'is_menu_item'")->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN `is_menu_item` TINYINT(1) NOT NULL DEFAULT 0");
        echo "  + products.is_menu_item added.\n";
    } else {
        echo "  ~ products.is_menu_item already exists — skipping.\n";
    }
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'is_available'")->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN `is_available` TINYINT(1) NOT NULL DEFAULT 1");
        echo "  + products.is_available added.\n";
    } else {
        echo "  ~ products.is_available already exists — skipping.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
