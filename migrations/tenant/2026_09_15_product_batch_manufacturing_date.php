<?php
/**
 * migrations/tenant/2026_09_15_product_batch_manufacturing_date.php
 *
 * Products — Simple POS simplification (products_simple_pos_plan.md §2).
 * Adds `manufacturing_date` alongside the existing `expiry_date` on
 * `product_batches`, so a batch can carry both dates. Nullable — not every
 * product has a meaningful manufacturing date, same discipline as expiry_date.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: product_batches.manufacturing_date...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'product_batches'")->fetch()) {
        echo "  ~ product_batches table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }

    $exists = $pdo->query("SHOW COLUMNS FROM product_batches LIKE 'manufacturing_date'")->fetch();
    if ($exists) {
        echo "  ~ product_batches.manufacturing_date already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE product_batches ADD COLUMN `manufacturing_date` DATE NULL DEFAULT NULL AFTER `expiry_date`");
        echo "  + product_batches.manufacturing_date column added.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
