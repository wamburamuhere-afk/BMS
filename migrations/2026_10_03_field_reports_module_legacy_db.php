<?php
/**
 * migrations/2026_10_03_field_reports_module_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_10_03_field_reports_module.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

if (!(bool)$pdo->query("SHOW TABLES LIKE 'permissions'")->fetch()) {
    echo "Legacy DB has no permissions table — skipping field_reports_module migration.\n";
    exit(0);
}

echo "Starting legacy migration: field_reports_module...\n";

try {
    require __DIR__ . '/../core/field_reports_schema.php';
    fieldReportsEnsureSchema($pdo);
    echo "Migration complete: field_reports_module (legacy).\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
