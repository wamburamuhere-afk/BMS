<?php
/**
 * migrations/2026_10_31_product_batches_manufacturing_date_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_15_product_batch_manufacturing_date.php onto
 * the LEGACY / non-tenant database. See
 * 2026_09_08_pos_network_printer_legacy_db.php for why this pairing exists.
 *
 * Without it, creating a product with opening stock fails on the legacy
 * database: "Unknown column 'manufacturing_date' in 'field list'".
 *
 * Degrades instead of failing: if `product_batches` doesn't exist on this
 * database, that's outside this migration's job — log it and exit 0 rather
 * than blocking every other host's deploy over it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add product_batches.manufacturing_date on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'product_batches'")->fetch();
    if (!$table) {
        echo "  · product_batches does not exist on this database — nothing to do. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $col = $pdo->query("SHOW COLUMNS FROM product_batches LIKE 'manufacturing_date'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "  product_batches.manufacturing_date already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE product_batches ADD COLUMN `manufacturing_date` DATE NULL DEFAULT NULL AFTER `expiry_date`");
        echo "  + Added product_batches.manufacturing_date (nullable).\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
