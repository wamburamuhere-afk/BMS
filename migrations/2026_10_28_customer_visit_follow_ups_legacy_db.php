<?php
/**
 * migrations/2026_10_28_customer_visit_follow_ups_legacy_db.php
 *
 * Legacy-DB mirror of migrations/tenant/2026_10_28_customer_visit_follow_ups.php —
 * repeated follow-ups (customer_visit_followups_plan.md Phase 1). Re-runs
 * fieldReportsEnsureSchema(), which only adds what is missing (idempotent, no DDL
 * inside a transaction).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: customer_visit_follow_ups...\n";
try {
    require_once __DIR__ . '/../core/field_reports_schema.php';
    fieldReportsEnsureSchema($pdo);
    echo "Migration complete: customer_visit_follow_ups.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
