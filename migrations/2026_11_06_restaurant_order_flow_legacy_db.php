<?php
/**
 * migrations/2026_11_06_restaurant_order_flow_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_06_restaurant_order_flow.php onto the LEGACY / non-tenant
 * database (restaurant_order_flow_plan.md Phase 1). Same body: core/restaurant_order_flow_schema.php.
 * Idempotent; degrades when the restaurant tables are absent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/restaurant_order_flow_schema.php';
global $pdo;

echo "Starting migration: restaurant order flow (legacy database)...\n";

try {
    restaurantOrderFlowEnsureSchema($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
