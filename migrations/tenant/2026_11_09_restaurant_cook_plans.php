<?php
/**
 * migrations/tenant/2026_11_09_restaurant_cook_plans.php
 *
 * Restaurant costing plan, Phase 3 — "Pika Leo" (cooking plan) and spoiled stock: tables
 * restaurant_cook_plans, restaurant_cook_plan_items, restaurant_spoilage.
 * Logic in core/restaurant_costing_schema.php. Idempotent (CREATE TABLE IF NOT EXISTS).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_costing_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: restaurant cook plans...\n";

try {
    rcsEnsureCookPlanTables($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
