<?php
/**
 * migrations/2026_11_03_products_recipe_yield_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_03_products_recipe_yield.php onto the LEGACY / non-tenant
 * database: products.recipe_yield INT UNSIGNED NOT NULL DEFAULT 1 — how many plates a dish's
 * recipe makes ("1 pot = 20 plates"). Every existing product gets 1. Idempotent; degrades if absent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: products recipe yield (legacy database)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'products'")->fetch()) {
        echo "  · products does not exist on this database — nothing to do. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    if ($pdo->query("SHOW COLUMNS FROM products LIKE 'recipe_yield'")->fetch()) {
        echo "  · products.recipe_yield already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE products ADD COLUMN `recipe_yield` INT UNSIGNED NOT NULL DEFAULT 1");
        echo "  + products.recipe_yield added (default 1).\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
