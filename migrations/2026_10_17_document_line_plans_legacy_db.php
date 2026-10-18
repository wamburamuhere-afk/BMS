<?php
/**
 * migrations/2026_10_17_document_line_plans_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_10_17_document_line_plans.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/service_price_terms.php';
global $pdo;

echo "Starting legacy migration: document_line_plans...\n";
try {
    sptEnsurePlanLineColumns($pdo);
    echo "Migration complete: document_line_plans.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
