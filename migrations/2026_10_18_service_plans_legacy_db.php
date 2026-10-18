<?php
/**
 * migrations/2026_10_18_service_plans_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_10_17_service_plans.php.
 *
 * The root install (BJP's own books on bms/demo) runs on the legacy DB, so it
 * never got service_plans, service_plan_items, service_price_terms.plan_id or
 * vendor_subscription_services.platform_product_key — and linking a service to
 * a platform product failed with "the database update has not run". Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/service_plans.php';
global $pdo;

echo "Starting legacy migration: service_plans...\n";
try {
    spEnsureSchema($pdo);
    echo "Migration complete: service_plans.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
