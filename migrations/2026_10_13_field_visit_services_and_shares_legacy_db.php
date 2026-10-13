<?php
/**
 * migrations/2026_10_13_field_visit_services_and_shares_legacy_db.php
 * Legacy-DB mirror of the tenant migration of the same name.
 *
 * Customer Visits: services intended on a visit (field_visit_services) and report
 * PDFs shared as expiring links (field_report_shares). Same shared schema function
 * as the module migration, so tenant and legacy stay identical. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting legacy migration: field_visit_services_and_shares...
";

try {
    require_once __DIR__ . '/../core/field_reports_schema.php';
    fieldReportsEnsureSchema($pdo);
    echo "Migration complete: field_visit_services_and_shares.
";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "
";
    exit(1);
}
