<?php
/**
 * migrations/tenant/2026_11_09_restaurant_costing_permission.php
 *
 * Restaurant costing plan, Phase 1 — permission row `restaurant_costing` ("Restaurant Costs & Profit"):
 * who sees buying prices, recipe costs and gross profit in the restaurant screens. Roles that can edit
 * Restaurant POS get view once (back-compat); waiter / cashier / cook roles do not.
 * Logic in core/restaurant_costing_schema.php (dependency-free). Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_costing_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: restaurant costing permission...\n";

try {
    rcsEnsureCostingPermission($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
