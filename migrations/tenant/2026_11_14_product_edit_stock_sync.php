<?php
/**
 * migrations/tenant/2026_11_14_product_edit_stock_sync.php
 *
 * Products whose shop stock was changed through Edit product before the 2026-11-14 fix: the
 * product's own stock copy (products.stock_quantity / current_stock) and, in Smart POS, the
 * shop's batches were left behind (e.g. shop 599, product 5, batches 5). Brings both in line
 * with the shop stock (core/stock_adjust_sync.php). Criteria-based (only shops with a "Stock
 * adjustment via product edit" movement), state-based, so a re-run changes nothing. The ledger
 * is not touched — the adjustment already posted its value.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/stock_adjust_sync.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: product edit stock sync...\n";

try {
    foreach (['stock_movements', 'product_stocks', 'product_batches', 'products'] as $t) {
        if (!$pdo->query("SHOW TABLES LIKE '$t'")->fetch()) { echo "  ~ $t not present — nothing to do.\n"; echo "Migration complete.\n"; exit(0); }
    }
    $pdo->beginTransaction();
    $n = stockAdjustRepairProductEdits($pdo);
    $pdo->commit();
    echo "  + {$n['products']} product(s) checked (product stock copy set to the shop total); batches: {$n['batches_added']} added, {$n['batches_reduced']} reduced.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
