<?php
/**
 * migrations/tenant/2026_10_15_service_price_terms.php
 *
 * Optional "price by term" for services (customer_subscription_plan.md Phase 1):
 * a service can carry any number of user-defined terms (1 Month, 3 Months, 1 Year,
 * 2 Weeks…) each with its own price. Plus vendor_subscription_services — the
 * services BJP sells as BMS subscriptions; only BJP's own database ever has rows.
 * No column is added to products. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/service_price_terms.php';
global $pdo;

echo "Starting tenant migration: service_price_terms...\n";
try {
    sptEnsureSchema($pdo);
    echo "Migration complete: service_price_terms.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
