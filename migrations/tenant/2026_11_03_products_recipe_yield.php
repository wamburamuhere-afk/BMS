<?php
/**
 * migrations/tenant/2026_11_03_products_recipe_yield.php
 *
 * Restaurant recipes cooked by the pot: "1 pot of pilau = 20 plates". The dish remembers how many
 * plates its recipe makes; each recipe line keeps the whole pot's amount (total_qty) and one plate's
 * share (qty_per_unit), which is what selling, stock and cost of goods already read.
 *   products.recipe_yield  INT UNSIGNED NOT NULL DEFAULT 1   (1 = a recipe for one plate, as before)
 * Adds a column only; every existing product gets 1, so nothing changes until an owner sets it.
 * Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: products recipe yield...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'products'")->fetch()) {
        echo "  ~ products table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }
    if ($pdo->query("SHOW COLUMNS FROM products LIKE 'recipe_yield'")->fetch()) {
        echo "  ~ products.recipe_yield already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE products ADD COLUMN `recipe_yield` INT UNSIGNED NOT NULL DEFAULT 1");
        echo "  + products.recipe_yield added (default 1).\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
