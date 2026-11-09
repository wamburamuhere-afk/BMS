<?php
/**
 * migrations/2026_11_09_products_recipe_extra_cost_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_09_products_recipe_extra_cost.php onto the LEGACY / non-tenant
 * database. Same body: core/restaurant_costing_schema.php. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/restaurant_costing_schema.php';
global $pdo;

echo "Starting migration: products recipe extra cost (legacy database)...\n";

try {
    rcsEnsureExtraCostColumn($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
