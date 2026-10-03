<?php
/**
 * migrations/tenant/2026_10_03_wholesale_price_group_backfill.php
 *
 * Products registered with a wholesale price had it only in the legacy
 * products.wholesale_price column, which POS never reads — wholesale customers
 * were charged the normal price. Copies it into the Wholesale price group for
 * products that have no Wholesale override yet (never overwrites one).
 * Idempotent: a second run finds nothing to copy.
 * Product create/edit now write the group themselves (core/pos_price_groups.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: wholesale_price_group_backfill...\n";

try {
    require_once __DIR__ . '/../../core/pos_price_groups.php';
    $n = backfillWholesaleGroupPrices($pdo);
    echo "Migration complete: wholesale_price_group_backfill ($n product(s) fixed).\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
