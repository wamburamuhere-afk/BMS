<?php
/**
 * migrations/2026_09_13_product_batches_prices_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_13_product_batches_prices.php onto the
 * LEGACY / non-tenant database — see 2026_09_08_pos_product_batches_legacy_db.php
 * for why this pairing exists (core/tenant_migration_runner.php never reaches
 * a host's own legacy database, only rows registered in the `tenants` control
 * table).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: product_batches wholesale/selling price columns on the legacy database...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM product_batches LIKE 'wholesale_price'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE product_batches ADD COLUMN wholesale_price DECIMAL(15,2) DEFAULT NULL AFTER unit_cost");
        echo "  + product_batches.wholesale_price added.\n";
    } else {
        echo "  · product_batches.wholesale_price already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM product_batches LIKE 'selling_price'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE product_batches ADD COLUMN selling_price DECIMAL(15,2) DEFAULT NULL AFTER wholesale_price");
        echo "  + product_batches.selling_price added.\n";
    } else {
        echo "  · product_batches.selling_price already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
