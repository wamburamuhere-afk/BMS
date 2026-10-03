<?php
/**
 * migrations/tenant/2026_10_03_customer_visits_follow_up.php
 *
 * Customer Visits (customer_visits_ux_plan.md): follow-up columns on field_visits and
 * the permission's new name. Re-runs fieldReportsEnsureSchema(), which only adds what
 * is missing (idempotent, no DDL inside a transaction).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: customer_visits_follow_up...\n";

try {
    require_once __DIR__ . '/../../core/field_reports_schema.php';
    fieldReportsEnsureSchema($pdo);
    echo "Migration complete: customer_visits_follow_up.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
