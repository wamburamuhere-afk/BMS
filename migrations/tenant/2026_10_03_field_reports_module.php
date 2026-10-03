<?php
/**
 * migrations/tenant/2026_10_03_field_reports_module.php
 *
 * Field Reports (marketing) module — tables + permission row.
 * The module itself is OFF until a superadmin enables 'field_reports' for the
 * tenant (core/feature_registry.php). Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: field_reports_module...\n";

try {
    require __DIR__ . '/../../core/field_reports_schema.php';
    fieldReportsEnsureSchema($pdo);
    echo "Migration complete: field_reports_module.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
