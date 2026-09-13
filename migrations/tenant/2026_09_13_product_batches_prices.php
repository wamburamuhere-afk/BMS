<?php
/**
 * migrations/tenant/2026_09_13_product_batches_prices.php
 *
 * POS Restock Product shortcut — adds per-batch wholesale_price/selling_price
 * columns to product_batches, alongside the existing unit_cost (buying price).
 * product_batches already tracks WHAT a batch cost to buy; these two columns
 * let it also remember what it was priced to sell at, so the next restock of
 * the same product can prefill from "what we charged last time" instead of
 * only "what we paid last time". Purely additive/nullable — a product with no
 * batch rows, or an older batch row from before this column existed, behaves
 * exactly as before (NULL, no prefill available).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: product_batches wholesale/selling price columns...\n";

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
