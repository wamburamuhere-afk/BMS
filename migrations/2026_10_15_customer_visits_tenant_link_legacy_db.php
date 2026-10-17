<?php
/**
 * migrations/2026_10_15_customer_visits_tenant_link_legacy_db.php
 *
 * Customer Visits: tenant_id, tenant_subdomain, customer_id on field_visits
 * (customer_subscription_plan.md Phase 3). Re-runs fieldReportsEnsureSchema(),
 * which only adds what is missing (idempotent, no DDL inside a transaction).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: customer_visits_tenant_link...\n";
try {
    require_once __DIR__ . '/../core/field_reports_schema.php';
    fieldReportsEnsureSchema($pdo);
    echo "Migration complete: customer_visits_tenant_link.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
