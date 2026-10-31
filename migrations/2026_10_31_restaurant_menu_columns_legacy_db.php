<?php
/**
 * migrations/2026_10_31_restaurant_menu_columns_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_10_31_restaurant_menu_columns.php onto the
 * LEGACY / non-tenant database (see 2026_09_08_pos_network_printer_legacy_db.php
 * for why this pairing exists). Adds products.is_menu_item (default 0) and
 * products.is_available (default 1). Idempotent; degrades if products is absent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: restaurant menu columns on products (legacy database)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'products'")->fetch()) {
        echo "  · products does not exist on this database — nothing to do. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'is_menu_item'")->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN `is_menu_item` TINYINT(1) NOT NULL DEFAULT 0");
        echo "  + Added products.is_menu_item.\n";
    }
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'is_available'")->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN `is_available` TINYINT(1) NOT NULL DEFAULT 1");
        echo "  + Added products.is_available.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
