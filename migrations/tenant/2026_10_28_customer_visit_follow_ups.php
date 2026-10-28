<?php
/**
 * migrations/tenant/2026_10_28_customer_visit_follow_ups.php
 *
 * Customer Visits: repeated follow-ups (customer_visit_followups_plan.md Phase 1) —
 * table field_visit_follow_ups, a one-time copy of each visit's existing follow-up
 * date, and the "follow-up due" reminder event with its two default rules.
 * Re-runs fieldReportsEnsureSchema(), which only adds what is missing (idempotent,
 * no DDL inside a transaction).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting migration: customer_visit_follow_ups...\n";
try {
    require_once __DIR__ . '/../../core/field_reports_schema.php';
    fieldReportsEnsureSchema($pdo);
    echo "Migration complete: customer_visit_follow_ups.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
