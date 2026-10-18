<?php
/**
 * migrations/tenant/2026_10_17_service_plans.php
 *
 * Optional plans on a service (service_plans_plan.md Phase 2): service_plans,
 * service_plan_items, service_price_terms.plan_id, and
 * vendor_subscription_services.platform_product_key (only BJP's install uses it).
 * Existing terms are left with no plan, so every service behaves as before.
 * Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/service_plans.php';
global $pdo;

echo "Starting migration: service_plans...\n";
try {
    spEnsureSchema($pdo);
    echo "Migration complete: service_plans.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
