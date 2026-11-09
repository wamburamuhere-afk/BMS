<?php
/**
 * migrations/tenant/2026_11_09_products_recipe_extra_cost.php
 *
 * Restaurant costing plan, Phase 2 — products.recipe_extra_cost DECIMAL(15,2) NOT NULL DEFAULT 0:
 * a recipe's "other costs" for the whole pot (things not kept as stock, or the whole pot's cost for a
 * restaurant that keeps no stock). An estimate input only — nothing is posted from it.
 * Every existing product gets 0, so nothing changes until an owner types it. Logic in
 * core/restaurant_costing_schema.php. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_costing_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: products recipe extra cost...\n";

try {
    rcsEnsureExtraCostColumn($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
